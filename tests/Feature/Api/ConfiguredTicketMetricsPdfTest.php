<?php

use App\Models\ProblemSubcategory;
use App\Models\System;
use App\Models\Vehicle;
use App\Services\Reports\ConfiguredTicketMetricsPdf;
use App\Services\Reports\TicketMetricsPdfLayout;
use App\Services\TicketMetrics;
use App\Support\TicketMetricsFilters;
use App\Support\TicketMetricsPdfConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\ConfiguredMetricsPdfFixture as Fixture;
use Tests\Support\VehicleMetricsFixture;

function configuredMetricsSelection(array $sections, ?string $title = null, ?string $subtitle = null): TicketMetricsPdfConfiguration
{
    return TicketMetricsPdfConfiguration::fromValidated([
        'sections' => $sections, 'title' => $title === null ? ['mode' => 'automatic'] : ['mode' => 'custom', 'text' => $title],
        'subtitle' => $subtitle,
    ]);
}

function configuredMetricsText(string $bytes): string
{
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $matches);
    $snippets = [];
    foreach ($matches[1] as $stream) {
        $content = @gzuncompress($stream) ?: $stream;
        if (! str_contains($content, ' TJ')) {
            continue;
        }
        preg_match_all('/\[\(((?:\\\\.|[^\\\\)])*)\)\]\s*TJ/s', $content, $operators);
        foreach ($operators[1] as $text) {
            $snippets[] = mb_convert_encoding(strtr($text, ['\\(' => '(', '\\)' => ')', '\\\\' => '\\']), 'UTF-8', 'UTF-16BE');
        }
    }

    return preg_replace('/\s+/u', ' ', implode(' ', $snippets)) ?? '';
}

test('renders a real A4 PDF with only selected summary facts and the automatic title', function () {
    new VehicleMetricsFixture;
    $configuration = configuredMetricsSelection(['summary' => Fixture::section(['closed_percentage', 'text_summary'])]);
    $service = app(ConfiguredTicketMetricsPdf::class);
    $filters = TicketMetricsFilters::fromValidated(['period' => 'all']);
    $data = $service->prepare($filters, $configuration);
    expect($data['title'])->toBe('Relatório de indicadores de atendimento')
        ->and($data['subtitle'])->toBeNull()
        ->and(array_column($data['sections'], 'label'))->toBe(['Resumo geral dos atendimentos'])
        ->and($data['sections'][0]['blocks'][1]['text'])->toBe('Percentual de encerramento: 28,57%.');

    $pdf = $service->generate($filters, $configuration);
    $text = configuredMetricsText($pdf);
    expect($pdf)->toStartWith('%PDF-')->toContain('%%EOF', '/FontFile2', '/MediaBox [0.000 0.000 595.280 841.890]')
        ->and(preg_match_all('/\/Type\s*\/Page\b/', $pdf))->toBe(1);
    foreach (['28,57%', 'Resumo geral dos atendimentos', 'Página 1 de 1', 'Relatório de indicadores de atendimento'] as $label) {
        expect($text)->toContain($label);
    }
    foreach (['Total de atendimentos', 'Atendimentos encerrados', 'Distribuição por sistema', 'Estatísticas veiculares'] as $label) {
        expect($text)->not->toContain($label);
    }
});

test('distinguishes no records, a selected zero, a missing denominator and no recurrence', function () {
    $selection = configuredMetricsSelection([
        'summary' => Fixture::section(['total', 'closed_percentage', 'unclassified']),
        'systems' => Fixture::section(['system_percentage']),
        'vehicles' => Fixture::section(['distinct_vehicles', 'recurrence', 'lines' => 'doughnut']),
    ]);
    $pdf = app(ConfiguredTicketMetricsPdf::class)->generate(TicketMetricsFilters::fromValidated(['period' => 'all']), $selection);
    $text = configuredMetricsText($pdf);
    foreach (['Nenhum atendimento encontrado', 'Não aplicável', 'Percentual não aplicável', 'Veículos diferentes',
        'Nenhum veículo apresentou dois ou mais atendimentos no período analisado.'] as $label) {
        expect($text)->toContain($label);
    }
    expect($text)->toContain('0 Total de atendimentos')->not->toContain('0%');
});

