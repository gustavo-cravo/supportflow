<?php

use App\Http\Requests\Api\AnalyticalTicketReportRequest;
use App\Services\Reports\AnalyticalTicketPdfLayout;
use App\Services\Reports\AnalyticalTicketReport;
use App\Services\Reports\PdfAnalyticalTicketReport;
use App\Support\AnalyticalTicketReportConfiguration;
use App\Support\TicketMetricsFilters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Tests\Support\AnalyticalTicketPdfFixture as Fixture;

function analyticalPdf(array $configuration = [], array $filters = []): string
{
    $request = AnalyticalTicketReportRequest::create('/', 'POST', ['period' => 'all', ...$filters, 'configuration' => $configuration]);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    return app(PdfAnalyticalTicketReport::class)->generate(
        TicketMetricsFilters::fromValidated($request->validated()),
        $request->configuration(),
        CarbonImmutable::parse('2026-11-25 17:20:00 UTC'),
    );
}

function analyticalPdfPages(string $bytes): array
{
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $matches);
    $pages = [];
    foreach ($matches[1] as $stream) {
        $content = @gzuncompress($stream) ?: $stream;
        if (! str_contains($content, ' TJ')) {
            continue;
        }
        preg_match_all('/\[\(((?:\\\\.|[^\\\\)])*)\)\]\s*TJ/s', $content, $operators);
        $pieces = array_map(static fn (string $text): string => mb_convert_encoding(
            strtr($text, ['\\(' => '(', '\\)' => ')', '\\\\' => '\\']), 'UTF-8', 'UTF-16BE'
        ), $operators[1]);
        $page = preg_replace('/\s+/u', ' ', implode(' ', $pieces)) ?? '';
        if (preg_match('/Página \d+ de \d+/u', $page)) {
            $pages[] = $page;
        }
    }

    return $pages;
}

function analyticalPdfOnly(int ...$ids): array
{
    return ['mode' => 'only', 'ids' => $ids];
}

/** @return array{x: float, y: float, size: float} */
function analyticalPdfPosition(string $bytes, string $text): array
{
    $encoded = preg_quote(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'), '~');
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $streams);
    foreach ($streams[1] as $stream) {
        $content = @gzuncompress($stream) ?: $stream;
        if (preg_match('~BT ([\d.]+) ([\d.]+) Td /F\d+ ([\d.]+) Tf +\[\('.$encoded.'\)\] TJ ET~', $content, $match)) {
            return ['x' => (float) $match[1], 'y' => (float) $match[2], 'size' => (float) $match[3]];
        }
    }

    throw new LogicException('Texto não localizado no PDF: '.$text);
}

test('renders the institutional A4 and all 17 approved fields with the automatic title', function () {
    $fixture = new Fixture;
    $ticket = $fixture->ticket('Revisão de Óleo & aplicação');
    $pdf = analyticalPdf(['selection' => analyticalPdfOnly($ticket->id)], [
        'period' => 'custom', 'date_from' => '2026-11-01', 'date_to' => '2026-11-25',
        'problem_category_id' => $fixture->category->id, 'system_id' => $fixture->system->id,
    ]);
    expect($pdf)->toStartWith('%PDF-')->toContain('%%EOF', '/FontFile2', '/MediaBox [0.000 0.000 595.280 841.890]');
    $pages = analyticalPdfPages($pdf);
    expect($pages)->toHaveCount(1);
    expect($pages[0])->toContain(
        'SupportFlow', 'Relatório analítico de erros de indicação — ArgoLub', 'Emitido em 25/11/2026 14:20',
        'Período analisado 01/11/2026 a 25/11/2026', 'Categoria Erro de indicação', 'Sistema ArgoLub',
        'Atendimento 01 Revisão de Óleo & aplicação', 'Informações do atendimento', 'Contato de demonstração',
        'Cliente de demonstração', 'Atendimento anterior #0001 · Conferência anterior de catálogo',
        'Descrição do problema', 'Categoria do problema Erro de indicação', 'Filtro de óleo incorreto', 'Referência divergente',
        'Identificação veicular', 'Placa ZQA1234', 'Marca Ford', 'Modelo completo Série LX 2007', 'Linha veicular Linha leve',
        'Resolução e confirmação', 'Solução aplicada', 'Fonte da confirmação Setor responsável',
        'Data e horário da confirmação 20/11/2026 16:41:23', 'Confirmado por Equipe de validação', 'Como a resolução foi verificada',
        'SupportFlow | Relatório analítico de atendimentos', 'Página 1 de 1',
    )->not->toContain('CONTEÚDO EXCLUSIVO DA REFERÊNCIA ANTERIOR', 'Atendimento 02', 'Data de abertura', 'Data de encerramento');
});

