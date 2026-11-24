<?php

use App\Models\Contact;
use App\Models\SupportTicket;
use App\Models\System;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\VehicleBrandSeeder;
use Database\Seeders\VehicleDemoSeeder;
use Illuminate\Support\Facades\DB;

test('imports the 125 original names verbatim and keeps each separately named brand', function () {
    $source = database_path('seeders/data/vehicle-brands.txt');
    expect(hash_file('sha256', $source))->toBe('f79867e963602d2a223c80860fc46bf0dd52f44f5b7ffc5be620c5fddb0bfc1e');
    $this->artisan('db:seed', ['--class' => 'VehicleBrandSeeder'])->assertSuccessful();
    $names = VehicleBrand::orderBy('id')->pluck('name')->all();

    expect($names)->toHaveCount(125)->toBe(file($source, FILE_IGNORE_NEW_LINES))
        ->toContain('GWM', 'GREAT WALL', 'D2D', 'D2D MOTORS', 'EFFA', 'EFFA-JMC', 'SAAB', 'SAAB-SCANIA',
            'CHANA', 'CHANGAN', 'WALK', 'WAKE', 'WALKBUS');
    expect(VehicleBrand::distinct()->count('normalized_name'))->toBe(125);
    foreach (['vehicles', 'support_tickets', 'users'] as $table) {
        $this->assertDatabaseEmpty($table);
    }
});

test('rerunning the brand seeder preserves IDs, names and timestamps', function () {
    $this->seed(VehicleBrandSeeder::class);
    $snapshot = DB::table('vehicle_brands')->orderBy('id')->get()->toJson();
    $this->travel(5)->days();
    try {
        $this->seed(VehicleBrandSeeder::class);
        expect(DB::table('vehicle_brands')->orderBy('id')->get()->toJson())->toBe($snapshot);
    } finally {
        $this->travelBack();
    }
});

test('updates only official capitalization and preserves unrelated names and existing vehicle and ticket links', function () {
    $brand = VehicleBrand::create(['name' => 'Volkswagen']);
    $unrelated = VehicleBrand::create(['name' => 'Volks-Wagen']);
    $vehicle = Vehicle::factory()->for($brand, 'brand')->create(['plate' => 'REA2026']);
    $ticket = SupportTicket::create([
        'system_id' => System::create(['name' => 'ERP'])->id,
        'contact_id' => Contact::create(['name' => 'Contato'])->id,
        'vehicle_id' => $vehicle->id, 'title' => 'Atendimento existente', 'description' => 'Histórico preservado.',
    ]);
    $original = $brand->refresh()->getRawOriginal();
    $other = $unrelated->refresh()->getRawOriginal();
    $this->travel(5)->days();
    try {
        $this->seed(VehicleBrandSeeder::class);
    } finally {
        $this->travelBack();
    }
    expect($brand->fresh()->getRawOriginal())->toBe(array_replace($original, ['name' => 'VOLKSWAGEN']));
    expect($unrelated->fresh()->getRawOriginal())->toBe($other);
    $this->assertDatabaseCount('vehicle_brands', 126);
    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'brand_id' => $brand->id]);
    $this->assertDatabaseHas('support_tickets', ['id' => $ticket->id, 'vehicle_id' => $vehicle->id]);
});

test('reports a normalized-name conflict involving more than case and atomically rolls back the import', function () {
    $first = VehicleBrand::create(['name' => 'Acura']);
    DB::table('vehicle_brands')->insert(['name' => 'LAND  ROVER', 'normalized_name' => 'land rover']);

    expect(fn () => $this->seed(VehicleBrandSeeder::class))->toThrow(RuntimeException::class, 'LAND  ROVER');
    $this->assertDatabaseCount('vehicle_brands', 2);
    expect($first->fresh()->name)->toBe('Acura');
    $this->assertDatabaseHas('vehicle_brands', ['name' => 'LAND  ROVER', 'normalized_name' => 'land rover']);
});

test('default seeding includes the official catalog, which demo data reuses and cleanup preserves', function () {
    $this->seed(DatabaseSeeder::class);
    $this->assertDatabaseCount('vehicle_brands', 125);
    expect(User::count())->toBe(1);
    $this->assertDatabaseEmpty('vehicle_demo_batches');
    $this->assertDatabaseEmpty('support_tickets');
    $this->assertDatabaseEmpty('vehicles');
    $brands = DB::table('vehicle_brands')->orderBy('id')->get()->toJson();

    $this->seed(VehicleDemoSeeder::class);
    $this->assertDatabaseCount('vehicle_brands', 125);
    $this->assertDatabaseCount('vehicles', 4);
    expect(Vehicle::where('plate', 'TST1A01')->sole()->brand->name)->toBe('VOLKSWAGEN');
    $this->artisan('demo:vehicles:clean')->assertSuccessful();
    $this->assertDatabaseEmpty('vehicles');
    $this->assertDatabaseEmpty('support_tickets');
    expect(DB::table('vehicle_brands')->orderBy('id')->get()->toJson())->toBe($brands);
});

test('adopts demo-created brands by ID so existing demo ownership cannot remove them later', function () {
    $this->seed(VehicleDemoSeeder::class);
    $original = VehicleBrand::orderBy('id')->pluck('normalized_name', 'id')->all();
    expect($original)->toHaveCount(3);

    $this->seed(VehicleBrandSeeder::class);
    $manifest = json_decode(DB::table('vehicle_demo_batches')->sole()->manifest, true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['records']['vehicle_brands'])->toBe([]);
    $this->seed(VehicleDemoSeeder::class);
    $this->artisan('demo:vehicles:clean')->assertSuccessful();

    $this->assertDatabaseCount('vehicle_brands', 125);
    $this->assertDatabaseEmpty('vehicles');
    $this->assertDatabaseEmpty('support_tickets');
    expect(VehicleBrand::whereKey(array_keys($original))->orderBy('id')->pluck('normalized_name', 'id')->all())->toBe($original);
    expect(VehicleBrand::whereKey(array_keys($original))->orderBy('id')->pluck('name')->all())
        ->toBe(['VOLKSWAGEN', 'VOLVO', 'SCANIA']);
});