test('honors all applied criteria and keeps distinct tickets separate from overlapping problems and vehicles', function () {
    $fixture = new VehicleMetricsFixture;
    $fixture->car->update(['model_description' => 'Série 1.6 LX (placa HFS-4456)']);
    $configuration = configuredMetricsSelection([
        'summary' => Fixture::section(['total', 'closed', 'unclassified']),
        'systems' => Fixture::section(['system_percentage']),
        'categories' => Fixture::section(['subcategory_occurrences', 'subcategory_percentage']),
        'vehicles' => Fixture::section(['distinct_vehicles', 'models']),
        'vehicle_problems' => Fixture::groups(['systems', 'categories', 'counts'], 'model', 'table'),
    ], 'Resultados da equipe', 'Análise interna');
    $filters = TicketMetricsFilters::fromValidated([
        'period' => 'custom', 'date_from' => '2026-11-20', 'date_to' => '2026-11-20', 'status' => 'open',
        'system_id' => $fixture->system->id, 'problem_category_id' => $fixture->category->id,
        'vehicle_presence' => 'with', 'vehicle_plate' => 'HFS44', 'vehicle_brand_id' => $fixture->alfa->id,
        'vehicle_model' => 'Série 1.6 LX', 'vehicle_line' => 'light',
    ]);
    $service = app(ConfiguredTicketMetricsPdf::class);
    $report = $service->prepare($filters, $configuration);
    expect($report['title'])->toBe('Resultados da equipe')->and($report['subtitle'])->toBe('Análise interna')
        ->and($report['period'])->toBe('20/11/2026 a 20/11/2026')
        ->and(array_column($report['sections'][0]['blocks'][0]['rows'], 'value'))->toBe(['1', '0', '0'])
        ->and($report['sections'][1]['blocks'][0]['rows'])->toBe([['name' => 'Sistema A', 'value' => 100.0]])
        ->and($report['sections'][2]['blocks'][1]['rows'])->toBe([
            ['Modelo divergente', '1', '100%'], ['Ano divergente', '1', '100%'],
        ])
        ->and($report['sections'][3]['blocks'][0]['rows'][0]['value'])->toBe('1')
        ->and($report['sections'][4]['blocks'][0]['children'][0]['children'][0]['children'][0]['rows'])->toBe([
            ['Identificação', '1', ''], ['- Modelo divergente', '', '1'], ['- Ano divergente', '', '1'],
        ]);
    expect(json_encode($report, JSON_THROW_ON_ERROR))->not->toContain('HFS44', 'vehicle_id', 'model_key', 'TITULO_PRIVADO_987');
    $pdf = $service->generate($filters, $configuration);
    $text = configuredMetricsText($pdf);
    foreach (['Resultados da equipe', 'Análise interna', 'Filtro de placa aplicado', '[placa omitida]', '100%'] as $label) {
        expect($text)->toContain($label);
    }
    foreach (['HFS4456', 'HFS-4456', 'CLIENTE_PRIVADO_987', 'CONTATO_PRIVADO_987', 'DESCRICAO_PRIVADA_987'] as $label) {
        expect($pdf)->not->toContain($label);
        expect($text)->not->toContain($label);
    }
});

test('accepts the normalized API contract with three requested dimensions and retains system cross tabs', function () {
    $fixture = new VehicleMetricsFixture;
    $payload = [
        'period' => 'all',
        'configuration' => ['sections' => [
            'vehicle_problems' => Fixture::groups(['systems', 'categories', 'counts'], 'model', 'descriptive_group', [
                ['by' => 'brand', 'format' => 'table'], ['by' => 'line', 'format' => 'table'],
            ]),
        ]],
    ];
    $response = $this->postJson('/api/metrics/export/pdf/configuration', $payload)->assertOk();
    $service = app(ConfiguredTicketMetricsPdf::class);
    $document = $service->prepare(TicketMetricsFilters::fromValidated($payload), TicketMetricsPdfConfiguration::fromValidated($response->json('data')));
    $groups = $document['sections'][0]['blocks'];
    expect(array_column($groups, 'label'))->toBe(['Por marca e modelo', 'Por marca', 'Por linha veicular'])
        ->and($document['title'])->toBe('Relatório de problemas por modelo veicular')
        ->and(array_column($groups[0]['children'][0]['children'], 'label'))->toBe(['Sistema: Sistema A', 'Sistema: Sistema B']);
    expect(array_column($groups[0]['children'], 'label'))->toContain('Marca Alfa / Série 1.6 LX 2007', 'Marca Alfa / Modelo não informado');

    $onlyLabels = $service->prepare(TicketMetricsFilters::fromValidated($payload), configuredMetricsSelection([
        'vehicle_problems' => Fixture::groups([], 'line', 'table'),
    ]));
    expect($onlyLabels['sections'][0]['blocks'][0]['rows'])->toBe([['Linha leve'], ['Linha pesada']]);
    expect(json_encode($onlyLabels, JSON_THROW_ON_ERROR))->not->toContain('Sistema A', $fixture->first->name);
});