test('keeps only selected sections and uses generic criteria for deselected individual values', function () {
    $fixture = new Fixture;
    $ticket = $fixture->ticket('TÍTULO QUE NÃO DEVE IR AO PDF');
    $configuration = [
        'selection' => analyticalPdfOnly($ticket->id),
        'sections' => Fixture::fields(['ticket' => ['description'], 'resolution' => ['resolution']]),
    ];
    $filters = [
        'period' => 'all', 'system_id' => $fixture->system->id, 'problem_category_id' => $fixture->category->id,
        'problem_subcategory_id' => $fixture->problem->id, 'vehicle_brand_id' => $fixture->ford->brand_id,
        'vehicle_plate' => 'ZQA1234', 'vehicle_model' => 'LX 2007', 'vehicle_line' => 'light', 'status' => 'closed',
    ];
    $this->postJson('/api/metrics/analytical-report/configuration', [...$filters, 'configuration' => $configuration])
        ->assertOk()->assertJsonPath('data.title', 'Relatório analítico de atendimentos');
    $pdf = analyticalPdf($configuration, $filters);
    expect(implode(' ', analyticalPdfPages($pdf)))->toContain(
        'Relatório analítico de atendimentos', 'Atendimento 01', 'Sistema Filtro aplicado', 'Categoria Filtro aplicado',
        'Subcategoria Filtro aplicado', 'Marca Filtro aplicado', 'Placa Filtro aplicado', 'Linha Filtro aplicado',
        'Modelo Filtro de descrição aplicado', 'Status Encerrados', 'Descrição do problema', 'Solução aplicada',
    )->not->toContain(
        'ArgoLub', 'Erro de indicação', 'Filtro de óleo incorreto', 'Ford', 'ZQA1234', 'LX 2007', 'Linha leve', 'TÍTULO QUE NÃO DEVE IR AO PDF',
        'Identificação veicular', 'Contato de demonstração', 'Cliente de demonstração', 'Equipe de validação', '#0001',
    );
});

test('renders a custom title and subtitle and escapes markup while keeping literal free-text mentions', function () {
    $fixture = new Fixture;
    $literal = 'Menção original ZQA1234 & <script>alert(7)</script> com (ação) e referência nº 3.';
    $ticket = $fixture->ticket('REGISTRO OCULTO', ['description' => $literal]);
    $pdf = analyticalPdf([
        'selection' => analyticalPdfOnly($ticket->id),
        'sections' => Fixture::fields(['ticket' => ['description']]),
        'title' => ['mode' => 'custom', 'text' => 'Revisão interna de novembro'],
        'subtitle' => 'Conferência dos registros selecionados',
    ], ['vehicle_plate' => 'ZQA1234']);
    $text = implode(' ', analyticalPdfPages($pdf));
    expect($text)->toContain('Revisão interna de novembro', 'Conferência dos registros selecionados', $literal)
        ->and(substr_count($text, 'ZQA1234'))->toBe(1)
        ->and($pdf)->not->toContain('/JavaScript', '/EmbeddedFile', '/URI');
});

test('respects all, exclusion and only selection on the resolved date interval', function (string $mode, array $expected) {
    $fixture = new Fixture;
    $one = $fixture->ticket('REGISTRO DO RECORTE A');
    $two = $fixture->ticket('REGISTRO DO RECORTE B');
    $fixture->ticket('REGISTRO FORA DO RECORTE', ['created_at' => '2026-12-02 08:00:00']);
    $selection = match ($mode) {
        'only' => analyticalPdfOnly($one->id, $one->id),
        'all_except' => ['mode' => 'all_except', 'ids' => [$two->id, $two->id]],
        default => ['mode' => 'all', 'ids' => []],
    };
    $text = implode(' ', analyticalPdfPages(analyticalPdf(
        ['selection' => $selection, 'sections' => Fixture::fields(['ticket' => ['title']])],
        ['period' => 'custom', 'date_from' => '2026-11-01', 'date_to' => '2026-11-25'],
    )));
    foreach ($expected as $label) {
        expect(substr_count($text, 'REGISTRO DO RECORTE '.$label))->toBe(1);
    }
    expect($text)->not->toContain('REGISTRO FORA DO RECORTE', 'Conferência anterior de catálogo');
    expect(preg_match_all('/Atendimento \d\d\b/u', $text))->toBe(count($expected));
})->with([['all', ['A', 'B']], ['all_except', ['A']], ['only', ['A']]]);

