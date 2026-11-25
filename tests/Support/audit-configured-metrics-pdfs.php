<?php

use App\Http\Requests\Api\ExportConfiguredTicketMetricsPdfRequest;
use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Services\Reports\ConfiguredTicketMetricsPdf;
use App\Support\TicketMetricsFilters;
use App\Support\TicketMetricsPdfCatalog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\ConfiguredMetricsPdfFixture as Fixture;
use Tests\Support\VehicleMetricsFixture;

require dirname(__DIR__).'/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
    throw new LogicException('Use somente o banco SQLite em memória da infraestrutura de testes.');
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}

$fixture = new VehicleMetricsFixture;
$all = [];
foreach (TicketMetricsPdfCatalog::sections() as $key => $section) {
    $all[$key] = Fixture::section(array_keys($section['indicators']));
}
$all['vehicle_problems'] = Fixture::groups(['systems', 'categories', 'counts'], 'model', additional: [
    ['by' => 'brand', 'format' => 'table'],
    ['by' => 'line', 'format' => 'descriptive_group'],
]);
$manual = $all;
$manual['summary'] = Fixture::section(['total' => 'table', 'closed_percentage' => 'indicator', 'text_summary' => 'text']);
$manual['systems'] = Fixture::section(['tickets_by_system' => 'vertical_bar', 'system_percentage' => 'horizontal_bar']);
$manual['vehicles'] = Fixture::section(['lines' => 'doughnut', 'brands' => 'table', 'models' => 'table', 'recurrence' => 'indicator']);
$volume = [
    'summary' => Fixture::section(['total', 'unclassified']),
    'categories' => Fixture::section(['tickets_by_category', 'subcategory_occurrences' => 'table', 'subcategory_percentage' => 'table']),
    'vehicles' => Fixture::section(['brands' => 'table', 'models' => 'table']),
    'vehicle_problems' => Fixture::groups(['categories', 'counts'], 'brand', 'table'),
];
$readableLongTitle = 'Análise consolidada da compatibilidade e da identificação de veículos nos sistemas de atendimento';
$readableLongSubtitle = 'Revisão das vinculações de problemas, classificação e variantes de modelos no período selecionado para conferência dos resultados agregados pelos setores responsáveis.';
$cases = [
    ['A-sem-atendimentos.pdf', $all, ['period' => 'custom', 'date_from' => '2001-01-01', 'date_to' => '2001-01-31'], null, null],
    ['B-um-atendimento.pdf', [
        'summary' => $all['summary'],
        'categories' => $all['categories'],
        'vehicles' => Fixture::section(['distinct_vehicles', 'lines' => 'doughnut', 'recurrence']),
    ], ['period' => 'all', 'vehicle_plate' => 'HFS-4456', 'status' => 'open'], null, null],
    ['C-poucos-atendimentos.pdf', $manual, ['period' => 'all'], 'Relatório de conferência', null],
    ['D-grande-volume.pdf', $volume, ['period' => 'all'], null, null],
    ['E-descricoes-extensas.pdf', [
        'vehicles' => Fixture::section(['models' => 'horizontal_bar']),
        'vehicle_problems' => Fixture::groups(['categories', 'counts'], 'model', 'descriptive_group'),
    ], ['period' => 'all', 'vehicle_plate' => 'ZQX0047'], $readableLongTitle, $readableLongSubtitle],
    ['F-todas-informacoes.pdf', $all, ['period' => 'all'], null, null],
];

$directory = storage_path('app/private/pdf/auditoria-final');
File::ensureDirectoryExists($directory);
$service = $app->make(ConfiguredTicketMetricsPdf::class);
foreach ($cases as [$file, $sections, $filters, $title, $subtitle]) {
    if ($file === 'D-grande-volume.pdf') {
        Fixture::addVolume($fixture);
    }
    if ($file === 'E-descricoes-extensas.pdf') {
        $category = ProblemCategory::where('name', 'Grupo de diagnóstico 6')->firstOrFail();
        $category->update(['name' => 'Grupo de diagnóstico 6 - Compatibilidade, identificação, classificação e validação de componentes, variantes, versões, aplicações, motorizações, acabamentos e características técnicas do veículo no catálogo de referência']);
        $problem = ProblemSubcategory::where('name', 'like', 'Problema 47 -%')->firstOrFail();
        $problem->update(['name' => 'Problema 47 - Validação cruzada de compatibilidade, configuração, classificação e identificação de componentes, versões, adaptações e variantes do modelo e da linha veicular após revisão das especificações técnicas de referência']);
    }
    $request = ExportConfiguredTicketMetricsPdfRequest::create('/', 'POST', [
        ...$filters,
        'configuration' => [
            'sections' => $sections,
            'title' => $title === null ? ['mode' => 'automatic'] : ['mode' => 'custom', 'text' => $title],
            'subtitle' => $subtitle,
        ],
    ]);
    $request->setContainer($app)->setRedirector($app['redirect']);
    $request->validateResolved();
    $filters = TicketMetricsFilters::fromValidated($request->validated());
    $configuration = $request->configuration();
    $samples = [];
    for ($run = 0; $run < 3; $run++) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        memory_reset_peak_usage();
        $memory = memory_get_usage();
        $start = hrtime(true);
        $pdf = $service->generate($filters, $configuration);
        $samples[] = [
            'ms' => (int) round((hrtime(true) - $start) / 1e6),
            'peak_mib' => round((memory_get_peak_usage() - $memory) / 1048576, 1),
            'selects' => count(array_filter(DB::getQueryLog(), static fn (array $entry): bool => str_starts_with(strtolower($entry['query']), 'select'))),
        ];
        DB::disableQueryLog();
    }
    if (File::put($directory.DIRECTORY_SEPARATOR.$file, $pdf) !== strlen($pdf)) {
        throw new RuntimeException('Não foi possível gravar '.$file);
    }
    fwrite(STDOUT, json_encode(['file' => $file, 'bytes' => strlen($pdf), 'samples' => $samples], JSON_THROW_ON_ERROR).PHP_EOL);
    unset($pdf);
}
