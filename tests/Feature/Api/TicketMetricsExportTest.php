<?php

use App\Models\Contact;
use App\Models\ProblemCategory;
use App\Models\SupportTicket;
use App\Services\Reports\PdfTicketMetricsReport;
use App\Services\Reports\TicketMetricsReport;
use App\Services\Reports\XlsxTicketMetricsReport;
use App\Services\TicketMetrics;
use App\Services\VehicleMetrics;
use App\Support\TicketMetricsFilters;
use App\Support\TicketMetricsReportData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\TicketMetricsExportFixture;

function exportUrl(string $format = 'xlsx', array $filters = []): string
{
    return '/api/metrics/export?'.http_build_query(['format' => $format, ...$filters]);
}

function exportedWorkbook(string $bytes): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'sf-xlsx-');
    try {
        file_put_contents($path, $bytes);

        return IOFactory::load($path);
    } finally {
        unlink($path);
    }
}

function decodedPdfStreams(string $pdf): string
{
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);
    $streams = [];
    foreach ($matches[1] as $stream) {
        $inflated = @gzuncompress($stream);
        $streams[] = $inflated === false ? $stream : $inflated;
    }

    return implode("\n", $streams);
}

function reportSnapshot(): array
{
    $data = [];
    foreach (['support_tickets', 'systems', 'contacts', 'customers', 'problem_categories', 'problem_subcategories', 'problem_category_system', 'problem_subcategory_system', 'pending_tasks', 'reminders', 'knowledge_articles'] as $table) {
        $data[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
    }

    return $data;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', TicketMetricsFilters::TIMEZONE));
    app()->instance(TicketMetricsExportFixture::class, new TicketMetricsExportFixture);
});

afterEach(function () {
    $this->travelBack();
    DB::disableQueryLog();
    DB::flushQueryLog();
    gc_collect_cycles();
});

test('exports a complete valid PDF with metadata, embedded fonts, page numbers and aggregated data', function () {
    $response = $this->getJson(exportUrl('pdf', ['period' => 'all']))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')->assertDownload('supportflow_relatorio_2026-11-23.pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $pdf = $response->getContent();
    expect($pdf)->toStartWith('%PDF-')->toContain('%%EOF', '/FontFile2', '/Type /Page');
    $decoded = decodedPdfStreams($pdf);
    expect($decoded)->toContain(mb_convert_encoding('SupportFlow', 'UTF-16BE', 'UTF-8'));
    expect($decoded)->toContain(mb_convert_encoding('Sessão expirada', 'UTF-16BE', 'UTF-8'));
    preg_match_all('/\/Type\s*\/Page\b/', $pdf, $pages);
    expect(count($pages[0]))->toBeGreaterThanOrEqual(1);
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
});

test('exports distinct ticket totals and every selected subcategory in PDF and Excel', function () {
    $fixture = app(TicketMetricsExportFixture::class);
    $ticket = SupportTicket::where('created_at', '2026-11-01 00:00:00')->sole();
    $ticket->problemSubcategories()->attach($fixture->credentials->id, ['problem_category_id' => $fixture->category->id]);
    $scope = ['period' => 'custom', 'date_from' => '2026-11-01', 'date_to' => '2026-11-01', 'status' => 'open'];
    $report = app(TicketMetricsReport::class)->build(TicketMetricsFilters::fromValidated($scope));
    expect($report->summary['total'])->toBe(1);
    expect($report->summary['subcategory_occurrences'])->toBe(2);
    expect(array_column($report->categories[0]['subcategories'], 'percentage'))->toBe([100.0, 100.0]);
    $pdf = $this->getJson(exportUrl('pdf', $scope))->assertOk()->getContent();
    $streams = decodedPdfStreams($pdf);
    foreach ([$fixture->login->name, $fixture->credentials->name] as $name) {
        expect($streams)->toContain(mb_convert_encoding($name, 'UTF-16BE', 'UTF-8'));
    }
    expect($streams)->not->toContain(mb_convert_encoding('TITULO_INDIVIDUAL_7C52', 'UTF-16BE', 'UTF-8'));

    $book = exportedWorkbook($this->getJson(exportUrl('xlsx', $scope))->assertOk()->getContent());
    expect($book->getSheetByName('Resumo Geral')->getCell('D13')->getValue())->toBe(1);
    expect($book->getSheetByName('Resumo Geral')->getCell('D18')->getValue())->toBe(2);
    expect($book->getSheetByName('Subcategorias')->rangeToArray('B5:E6'))->toBe([
        [$fixture->login->name, $fixture->systems[0]->name, '1', '100.00%'],
        [$fixture->credentials->name, $fixture->systems[0]->name, '1', '100.00%'],
    ]);
    $book->disconnectWorksheets();

    $filtered = [...$scope, 'problem_subcategory_id' => $fixture->credentials->id];
    $report = app(TicketMetricsReport::class)->build(TicketMetricsFilters::fromValidated($filtered));
    expect($report->summary['total'])->toBe(1);
    expect($report->summary['subcategory_occurrences'])->toBe(1);
    expect(array_column($report->categories[0]['subcategories'], 'name'))->toBe([$fixture->credentials->name]);
});

