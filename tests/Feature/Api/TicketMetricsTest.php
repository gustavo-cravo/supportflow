<?php

use App\Models\Contact;
use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Models\SupportTicket;
use App\Models\System;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function metricTicket(int $system, ?int $category, ?int $subcategory, string $status, string $date): SupportTicket
{
    $ticket = new SupportTicket;
    $ticket->forceFill([
        'system_id' => $system, 'contact_id' => test()->contact->id, 'title' => 'Ocorrência para métricas',
        'description' => 'Descrição preservada', 'problem_category_id' => $category, 'problem_subcategory_id' => $subcategory,
        'status' => $status, 'created_at' => CarbonImmutable::parse($date, 'America/Sao_Paulo')->setTimezone('America/Sao_Paulo'),
        'updated_at' => '2026-11-23 12:00:00',
    ])->save();
    if ($subcategory !== null) {
        $ticket->problemSubcategories()->attach($subcategory, ['problem_category_id' => $category]);
    }

    return $ticket;
}

function metricUrl(string $endpoint, array $filters = []): string
{
    return '/api/metrics/'.$endpoint.($filters ? '?'.http_build_query($filters) : '');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', 'America/Sao_Paulo'));
    $this->a = System::create(['name' => 'Indicar']);
    $this->b = System::create(['name' => 'iLube']);
    $this->c = System::create(['name' => '.way']);
    $this->contact = Contact::create(['name' => 'Ana']);
    $this->access = ProblemCategory::create(['name' => 'Falha de acesso']);
    $this->search = ProblemCategory::create(['name' => 'Busca por placa']);
    $this->access->systems()->attach([$this->a->id, $this->b->id, $this->c->id], ['is_active' => true]);
    $this->search->systems()->attach([$this->a->id, $this->c->id], ['is_active' => true]);
    $this->login = ProblemSubcategory::create(['problem_category_id' => $this->access->id, 'name' => 'Falha comum']);
    $this->credentials = ProblemSubcategory::create(['problem_category_id' => $this->access->id, 'name' => 'Credenciais inválidas']);
    $this->plate = ProblemSubcategory::create(['problem_category_id' => $this->search->id, 'name' => 'Falha comum']);
    $this->login->systems()->attach([$this->a->id, $this->b->id, $this->c->id], ['is_active' => true, 'problem_category_id' => $this->access->id]);
    $this->credentials->systems()->attach([$this->a->id, $this->b->id], ['is_active' => true, 'problem_category_id' => $this->access->id]);
    $this->plate->systems()->attach([$this->a->id, $this->c->id], ['is_active' => true, 'problem_category_id' => $this->search->id]);
    $a = $this->a->id;
    $b = $this->b->id;
    $c = $this->c->id;
    $access = $this->access->id;
    $search = $this->search->id;
    $login = $this->login->id;
    $credentials = $this->credentials->id;
    $plate = $this->plate->id;
    $this->tickets = [
        metricTicket($a, $access, $login, 'open', '2026-11-01 00:00:00'),
        metricTicket($a, $access, $login, 'closed', '2026-11-23 23:59:59'),
        metricTicket($b, $access, $login, 'closed', '2026-10-10 10:00:00'),
        metricTicket($a, $access, $credentials, 'in_progress', '2026-09-20 10:00:00'),
        metricTicket($b, $access, null, 'waiting', '2026-08-15 10:00:00'),
        metricTicket($a, $search, $plate, 'resolved', '2026-11-15 10:00:00'),
        metricTicket($c, $search, null, 'closed', '2026-06-01 00:00:00'),
        metricTicket($c, null, null, 'open', '2026-11-20 10:00:00'),
        metricTicket($a, null, null, 'closed', '2026-05-31 23:59:59'),
        metricTicket($b, $access, $credentials, 'resolved', '2026-01-15 10:00:00'),
        metricTicket($b, null, null, 'waiting', '2025-12-31 23:59:59'),
        metricTicket($a, $search, $plate, 'closed', '2026-11-24 00:00:00'),
        metricTicket($c, $access, $login, 'open', '2026-06-01 00:00:00'),
    ];
    $this->tickets[1]->update(['previous_ticket_id' => $this->tickets[0]->id]);
    $this->b->update(['is_active' => false]);
    $this->access->update(['is_active' => false]);
    $this->login->update(['is_active' => false]);
    $this->access->systems()->updateExistingPivot($a, ['is_active' => false]);
    $this->access->systems()->updateExistingPivot($b, ['is_active' => false]);
    $this->login->systems()->updateExistingPivot($b, ['is_active' => false]);
});