test('preserves each grouping and makes numbering global without reordering prepared tickets', function (string $group, array $headings) {
    $fixture = new Fixture;
    $one = $fixture->ticket('ALFA REGISTRO');
    $two = $fixture->ticket('BETA REGISTRO', ['system_id' => $fixture->otherSystem->id, 'problem_category_id' => $fixture->otherCategory->id,
        'vehicle_id' => $fixture->volvo->id]);
    $configuration = ['selection' => analyticalPdfOnly($two->id, $one->id), 'order' => 'title', 'grouping' => $group,
        'sections' => Fixture::fields(['ticket' => ['title', 'system', 'problem_category'], 'vehicle' => ['vehicle_brand', 'vehicle_model']])];
    $text = implode(' ', analyticalPdfPages(analyticalPdf($configuration)));
    expect($text)->toContain('Atendimento 01 ALFA REGISTRO', 'Atendimento 02 BETA REGISTRO')
        ->and(strpos($text, 'ALFA REGISTRO'))->toBeLessThan(strpos($text, 'BETA REGISTRO'))
        ->and(substr_count($text, 'ALFA REGISTRO'))->toBe(1)
        ->and(substr_count($text, 'BETA REGISTRO'))->toBe(1);
    foreach ($headings as $heading) {
        expect($text)->toContain($heading);
    }
})->with([
    ['none', []], ['system', ['Sistema: ArgoLub', 'Sistema: Indicar']], ['category', ['Categoria: Erro de indicação', 'Categoria: Consulta de aplicação']],
    ['brand', ['Marca: Ford', 'Marca: Volvo']], ['model', ['Marca e modelo: Ford / Série LX 2007', 'Marca e modelo: Volvo / Série LX 2007']],
]);

test('renders absence messages and keeps a selected ticket with no presentable data', function (string $mode, array $expected, array $hidden) {
    $fixture = new Fixture;
    $ticket = $fixture->ticket('SEM DADOS SELECIONADOS', [
        'vehicle_id' => null, 'customer_id' => null, 'previous_ticket_id' => null, 'problem_category_id' => null, 'resolution' => null,
        'verification_source' => null, 'verified_at' => null, 'verified_by' => null, 'verification_notes' => null,
    ]);
    $text = implode(' ', analyticalPdfPages(analyticalPdf([
        'selection' => analyticalPdfOnly($ticket->id), 'empty_fields' => $mode,
        'sections' => Fixture::fields(['ticket' => ['customer', 'previous_ticket', 'problem_category', 'problem_subcategories'],
            'vehicle' => ['vehicle_plate', 'vehicle_model'], 'resolution' => ['resolution', 'verified_at', 'verification_notes']]),
    ])));
    expect($text)->toContain('Atendimento 01')->not->toContain('SEM DADOS SELECIONADOS');
    foreach ($expected as $value) {
        expect($text)->toContain($value);
    }
    foreach ($hidden as $value) {
        expect($text)->not->toContain($value);
    }
    expect(substr_count($text, 'Sem veículo vinculado'))->toBe($mode === 'smart' ? 1 : 0);
})->with([
    ['show', ['Cliente não informado', 'Sem atendimento anterior vinculado', 'Sem subcategorias registradas', 'Placa não informada',
        'Modelo não informado', 'Data e horário da confirmação não informados'], ['Sem informações nos campos selecionados']],
    ['smart', ['Sem classificação', 'Nenhuma solução registrada', 'Nenhuma verificação registrada'], ['Cliente não informado', 'Modelo não informado', 'Data e horário da confirmação']],
    ['hide', ['Sem informações nos campos selecionados'], ['Identificação veicular', 'Resolução e confirmação', 'Informações do atendimento', 'Sem classificação']],
]);