test('suppresses a plate fragment from custom titles while keeping coincident statistical values', function () {
    $fixture = new VehicleMetricsFixture;
    $fixture->car->update(['plate' => 'HFS4452', 'model_description' => 'Modelo exclusivo']);
    $configuration = configuredMetricsSelection([
        'summary' => Fixture::section(['total' => 'table', 'text_summary' => 'text']),
    ], 'Equipe 2');
    $filters = TicketMetricsFilters::fromValidated(['period' => 'all', 'vehicle_plate' => '2', 'vehicle_model' => 'Modelo exclusivo']);
    $document = app(ConfiguredTicketMetricsPdf::class)->prepare($filters, $configuration);
    expect($document['title'])->toBe('Equipe [placa omitida]')
        ->and($document['sections'][0]['blocks'][0]['rows'])->toBe([['Total de atendimentos', '2']])
        ->and($document['sections'][0]['blocks'][1]['text'])->toBe('Total de atendimentos: 2.');
});

test('separates brand and model ranks from recurrence and preserves tied recurring problems without counts when unselected', function () {
    $fixture = new VehicleMetricsFixture;
    $fixture->secondTicket->problemSubcategories()->attach($fixture->second->id, ['problem_category_id' => $fixture->category->id]);
    $extra = Vehicle::factory()->for($fixture->beta, 'brand')->create(['vehicle_line' => 'heavy', 'model_description' => null]);
    $fixture->firstTicket->replicate()->forceFill(['vehicle_id' => $extra->id])->save();
    $selection = configuredMetricsSelection([
        'categories' => Fixture::section(['most_frequent_problems']),
        'vehicles' => Fixture::section(['distinct_vehicles', 'brands', 'models', 'recurrence']),
    ]);
    $report = app(ConfiguredTicketMetricsPdf::class)->prepare(TicketMetricsFilters::fromValidated(['period' => 'all']), $selection);
    expect($report['sections'][0]['blocks'][0]['children'][0]['rows'])->toBe([['Modelo divergente'], ['Ano divergente']]);
    $blocks = $report['sections'][1]['blocks'];
    expect($blocks[0]['rows'][0]['value'])->toBe('6')
        ->and($blocks[1]['rows'][0])->toBe(['name' => 'Marca Alfa', 'value' => 5, 'vehicles' => 4])
        ->and(array_column($blocks[2]['rows'], 'name'))->toContain('Marca Alfa / Modelo não informado', 'Marca Beta / Modelo não informado')
        ->and(array_column($blocks[3]['children'][0]['rows'], 'value'))->toBe(['1', '2', '2']);
});

test('matches the modal title priority for explicit topics, brand and system qualifiers', function () {
    $fixture = new VehicleMetricsFixture;
    $fixture->category->update(['name' => 'Erro de indicação']);
    $fixture->first->update(['name' => 'Falha de indicação veicular']);
    $fixture->alfa->update(['name' => 'Ford']);
    $fixture->system->update(['name' => 'Indicar']);
    $service = app(ConfiguredTicketMetricsPdf::class);
    $configuration = configuredMetricsSelection(['vehicle_problems' => Fixture::groups([], 'model')]);
    $scope = ['period' => 'all', 'system_id' => $fixture->system->id, 'problem_category_id' => $fixture->category->id, 'vehicle_brand_id' => $fixture->alfa->id];
    expect($service->prepare(TicketMetricsFilters::fromValidated($scope), $configuration)['title'])
        ->toBe('Relatório de erros de indicação — Ford');
    $scope['problem_subcategory_id'] = $fixture->first->id;
    expect($service->prepare(TicketMetricsFilters::fromValidated($scope), $configuration)['title'])
        ->toBe('Relatório de erros de indicação veicular — Ford');
});