afterEach(function () {
    $this->travelBack();
    Model::preventLazyLoading(false);
    DB::disableQueryLog();
    DB::flushQueryLog();
});

test('metrics summary counts every ticket once including resolved and inactive historical records', function () {
    $this->getJson(metricUrl('summary', ['period' => 'all']))->assertOk()->assertJsonPath('data', [
        'total' => 13, 'closed' => 5, 'not_closed' => 8, 'systems_count' => 3, 'unclassified' => 3, 'subcategory_occurrences' => 8,
    ])->assertJsonPath('meta.filters.timezone', 'America/Sao_Paulo');
});

test('system metrics partition tickets and provide a consolidated total without pivot multiplication', function () {
    $response = $this->getJson(metricUrl('systems', ['period' => 'all']))->assertOk()->assertJsonPath('meta.total', 13)->json('data');
    expect(array_column($response, 'system_id'))->toBe([$this->a->id, $this->b->id, $this->c->id]);
    expect(array_column($response, 'total'))->toBe([6, 4, 3]);
    expect(array_column($response, 'closed'))->toBe([3, 1, 1]);
    expect(array_column($response, 'not_closed'))->toBe([3, 3, 2]);
    expect($response[1]['is_active'])->toBeFalse();
});

test('category metrics include shared inactive categories, null classification and their system distribution', function () {
    $rows = $this->getJson(metricUrl('categories', ['period' => 'all']))->assertOk()->assertJsonPath('meta.total', 13)->json('data');
    $access = collect($rows)->firstWhere('problem_category_id', $this->access->id);
    expect($access['total'])->toBe(7);
    expect($access['is_active'])->toBeFalse();
    expect($access['percentage'])->toBe(53.85);
    expect(array_column($access['systems'], 'total', 'system_id'))->toBe([$this->a->id => 3, $this->b->id => 3, $this->c->id => 1]);
    $none = collect($rows)->firstWhere('problem_category_id', null);
    expect($none['category_name'])->toBe('Sem classificação');
    expect($none['total'])->toBe(3);
    expect(array_sum(array_column($rows, 'total')))->toBe(13);
});

test('subcategory metrics preserve parent scope, same-name records and unclassified buckets', function () {
    $rows = $this->getJson(metricUrl('subcategories', ['period' => 'all']))->assertOk()->assertJsonPath('meta.total', 13)->json('data');
    $login = collect($rows)->firstWhere('problem_subcategory_id', $this->login->id);
    expect($login['total'])->toBe(4);
    expect($login['category_total'])->toBe(7);
    expect($login['percentage'])->toBe(57.14);
    expect($login['is_active'])->toBeFalse();
    expect(array_sum(array_column($login['systems'], 'total')))->toBe(4);
    $plate = collect($rows)->firstWhere('problem_subcategory_id', $this->plate->id);
    expect($plate['total'])->toBe(2);
    expect($plate['subcategory_name'])->toBe($login['subcategory_name']);
    expect($plate['problem_category_id'])->not->toBe($login['problem_category_id']);
    $without = collect($rows)->first(fn ($row) => $row['problem_category_id'] === $this->access->id && $row['problem_subcategory_id'] === null);
    expect($without['subcategory_name'])->toBe('Sem subcategoria');
    expect($without['total'])->toBe(1);
    expect($without['percentage'])->toBe(14.29);
    expect(collect($rows)->firstWhere('problem_category_id', null)['subcategory_name'])->toBe('Sem classificação');
});

test('multiple subcategories count as separate occurrences without duplicating tickets', function () {
    $third = ProblemSubcategory::create(['problem_category_id' => $this->access->id, 'name' => 'Terceiro problema']);
    $ticket = $this->tickets[0];
    $ticket->problemSubcategories()->attach([$this->credentials->id, $third->id], ['problem_category_id' => $this->access->id]);
    $scope = ['period' => 'custom', 'date_from' => '2026-11-01', 'date_to' => '2026-11-01', 'status' => 'open'];
    $this->getJson(metricUrl('summary', $scope))->assertOk()->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.subcategory_occurrences', 3);
    foreach (['systems', 'categories', 'monthly', 'tickets'] as $endpoint) {
        $this->getJson(metricUrl($endpoint, $scope))->assertOk()->assertJsonPath('meta.total', 1);
    }
    $rows = $this->getJson(metricUrl('subcategories', $scope))->assertOk()->assertJsonPath('meta.occurrences', 3)->json('data');
    expect(array_column($rows, 'problem_subcategory_id'))->toBe([$this->login->id, $this->credentials->id, $third->id]);
    expect(array_column($rows, 'total'))->toBe([1, 1, 1]);
    expect(array_column($rows, 'category_total'))->toBe([1, 1, 1]);
    expect(array_sum(array_column($rows, 'percentage')))->toEqual(300);
    $filtered = [...$scope, 'problem_subcategory_id' => $third->id];
    $this->getJson(metricUrl('subcategories', $filtered))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.occurrences', 1)->assertJsonPath('data.0.problem_subcategory_id', $third->id);
    $this->getJson(metricUrl('tickets', $filtered))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ticket->id)->assertJsonCount(3, 'data.0.problem_subcategories');
    $this->getJson(metricUrl('tickets', [...$scope, 'classification' => 'without_subcategory']))->assertOk()
        ->assertJsonPath('meta.total', 0);
});

