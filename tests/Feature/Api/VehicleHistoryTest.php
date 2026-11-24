<?php

use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Tests\Support\VehicleHistoryFixture;

beforeEach(fn () => app()->instance(VehicleHistoryFixture::class, new VehicleHistoryFixture));

afterEach(fn () => Model::preventLazyLoading(false));

test('returns the vehicle and global history totals even when the vehicle has no or only one ticket', function () {
    $fixture = app(VehicleHistoryFixture::class);
    $empty = Vehicle::factory()->create();
    $this->getJson('/api/vehicles/'.$empty->id.'/support-tickets')->assertOk()
        ->assertJsonPath('meta.vehicle.id', $empty->id)
        ->assertJsonPath('meta.counts', ['total' => 0, 'not_closed' => 0, 'closed' => 0])
        ->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
    $this->getJson('/api/vehicles/'.$fixture->truck->id.'/support-tickets')->assertOk()
        ->assertJsonPath('meta.vehicle.model_description', null)
        ->assertJsonPath('meta.counts', ['total' => 1, 'not_closed' => 1, 'closed' => 0])
        ->assertJsonPath('data.0.id', $fixture->heavy->id);
    $this->getJson('/api/vehicles/999999/support-tickets')->assertNotFound();
});

test('paginates tickets across systems by opening date with a stable tie break and no lazy loading', function () {
    $fixture = app(VehicleHistoryFixture::class);
    Model::preventLazyLoading();
    $response = $this->getJson($fixture->history.'?per_page=2')->assertOk()
        ->assertJsonPath('meta.counts', ['total' => 3, 'not_closed' => 2, 'closed' => 1])
        ->assertJsonPath('meta.vehicle.brand.name', 'Volkswagen')
        ->assertJsonPath('meta.vehicle.vehicle_line', 'light')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.id', $fixture->recent->id)
        ->assertJsonPath('data.1.id', $fixture->sameDay->id)
        ->assertJsonPath('data.0.system.name', 'Sistema A')
        ->assertJsonPath('data.0.contact.name', 'Ana')
        ->assertJsonPath('data.0.customer.name', 'Oficina')
        ->assertJsonPath('data.0.problem_category.name', 'Identificação')
        ->assertJsonPath('data.0.problem_subcategories.1.name', 'Modelo')
        ->assertJsonPath('data.0.closed_at', '2026-11-21T10:00:00-03:00')
        ->assertJsonPath('data.0.resolution', 'Cadastro conferido.');
    $this->getJson($response->json('links.next'))->assertOk()->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('data.0.id', $fixture->earlier->id)
        ->assertJsonPath('data.0.system.name', 'Sistema B');
});

test('combines history filters with inclusive date boundaries and distinct tickets', function () {
    $fixture = app(VehicleHistoryFixture::class);
    $params = [
        'system_id' => $fixture->system->id, 'problem_category_id' => $fixture->category->id,
        'problem_subcategory_id' => $fixture->subcategory->id, 'status' => 'closed',
        'created_from' => '2026-11-20', 'created_to' => '2026-11-20',
    ];
    $this->getJson($fixture->history.'?'.http_build_query($params))->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.counts', ['total' => 3, 'not_closed' => 2, 'closed' => 1])
        ->assertJsonPath('data.0.id', $fixture->recent->id)->assertJsonCount(2, 'data.0.problem_subcategories');
    $this->getJson($fixture->history.'?status=open')->assertOk()
        ->assertJsonPath('meta.total', 0)->assertJsonPath('meta.counts.total', 3);
    $this->getJson($fixture->history.'?status=unknown&created_from=2026-11-21&created_to=2026-11-20')
        ->assertUnprocessable()->assertJsonValidationErrors(['status', 'created_to']);
});

test('filters the ticket index by normalized complete and partial plates and literal model text', function (array $filter) {
    $this->getJson('/api/support-tickets?'.http_build_query($filter))
        ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.vehicle.plate', 'HFS4456');
})->with([
    [['vehicle_plate' => 'HFS4456']], [['vehicle_plate' => 'fs-44']], [['vehicle_plate' => 'hfs 4456']],
    [['vehicle_model' => 'SPACEFOX 1.6/ 1.6 Trend Total Flex 8V 5p 2007']],
    [['vehicle_model' => 'Trend Total']],
]);