test('continues a long grouped ticket with complete text and starts the next individual ticket on a fresh page', function () {
    $fixture = new Fixture;
    $issue = $fixture->ticket('Primeiro atendimento longo', [
        'description' => Fixture::narrative('Parágrafo', 18)."\n\nMARCADOR FINAL DO PRIMEIRO.",
        'resolution' => Fixture::narrative('Solução', 12)."\n\nMARCADOR FINAL DA SOLUÇÃO.",
    ]);
    $next = $fixture->ticket('SEGUNDO ATENDIMENTO CURTO', ['system_id' => $fixture->otherSystem->id]);
    $pdf = analyticalPdf(['selection' => analyticalPdfOnly($issue->id, $next->id), 'grouping' => 'system', 'order' => 'oldest',
        'sections' => Fixture::fields(['ticket' => ['system', 'title', 'description'], 'resolution' => ['resolution']]),
        'layout' => ['pagination' => 'individual', 'columns' => 'one', 'spacing' => 'expanded']]);
    $pages = analyticalPdfPages($pdf);
    expect(count($pages))->toBeGreaterThan(2)->and($pages[0])->toContain('Atendimento 01 Primeiro atendimento longo', 'Sistema: ArgoLub');
    foreach (array_slice($pages, 1, -1) as $page) {
        expect($page)->toContain('Atendimento 01 - Continuação', 'ArgoLub');
    }
    expect(end($pages))->toContain('Atendimento 02 SEGUNDO ATENDIMENTO CURTO', 'Sistema: Indicar')->not->toContain('Continuação');
    $text = implode(' ', $pages);
    expect($text)->toContain('MARCADOR FINAL DO PRIMEIRO.', 'MARCADOR FINAL DA SOLUÇÃO.')
        ->and(substr_count($text, 'Parágrafo 018'))->toBe(1);
    foreach ($pages as $index => $page) {
        expect($page)->toContain('Página '.($index + 1).' de '.count($pages));
    }
});

test('uses different real pagination strategies for short tickets that fit together', function () {
    $fixture = new Fixture;
    $ids = [];
    for ($index = 1; $index <= 6; $index++) {
        $ids[] = $fixture->ticket('Registro '.$index, ['description' => implode("\n", array_fill(0, 10, 'Uma linha de teste da aplicação.'))])->id;
    }
    $configuration = ['selection' => analyticalPdfOnly(...$ids), 'sections' => Fixture::fields(['ticket' => ['title', 'description']])];
    $results = [];
    foreach (['automatic', 'compact', 'individual'] as $mode) {
        $results[$mode] = analyticalPdfPages(analyticalPdf([...$configuration, 'layout' => ['pagination' => $mode]]));
        expect(substr_count(implode(' ', $results[$mode]), 'Uma linha de teste da aplicação.'))->toBe(60);
    }
    expect($results['individual'])->toHaveCount(6)
        ->and(count($results['automatic']))->toBeLessThan(6)
        ->and(implode(' ', $results['automatic']))->not->toContain(' - Continuação')
        ->and(implode(' ', $results['compact']))->toContain(' - Continuação');
});

test('plans columns with complete width for narratives and promotes exceptionally long short values', function () {
    $fixture = new Fixture;
    $ticket = $fixture->ticket('Título selecionado');
    $configuration = AnalyticalTicketReportConfiguration::fromValidated([
        'selection' => analyticalPdfOnly($ticket->id),
        'sections' => Fixture::fields(['ticket' => ['system', 'contact', 'customer', 'description'], 'resolution' => ['verification_source', 'verified_by', 'verification_notes']]),
    ]);
    $record = app(AnalyticalTicketReport::class)->prepare(TicketMetricsFilters::fromValidated(['period' => 'all']), $configuration)['document']['groups'][0]['tickets'][0];
    $layout = app(AnalyticalTicketPdfLayout::class);
    expect(array_map('count', $layout->ticket($record, 'one')['sections'][0]['rows']))->toBe([1, 1, 1, 1])
        ->and(array_map('count', $layout->ticket($record, 'automatic')['sections'][0]['rows']))->toBe([2, 1, 1])
        ->and(array_map('count', $layout->ticket($record, 'automatic')['sections'][1]['rows']))->toBe([1, 1, 1])
        ->and(array_map('count', $layout->ticket($record, 'two')['sections'][1]['rows']))->toBe([2, 1]);
    $record['sections']['ticket']['fields']['system']['value'] = str_repeat('Valor longo. ', 60);
    expect($layout->ticket($record, 'two')['sections'][0]['rows'][0][0]['value'])->toBe(str_repeat('Valor longo. ', 60));
});