test('relative periods use calendar boundaries and end at the end of today in Sao Paulo', function (string $period, string $start, int $total) {
    $this->getJson(metricUrl('summary', ['period' => $period]))->assertOk()
        ->assertJsonPath('data.total', $total)->assertJsonPath('meta.filters.date_from', $start)->assertJsonPath('meta.filters.date_to', '2026-11-23');
})->with([
    ['this_month', '2026-11-01', 4], ['last_30_days', '2026-10-25', 4],
    ['last_3_months', '2026-09-01', 6], ['last_6_months', '2026-06-01', 9],
    ['this_year', '2026-01-01', 11],
]);

test('default period is six calendar months including the current one', function () {
    $this->getJson('/api/metrics/summary')->assertOk()->assertJsonPath('data.total', 9)
        ->assertJsonPath('meta.filters.period', 'last_6_months')->assertJsonPath('meta.filters.date_from', '2026-06-01');
    $this->getJson('/api/metrics/monthly')->assertOk()->assertJsonPath('data', [
        ['month' => '2026-06', 'total' => 2], ['month' => '2026-07', 'total' => 0],
        ['month' => '2026-08', 'total' => 1], ['month' => '2026-09', 'total' => 1],
        ['month' => '2026-10', 'total' => 1], ['month' => '2026-11', 'total' => 4],
    ]);
});

test('bounded monthly series is chronological and includes zero months even with no matches', function () {
    $filters = ['period' => 'custom', 'date_from' => '2026-05-31', 'date_to' => '2026-08-31'];
    $this->getJson(metricUrl('monthly', $filters))->assertOk()->assertJsonPath('data', [
        ['month' => '2026-05', 'total' => 1], ['month' => '2026-06', 'total' => 2],
        ['month' => '2026-07', 'total' => 0], ['month' => '2026-08', 'total' => 1],
    ]);
    $empty = ['period' => 'custom', 'date_from' => '2000-01-01', 'date_to' => '2000-03-31'];
    $this->getJson(metricUrl('summary', $empty))->assertJsonPath('data', ['total' => 0, 'closed' => 0, 'not_closed' => 0, 'systems_count' => 0, 'unclassified' => 0, 'subcategory_occurrences' => 0]);
    $this->getJson(metricUrl('monthly', $empty))->assertJsonPath('data', [
        ['month' => '2000-01', 'total' => 0], ['month' => '2000-02', 'total' => 0], ['month' => '2000-03', 'total' => 0],
    ]);
    foreach (['systems', 'categories', 'subcategories'] as $endpoint) {
        $this->getJson(metricUrl($endpoint, $empty))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }
});

test('unbounded monthly history returns observed months without adding an arbitrary horizon', function () {
    $rows = $this->getJson(metricUrl('monthly', ['period' => 'all']))->assertOk()->assertJsonPath('meta.total', 13)->json('data');
    expect(array_column($rows, 'month'))->toBe(['2025-12', '2026-01', '2026-05', '2026-06', '2026-08', '2026-09', '2026-10', '2026-11']);
    expect($rows[7]['total'])->toBe(5);
});

test('all real individual statuses and the not-closed group use the same filtered base', function (string $status, int $total, int $closed) {
    $this->getJson(metricUrl('summary', ['period' => 'all', 'status' => $status]))->assertOk()
        ->assertJsonPath('data.total', $total)->assertJsonPath('data.closed', $closed)->assertJsonPath('data.not_closed', $total - $closed);
})->with([['open', 3, 0], ['in_progress', 1, 0], ['waiting', 2, 0], ['resolved', 2, 0], ['closed', 5, 5], ['not_closed', 8, 0], ['all', 13, 5]]);

