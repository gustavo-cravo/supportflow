<?php

use App\Models\SupportTicket;
use App\Models\System;
use App\Services\Reports\AnalyticalTicketReport;
use App\Support\AnalyticalTicketReportConfiguration;
use App\Support\TicketMetricsFilters;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\VehicleMetricsFixture;

$root = dirname(__DIR__, 2);
$expectedDirectory = realpath($root.'/storage/framework/testing/subcategory-mysql-qa');
if ($expectedDirectory === false) {
    throw new RuntimeException('O diretório isolado do MySQL de QA é obrigatório.');
}
$pdo = new PDO('mysql:host=127.0.0.1;port=33317', 'root', '', [PDO::ATTR_TIMEOUT => 3]);
[$port, $directory, $version] = $pdo->query('SELECT @@port, @@datadir, VERSION()')->fetch(PDO::FETCH_NUM);
if ((int) $port !== 33317 || realpath($directory) !== $expectedDirectory) {
    throw new RuntimeException('A instância informada não corresponde ao MySQL descartável de QA.');
}
$schema = 'supportflow_analytical_qa_'.bin2hex(random_bytes(4));
$pdo->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

require dirname(__DIR__).'/bootstrap.php';
foreach ([
    'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '33317', 'DB_DATABASE' => $schema,
    'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'DB_SOCKET' => '', 'DB_URL' => '',
] as $key => $value) {
    putenv($key.'='.$value);
    $_SERVER[$key] = $_ENV[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->useEnvironmentPath(__DIR__)->loadEnvironmentFrom('.env.analytical-mysql-absent');
$app->make(ConsoleKernel::class)->bootstrap();
$actual = DB::selectOne('SELECT @@port AS port, @@datadir AS directory, DATABASE() AS db_name');
if (! $app->environment('testing') || config('database.default') !== 'mysql'
    || (int) $actual->port !== 33317 || realpath($actual->directory) !== $expectedDirectory || $actual->db_name !== $schema) {
    throw new RuntimeException('Conexão Laravel fora da instância ou do esquema isolado.');
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}

$fixture = new VehicleMetricsFixture;
$inactive = System::where('name', 'Sistema B')->firstOrFail();
$inactive->update(['is_active' => false]);
$titles = ['Zeta', 'Alfa', 'Beta', 'Gama', 'Delta', 'Eta', 'Teta'];
foreach (SupportTicket::orderBy('id')->get() as $index => $ticket) {
    $ticket->update(['title' => $titles[$index]]);
}
$before = DB::table('support_tickets')->orderBy('id')->get()->toJson();
$day = ['period' => 'custom', 'date_from' => '2026-11-20', 'date_to' => '2026-11-20'];
$cases = [
    'all' => [[], 7], 'date' => [$day, 6], 'inactive' => [['system_id' => $inactive->id], 1],
    'category' => [['problem_category_id' => $fixture->category->id], 4],
    'subcategory' => [['problem_subcategory_id' => $fixture->first->id], 2],
    'status' => [['status' => 'closed'], 2], 'presence' => [['vehicle_presence' => 'without'], 1],
    'plate' => [['vehicle_plate' => 'hfs-44'], 2], 'brand' => [['vehicle_brand_id' => $fixture->alfa->id], 5],
    'model' => [['vehicle_model' => '2008'], 1], 'line' => [['vehicle_line' => 'heavy'], 2],
    'unclassified' => [['classification' => 'unclassified'], 3], 'without_subcategory' => [['classification' => 'without_subcategory'], 2],
    'combined' => [[...$day, 'problem_subcategory_id' => $fixture->second->id, 'vehicle_model' => '2007', 'status' => 'open', 'vehicle_line' => 'light'], 1],
    'empty' => [['vehicle_brand_id' => $fixture->beta->id, 'vehicle_line' => 'light'], 0],
];
$endpoint = '/api/metrics/analytical-report/';
$request = static function (string $uri, string $method = 'GET', array $payload = []) use ($app) {
    return $app->make(HttpKernel::class)->handle(Request::create($uri, $method, [], [], [], [
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
    ], $method === 'POST' ? json_encode($payload, JSON_THROW_ON_ERROR) : null));
};
$check = static function (bool $passed, string $label): void {
    if (! $passed) {
        throw new RuntimeException('Validação MySQL falhou no cenário '.$label);
    }
};
$options = [
    'sections' => ['ticket' => ['enabled' => true, 'fields' => ['title', 'problem_subcategories']],
        'vehicle' => ['enabled' => true, 'fields' => ['vehicle_brand', 'vehicle_model']]],
    'grouping' => 'model', 'order' => 'title',
];
foreach ($cases as $label => [$criteria, $expected]) {
    $filters = ['period' => 'all', ...$criteria];
    $query = '?'.http_build_query($filters);
    $rows = $request($endpoint.'tickets'.$query);
    $dashboard = $request('/api/metrics/tickets'.$query);
    $local = json_decode((string) $rows->getContent(), true, flags: JSON_THROW_ON_ERROR);
    $overall = json_decode((string) $dashboard->getContent(), true, flags: JSON_THROW_ON_ERROR);
    $check($rows->getStatusCode() === 200 && $dashboard->getStatusCode() === 200
        && $local['meta']['total'] === $expected && array_column($local['data'], 'id') === array_column($overall['data'], 'id'), $label.' list');
    $pdf = $request($endpoint.'pdf', 'POST', [...$filters, 'configuration' => $options]);
    $check($expected === 0 ? $pdf->getStatusCode() === 422 : $pdf->getStatusCode() === 200
        && $pdf->headers->get('X-Report-Selected-Count') === (string) $expected
        && str_starts_with((string) $pdf->getContent(), '%PDF-'), $label.' PDF');
}
$service = $app->make(AnalyticalTicketReport::class);
$prepared = $service->prepare(TicketMetricsFilters::fromValidated(['period' => 'all']), AnalyticalTicketReportConfiguration::fromValidated($options))['document'];
$check(array_column($prepared['groups'], 'label') === [
    'Marca Alfa / Série 1.6 LX 2007', 'Marca Alfa / Modelo não informado', 'Marca Alfa / Série 1.6 LX 2008',
    'Marca Beta / Série 1.6 LX 2007', 'Sem identificação veicular',
], 'normalized model keys and missing cases');
$groupTitles = array_map(static fn (array $ticket): string => $ticket['sections']['ticket']['fields']['title']['value'], $prepared['groups'][0]['tickets']);
$check($groupTitles === ['Alfa', 'Beta', 'Zeta'], 'title order within group');
$links = $prepared['groups'][0]['tickets'][2]['sections']['ticket']['fields']['problem_subcategories']['value'];
sort($links);
$check($links === ['Ano divergente', 'Modelo divergente'], 'multiple subcategories');
foreach ([
    'oldest' => ['Zeta', 'Beta', 'Gama', 'Delta', 'Teta', 'Alfa', 'Eta'],
    'newest' => ['Eta', 'Alfa', 'Teta', 'Delta', 'Gama', 'Beta', 'Zeta'],
] as $order => $ordered) {
    $document = $service->prepare(TicketMetricsFilters::fromValidated(['period' => 'all']),
        AnalyticalTicketReportConfiguration::fromValidated([...$options, 'grouping' => 'none', 'order' => $order]))['document'];
    $actualOrder = array_map(static fn (array $ticket): string => $ticket['sections']['ticket']['fields']['title']['value'], $document['groups'][0]['tickets']);
    $check($actualOrder === $ordered, $order.' tie break');
}
foreach (['only' => 1, 'all_except' => 6] as $mode => $count) {
    $response = $request($endpoint.'pdf', 'POST', ['period' => 'all', 'configuration' => [
        ...$options, 'selection' => ['mode' => $mode, 'ids' => [$fixture->firstTicket->id, $fixture->firstTicket->id]],
    ]]);
    $check($response->getStatusCode() === 200 && $response->headers->get('X-Report-Selected-Count') === (string) $count, $mode);
}
$outOfScope = $request($endpoint.'pdf', 'POST', [...$day, 'configuration' => [
    ...$options, 'selection' => ['mode' => 'only', 'ids' => [SupportTicket::where('title', 'Eta')->sole()->id]],
]]);
$check($outOfScope->getStatusCode() === 422, 'out of scope');
$check(DB::table('support_tickets')->orderBy('id')->get()->toJson() === $before, 'original records');
echo json_encode(['mysql' => $version, 'schema' => $schema, 'filter_cases' => count($cases),
    'model_groups' => count($prepared['groups']), 'hybrid_modes' => 3, 'date' => '2026-11-20',
    'pdfs_generated' => count($cases) - 1 + 2, 'result' => 'passed'], JSON_THROW_ON_ERROR).PHP_EOL;