test('resolves automatic formats by volume and renders compatible manual formats in the real PDF', function () {
    new VehicleMetricsFixture;
    $small = [['name' => 'Sistema A', 'value' => 1]];
    expect(TicketMetricsPdfLayout::seriesFormat('tickets_by_system', 'automatic', $small))->toBe('table')
        ->and(TicketMetricsPdfLayout::seriesFormat('tickets_by_system', 'automatic', [...$small, ['name' => 'Sistema B', 'value' => 2]]))->toBe('horizontal_bar')
        ->and(TicketMetricsPdfLayout::seriesFormat('tickets_by_system', 'automatic', array_fill(0, 9, $small[0])))->toBe('table')
        ->and(TicketMetricsPdfLayout::seriesFormat('lines', 'automatic', $small))->toBe('doughnut')
        ->and(TicketMetricsPdfLayout::seriesFormat('models', 'vertical_bar', $small))->toBe('vertical_bar');
    $singleSlice = (new TicketMetricsPdfLayout)->doughnut([0, 1]);
    expect(base64_decode(substr($singleSlice, strlen('data:image/svg+xml;base64,')), true))
        ->toContain('<circle', '>2</text>');
    $configuration = configuredMetricsSelection([
        'summary' => Fixture::section(['total' => 'table', 'closed_percentage' => 'indicator', 'text_summary' => 'text']),
        'systems' => Fixture::section(['tickets_by_system' => 'vertical_bar']),
        'categories' => Fixture::section(['subcategory_occurrences' => 'horizontal_bar']),
        'vehicles' => Fixture::section(['lines' => 'doughnut', 'models' => 'table']),
        'vehicle_problems' => Fixture::groups(['categories', 'counts'], 'brand', 'descriptive_group'),
    ], 'Formatos solicitados');
    $pdf = app(ConfiguredTicketMetricsPdf::class)->generate(TicketMetricsFilters::fromValidated(['period' => 'all']), $configuration);
    $text = configuredMetricsText($pdf);
    foreach (['Barras verticais', 'Barras horizontais', 'Resumo textual', 'Indicador', 'veículos diferentes', 'Por marca'] as $label) {
        expect($text)->toContain($label);
    }
});

test('preserves complete long models through dynamic pages and repeated table headers', function () {
    $fixture = new VehicleMetricsFixture;
    Fixture::addVolume($fixture, 26);
    $configuration = configuredMetricsSelection(['vehicles' => Fixture::section(['models' => 'table'])]);
    DB::enableQueryLog();
    $pdf = app(ConfiguredTicketMetricsPdf::class)->generate(TicketMetricsFilters::fromValidated(['period' => 'all']), $configuration);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    DB::flushQueryLog();
    $text = configuredMetricsText($pdf);
    expect(preg_match_all('/\/Type\s*\/Page\b/', $pdf))->toBeGreaterThan(2)
        ->and(substr_count($text, 'Veículos diferentes'))->toBeGreaterThan(1)
        ->and($text)->toContain('Descrição integral confirmada');
    foreach ($queries as $query) {
        expect(mb_strtolower(ltrim($query['query'])))->toStartWith('select');
    }
});

test('serves the same configured rendering inline and as an attachment through the binary route', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 01:15:00 UTC'));
    $fixture = new VehicleMetricsFixture;
    $fixture->car->update(['model_description' => 'Série especial com referência HFS-4456']);
    $title = 'Relatório: validação / equipe "A"?';
    $payload = [
        'period' => 'custom', 'date_from' => '2026-11-20', 'date_to' => '2026-11-20',
        'system_id' => $fixture->system->id, 'status' => 'open', 'vehicle_plate' => 'hfs-4456',
        'configuration' => [
            'sections' => [
                'summary' => Fixture::section(['total' => 'table', 'closed_percentage']),
                'systems' => Fixture::section(['tickets_by_system' => 'vertical_bar']),
                'vehicle_problems' => Fixture::groups(['categories', 'counts'], 'model', 'descriptive_group'),
            ],
            'title' => ['mode' => 'custom', 'text' => $title],
            'subtitle' => 'Análise da equipe',
        ],
    ];
    $textByMode = [];
    foreach (['inline', 'attachment'] as $mode) {
        $route = '/api/metrics/export/pdf'.($mode === 'inline' ? '?disposition=inline' : '');
        $response = $this->postJson($route, $payload)->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', $mode.'; filename="supportflow_relatorio_2026-11-25.pdf"')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
        $bytes = $response->getContent();
        $text = $textByMode[$mode] = configuredMetricsText($bytes);
        expect($bytes)->toStartWith('%PDF-')->toContain('%%EOF')->not->toContain('HFS4456', 'HFS-4456');
        expect($text)->toContain($title, 'Análise da equipe', '25/11/2026 22:15', '20/11/2026 a 20/11/2026',
            'Total de atendimentos 1', 'Barras verticais', 'Filtro de placa aplicado', 'Marca Alfa / Série especial com referência [placa omitida]')
            ->not->toContain('HFS4456', 'HFS-4456', 'TITULO_PRIVADO_987', 'CONTATO_PRIVADO_987', 'Categorias e problemas identificados');
    }
    expect($textByMode['inline'])->toBe($textByMode['attachment']);
});

