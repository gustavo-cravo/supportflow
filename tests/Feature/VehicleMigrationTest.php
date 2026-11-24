<?php

use App\Models\Contact;
use App\Models\ProblemCategory;
use App\Models\SupportTicket;
use App\Models\System;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the vehicle migrations preserve existing tickets and their classifications', function () {
    $vehicles = require database_path('migrations/2026_11_24_000002_create_vehicles_table.php');
    $links = require database_path('migrations/2026_11_24_000003_add_vehicle_id_to_support_tickets_table.php');
    $classification = require database_path('migrations/2026_11_24_000005_add_brand_and_line_to_vehicles_table.php');
    $required = require database_path('migrations/2026_11_24_000006_require_brand_and_line_on_vehicles_table.php');
    $required->down();
    $classification->down();
    $links->down();
    $vehicles->down();

    $category = ProblemCategory::create(['name' => 'Categoria histórica']);
    $subcategories = [];
    foreach (['Primeira', 'Segunda'] as $name) {
        $subcategories[] = $category->subcategories()->create(['name' => $name])->id;
    }
    $base = [
        'system_id' => System::create(['name' => 'Sistema histórico', 'is_active' => false])->id,
        'contact_id' => Contact::create(['name' => 'Contato histórico'])->id,
        'title' => 'Atendimento histórico',
        'description' => 'Descrição anterior à migração.',
        'problem_category_id' => $category->id,
        'problem_subcategory_id' => $subcategories[0],
    ];
    $ticket = SupportTicket::create($base);
    $ticket->problemSubcategories()->attach($subcategories, ['problem_category_id' => $category->id]);
    SupportTicket::create([...$base, 'previous_ticket_id' => $ticket->id]);
    $before = DB::table('support_tickets')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

    $vehicles->up();
    $links->up();
    $vehicles->up();
    $links->up();
    expect(DB::table('support_tickets')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())
        ->toBe(array_map(fn (array $row) => [...$row, 'vehicle_id' => null], $before));
    expect($ticket->problemSubcategories()->get()->modelKeys())->toBe($subcategories);
    expect(Schema::hasIndex('vehicles', ['plate'], 'unique'))->toBeTrue();
    expect(Schema::hasIndex('support_tickets', ['vehicle_id']))->toBeTrue();
    $this->getJson('/api/support-tickets/'.$ticket->id)->assertOk()
        ->assertJsonPath('data.vehicle_id', null)->assertJsonPath('data.problem_subcategory_ids', $subcategories);

    expect(fn () => $ticket->update(['vehicle_id' => 999999]))->toThrow(QueryException::class);
    $classification->up();
    $required->up();
    $vehicle = Vehicle::factory()->create();
    $ticket->update(['vehicle_id' => $vehicle->id]);
    expect(fn () => $links->down())->toThrow(RuntimeException::class, 'há atendimentos vinculados');
    expect(fn () => $vehicles->down())->toThrow(RuntimeException::class, 'há veículos cadastrados');
});

test('the brand and line migrations preserve legacy vehicles and require completion before enforcing constraints', function () {
    $brands = require database_path('migrations/2026_11_24_000004_create_vehicle_brands_table.php');
    $classification = require database_path('migrations/2026_11_24_000005_add_brand_and_line_to_vehicles_table.php');
    $required = require database_path('migrations/2026_11_24_000006_require_brand_and_line_on_vehicles_table.php');
    $required->down();
    $classification->down();
    $brands->down();

    $vehicleId = DB::table('vehicles')->insertGetId(['plate' => 'HFS4456', 'model_description' => 'Modelo anterior']);
    $ticket = SupportTicket::create([
        'system_id' => System::create(['name' => 'Sistema anterior'])->id,
        'contact_id' => Contact::create(['name' => 'Contato anterior'])->id,
        'title' => 'Atendimento anterior',
        'description' => 'Preservar vínculo.',
        'vehicle_id' => $vehicleId,
    ]);
    $before = (array) DB::table('vehicles')->where('id', $vehicleId)->sole();

    $brands->up();
    $classification->up();
    $brands->up();
    $classification->up();
    expect((array) DB::table('vehicles')->where('id', $vehicleId)->sole())
        ->toBe([...$before, 'brand_id' => null, 'vehicle_line' => null]);
    $this->assertDatabaseEmpty('vehicle_brands');
    $this->getJson('/api/support-tickets/'.$ticket->id)->assertOk()
        ->assertJsonPath('data.vehicle_id', $vehicleId)
        ->assertJsonPath('data.vehicle.brand_id', null)
        ->assertJsonPath('data.vehicle.brand', null)
        ->assertJsonPath('data.vehicle.vehicle_line', null);
    expect(fn () => $required->up())->toThrow(RuntimeException::class, 'Há 1 veículo(s) sem marca ou linha');

    $this->patchJson('/api/vehicles/'.$vehicleId, ['vehicle_line' => 'heavy'])->assertOk()
        ->assertJsonPath('data.vehicle_line', 'heavy');
    expect(fn () => $required->up())->toThrow(RuntimeException::class, 'Há 1 veículo(s) sem marca ou linha');
    $brand = VehicleBrand::create(['name' => 'Fabricante confirmado']);
    $this->patchJson('/api/vehicles/'.$vehicleId, ['brand_id' => $brand->id])->assertOk()
        ->assertJsonPath('data.brand.id', $brand->id);

    $required->up();
    $required->up();
    expect(Schema::hasIndex('vehicle_brands', ['normalized_name'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('vehicles', ['brand_id']))->toBeTrue()
        ->and(Schema::hasIndex('vehicles', ['vehicle_line']))->toBeTrue();
    $this->assertDatabaseHas('support_tickets', ['id' => $ticket->id, 'vehicle_id' => $vehicleId, 'description' => 'Preservar vínculo.']);
    $this->assertDatabaseHas('vehicles', ['id' => $vehicleId, 'brand_id' => $brand->id, 'vehicle_line' => 'heavy', 'model_description' => 'Modelo anterior']);

    $record = DB::table('vehicles')->where('id', $vehicleId);
    expect(fn () => $record->update(['brand_id' => null]))->toThrow(QueryException::class)
        ->and(fn () => $record->update(['vehicle_line' => null]))->toThrow(QueryException::class)
        ->and(fn () => $record->update(['vehicle_line' => 'heavy ']))->toThrow(QueryException::class, 'CHECK')
        ->and(fn () => $record->update(['brand_id' => 999999]))->toThrow(QueryException::class, 'FOREIGN KEY')
        ->and(fn () => DB::table('vehicles')->insert(['plate' => 'ABC1234']))->toThrow(QueryException::class);
    expect(fn () => $classification->down())->toThrow(RuntimeException::class, 'há veículos com marca ou linha preenchida');
});