test('fits a small two-ticket report with two months and subcategories on one A4 portrait page', function () {
    $fixture = app(TicketMetricsExportFixture::class);
    $filters = ['period' => 'all', 'system_id' => $fixture->systems[1]->id, 'problem_category_id' => $fixture->category->id];
    $this->getJson('/api/metrics/summary?'.http_build_query($filters))->assertOk()->assertJsonPath('data.total', 2);
    $pdf = $this->getJson(exportUrl('pdf', $filters))->assertOk()->getContent();
    preg_match_all('/\/Type\s*\/Page\b/', $pdf, $pages);
    expect(count($pages[0]))->toBe(1);
    expect($pdf)->toMatch('/\/MediaBox\s*\[\s*0\.\d+\s+0\.\d+\s+595\.\d+\s+841\.\d+\s*\]/');
    $decoded = decodedPdfStreams($pdf);
    foreach (['Credenciais inválidas', 'Sem subcategoria', '08/2026', '10/2026', 'America/Sao_Paulo'] as $text) {
        expect($decoded)->toContain(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'));
    }
});

test('exports a valid XLSX with five sheets, numeric values, percentages, frozen headers and filters', function () {
    $response = $this->getJson(exportUrl('xlsx', ['period' => 'all']))->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertDownload('supportflow_relatorio_2026-11-23.xlsx');
    expect($response->getContent())->toStartWith('PK');
    $book = exportedWorkbook($response->getContent());
    try {
        expect($book->getSheetNames())->toBe(['Resumo Geral', 'Por Sistema', 'Categorias', 'Subcategorias', 'Evolução Mensal']);
        $summary = $book->getSheetByName('Resumo Geral');
        expect(array_map(fn ($row) => $summary->getCell('D'.$row)->getValue(), range(13, 17)))->toBe([8, 3, 5, 3, 2]);
        expect($summary->getCell('D13')->getDataType())->toBe(DataType::TYPE_NUMERIC);
        foreach (['Por Sistema', 'Categorias', 'Subcategorias', 'Evolução Mensal'] as $title) {
            $sheet = $book->getSheetByName($title);
            expect($sheet->getFreezePane())->toBe('A5');
            expect($sheet->getAutoFilter()->getRange())->not->toBe('');
            expect($sheet->getStyle('A4')->getFont()->getBold())->toBeTrue();
        }
        expect($book->getSheetByName('Por Sistema')->getCell('E5')->getValue())->toBe(0.625);
        expect($book->getSheetByName('Por Sistema')->getStyle('E5')->getNumberFormat()->getFormatCode())->toBe('0.00%');
        expect($book->getSheetByName('Evolução Mensal')->getCell('A5')->getFormattedValue())->toBe('05/2026');
    } finally {
        $book->disconnectWorksheets();
    }
});

test('shares exactly the dashboard aggregates and conserves total counts in every distribution', function () {
    $report = app(TicketMetricsReport::class)->build(TicketMetricsFilters::fromValidated(['period' => 'all']));
    expect($report->summary)->toBe($this->getJson('/api/metrics/summary?period=all')->json('data'));
    expect(array_sum(array_column($report->systems, 'total')))->toBe(8);
    expect(array_sum(array_column($report->categories, 'total')))->toBe(8);
    foreach ($report->categories as $category) {
        expect(array_sum(array_column($category['subcategories'], 'total')))->toBe($category['total']);
        foreach ($category['subcategories'] as $sub) {
            expect(array_sum(array_column($sub['systems'], 'total')))->toBe($sub['total']);
        }
    }
    expect(array_column($report->categories, 'name'))->toContain('Sem classificação', 'Falha de acesso');
    expect(array_column($report->categories[0]['subcategories'], 'name'))->toContain('Sem subcategoria');
    expect(array_column($report->systems, 'name'))->toContain('iLube');
});

test('uses calendar periods and inclusive Sao Paulo boundaries identically in exported documents', function (string $period, int $total, string $from) {
    $response = $this->getJson(exportUrl('xlsx', ['period' => $period]))->assertOk();
    $book = exportedWorkbook($response->getContent());
    try {
        expect($book->getSheetByName('Resumo Geral')->getCell('D13')->getValue())->toBe($total);
        expect($book->getSheetByName('Resumo Geral')->getCell('B5')->getValue())->toContain($from);
    } finally {
        $book->disconnectWorksheets();
    }
})->with([
    ['this_month', 3, '01/11/2026'], ['last_30_days', 3, '25/10/2026'], ['last_3_months', 4, '01/09/2026'],
    ['last_6_months', 6, '01/06/2026'], ['this_year', 7, '01/01/2026'], ['all', 8, 'Todo o histórico'],
]);

test('exports individual and combined filter results using the same filter object for both writers', function (string $case) {
    $fixture = app(TicketMetricsExportFixture::class);
    $filters = match ($case) {
        'system' => ['system_id' => $fixture->systems[1]->id],
        'status' => ['status' => 'closed'],
        'not_closed' => ['status' => 'not_closed'],
        'category' => ['problem_category_id' => $fixture->category->id],
        'subcategory' => ['problem_subcategory_id' => $fixture->login->id],
        'unclassified' => ['classification' => 'unclassified'],
        'without_subcategory' => ['classification' => 'without_subcategory', 'problem_category_id' => $fixture->category->id],
        default => ['period' => 'custom', 'date_from' => '2026-11-23', 'date_to' => '2026-11-23', 'system_id' => $fixture->systems[0]->id, 'status' => 'closed', 'problem_category_id' => $fixture->category->id, 'problem_subcategory_id' => $fixture->login->id],
    };
    $filters = ['period' => 'all', ...$filters];
    $expected = $this->getJson('/api/metrics/summary?'.http_build_query($filters))->assertOk()->json('data.total');
    $pdf = Mockery::mock(PdfTicketMetricsReport::class, function (MockInterface $mock) use ($expected): void {
        $mock->shouldReceive('render')->once()
            ->withArgs(fn (TicketMetricsReportData $data) => $data->summary['total'] === $expected)
            ->andReturn('%PDF-test');
    });
    $this->app->instance(PdfTicketMetricsReport::class, $pdf);
    $this->getJson(exportUrl('pdf', $filters))->assertOk();
    $book = exportedWorkbook($this->getJson(exportUrl('xlsx', $filters))->assertOk()->getContent());
    try {
        expect($book->getSheetByName('Resumo Geral')->getCell('D13')->getValue())->toBe($expected);
    } finally {
        $book->disconnectWorksheets();
    }
})->with(['system', 'status', 'not_closed', 'category', 'subcategory', 'unclassified', 'without_subcategory', 'combined']);

test('preserves monthly zeros and returns empty documents with explicit criteria and zero indicators', function () {
    $filters = ['period' => 'custom', 'date_from' => '2030-01-01', 'date_to' => '2030-03-31'];
    $report = app(TicketMetricsReport::class)->build(TicketMetricsFilters::fromValidated($filters));
    expect($report->monthly)->toBe([['month' => '2030-01', 'total' => 0], ['month' => '2030-02', 'total' => 0], ['month' => '2030-03', 'total' => 0]]);
    $pdf = $this->getJson(exportUrl('pdf', $filters))->assertOk()->getContent();
    expect(decodedPdfStreams($pdf))->toContain(mb_convert_encoding('Nenhum atendimento encontrado', 'UTF-16BE', 'UTF-8'));
    preg_match_all('/\/Type\s*\/Page\b/', $pdf, $pages);
    expect(count($pages[0]))->toBe(1);
    $book = exportedWorkbook($this->getJson(exportUrl('xlsx', $filters))->assertOk()->getContent());
    try {
        $summary = $book->getSheetByName('Resumo Geral');
        foreach (range(13, 17) as $row) {
            expect($summary->getCell('D'.$row)->getValue())->toBe(0);
        }
        expect($summary->getCell('B5')->getValue())->toContain('01/01/2030 a 31/03/2030');
        expect($book->getSheetByName('Por Sistema')->getCell('A5')->getValue())->toContain('Nenhuma ocorrência');
        expect($book->getSheetByName('Evolução Mensal')->getCell('B7')->getValue())->toBe(0);
    } finally {
        $book->disconnectWorksheets();
    }
});

test('prevents individual and personal information from entering either actual output file', function () {
    $pdf = $this->getJson(exportUrl('pdf', ['period' => 'all']))->assertOk()->getContent();
    $decoded = decodedPdfStreams($pdf);
    expect($decoded)->toContain(mb_convert_encoding('Falha de acesso', 'UTF-16BE', 'UTF-8'));
    $bytes = $this->getJson(exportUrl('xlsx', ['period' => 'all']))->assertOk()->getContent();
    $path = tempnam(sys_get_temp_dir(), 'sf-privacy-');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = '';
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $xml .= $zip->getFromIndex($index);
    }
    $zip->close();
    unlink($path);
    foreach (['CONTATO_PRIVADO_7C52', 'CLIENTE_PRIVADO_7C52', 'TITULO_INDIVIDUAL_7C52', 'DESCRICAO_INDIVIDUAL_7C52', 'RESOLUCAO_PRIVADA_7C52', '5511987654321', '01234567000189', 'privado7c52@example.test', 'OBSERVACAO_PRIVADA_7C52'] as $secret) {
        expect($xml)->not->toContain($secret);
        expect($decoded)->not->toContain($secret)->not->toContain(mb_convert_encoding($secret, 'UTF-16BE', 'UTF-8'));
    }
    expect($xml)->not->toContain('support_ticket_id', 'customer_id', 'contact_id', '<f>');
});