test('generates the automatic title and a genuine PDF for an empty scope', function () {
    $response = $this->postJson('/api/metrics/export/pdf', [
        'period' => 'all',
        'configuration' => ['sections' => ['summary' => Fixture::section(['total'])]],
    ])->assertOk();
    expect(configuredMetricsText($response->getContent()))
        ->toContain('Relatório de indicadores de atendimento', 'Nenhum atendimento encontrado', '0 Total de atendimentos');
});

test('rejects invalid binary requests before running report queries', function (string $suffix, array $overrides, string $field) {
    $payload = ['period' => 'all', 'configuration' => ['sections' => ['summary' => Fixture::section(['total'])]]];
    $metrics = Mockery::mock(TicketMetrics::class);
    $metrics->shouldNotReceive('summary');
    app()->instance(TicketMetrics::class, $metrics);
    $this->postJson('/api/metrics/export/pdf'.$suffix, [...$payload, ...$overrides])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'disposition' => ['?disposition=unsupported', [], 'disposition'],
    'empty selection' => ['', ['configuration' => ['sections' => []]], 'configuration.sections'],
    'conflicting vehicle filters' => ['', ['vehicle_presence' => 'without', 'vehicle_plate' => 'HFS44'], 'vehicle_plate'],
    'format for scalar' => ['', ['configuration' => ['sections' => ['summary' => Fixture::section(['total' => 'doughnut'])]]], 'configuration.sections.summary.indicators.total.format'],
    'invalid grouping' => ['', ['configuration' => ['sections' => ['vehicle_problems' => Fixture::groups([], 'placa')]]], 'configuration.sections.vehicle_problems.grouping.primary.by'],
    'html subtitle' => ['', ['configuration' => ['sections' => ['summary' => Fixture::section(['total'])], 'subtitle' => '&lt;b&gt;Equipe&lt;/b&gt;']], 'configuration.subtitle'],
    'ticket number' => ['', ['configuration' => ['sections' => ['summary' => Fixture::section(['total'])], 'title' => ['mode' => 'custom', 'text' => 'Atendimento #00321']]], 'configuration.title.text'],
    'external reference' => ['', ['configuration' => ['sections' => ['summary' => Fixture::section(['total'])], 'subtitle' => 'Protocolo SUP-20884']], 'configuration.subtitle'],
    'individual email' => ['', ['configuration' => ['sections' => ['summary' => Fixture::section(['total'])], 'subtitle' => 'Contato pessoa@example.test']], 'configuration.subtitle'],
]);

test('reports a binary generation failure without returning or logging filter bindings or title text', function () {
    Log::spy();
    $metrics = Mockery::mock(TicketMetrics::class);
    $metrics->shouldReceive('summary')->once()->andThrow(new RuntimeException('PRIVATE_SQL / HFS4456'));
    app()->instance(TicketMetrics::class, $metrics);
    $response = $this->postJson('/api/metrics/export/pdf?disposition=inline', [
        'vehicle_plate' => 'HFS4456',
        'configuration' => ['sections' => ['summary' => Fixture::section(['total'])]],
    ])->assertServerError()->assertExactJson(['message' => 'Não foi possível gerar o relatório. Tente novamente.']);
    expect($response->headers->has('Content-Disposition'))->toBeFalse();
    Log::shouldHaveReceived('error')->once()->with(
        'Falha ao gerar o PDF estatístico configurado.',
        ['exception_type' => RuntimeException::class],
    );
});