test('all metric groupings and paginated drilldown reconcile under combined filters', function (array $filters) {
    $base = ['period' => 'all', ...$filters];
    $total = $this->getJson(metricUrl('summary', $base))->assertOk()->json('data.total');
    foreach (['systems', 'categories', 'subcategories', 'monthly'] as $endpoint) {
        $response = $this->getJson(metricUrl($endpoint, $base))->assertOk();
        expect(array_sum(array_column($response->json('data'), 'total')))->toBe($total);
        expect($response->json('meta.total'))->toBe($total);
        if ($endpoint === 'subcategories') {
            foreach (collect($response->json('data'))->groupBy(fn ($row) => $row['problem_category_id'] ?? 'none') as $rows) {
                expect($rows->sum('total'))->toBe($rows->first()['category_total']);
            }
        }
    }
    $this->getJson(metricUrl('tickets', [...$base, 'per_page' => 2]))->assertOk()->assertJsonPath('meta.total', $total);
})->with([
    [[]], [['system_id' => 2]], [['status' => 'not_closed']], [['problem_category_id' => 1]],
    [['problem_subcategory_id' => 1]], [['classification' => 'unclassified']],
    [['classification' => 'without_subcategory']], [['problem_category_id' => 1, 'classification' => 'without_subcategory']],
    [['period' => 'custom', 'date_from' => '2026-11-01', 'date_to' => '2026-11-23', 'system_id' => 1, 'problem_category_id' => 1, 'status' => 'closed']],
    [['period' => 'this_year', 'system_id' => 3]],
]);

test('historical filters are valid after current associations have been disabled', function () {
    $this->getJson(metricUrl('summary', ['period' => 'all', 'system_id' => $this->b->id, 'problem_category_id' => $this->access->id, 'problem_subcategory_id' => $this->login->id]))
        ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.closed', 1);
    $this->getJson(metricUrl('subcategories', ['period' => 'all', 'problem_subcategory_id' => $this->login->id]))
        ->assertOk()->assertJsonPath('data.0.total', 4)->assertJsonPath('data.0.percentage', 100)->assertJsonPath('data.0.category_total', 4);
});

test('date filters use creation time and include both complete local boundary dates', function () {
    $this->tickets[1]->update(['closed_at' => '2025-01-01 00:00:00']);
    $filters = ['period' => 'custom', 'date_from' => '2026-11-23', 'date_to' => '2026-11-23'];
    $this->getJson(metricUrl('summary', $filters))->assertOk()->assertJsonPath('data.total', 1);
    $this->getJson(metricUrl('tickets', $filters))->assertJsonPath('data.0.id', $this->tickets[1]->id);
    $this->travelTo(CarbonImmutable::parse('2026-11-24T01:30:00Z'));
    $this->getJson('/api/metrics/summary')->assertJsonPath('meta.filters.date_to', '2026-11-23')->assertJsonPath('data.total', 9);
});

test('custom leap days and UTC instants crossing a local month boundary are counted correctly', function () {
    metricTicket($this->a->id, null, null, 'open', '2024-02-28 23:59:59');
    metricTicket($this->a->id, null, null, 'open', '2024-02-29 00:00:00');
    metricTicket($this->a->id, null, null, 'open', '2024-03-01T02:59:59Z');
    metricTicket($this->a->id, null, null, 'open', '2024-03-01T03:00:00Z');
    $filters = ['period' => 'custom', 'date_from' => '2024-02-29', 'date_to' => '2024-02-29'];
    $this->getJson(metricUrl('monthly', $filters))->assertOk()->assertJsonPath('data', [['month' => '2024-02', 'total' => 2]]);
    $this->travelTo(CarbonImmutable::parse('2026-03-31 22:00:00', 'America/Sao_Paulo'));
    $this->getJson('/api/metrics/summary?period=last_3_months')->assertJsonPath('meta.filters.date_from', '2026-01-01')->assertJsonPath('meta.filters.date_to', '2026-03-31');
});

test('drilldown keeps filters, deterministic order and the existing ticket resource and detail access', function () {
    Model::preventLazyLoading();
    $filters = ['period' => 'all', 'status' => 'closed', 'per_page' => 2];
    $first = $this->getJson(metricUrl('tickets', $filters))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5);
    expect(array_column($first->json('data'), 'id'))->toBe([$this->tickets[11]->id, $this->tickets[1]->id]);
    expect($first->json('links.next'))->toContain('status=closed');
    $this->getJson(metricUrl('tickets', [...$filters, 'page' => 2]))->assertOk()->assertJsonPath('data.0.id', $this->tickets[2]->id)->assertJsonPath('data.1.id', $this->tickets[6]->id)
        ->assertJsonPath('meta.filters.status', 'closed')->assertJsonPath('meta.current_page', 2);
    $this->getJson('/api/support-tickets/'.$this->tickets[1]->id)->assertOk()->assertJsonPath('data.problem_category.name', 'Falha de acesso');
});