test('combines vehicle presence, brand, line and ordinary ticket filters without duplicates', function () {
    $fixture = app(VehicleHistoryFixture::class);
    foreach ([
        [['vehicle_presence' => 'with'], 4],
        [['vehicle_presence' => 'without'], 1],
        [['vehicle_brand_id' => $fixture->brand->id], 3],
        [['vehicle_line' => 'heavy'], 1],
        [['vehicle_line' => 'light'], 3],
        [['vehicle_model' => '%'], 0],
        [['vehicle_plate' => 'ABC-1D23', 'vehicle_model' => 'Trend'], 0],
    ] as [$filter, $total]) {
        $this->getJson('/api/support-tickets?'.http_build_query($filter))->assertOk()->assertJsonPath('meta.total', $total);
    }
    $combined = [
        'q' => 'Consulta de placa', 'system_id' => $fixture->system->id, 'customer_id' => $fixture->customer->id,
        'problem_subcategory_id' => $fixture->subcategory->id, 'status' => 'closed',
        'vehicle_presence' => 'with', 'vehicle_plate' => 'HFS', 'vehicle_brand_id' => $fixture->brand->id,
        'vehicle_model' => 'spacefox', 'vehicle_line' => 'light',
    ];
    $this->getJson('/api/support-tickets?'.http_build_query($combined))->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $fixture->recent->id);
    $this->getJson('/api/support-tickets?vehicle_presence=without')->assertOk()
        ->assertJsonPath('data.0.id', $fixture->unlinked->id)->assertJsonPath('data.0.vehicle', null);
});

test('rejects invalid and contradictory vehicle filters', function () {
    $fixture = app(VehicleHistoryFixture::class);
    $this->getJson('/api/support-tickets?vehicle_presence=without&vehicle_plate=HFS&vehicle_brand_id=1&vehicle_model=fox&vehicle_line=light')
        ->assertUnprocessable()->assertJsonValidationErrors(['vehicle_plate', 'vehicle_brand_id', 'vehicle_model', 'vehicle_line']);
    $this->getJson('/api/support-tickets?vehicle_presence=some&vehicle_plate=---&vehicle_brand_id=0&vehicle_line=medium')
        ->assertUnprocessable()->assertJsonValidationErrors(['vehicle_presence', 'vehicle_plate', 'vehicle_brand_id', 'vehicle_line']);
    $this->getJson($fixture->history.'?problem_category_id=bad&per_page=101')
        ->assertUnprocessable()->assertJsonValidationErrors(['problem_category_id', 'per_page']);
    $this->getJson('/api/support-tickets/'.$fixture->recent->id.'/related?problem_category_id=bad')
        ->assertOk()->assertJsonCount(0, 'data');
});

test('searches and returns the current vehicle identity without editing its linked tickets', function () {
    $fixture = app(VehicleHistoryFixture::class);
    $this->patchJson('/api/vehicles/'.$fixture->car->id, [
        'plate' => 'ZZZ-9087', 'model_description' => 'Modelo de catálogo atualizado',
        'brand_id' => $fixture->otherBrand->id, 'vehicle_line' => 'heavy',
    ])->assertOk();
    $this->getJson('/api/support-tickets?vehicle_plate=HFS4456&vehicle_line=light')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/support-tickets?'.http_build_query([
        'vehicle_plate' => 'zzz 90', 'vehicle_brand_id' => $fixture->otherBrand->id,
        'vehicle_model' => 'atualizado', 'vehicle_line' => 'heavy',
    ]))->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.vehicle.plate', 'ZZZ9087');
    $this->getJson($fixture->history)->assertOk()
        ->assertJsonPath('meta.vehicle.model_description', 'Modelo de catálogo atualizado')
        ->assertJsonPath('meta.vehicle.brand.name', 'Ford')->assertJsonPath('meta.counts.total', 3)
        ->assertJsonPath('data.0.description', $fixture->recent->description)
        ->assertJsonPath('data.0.resolution', $fixture->recent->resolution);
    $this->getJson('/api/support-tickets/'.$fixture->recent->id)->assertOk()->assertJsonPath('data.vehicle.vehicle_line', 'heavy');
});