test('treats formula-like registered names as literal strings in all worksheets', function (string $name) {
    $fixture = app(TicketMetricsExportFixture::class);
    $fixture->systems[0]->update(['name' => $name]);
    $fixture->category->update(['name' => $name]);
    $fixture->login->update(['name' => $name]);
    $book = exportedWorkbook($this->getJson(exportUrl('xlsx', ['period' => 'all', 'problem_category_id' => $fixture->category->id]))->assertOk()->getContent());
    try {
        expect($book->getSheetByName('Resumo Geral')->getCell('B8')->getValue())->toBe($name);
        expect($book->getSheetByName('Categorias')->getCell('A5')->getValue())->toBe($name);
        foreach ($book->getAllSheets() as $sheet) {
            expect($sheet->getHyperlinkCollection())->toBe([]);
            foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                expect($sheet->getCell($coordinate)->getDataType())->not->toBe(DataType::TYPE_FORMULA);
            }
        }
    } finally {
        $book->disconnectWorksheets();
    }
})->with(['=1+1', '+SUM(A1:A2)', '-1+1', '@SUM(A1:A2)', '=HYPERLINK("https://example.test","Nome")']);

test('escapes markup names in the PDF template instead of loading external images or scripts', function () {
    $fixture = app(TicketMetricsExportFixture::class);
    $name = '<img src="https://example.test/private"> & Ação';
    $fixture->systems[0]->update(['name' => $name]);
    $report = app(TicketMetricsReport::class)->build(TicketMetricsFilters::fromValidated(['period' => 'all']));
    $html = view('reports.ticket-metrics', ['report' => $report, 'charts' => []])->render();
    expect($html)->toContain(e($name))->not->toContain($name);
});