test('metric queries aggregate in SQL without reading ticket models or association pivots', function (string $endpoint) {
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson(metricUrl($endpoint, ['period' => 'all']))->assertOk();
    $queries = DB::getQueryLog();
    expect($queries)->toHaveCount($endpoint === 'subcategories' ? 2 : 1);
    foreach ($queries as $query) {
        $sql = strtolower($query['query']);
        expect($sql)->toContain('count(')->not->toContain('problem_category_system')->not->toContain('problem_subcategory_system');
        expect($sql)->not->toContain('select *');
    }
})->with(['summary', 'systems', 'categories', 'subcategories', 'monthly']);

test('drilldown eager loads relations with a bounded query count for a full page', function () {
    Model::preventLazyLoading();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson(metricUrl('tickets', ['period' => 'all', 'per_page' => 100]))->assertOk()->assertJsonCount(13, 'data');
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(11);
});

test('metrics and drilldowns leave every existing record and the operational dashboard unchanged', function () {
    $tables = ['systems', 'contacts', 'customers', 'support_tickets', 'pending_tasks', 'reminders', 'knowledge_articles', 'problem_categories', 'problem_subcategories', 'problem_category_system', 'problem_subcategory_system'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    $dashboard = $this->getJson('/api/dashboard/summary')->assertOk()->json();
    foreach (['summary', 'systems', 'categories', 'subcategories', 'monthly', 'tickets'] as $endpoint) {
        $this->getJson(metricUrl($endpoint, ['period' => 'all']))->assertOk();
        $this->postJson('/api/metrics/'.$endpoint, [])->assertStatus(405);
    }
    expect($snapshot())->toBe($before);
    expect($this->getJson('/api/dashboard/summary')->assertOk()->json())->toBe($dashboard);
});

test('invalid metrics filters return standard validation errors before queries are built', function (array $filters, string $field) {
    $this->getJson(metricUrl('summary', $filters))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['period' => 'invalid'], 'period'], [['period' => 'custom'], 'date_from'],
    [['period' => 'custom', 'date_from' => '2026-11-01'], 'date_to'],
    [['period' => 'custom', 'date_from' => '2026-11-23', 'date_to' => '2026-11-01'], 'date_to'],
    [['period' => 'custom', 'date_from' => '2026-02-30', 'date_to' => '2026-03-01'], 'date_from'],
    [['period' => 'custom', 'date_from' => '23/11/2026', 'date_to' => '2026-11-23'], 'date_from'],
    [['period' => 'all', 'date_from' => '2026-11-01'], 'date_from'],
    [['date_to' => '2026-11-23'], 'date_to'], [['status' => 'finished'], 'status'],
    [['system_id' => 999999], 'system_id'], [['system_id' => [[1]]], 'system_id'],
    [['problem_category_id' => 999999], 'problem_category_id'],
    [['problem_subcategory_id' => 999999], 'problem_subcategory_id'],
    [['problem_category_id' => 2, 'problem_subcategory_id' => 1], 'problem_subcategory_id'],
    [['classification' => 'unclassified', 'problem_category_id' => 1], 'classification'],
    [['classification' => 'unclassified', 'problem_subcategory_id' => 1], 'classification'],
    [['classification' => 'without_subcategory', 'problem_subcategory_id' => 1], 'classification'],
    [['classification' => 'invalid'], 'classification'], [['per_page' => 10], 'per_page'], [['page' => 1], 'page'],
]);

test('drilldown validates pagination limits', function (array $filters, string $field) {
    $this->getJson(metricUrl('tickets', $filters))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([[['per_page' => 101], 'per_page'], [['per_page' => 0], 'per_page'], [['page' => 0], 'page']]);

test('metrics indexes can be reverted and reapplied without changing tickets', function () {
    $before = DB::table('support_tickets')->orderBy('id')->get()->toJson();
    $migration = require database_path('migrations/2026_11_23_000003_add_ticket_metrics_indexes.php');
    $migration->down();
    foreach (['created', 'system_created', 'category_created', 'subcategory_created'] as $name) {
        expect(Schema::hasIndex('support_tickets', 'tickets_metrics_'.$name.'_index'))->toBeFalse();
    }
    $migration->up();
    foreach (['created', 'system_created', 'category_created', 'subcategory_created'] as $name) {
        expect(Schema::hasIndex('support_tickets', 'tickets_metrics_'.$name.'_index'))->toBeTrue();
    }
    expect(DB::table('support_tickets')->orderBy('id')->get()->toJson())->toBe($before);
});