test('positions short fields in the requested columns and keeps descriptions full width in the PDF', function () {
    $fixture = new Fixture;
    $ticket = $fixture->ticket('Colunas do relatório');
    $configuration = ['selection' => analyticalPdfOnly($ticket->id),
        'sections' => Fixture::fields(['ticket' => ['system', 'contact', 'customer', 'description']])];
    foreach (['automatic', 'one', 'two'] as $columns) {
        $pdf = analyticalPdf([...$configuration, 'layout' => ['columns' => $columns]]);
        $system = analyticalPdfPosition($pdf, 'Sistema');
        $contact = analyticalPdfPosition($pdf, 'Contato');
        expect(analyticalPdfPosition($pdf, 'Descrição do problema')['x'])->toBe($system['x']);
        if ($columns === 'one') {
            expect($contact['x'])->toBe($system['x'])->and($contact['y'])->toBeLessThan($system['y']);
        } else {
            expect($contact['x'])->toBeGreaterThan($system['x'])->and($contact['y'])->toBe($system['y']);
        }
    }
});

test('changes vertical spacing in the PDF while preserving the font size', function () {
    $fixture = new Fixture;
    $first = $fixture->ticket('Primeiro registro');
    $second = $fixture->ticket('Segundo registro');
    $configuration = ['selection' => analyticalPdfOnly($first->id, $second->id),
        'sections' => Fixture::fields(['ticket' => ['title', 'description']])];
    $positions = [];
    foreach (['compact', 'normal', 'expanded'] as $spacing) {
        $pdf = analyticalPdf([...$configuration, 'layout' => ['spacing' => $spacing]]);
        expect(analyticalPdfPages($pdf))->toHaveCount(1);
        $positions[$spacing] = analyticalPdfPosition($pdf, 'Atendimento 02');
    }
    expect($positions['compact']['y'])->toBeGreaterThan($positions['normal']['y'])
        ->and($positions['normal']['y'])->toBeGreaterThan($positions['expanded']['y'])
        ->and(array_unique(array_column($positions, 'size')))->toBe([9.0]);
});

test('wraps an unbroken Unicode value without dropping any character', function () {
    $fixture = new Fixture;
    $token = 'REFERÊNCIA'.str_repeat('Óleo123', 75).'FIMTOKEN';
    $ticket = $fixture->ticket('Identificador textual', ['description' => $token]);
    $pdf = analyticalPdf(['selection' => analyticalPdfOnly($ticket->id),
        'sections' => Fixture::fields(['ticket' => ['description']])]);
    expect(preg_replace('/\s+/u', '', implode(' ', analyticalPdfPages($pdf))))->toContain($token);
});

test('rejects invalid or empty document selections before rendering', function (string $case) {
    $fixture = new Fixture;
    $ticket = $fixture->ticket('Elegível');
    $configuration = match ($case) {
        'fields' => ['sections' => []],
        'empty' => ['selection' => analyticalPdfOnly()],
        'exclude' => ['selection' => ['mode' => 'all_except', 'ids' => [$ticket->id]]],
        'outside' => ['selection' => analyticalPdfOnly($fixture->previous->id)],
        default => [],
    };
    $date = $case === 'scope' ? '2000-01-01' : '2026-11-11';
    expect(fn () => analyticalPdf($configuration, ['period' => 'custom', 'date_from' => $date, 'date_to' => $date]))
        ->toThrow(ValidationException::class);
})->with(['fields', 'empty', 'exclude', 'outside', 'scope']);

