<?php

use App\Services\Reports\PdfAnalyticalTicketReport;
use App\Support\AnalyticalTicketReportConfiguration;
use App\Support\TicketMetricsFilters;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\AnalyticalTicketPdfFixture as Fixture;

require dirname(__DIR__).'/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath(__DIR__)->loadEnvironmentFrom('.env.analytical-performance-absent');
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
    throw new RuntimeException('A medição requer SQLite de teste em memória.');
}
$volume = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
if (! in_array($volume, [1, 10, 50, 100, 250], true)) {
    throw new InvalidArgumentException('Informe 1, 10, 50, 100 ou 250 como volume sintético de teste.');
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}

$fixture = new Fixture;
$date = ['period' => 'custom', 'date_from' => '2026-11-25', 'date_to' => '2026-11-25'];
for ($number = 1; $number <= $volume; $number++) {
    $attributes = ['created_at' => '2026-11-25 12:00:00', 'vehicle_id' => $number % 2 ? $fixture->ford->id : $fixture->volvo->id];
    if ($number % 5 === 0) {
        $attributes['description'] = Fixture::narrative('Ocorrência', 4);
        $attributes['resolution'] = Fixture::narrative('Solução', 2);
    }
    $fixture->ticket(sprintf('Atendimento sintético %03d', $number), $attributes);
}
$options = match (true) {
    $volume === 1 => [],
    $volume === 10 => ['layout' => ['pagination' => 'individual', 'columns' => 'one', 'spacing' => 'expanded']],
    default => [
        'sections' => Fixture::fields([
            'ticket' => ['title', 'system', 'description', 'problem_subcategories'],
            'vehicle' => ['vehicle_brand', 'vehicle_model'],
            'resolution' => ['resolution', 'verification_notes'],
        ]),
        'grouping' => 'model', 'order' => 'title',
        'layout' => ['pagination' => 'compact', 'spacing' => 'compact'],
    ],
};
$configuration = AnalyticalTicketReportConfiguration::fromValidated($options);
$generator = $app->make(PdfAnalyticalTicketReport::class);
$filters = TicketMetricsFilters::fromValidated($date);
$issuedAt = CarbonImmutable::parse('2026-11-25 14:20:00', TicketMetricsFilters::TIMEZONE);
DB::enableQueryLog();
memory_reset_peak_usage();
$memoryBefore = memory_get_usage();
$started = hrtime(true);
$result = $generator->generateDocument($filters, $configuration, $issuedAt);
$elapsed = (int) round((hrtime(true) - $started) / 1e6);
$peak = memory_get_peak_usage();
$queries = DB::getQueryLog();
DB::disableQueryLog();
$selects = count(array_filter($queries, static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')));
$bytes = $result['bytes'];
$pages = preg_match_all('~/Type\s*/Page\b~', $bytes);
if ($result['selected_count'] !== $volume || ! $pages || ! str_starts_with($bytes, '%PDF-') || ! str_ends_with(rtrim($bytes), '%%EOF')) {
    throw new RuntimeException('Contagem ou documento divergente na medição.');
}
echo json_encode([
    'tickets' => $volume, 'pages' => $pages, 'bytes' => strlen($bytes), 'ms' => $elapsed, 'selects' => $selects,
    'php_peak_mib' => round($peak / 1048576, 1), 'php_extra_mib' => round(($peak - $memoryBefore) / 1048576, 1),
    'result' => 'passed',
], JSON_THROW_ON_ERROR).PHP_EOL;
