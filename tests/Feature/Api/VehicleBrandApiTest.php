<?php

use App\Models\Vehicle;
use App\Models\VehicleBrand;
use Database\Seeders\VehicleValidationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('creates, edits and searches paginated vehicle brands', function () {
    $brand = $this->postJson('/api/vehicle-brands', ['name' => "  Árvore \t  Motors  "])->assertCreated()
        ->assertJsonPath('data.name', 'Árvore Motors')
        ->assertJsonMissingPath('data.normalized_name')
        ->assertJsonStructure(['data' => ['id', 'name', 'created_at', 'updated_at']]);
    $id = $brand->json('data.id');
    $url = '/api/vehicle-brands/'.$id;
    $this->getJson($url)->assertOk()->assertJsonPath('data.name', 'Árvore Motors');
    $this->getJson('/api/vehicle-brands/999999')->assertNotFound();
    $this->getJson('/api/vehicle-brands?'.http_build_query(['q' => 'ÁRVORE   MO']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    $this->patchJson($url, ['name' => 'ÁRVORE MOTORS'])->assertOk()->assertJsonPath('data.name', 'ÁRVORE MOTORS');
    $this->putJson($url, ['name' => 'Árvore Vias'])->assertOk()->assertJsonPath('data.name', 'Árvore Vias');
    $this->getJson('/api/vehicle-brands?q=motors')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/vehicle-brands?q=%25')->assertOk()->assertJsonCount(0, 'data');

    VehicleBrand::factory()->create(['name' => 'Alfa Vias']);
    $page = $this->getJson('/api/vehicle-brands?q=vias&per_page=1')->assertOk()
        ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.name', 'Alfa Vias');
    $this->getJson($page->json('links.next'))->assertOk()
        ->assertJsonPath('meta.current_page', 2)->assertJsonPath('data.0.name', 'Árvore Vias');
});

test('validates brand names and rejects duplicates after collapsing spaces and case', function () {
    foreach ([[], ['name' => '   '], ['name' => null], ['name' => ['Marca']], ['name' => str_repeat('a', 256)]] as $payload) {
        $this->postJson('/api/vehicle-brands', $payload)->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    $brand = VehicleBrand::create(['name' => 'São Miguel']);
    $other = VehicleBrand::create(['name' => 'Outra']);
    foreach (['São Miguel', '  SÃO   MIGUEL  '] as $name) {
        $this->postJson('/api/vehicle-brands', ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
    }
    $this->patchJson('/api/vehicle-brands/'.$other->id, ['name' => 'são miguel'])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->assertDatabaseCount('vehicle_brands', 2);
    expect(fn () => VehicleBrand::create(['name' => "  SÃO\t MIGUEL\n"]))->toThrow(QueryException::class, 'vehicle_brands.normalized_name');
    $this->assertDatabaseHas('vehicle_brands', ['id' => $brand->id, 'name' => 'São Miguel']);
});

test('protects a brand referenced by a vehicle', function () {
    $brand = VehicleBrand::factory()->create();
    $vehicle = Vehicle::factory()->for($brand, 'brand')->create();

    $this->deleteJson('/api/vehicle-brands/'.$brand->id)->assertStatus(405);
    expect(fn () => DB::table('vehicle_brands')->where('id', $brand->id)->delete())->toThrow(QueryException::class, 'FOREIGN KEY');
    $this->assertDatabaseHas('vehicle_brands', ['id' => $brand->id]);
    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'brand_id' => $brand->id]);
});

test('lists brands with the number of vehicles in both lines', function () {
    $this->seed(VehicleValidationSeeder::class);
    VehicleBrand::create(['name' => 'Marca sem veículos']);

    $this->getJson('/api/vehicle-brands?q=marca')->assertOk()
        ->assertJsonPath('data.0.name', 'Marca Exemplo')
        ->assertJsonPath('data.0.vehicles_count', 2)
        ->assertJsonPath('data.1.vehicles_count', 0)
        ->assertJsonPath('data.2.vehicles_count', 1)
        ->assertJsonMissingPath('data.0.vehicles');
});
