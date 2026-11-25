<?php

use App\Http\Requests\Api\AnalyticalTicketReportRequest;
use App\Services\Reports\PdfAnalyticalTicketReport;
use App\Support\TicketMetricsFilters;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\AnalyticalTicketPdfFixture as Fixture;

require dirname(__DIR__).'/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
    throw new LogicException('Use somente o SQLite em memória da infraestrutura de testes.');
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}

$fixture = new Fixture;
$start = $fixture->ticket('Revisão de filtro de óleo');
$second = $fixture->ticket('Compatibilidade de referência', [
    'system_id' => $fixture->otherSystem->id, 'vehicle_id' => $fixture->volvo->id, 'verification_source' => 'customer',
]);
$third = $fixture->ticket('Conferência de adaptação do catálogo');
$absence = ['resolution' => null, 'verification_notes' => null, 'verification_source' => null, 'verified_at' => null, 'verified_by' => null];
$withoutVehicle = $fixture->ticket('Consulta sem vínculo veicular', [
    ...$absence, 'customer_id' => null, 'previous_ticket_id' => null, 'vehicle_id' => null, 'problem_category_id' => null,
]);
$withoutModel = $fixture->ticket('Marca informada com modelo ausente', [
    ...$absence, 'vehicle_id' => $fixture->missingModel->id, 'customer_id' => null, 'previous_ticket_id' => null,
]);
$long = $fixture->ticket('Diagnóstico detalhado com solução e verificação', [
    'description' => Fixture::narrative('Descrição', 25)."\n\nTexto literal <b>pressão & referência</b>.\nFinal da descrição preservado.",
    'resolution' => Fixture::narrative('Solução', 20)."\n\nFinal da solução preservado.",
    'verification_notes' => Fixture::narrative('Verificação', 16)."\n\nFinal da verificação preservado.",
]);
$longVehicle = $fixture->ford->replicate()->fill([
    'plate' => 'ZQA4567',
    'model_description' => Fixture::narrative('Modelo', 19)."\n\nFinal do modelo preservado.",
]);
$longVehicle->save();
$long->update(['vehicle_id' => $longVehicle->id]);

$compactIds = [];
for ($i = 1; $i <= 10; $i++) {
    $compactIds[] = $fixture->ticket('Conferência compacta '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), [
        'description' => $i % 3 === 0 ? Fixture::narrative('Consulta', 2) : 'Aplicação conferida durante atendimento.',
        'vehicle_id' => $i % 2 ? $fixture->ford->id : $fixture->volvo->id,
    ])->id;
}
$volumeIds = [];
for ($i = 1; $i <= 36; $i++) {
    $volumeIds[] = $fixture->ticket('Registro de volume '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), [
        'system_id' => $i % 2 ? $fixture->system->id : $fixture->otherSystem->id,
        'problem_category_id' => $i % 3 ? $fixture->category->id : $fixture->otherCategory->id,
        'vehicle_id' => [$fixture->ford->id, $fixture->volvo->id, $fixture->missingModel->id][$i % 3],
        'created_at' => '2026-11-25 10:00:00',
    ])->id;
}
$only = static fn (array $ids): array => ['mode' => 'only', 'ids' => $ids];
$period = ['period' => 'custom', 'date_from' => '2026-11-01', 'date_to' => '2026-11-25'];
$complete = $only([$start->id, $second->id, $third->id]);
$operational = Fixture::fields([
    'ticket' => ['title', 'system', 'problem_category', 'problem_subcategories', 'description'],
    'vehicle' => ['vehicle_plate', 'vehicle_brand', 'vehicle_model', 'vehicle_line'],
    'resolution' => ['resolution', 'verification_source', 'verified_at', 'verified_by', 'verification_notes'],
]);
$cases = [
    ['A-completo.pdf', $period, ['selection' => $complete]],
    ['B-setor-validacao.pdf', [...$period, 'problem_category_id' => $fixture->category->id],
        ['selection' => $complete, 'sections' => $operational, 'grouping' => 'model', 'subtitle' => 'Conferência para o setor de validação']],
    ['C-vazios-inteligentes.pdf', $period, ['selection' => $only([$withoutVehicle->id, $withoutModel->id, $start->id])]],
    ['D-paginacao-compacta.pdf', $period, ['selection' => $only($compactIds), 'sections' => $operational,
        'layout' => ['pagination' => 'compact', 'columns' => 'two', 'spacing' => 'compact']]],
    ['E-paginacao-individual.pdf', $period, ['selection' => $only([$start->id, $long->id, $second->id]), 'sections' => $operational,
        'grouping' => 'system', 'order' => 'oldest', 'layout' => ['pagination' => 'individual', 'columns' => 'one', 'spacing' => 'expanded']]],
    ['F-descricoes-extensas.pdf', $period, ['selection' => $only([$long->id]), 'sections' => $operational]],
    ['G-grande-volume.pdf', [...$period, 'date_from' => '2026-11-25'], [
        'selection' => ['mode' => 'all_except', 'ids' => [$volumeIds[3], $volumeIds[19]]],
        'sections' => Fixture::fields(['ticket' => ['title', 'system', 'problem_category', 'description'],
            'vehicle' => ['vehicle_brand', 'vehicle_model'], 'resolution' => ['resolution']]),
        'grouping' => 'model', 'order' => 'title', 'layout' => ['pagination' => 'compact'],
    ]],
    ['H-personalizacao-minima.pdf', [...$period, 'system_id' => $fixture->system->id, 'vehicle_plate' => $fixture->ford->plate], [
        'selection' => $only([$start->id, $third->id]),
        'sections' => Fixture::fields(['ticket' => ['description'], 'resolution' => ['resolution', 'verification_notes']]),
    ]],
];

$directory = storage_path('app/private/pdf/analitico');
File::ensureDirectoryExists($directory);
$generator = $app->make(PdfAnalyticalTicketReport::class);
foreach ($cases as [$file, $filters, $configuration]) {
    $request = AnalyticalTicketReportRequest::create('/', 'POST', [...$filters, 'configuration' => $configuration]);
    $request->setContainer($app)->setRedirector($app['redirect']);
    $request->validateResolved();
    DB::flushQueryLog();
    DB::enableQueryLog();
    memory_reset_peak_usage();
    $memory = memory_get_usage();
    $started = hrtime(true);
    $bytes = $generator->generate(TicketMetricsFilters::fromValidated($request->validated()), $request->configuration(),
        CarbonImmutable::parse('2026-11-25 14:20:00', 'America/Sao_Paulo'));
    $elapsed = (int) round((hrtime(true) - $started) / 1e6);
    $queries = count(array_filter(DB::getQueryLog(), static fn (array $entry): bool => str_starts_with(strtolower($entry['query']), 'select')));
    $peak = round((memory_get_peak_usage() - $memory) / 1048576, 1);
    DB::disableQueryLog();
    if (File::put($directory.DIRECTORY_SEPARATOR.$file, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('Falha ao gravar '.$file);
    }
    echo json_encode(['file' => $file, 'ms' => $elapsed, 'peak_mib' => $peak, 'selects' => $queries, 'bytes' => strlen($bytes)], JSON_THROW_ON_ERROR).PHP_EOL;
    unset($bytes);
}