test('reconciles a real PDF and workbook with Dashboard aggregates for a known multi-problem fleet', function () {
    $fixture = new VehicleMetricsFixture;
    System::where('name', 'Sistema B')->update(['is_active' => false]);
    $third = ProblemSubcategory::create(['name' => 'Versão divergente', 'problem_category_id' => $fixture->category->id]);
    $fixture->firstTicket->problemSubcategories()->attach($third, ['problem_category_id' => $fixture->category->id]);
    $older = $fixture->firstTicket->replicate()->forceFill(['created_at' => '2026-11-19 23:59:59']);
    $older->save();
    $filters = ['period' => 'custom', 'date_from' => '2026-11-20', 'date_to' => '2026-11-21'];
    $query = http_build_query($filters);
    $summary = $this->getJson('/api/metrics/summary?'.$query)->assertOk()->json('data');
    $systems = $this->getJson('/api/metrics/systems?'.$query)->assertOk()->json('data');
    $categories = $this->getJson('/api/metrics/categories?'.$query)->assertOk()->json('data');
    $problems = $this->getJson('/api/metrics/subcategories?'.$query)->assertOk()->assertJsonPath('meta.occurrences', 4)->json('data');
    $vehicles = $this->getJson('/api/metrics/vehicles?'.$query)->assertOk()->json('data');
    expect($summary)->toBe([
        'total' => 7, 'closed' => 2, 'not_closed' => 5, 'systems_count' => 2, 'unclassified' => 3, 'subcategory_occurrences' => 4,
    ])->and(array_column($systems, 'total', 'system_name'))->toBe(['Sistema A' => 6, 'Sistema B' => 1])
        ->and($systems[1]['is_active'])->toBeFalse()
        ->and(array_column($categories, 'total', 'category_name'))->toBe(['Identificação' => 4, 'Sem classificação' => 3])
        ->and($vehicles['recurrence'])->toBe(['repeat_vehicles' => 1, 'repeat_tickets' => 2, 'max_tickets_per_vehicle' => 2]);
    $actualProblems = array_values(array_filter($problems, fn (array $row): bool => $row['problem_subcategory_id'] !== null));
    expect(array_column($actualProblems, 'total', 'subcategory_name'))->toBe(['Modelo divergente' => 2, 'Ano divergente' => 1, 'Versão divergente' => 1])
        ->and(array_column($actualProblems, 'percentage'))->toEqual([50, 25, 25])
        ->and(array_column($actualProblems, 'category_total'))->toBe([4, 4, 4]);

    $xlsx = $this->get('/api/metrics/export?format=xlsx&include_vehicle_statistics=1&'.$query)
        ->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $temp = tmpfile();
    fwrite($temp, $xlsx->getContent());
    try {
        $book = IOFactory::load(stream_get_meta_data($temp)['uri']);
        $rows = fn (string $sheet): array => array_slice($book->getSheetByName($sheet)->toArray(formatData: false), 4);
        $general = array_column($rows('Resumo Geral'), 3, 0);
        expect([$general['Total de atendimentos'], $general['Encerrados'], $general['Não encerrados'], $general['Sistemas com atendimentos'],
            $general['Sem classificação'], $general['Vínculos com subcategorias']])->toBe(array_values($summary))
            ->and(array_column($rows('Por Sistema'), 1, 0))->toBe(array_column($systems, 'total', 'system_name'))
            ->and(array_column($rows('Categorias'), 1, 0))->toBe(array_column($categories, 'total', 'category_name'));
        $workbookProblems = array_column($rows('Subcategorias'), 3, 1);
        $workbookPercentages = array_column($rows('Subcategorias'), 4, 1);
        foreach ($actualProblems as $row) {
            expect($workbookProblems[$row['subcategory_name']])->toBe($row['total'])
                ->and($workbookPercentages[$row['subcategory_name']])->toBe($row['percentage'] / 100);
        }
        $vehicleSummary = array_column($rows('Resumo veicular'), 1, 0);
        expect([$vehicleSummary['Atendimentos com veículo'], $vehicleSummary['Atendimentos sem veículo'], $vehicleSummary['Veículos distintos']])->toBe([6, 1, 5])
            ->and(array_slice(array_values($vehicleSummary), -3))->toBe(array_values($vehicles['recurrence']))
            ->and(array_map(fn (array $row): array => array_slice($row, 0, 3), $rows('Linhas veiculares')))
            ->toBe([['Linha leve', 4, 3], ['Linha pesada', 2, 2]])
            ->and(array_column($rows('Marcas'), 1, 0))->toBe(['Marca Alfa' => 5, 'Marca Beta' => 1])
            ->and(array_slice($rows('Modelos')[0], 0, 5))->toBe(['Marca Alfa', 'Série 1.6 LX 2007', 'Leve e pesada', 3, 2]);
        foreach (['brand' => 'marca', 'model' => 'modelo', 'line' => 'linha'] as $dimension => $sheetSuffix) {
            $byType = [];
            foreach ($rows('Problemas por '.$sheetSuffix) as $row) {
                $type = $row[count($row) - 2];
                $byType[$type] = ($byType[$type] ?? 0) + end($row);
            }
            expect($byType)->toBe(['Atendimentos' => 6, 'Vínculos' => 4])
                ->and(array_sum(array_column($vehicles['problems'][$dimension], 'total')))->toBe(10);
        }
    } finally {
        if (isset($book)) {
            $book->disconnectWorksheets();
        }
        fclose($temp);
    }

    $pdf = $this->postJson('/api/metrics/export/pdf', [...$filters, 'configuration' => ['sections' => [
        'summary' => Fixture::section(['total' => 'table', 'closed' => 'table', 'not_closed' => 'table', 'unclassified' => 'table', 'closed_percentage' => 'table']),
        'systems' => Fixture::section(['tickets_by_system', 'system_percentage']),
        'categories' => Fixture::section(['tickets_by_category' => 'table', 'subcategory_occurrences', 'subcategory_percentage']),
        'vehicles' => Fixture::section(['distinct_vehicles' => 'table', 'with_vehicle' => 'table', 'without_vehicle' => 'table', 'lines' => 'table',
            'brands' => 'table', 'models' => 'table', 'recurrence' => 'table']),
        'vehicle_problems' => Fixture::groups(['systems', 'categories', 'counts'], 'model', 'table', [
            ['by' => 'brand', 'format' => 'table'], ['by' => 'line', 'format' => 'table'],
        ]),
    ]]])->assertOk()->getContent();
    $text = configuredMetricsText($pdf);
    foreach (['Total de atendimentos 7', 'Atendimentos encerrados 2', 'Atendimentos não encerrados 5', 'Atendimentos sem classificação 3',
        'Percentual de encerramento 28,57%', 'Sistema A 6 85,71%', 'Sistema B 1 14,29%', 'Identificação 4',
        'Modelo divergente 2 50%', 'Ano divergente 1 25%', 'Versão divergente 1 25%', 'Veículos diferentes 5',
        'Atendimentos com veículo identificado 6', 'Atendimentos sem veículo identificado 1', 'Linha leve 4 3', 'Linha pesada 2 2',
        'Marca Alfa 5 4', 'Marca Beta 1 1', 'Marca Alfa / Série 1.6 LX 2007 3 2', 'Marca Alfa / Modelo não informado',
        'Veículos com dois ou mais atendimentos 1', 'Atendimentos desses veículos 2', 'Por marca e modelo', 'Por marca', 'Por linha veicular',
        'Identificação 2 - Modelo divergente 2 - Ano divergente 1 - Versão divergente 1'] as $label) {
        expect($text)->toContain($label);
    }
    expect($text)->not->toContain('HFS4456', 'CONTATO_PRIVADO_987', 'CLIENTE_PRIVADO_987', 'DESCRICAO_PRIVADA_987');

    $onlyFirst = [...$filters, 'status' => 'open', 'vehicle_plate' => 'HFS-4456'];
    $query = http_build_query($onlyFirst);
    $this->getJson('/api/metrics/summary?'.$query)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.subcategory_occurrences', 3);
    $this->getJson('/api/metrics/vehicles?'.$query)->assertOk()->assertJsonPath('data.recurrence.repeat_vehicles', 0);
    $single = $this->postJson('/api/metrics/export/pdf', [...$onlyFirst, 'configuration' => ['sections' => [
        'categories' => Fixture::section(['subcategory_occurrences', 'subcategory_percentage']),
        'vehicles' => Fixture::section(['recurrence' => 'table']),
    ]]])->assertOk();
    expect(configuredMetricsText($single->getContent()))->toContain(
        'Modelo divergente 1 100%', 'Ano divergente 1 100%', 'Versão divergente 1 100%',
        'Veículos com dois ou mais atendimentos 0', 'Filtro de placa aplicado',
    )->not->toContain('HFS4456', 'HFS-4456');
});

