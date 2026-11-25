<?php

use App\Http\Requests\Api\TicketMetricsPdfConfigurationRequest;
use App\Services\Reports\ConfiguredTicketMetricsPdf;
use App\Support\TicketMetricsFilters;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
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
$fixture->category->update(['name' => 'Erro de indicação']);
$general = [
    'summary' => Fixture::section(['total', 'closed_percentage', 'unclassified', 'text_summary']),
    'systems' => Fixture::section(['tickets_by_system', 'systems_count']),
    'categories' => Fixture::section(['tickets_by_category', 'subcategory_occurrences', 'subcategory_percentage']),
    'vehicles' => Fixture::section(['distinct_vehicles', 'with_vehicle', 'without_vehicle', 'lines']),
    'vehicle_problems' => Fixture::groups(['categories', 'counts'], 'brand'),
];
$validation = array_diff_key($general, ['summary' => true]);
$validation['vehicle_problems'] = Fixture::groups(['systems', 'categories', 'counts'], 'model', 'automatic', [
    ['by' => 'line', 'format' => 'table'],
]);
$support = array_intersect_key($general, array_flip(['summary', 'systems', 'categories']));
$support['systems'] = Fixture::section(['tickets_by_system', 'system_percentage']);
$manual = [
    'summary' => Fixture::section(['total' => 'table', 'closed_percentage' => 'indicator', 'text_summary' => 'text']),
    'systems' => Fixture::section(['tickets_by_system' => 'vertical_bar']),
    'categories' => Fixture::section(['subcategory_occurrences' => 'horizontal_bar', 'subcategory_percentage' => 'table', 'most_frequent_problems' => 'table']),
    'vehicles' => Fixture::section(['lines' => 'doughnut', 'brands' => 'vertical_bar', 'models' => 'table', 'recurrence' => 'table']),
    'vehicle_problems' => Fixture::groups(['systems', 'categories', 'counts'], 'brand', 'descriptive_group'),
];
$empty = [
    'summary' => $general['summary'],
    'systems' => Fixture::section(['system_percentage']),
    'vehicles' => Fixture::section(['distinct_vehicles', 'recurrence']),
];
$volume = [
    'summary' => Fixture::section(['total', 'unclassified']),
    'categories' => Fixture::section(['tickets_by_category', 'subcategory_occurrences' => 'table', 'subcategory_percentage' => 'table']),
    'vehicles' => Fixture::section(['brands' => 'table', 'models' => 'table']),
    'vehicle_problems' => Fixture::groups(['categories', 'counts'], 'brand', 'table'),
];
$demos = [
    ['01-geral-simplificado.pdf', $general, ['period' => 'all'], null, null],
    ['02-setor-validacao.pdf', $validation, ['period' => 'all', 'problem_category_id' => $fixture->category->id], null, 'Análise destinada ao setor de validação'],
    ['03-gestao-suporte.pdf', $support, ['period' => 'all'], 'Relatório gerencial de suporte', null],
    ['04-formatos-manuais.pdf', $manual, ['period' => 'all'], 'Relatório com formatos manuais', 'Exemplos de tabelas, indicadores e gráficos'],
    ['05-sem-registros.pdf', $empty, ['period' => 'custom', 'date_from' => '2001-01-01', 'date_to' => '2001-01-31'], null, null],
    ['06-grande-volume.pdf', $volume, ['period' => 'all'], 'Relatório de grande volume', 'Demonstração de paginação e descrições extensas'],
];

$directory = storage_path('app/private/pdf/relatorios-gerenciais');
File::ensureDirectoryExists($directory);
foreach ($demos as [$file, $sections, $filters, $title, $subtitle]) {
    if ($file === '06-grande-volume.pdf') {
        Fixture::addVolume($fixture);
    }
    $request = TicketMetricsPdfConfigurationRequest::create('/', 'POST', [
        ...$filters,
        'configuration' => [
            'sections' => $sections,
            'title' => $title === null ? ['mode' => 'automatic'] : ['mode' => 'custom', 'text' => $title],
            'subtitle' => $subtitle,
        ],
    ]);
    $request->setContainer($app)->setRedirector($app['redirect']);
    $request->validateResolved();
    $bytes = $app->make(ConfiguredTicketMetricsPdf::class)
        ->generate(TicketMetricsFilters::fromValidated($request->validated()), $request->configuration());
    if (File::put($directory.DIRECTORY_SEPARATOR.$file, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('Não foi possível gravar '.$file);
    }
    fwrite(STDOUT, $directory.DIRECTORY_SEPARATOR.$file.PHP_EOL);
}