test('renders privately without queries per ticket or writing application records', function () {
    $fixture = new Fixture;
    for ($index = 1; $index <= 16; $index++) {
        $fixture->ticket('Registro para consulta '.$index);
    }
    DB::enableQueryLog();
    try {
        $pdf = analyticalPdf(['sections' => Fixture::fields(['ticket' => ['title', 'system', 'problem_subcategories']])],
            ['period' => 'custom', 'date_from' => '2026-11-01', 'date_to' => '2026-11-25']);
        $queries = array_column(DB::getQueryLog(), 'query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries)->toHaveCount(4);
    foreach ($queries as $query) {
        expect(strtolower($query))->toStartWith('select');
    }
    expect(substr_count(implode(' ', analyticalPdfPages($pdf)), 'Registro para consulta'))->toBe(16);
});

test('serves the real PDF with the effective count, safe filename and both private dispositions', function () {
    Storage::fake('local');
    Storage::fake('public');
    $fixture = new Fixture;
    $ticket = $fixture->ticket('Atendimento reservado');
    $before = $ticket->refresh()->getRawOriginal();
    $payload = ['period' => 'all', 'problem_category_id' => $fixture->category->id, 'configuration' => [
        'selection' => analyticalPdfOnly($ticket->id),
        'sections' => Fixture::fields(['ticket' => ['title', 'problem_category', 'description'], 'resolution' => ['resolution']]),
        'subtitle' => 'Conferência interna de resolução',
    ]];
    $endpoint = '/api/metrics/analytical-report/pdf';
    $preview = $this->postJson($endpoint.'?disposition=inline', $payload)->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Report-Selected-Count', '1');
    $filename = 'supportflow-relatorio-analitico-'.CarbonImmutable::now(TicketMetricsFilters::TIMEZONE)->format('Y-m-d').'.pdf';
    $preview->assertHeader('Content-Disposition', 'inline; filename="'.$filename.'"');
    expect($preview->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $bytes = $preview->getContent();
    expect($bytes)->toStartWith('%PDF-')->and(rtrim($bytes))->toEndWith('%%EOF');
    $text = implode(' ', analyticalPdfPages($bytes));
    expect($text)->toContain('Relatório analítico de erros de indicação', 'Conferência interna de resolução', 'Atendimento reservado', 'Solução aplicada')
        ->not->toContain('ZQA1234', 'Contato de demonstração', 'Cliente de demonstração', 'Conferência anterior de catálogo');

    $payload['configuration']['title'] = ['mode' => 'custom', 'text' => 'Revisão técnica / uso interno'];
    $download = $this->postJson($endpoint, $payload)->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="'.$filename.'"');
    expect(implode(' ', analyticalPdfPages($download->getContent())))->toContain('Revisão técnica / uso interno');
    expect($ticket->fresh()->getRawOriginal())->toBe($before);
    expect(Storage::disk('local')->allFiles())->toBe([])->and(Storage::disk('public')->allFiles())->toBe([]);
    $this->getJson($endpoint)->assertMethodNotAllowed();
    $this->get('/storage/'.$filename)->assertForbidden();
});

test('renders the filtered selection across listing pages without duplicates', function (string $mode) {
    $fixture = new Fixture;
    $titles = [];
    $ids = [];
    for ($index = 1; $index <= 23; $index++) {
        $title = sprintf('Protocolo sintético %02d', $index);
        $ids[] = $fixture->ticket($title)->id;
        $titles[] = $title;
    }
    $fixture->ticket('Outro sistema fora do recorte', ['system_id' => $fixture->otherSystem->id]);
    $filters = ['period' => 'custom', 'date_from' => '2026-11-01', 'date_to' => '2026-11-25', 'system_id' => $fixture->system->id];
    $this->getJson('/api/metrics/analytical-report/tickets?'.http_build_query([...$filters, 'per_page' => 20]))
        ->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 23);
    $selected = match ($mode) {
        'all' => [], 'all_except' => [$ids[0]], 'only' => [$ids[0], $ids[0], $ids[22]],
    };
    $count = match ($mode) {
        'all' => 23, 'all_except' => 22, 'only' => 2
    };
    $response = $this->postJson('/api/metrics/analytical-report/pdf', [...$filters, 'configuration' => [
        'selection' => ['mode' => $mode, 'ids' => $selected],
        'sections' => Fixture::fields(['ticket' => ['title']]),
    ]])->assertOk()->assertHeader('X-Report-Selected-Count', (string) $count);
    $text = implode(' ', analyticalPdfPages($response->getContent()));
    foreach ($titles as $index => $title) {
        $included = match ($mode) {
            'all' => true, 'all_except' => $index !== 0, 'only' => in_array($index, [0, 22], true)
        };
        expect(substr_count($text, $title))->toBe((int) $included);
    }
    expect($text)->not->toContain('Outro sistema fora do recorte', 'Conferência anterior de catálogo');
})->with(['all', 'all_except', 'only']);

test('validates filters, options and membership at the PDF endpoint', function (string $case, string $field) {
    $fixture = new Fixture;
    $ticket = $fixture->ticket('Elegível na abertura');
    $filters = ['period' => 'custom', 'date_from' => '2026-11-01', 'date_to' => '2026-11-25'];
    $endpoint = '/api/metrics/analytical-report/pdf';
    $payload = match ($case) {
        'empty selection' => ['configuration' => ['selection' => analyticalPdfOnly()]],
        'empty scope' => ['date_to' => '2026-11-01'],
        'empty fields' => ['configuration' => ['sections' => []]],
        'invalid field' => ['configuration' => ['sections' => Fixture::fields(['ticket' => ['status']])]],
        'unknown id' => ['configuration' => ['selection' => analyticalPdfOnly(99999)]],
        'invalid id' => ['configuration' => ['selection' => analyticalPdfOnly(0)]],
        'out of scope' => ['configuration' => ['selection' => analyticalPdfOnly($fixture->previous->id)]],
        'group dependency' => ['configuration' => ['grouping' => 'model', 'sections' => Fixture::fields(['vehicle' => ['vehicle_brand']])]],
        'invalid filter' => ['period' => 'impossível'],
        'invalid title' => ['configuration' => ['title' => ['mode' => 'custom', 'text' => '<b>Não permitido</b>']]],
        'raw records' => ['tickets' => [['id' => $ticket->id, 'description' => 'Texto enviado pelo cliente']]],
        'disposition' => ['disposition' => 'unsafe'],
    };
    $this->postJson($endpoint, [...$filters, ...$payload])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['empty selection', 'configuration.selection.ids'], ['empty scope', 'configuration.selection'],
    ['empty fields', 'configuration.sections'], ['invalid field', 'configuration.sections.ticket.fields.0'],
    ['unknown id', 'configuration.selection.ids'], ['invalid id', 'configuration.selection.ids.0'],
    ['out of scope', 'configuration.selection.ids'], ['group dependency', 'configuration.grouping'],
    ['invalid filter', 'period'], ['invalid title', 'configuration.title.text'],
    ['raw records', 'tickets'], ['disposition', 'disposition'],
]);

test('rechecks new and changed records after the configuration was validated', function () {
    $fixture = new Fixture;
    $ticket = $fixture->ticket('Elegível na abertura');
    $filters = ['period' => 'all', 'system_id' => $fixture->system->id];
    $configuration = ['sections' => Fixture::fields(['ticket' => ['title']])];
    $root = '/api/metrics/analytical-report/';
    $this->postJson($root.'configuration', [...$filters, 'configuration' => $configuration])
        ->assertOk()->assertJsonPath('data.selected_count', 2);
    $fixture->ticket('Incluído após a consulta inicial');
    $response = $this->postJson($root.'pdf', [...$filters, 'configuration' => $configuration])->assertOk()
        ->assertHeader('X-Report-Selected-Count', '3');
    expect(implode(' ', analyticalPdfPages($response->getContent())))->toContain('Incluído após a consulta inicial');
    $ticket->update(['system_id' => $fixture->otherSystem->id]);
    $this->postJson($root.'pdf', [...$filters, 'configuration' => [
        ...$configuration, 'selection' => analyticalPdfOnly($ticket->id),
    ]])->assertUnprocessable()->assertJsonValidationErrors('configuration.selection.ids');
});

test('returns a generic API error when the renderer fails and logs only the exception type', function () {
    $fixture = new Fixture;
    $fixture->ticket('Título de teste');
    View::composer('reports.analytical-ticket-report', static function (): never {
        throw new RuntimeException('Conteúdo privado de teste');
    });
    Log::spy();

    $this->postJson('/api/metrics/analytical-report/pdf', ['period' => 'all'])->assertInternalServerError()
        ->assertExactJson(['message' => 'Não foi possível gerar o relatório. Tente novamente.']);
    Log::shouldHaveReceived('error')->once()->with('Falha ao gerar o PDF analítico de atendimentos.', ['exception_type' => RuntimeException::class]);
});