test('uses each applied Dashboard filter for the actual configured PDF', function (string $case, int $expected) {
    $fixture = new VehicleMetricsFixture;
    $day = ['period' => 'custom', 'date_from' => '2026-11-20', 'date_to' => '2026-11-20'];
    $filters = ['period' => 'all', ...match ($case) {
        'day' => $day,
        'inactive system' => ['system_id' => System::where('name', 'Sistema B')->firstOrFail()->id],
        'category' => ['problem_category_id' => $fixture->category->id],
        'subcategory' => ['problem_subcategory_id' => $fixture->first->id],
        'closed' => ['status' => 'closed'],
        'without vehicle' => ['vehicle_presence' => 'without'],
        'plate' => ['vehicle_plate' => 'hfs-44'],
        'model' => ['vehicle_model' => '2008'],
        'brand' => ['vehicle_brand_id' => $fixture->alfa->id],
        'line' => ['vehicle_line' => 'heavy'],
        'unclassified' => ['classification' => 'unclassified'],
        'missing subcategory' => ['classification' => 'without_subcategory'],
        'empty' => ['vehicle_brand_id' => $fixture->beta->id, 'vehicle_line' => 'light'],
        'combined' => [...$day, 'system_id' => $fixture->system->id, 'problem_category_id' => $fixture->category->id,
            'problem_subcategory_id' => $fixture->first->id, 'status' => 'open', 'vehicle_presence' => 'with', 'vehicle_plate' => 'HFS4456',
            'vehicle_model' => '2007', 'vehicle_brand_id' => $fixture->alfa->id, 'vehicle_line' => 'light'],
    }];
    System::where('name', 'Sistema B')->update(['is_active' => false]);
    $this->getJson('/api/metrics/summary?'.http_build_query($filters))->assertOk()->assertJsonPath('data.total', $expected);
    $pdf = $this->postJson('/api/metrics/export/pdf', [...$filters, 'configuration' => [
        'sections' => ['summary' => Fixture::section(['total' => 'table'])],
    ]])->assertOk();
    $text = configuredMetricsText($pdf->getContent());
    expect($text)->toContain('Total de atendimentos '.$expected)->not->toContain('Distribuição por sistema', 'HFS4456', 'HFS44');
})->with([
    ['day', 6], ['inactive system', 1], ['category', 4], ['subcategory', 2], ['closed', 2], ['without vehicle', 1],
    ['plate', 2], ['model', 1], ['brand', 5], ['line', 2], ['unclassified', 3], ['missing subcategory', 2], ['empty', 0], ['combined', 1],
]);