test('rejects invalid export formats, dates, IDs and classification combinations before rendering', function (array $filters, string $field) {
    $renderer = Mockery::mock(PdfTicketMetricsReport::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('render');
    });
    $this->app->instance(PdfTicketMetricsReport::class, $renderer);
    $this->getJson('/api/metrics/export?'.http_build_query(['format' => 'pdf', ...$filters]))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['format' => 'csv'], 'format'], [['period' => 'custom'], 'date_from'],
    [['period' => 'custom', 'date_from' => '2026-11-30', 'date_to' => '2026-11-01'], 'date_to'],
    [['period' => 'invalid'], 'period'], [['system_id' => 99999], 'system_id'],
    [['problem_category_id' => 99999], 'problem_category_id'], [['problem_subcategory_id' => 99999], 'problem_subcategory_id'],
    [['status' => 'invalid'], 'status'], [['page' => 1], 'page'], [['per_page' => 50], 'per_page'],
    [['classification' => 'unclassified', 'problem_category_id' => 1], 'classification'],
    [['classification' => 'without_subcategory', 'problem_subcategory_id' => 1], 'classification'],
]);

test('requires a format and rejects a subcategory from another category', function () {
    $fixture = app(TicketMetricsExportFixture::class);
    $this->getJson('/api/metrics/export')->assertUnprocessable()->assertJsonValidationErrors('format');
    $this->getJson(exportUrl('pdf', ['problem_category_id' => $fixture->category->id, 'problem_subcategory_id' => $fixture->plate->id]))->assertUnprocessable()->assertJsonValidationErrors('problem_subcategory_id');
});