test('repeats category context on continued problem and ranking tables', function () {
    $fixture = new VehicleMetricsFixture;
    Fixture::addVolume($fixture, 6);
    $category = $fixture->category;
    for ($i = 1; $i <= 25; $i++) {
        $problem = ProblemSubcategory::create([
            'name' => sprintf('Verificação %02d da compatibilidade para os componentes e variantes de classificação', $i),
            'problem_category_id' => $category->id,
        ]);
        foreach ([$fixture->firstTicket, $fixture->secondTicket] as $ticket) {
            $ticket->problemSubcategories()->attach($problem, ['problem_category_id' => $category->id]);
        }
    }
    $pdf = $this->postJson('/api/metrics/export/pdf', ['period' => 'all', 'configuration' => ['sections' => [
        'categories' => Fixture::section(['subcategory_occurrences', 'subcategory_percentage', 'most_frequent_problems']),
    ]]])->assertOk()->getContent();
    $text = configuredMetricsText($pdf);
    expect(substr_count($text, 'Identificação Problema identificado'))->toBeGreaterThan(1)
        ->and($text)->toContain('Identificação Problemas mais frequentes', 'Verificação 25 da compatibilidade');

    $separate = $this->postJson('/api/metrics/export/pdf', ['period' => 'all', 'configuration' => ['sections' => [
        'categories' => Fixture::section(['subcategory_occurrences' => 'table']),
    ]]])->assertOk()->getContent();
    expect(substr_count(configuredMetricsText($separate), 'Identificação Grupo Ocorrências'))->toBeGreaterThan(1);
});

test('blocks a typographically separated plate in report identification and omits it from a stored model', function () {
    $fixture = new VehicleMetricsFixture;
    $plate = 'HFS‑4456';
    $fixture->car->update(['model_description' => 'Série de teste '.$plate]);
    $payload = ['period' => 'all', 'vehicle_plate' => 'HFS4456', 'configuration' => ['sections' => [
        'vehicles' => Fixture::section(['models' => 'table']),
    ]]];
    $this->postJson('/api/metrics/export/pdf', [...$payload, 'configuration' => [
        ...$payload['configuration'], 'title' => ['mode' => 'custom', 'text' => 'HFS‑4456'],
    ]])->assertUnprocessable()->assertJsonValidationErrors('configuration.title.text');

    $pdf = $this->postJson('/api/metrics/export/pdf', $payload)->assertOk();
    expect(configuredMetricsText($pdf->getContent()))->not->toContain($plate, 'HFS4456')
        ->toContain('Série de teste [placa omitida]', 'Filtro de placa aplicado');
});

test('keeps line distributions invariant across every compatible visual format', function () {
    new VehicleMetricsFixture;
    $service = app(ConfiguredTicketMetricsPdf::class);
    $filters = TicketMetricsFilters::fromValidated(['period' => 'all']);
    $expected = [
        ['name' => 'Linha leve', 'value' => 4, 'vehicles' => 3],
        ['name' => 'Linha pesada', 'value' => 2, 'vehicles' => 2],
    ];
    foreach (['automatic', 'table', 'horizontal_bar', 'vertical_bar', 'doughnut'] as $format) {
        $selection = configuredMetricsSelection(['vehicles' => Fixture::section(['lines' => $format])]);
        $report = $service->prepare($filters, $selection);
        $block = $report['sections'][0]['blocks'][0];
        expect($block['rows'])->toBe($expected)
            ->and($block['format'])->toBe($format === 'automatic' ? 'doughnut' : $format)
            ->and(configuredMetricsText($service->generate($filters, $selection)))
            ->toContain('Linha leve', 'Linha pesada', 'Atendimentos por linha veicular')
            ->not->toContain('Ranking de marcas', 'Ranking de modelos');
    }
});