test('exports only read queries without changing any business records', function () {
    $before = reportSnapshot();
    DB::enableQueryLog();
    foreach (['pdf', 'xlsx'] as $format) {
        $this->getJson(exportUrl($format, ['period' => 'all']))->assertOk();
    }
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();
    expect(reportSnapshot())->toBe($before);
    foreach ($queries as $query) {
        expect(strtolower($query))->not->toMatch('/\b(insert|update|delete)\s/i');
        expect(strtolower($query))->not->toContain('"contacts"', '"customers"', 'contact_id', 'customer_id', 'description', 'resolution');
    }
    expect(count($queries))->toBeLessThanOrEqual(12);
});

test('handles renderer failures as HTTP errors without leaking paths or delivering broken files', function (string $format) {
    $rendererClass = $format === 'pdf' ? PdfTicketMetricsReport::class : XlsxTicketMetricsReport::class;
    $renderer = Mockery::mock($rendererClass, function (MockInterface $mock): void {
        $mock->shouldReceive('render')->once()->andThrow(new RuntimeException('PRIVATE_SERVER_PATH_C:/secret/report'));
    });
    $this->app->instance($rendererClass, $renderer);
    $response = $this->getJson(exportUrl($format))->assertStatus(500)->assertJsonPath('message', 'Não foi possível gerar o relatório. Tente novamente.');
    expect($response->getContent())->not->toContain('PRIVATE_SERVER_PATH');
    expect($response->headers->get('Content-Disposition'))->toBeNull();
})->with(['pdf', 'xlsx']);

test('refuses inconsistent aggregate results instead of generating an incorrect report', function () {
    $metrics = Mockery::mock(TicketMetrics::class, function (MockInterface $mock): void {
        $mock->shouldReceive('summary')->once()->andReturn(['total' => 99, 'closed' => 0, 'not_closed' => 99, 'systems_count' => 0, 'unclassified' => 0]);
        foreach (['systems', 'categories', 'subcategories', 'monthly'] as $method) {
            $mock->shouldReceive($method)->once()->andReturn([]);
        }
    });
    expect(fn () => (new TicketMetricsReport($metrics, app(VehicleMetrics::class)))->build(TicketMetricsFilters::fromValidated([])))->toThrow(LogicException::class);
});

test('renders large category distributions across multiple valid PDF pages', function () {
    $fixture = app(TicketMetricsExportFixture::class);
    for ($index = 0; $index < 40; $index++) {
        $category = ProblemCategory::create(['name' => 'Categoria '.str_pad((string) $index, 2, '0', STR_PAD_LEFT).' - análise de ocorrências e integração com nome extenso para paginação']);
        SupportTicket::make(['system_id' => $fixture->systems[0]->id, 'contact_id' => Contact::first()->id, 'problem_category_id' => $category->id, 'title' => 'NUNCA_EXPORTAR_PAGINACAO', 'description' => 'NUNCA_EXPORTAR_DESCRICAO'])->save();
    }
    $pdf = $this->getJson(exportUrl('pdf', ['period' => 'all']))->assertOk()->getContent();
    preg_match_all('/\/Type\s*\/Page\b/', $pdf, $pages);
    expect(count($pages[0]))->toBeGreaterThan(1);
    expect($pdf)->toContain('%%EOF');
    expect(decodedPdfStreams($pdf))->not->toContain(mb_convert_encoding('NUNCA_EXPORTAR_PAGINACAO', 'UTF-16BE', 'UTF-8'));
});

test('preserves all rows and months when one category continues across multiple PDF pages', function () {
    $report = app(TicketMetricsReport::class)->build(TicketMetricsFilters::fromValidated(['period' => 'all']));
    $children = [];
    for ($index = 1; $index <= 110; $index++) {
        $children[] = ['name' => sprintf('Detalhe%03d - análise de integração e credenciais inválidas', $index), 'total' => 1, 'percentage' => round(100 / 110, 2), 'systems' => []];
    }
    $monthly = [];
    for ($index = 0; $index < 36; $index++) {
        $monthly[] = ['month' => CarbonImmutable::parse('2024-01-01')->addMonths($index)->format('Y-m'), 'total' => $index === 35 ? 110 : 0];
    }
    $data = new TicketMetricsReportData($report->generatedAt, $report->criteria,
        ['total' => 110, 'closed' => 0, 'not_closed' => 110, 'systems_count' => 1, 'unclassified' => 0, 'subcategory_occurrences' => 110],
        [['name' => 'Sistema de teste', 'total' => 110, 'closed' => 0, 'not_closed' => 110, 'percentage' => 100.0]],
        [['name' => 'Categoria extensa', 'total' => 110, 'percentage' => 100.0, 'subcategories' => $children]], $monthly);
    $pdf = app(PdfTicketMetricsReport::class)->render($data);
    preg_match_all('/\/Type\s*\/Page\b/', $pdf, $pages);
    expect(count($pages[0]))->toBeGreaterThan(1);
    $decoded = decodedPdfStreams($pdf);
    for ($index = 1; $index <= 110; $index++) {
        expect($decoded)->toContain(mb_convert_encoding(sprintf('Detalhe%03d', $index), 'UTF-16BE', 'UTF-8'));
    }
    foreach ($monthly as $month) {
        $label = substr($month['month'], 5, 2).'/'.substr($month['month'], 0, 4);
        expect($decoded)->toContain(mb_convert_encoding($label, 'UTF-16BE', 'UTF-8'));
    }
});

test('preserves month labels for dates outside Excel native date display without broken cells', function () {
    $book = exportedWorkbook($this->getJson(exportUrl('xlsx', ['period' => 'custom', 'date_from' => '1000-01-01', 'date_to' => '1000-01-31']))->assertOk()->getContent());
    try {
        expect($book->getSheetByName('Evolução Mensal')->getCell('A5')->getValue())->toBe('1000-01');
        expect($book->getSheetByName('Evolução Mensal')->getCell('B5')->getValue())->toBe(0);
    } finally {
        $book->disconnectWorksheets();
    }
});
