<?php

use App\Models\Contact;
use App\Models\SupportTicket;
use App\Models\System;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use Database\Seeders\VehicleValidationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->brand = VehicleBrand::factory()->create();
    $this->classification = ['brand_id' => $this->brand->id, 'vehicle_line' => 'light'];
});

test('creates a vehicle with a normalized plate and an optional model description', function (string $input, string $plate, ?string $description) {
    $payload = [...$this->classification, 'plate' => $input];
    if ($description !== null) {
        $payload['model_description'] = $description;
    }
    $response = $this->postJson('/api/vehicles', $payload)->assertCreated()
        ->assertJsonPath('data.plate', $plate)
        ->assertJsonPath('data.brand_id', $this->brand->id)
        ->assertJsonPath('data.brand.name', $this->brand->name)
        ->assertJsonPath('data.vehicle_line', 'light')
        ->assertJsonPath('data.model_description', $description)
        ->assertJsonPath('data.support_tickets_count', 0)
        ->assertJsonStructure(['data' => ['id', 'plate', 'model_description', 'support_tickets_count', 'created_at', 'updated_at']]);

    $this->assertDatabaseHas('vehicles', ['plate' => $plate, 'model_description' => $description]);
    $this->getJson('/api/vehicles/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.model_description', $description);
})->with([
    'traditional with a full model description' => ['hfs-4456', 'HFS4456', 'SPACEFOX 1.6/ 1.6 Trend Total Flex 8V 5p 2007'],
    'Mercosul without a model description' => ['abc1d23', 'ABC1D23', null],
    'Mercosul with hyphens and spaces' => [' aBc - 1d23 ', 'ABC1D23', null],
    'traditional with a long multibyte description' => ['DEF1234', 'DEF1234', str_repeat('🚗', 16000)],
]);

test('rejects missing and invalid plates', function (array $payload) {
    $this->postJson('/api/vehicles', [...$this->classification, ...$payload])->assertUnprocessable()->assertJsonValidationErrors('plate');
    $this->assertDatabaseEmpty('vehicles');
})->with([
    'missing' => [[]],
    'null' => [['plate' => null]],
    'only separators' => [['plate' => ' - - ']],
    'too short' => [['plate' => 'ABC123']],
    'too long' => [['plate' => 'ABC12345']],
    'invalid traditional prefix' => [['plate' => 'A1C1234']],
    'invalid Mercosul ending' => [['plate' => 'ABC1D2E']],
    'non-ASCII letter' => [['plate' => 'ÁBC1234']],
    'unsupported punctuation' => [['plate' => 'ABC/1234']],
    'array' => [['plate' => ['ABC1234']]],
]);

test('rejects an invalid model description', function (mixed $description) {
    $this->postJson('/api/vehicles', [...$this->classification, 'plate' => 'ABC1234', 'model_description' => $description])
        ->assertUnprocessable()->assertJsonValidationErrors('model_description');
})->with([[123], [str_repeat('a', 16001)]]);

test('enforces plate uniqueness after normalization in the API and in the database', function () {
    $vehicle = Vehicle::factory()->create(['plate' => 'abc 1d23']);
    expect($vehicle->plate)->toBe('ABC1D23');
    foreach (['ABC1D23', 'abc-1d23', 'aBc 1D23'] as $plate) {
        $this->postJson('/api/vehicles', [...$this->classification, 'plate' => $plate])->assertUnprocessable()->assertJsonValidationErrors('plate');
    }
    $this->assertDatabaseCount('vehicles', 1);
    expect(fn () => DB::table('vehicles')->insert([...$this->classification, 'plate' => 'ABC1D23']))
        ->toThrow(QueryException::class, 'vehicles.plate');
});

test('updates the model description, preserves omitted fields and validates plate edits', function () {
    $vehicle = Vehicle::factory()->create(['plate' => 'HFS4456', 'model_description' => null]);
    $other = Vehicle::factory()->mercosul()->create(['plate' => 'ABC1D23']);
    $url = '/api/vehicles/'.$vehicle->id;

    $this->patchJson($url, ['model_description' => 'SPACEFOX 1.6 2007'])->assertOk()
        ->assertJsonPath('data.plate', 'HFS4456')->assertJsonPath('data.model_description', 'SPACEFOX 1.6 2007');
    $this->patchJson($url, ['model_description' => 'SPACEFOX 1.6 Trend 2007'])->assertOk()
        ->assertJsonPath('data.model_description', 'SPACEFOX 1.6 Trend 2007');
    $this->patchJson($url, ['plate' => 'hfs-4456'])->assertOk()->assertJsonPath('data.plate', 'HFS4456');
    $this->patchJson($url, ['plate' => 'abc 1d23'])->assertUnprocessable()->assertJsonValidationErrors('plate');
    $this->patchJson($url, ['plate' => null])->assertUnprocessable()->assertJsonValidationErrors('plate');
    $this->patchJson($url, ['plate' => 'inválida'])->assertUnprocessable()->assertJsonValidationErrors('plate');
    $this->putJson($url, ['plate' => ' xyz-0912 '])->assertOk()
        ->assertJsonPath('data.plate', 'XYZ0912')->assertJsonPath('data.model_description', 'SPACEFOX 1.6 Trend 2007');
    $this->patchJson($url, ['model_description' => null])->assertOk()
        ->assertJsonPath('data.plate', 'XYZ0912')->assertJsonPath('data.model_description', null);
    $this->assertDatabaseHas('vehicles', ['id' => $other->id, 'plate' => 'ABC1D23']);
});

test('searches by normalized plate or model text and paginates without loading the tickets', function () {
    $this->seed(VehicleValidationSeeder::class);
    $model = 'SPACEFOX 1.6/ 1.6 Trend Total Flex 8V 5p 2007';
    $this->getJson('/api/vehicles')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.per_page', 15);

    foreach (['HFS4456', 'hfs-4456', 'hfs 4456', 'fs-44'] as $term) {
        $this->getJson('/api/vehicles?'.http_build_query(['q' => $term]))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.plate', 'HFS4456')
            ->assertJsonPath('data.0.support_tickets_count', 2)->assertJsonMissingPath('data.0.support_tickets');
    }
    foreach ([$model, 'trend total flex'] as $term) {
        $this->getJson('/api/vehicles?'.http_build_query(['q' => $term]))->assertOk()->assertJsonCount(2, 'data');
    }
    $this->getJson('/api/vehicles?q=bc-1d')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.plate', 'ABC1D23')->assertJsonPath('data.0.support_tickets_count', 0);
    $this->getJson('/api/vehicles?q=xyz')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.model_description', null);
    foreach (['%', '_', '--', 'inexistente'] as $term) {
        $this->getJson('/api/vehicles?'.http_build_query(['q' => $term]))->assertOk()->assertJsonCount(0, 'data');
    }

    $page = $this->getJson('/api/vehicles?q=Spacefox&per_page=1')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.plate', 'ABC1D23');
    $this->getJson($page->json('links.next'))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.current_page', 2)->assertJsonPath('data.0.plate', 'HFS4456');
    $this->getJson('/api/vehicles?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    $this->getJson('/api/vehicles/999999')->assertNotFound();

    $id = Vehicle::where('plate', 'HFS4456')->sole()->id;
    $this->getJson('/api/vehicles/'.$id)->assertOk()->assertJsonPath('data.support_tickets_count', 2);
    $this->deleteJson('/api/vehicles/'.$id)->assertStatus(405);
    expect(fn () => DB::table('vehicles')->where('id', $id)->delete())->toThrow(QueryException::class);
    $this->assertDatabaseHas('vehicles', ['id' => $id]);
    expect(SupportTicket::where('vehicle_id', $id)->count())->toBe(2);
});

test('creates, updates and removes a single optional vehicle link on a ticket', function () {
    $system = System::create(['name' => 'ERP']);
    $otherSystem = System::create(['name' => 'Consultas']);
    $first = Vehicle::factory()->create(['plate' => 'HFS4456', 'model_description' => null]);
    $second = Vehicle::factory()->mercosul()->create();
    $base = [
        'system_id' => $system->id,
        'contact_id' => Contact::create(['name' => 'Contato'])->id,
        'title' => 'Falha na consulta',
        'description' => 'O aplicativo retornou um modelo diferente.',
    ];

    foreach ([$base, [...$base, 'vehicle_id' => null]] as $payload) {
        $this->postJson('/api/support-tickets', $payload)->assertCreated()
            ->assertJsonPath('data.vehicle_id', null)->assertJsonPath('data.vehicle', null);
    }
    $invalidVehicles = [999999, [$first->id, $second->id], (string) $first->id, true];
    foreach ($invalidVehicles as $invalid) {
        $this->postJson('/api/support-tickets', [...$base, 'vehicle_id' => $invalid])
            ->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
    }

    $ticket = $this->postJson('/api/support-tickets', [...$base, 'vehicle_id' => $first->id])
        ->assertCreated()->assertJsonPath('data.vehicle_id', $first->id)
        ->assertJsonPath('data.vehicle.plate', 'HFS4456')->assertJsonPath('data.vehicle.model_description', null);
    $firstTicketId = $ticket->json('data.id');
    $firstTicketUrl = '/api/support-tickets/'.$firstTicketId;
    $secondTicketId = $this->postJson('/api/support-tickets', [
        ...$base, 'system_id' => $otherSystem->id, 'vehicle_id' => $first->id, 'previous_ticket_id' => $firstTicketId,
    ])->assertCreated()->assertJsonPath('data.vehicle_id', $first->id)->json('data.id');
    $this->getJson('/api/vehicles/'.$first->id)->assertOk()->assertJsonPath('data.support_tickets_count', 2);

    $this->patchJson('/api/vehicles/'.$first->id, [
        'plate' => ' hfs-4457 ', 'model_description' => 'SPACEFOX 1.6 Trend',
    ])->assertOk()->assertJsonPath('data.support_tickets_count', 2);
    $this->getJson($firstTicketUrl)->assertOk()->assertJsonPath('data.vehicle_id', $first->id)
        ->assertJsonPath('data.vehicle.plate', 'HFS4457')->assertJsonPath('data.vehicle.model_description', 'SPACEFOX 1.6 Trend')
        ->assertJsonPath('data.follow_up_tickets.0.vehicle.model_description', 'SPACEFOX 1.6 Trend')
        ->assertJsonPath('data.description', $base['description']);
    $this->getJson('/api/support-tickets/'.$secondTicketId)->assertOk()
        ->assertJsonPath('data.previous_ticket.vehicle.plate', 'HFS4457')->assertJsonPath('data.vehicle.plate', 'HFS4457');
    $this->getJson($firstTicketUrl.'/related')->assertOk()->assertJsonPath('data.0.vehicle.plate', 'HFS4457');
    $this->getJson('/api/support-tickets')->assertOk()->assertJsonPath('data.0.vehicle.plate', 'HFS4457');

    $this->patchJson($firstTicketUrl, ['title' => 'Título atualizado'])->assertOk()->assertJsonPath('data.vehicle_id', $first->id);
    $this->patchJson($firstTicketUrl, ['vehicle_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
    $this->assertDatabaseHas('support_tickets', ['id' => $firstTicketId, 'vehicle_id' => $first->id]);
    $this->putJson($firstTicketUrl, ['vehicle_id' => $second->id])->assertOk()->assertJsonPath('data.vehicle_id', $second->id);
    $this->getJson('/api/vehicles/'.$first->id)->assertOk()->assertJsonPath('data.support_tickets_count', 1);
    $this->getJson('/api/vehicles/'.$second->id)->assertOk()->assertJsonPath('data.support_tickets_count', 1);
    $this->postJson($firstTicketUrl.'/close')->assertOk()
        ->assertJsonPath('data.status', 'closed')->assertJsonPath('data.vehicle_id', $second->id);
    $this->patchJson($firstTicketUrl, ['vehicle_id' => null])->assertOk()
        ->assertJsonPath('data.vehicle_id', null)->assertJsonPath('data.vehicle', null);
    $this->assertDatabaseHas('support_tickets', ['id' => $firstTicketId, 'vehicle_id' => null, 'status' => 'closed']);
    $this->getJson('/api/vehicles/'.$second->id)->assertOk()->assertJsonPath('data.support_tickets_count', 0);
});

test('requires a registered brand and an explicit valid line on new vehicles', function () {
    $vehicle = [...$this->classification, 'plate' => 'ABC1234'];
    foreach (['brand_id', 'vehicle_line'] as $field) {
        $missing = $vehicle;
        unset($missing[$field]);
        $this->postJson('/api/vehicles', $missing)->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    foreach ([999999, true, (string) $this->brand->id, null] as $brand) {
        $this->postJson('/api/vehicles', [...$vehicle, 'brand_id' => $brand])->assertUnprocessable()->assertJsonValidationErrors('brand_id');
    }
    foreach (['LIGHT', 'passenger', ['heavy'], null] as $line) {
        $this->postJson('/api/vehicles', [...$vehicle, 'vehicle_line' => $line])->assertUnprocessable()->assertJsonValidationErrors('vehicle_line');
    }
    $this->assertDatabaseEmpty('vehicles');

    $this->postJson('/api/vehicles', $vehicle)->assertCreated()->assertJsonPath('data.vehicle_line', 'light');
    $this->postJson('/api/vehicles', [...$vehicle, 'plate' => 'ABC1D23', 'vehicle_line' => 'heavy'])
        ->assertCreated()->assertJsonPath('data.vehicle_line', 'heavy')->assertJsonPath('data.brand.id', $this->brand->id);
    expect($this->brand->vehicles()->count())->toBe(2);
});

test('searches brand names and combines text, brand and vehicle line filters', function () {
    $this->seed(VehicleValidationSeeder::class);
    $brand = VehicleBrand::where('name', 'Marca Exemplo')->sole();
    $other = VehicleBrand::where('name', 'Outra Marca')->sole();

    $this->getJson('/api/vehicles?q=MARCA%20%20EXEMP')->assertOk()
        ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.brand.id', $brand->id);
    $this->getJson('/api/vehicles?brand_id='.$brand->id)->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/vehicles?vehicle_line=light')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/vehicles?vehicle_line=heavy')->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.plate', 'XYZ1234');
    $this->getJson('/api/vehicles?brand_id='.$brand->id.'&vehicle_line=light&q=spacefox')->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.plate', 'HFS4456');
    $this->getJson('/api/vehicles?brand_id='.$brand->id.'&vehicle_line=heavy&q=xyz-12')->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.plate', 'XYZ1234');
    $this->getJson('/api/vehicles?brand_id='.$other->id.'&vehicle_line=heavy&q=marca')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/vehicles?brand_id=0&vehicle_line=medium')->assertUnprocessable()
        ->assertJsonValidationErrors(['brand_id', 'vehicle_line']);
});

test('returns global vehicle counts independently of filters and pagination', function () {
    $this->getJson('/api/vehicles')->assertOk()
        ->assertJsonPath('meta.counts', ['total' => 0, 'light' => 0, 'heavy' => 0]);

    $this->seed(VehicleValidationSeeder::class);
    $brand = VehicleBrand::where('name', 'Marca Exemplo')->sole();
    $this->getJson('/api/vehicles?brand_id='.$brand->id.'&vehicle_line=heavy&q=xyz&per_page=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.counts', ['total' => 3, 'light' => 2, 'heavy' => 1]);
    $this->getJson('/api/vehicles?q=inexistente')->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.counts', ['total' => 3, 'light' => 2, 'heavy' => 1]);
});

test('returns the current brand and line in linked tickets, including changes to the brand name', function () {
    $vehicle = Vehicle::factory()->for($this->brand, 'brand')->create();
    $other = VehicleBrand::factory()->create(['name' => 'Outra Marca']);
    $ticket = SupportTicket::create([
        'system_id' => System::create(['name' => 'Consultas'])->id,
        'contact_id' => Contact::create(['name' => 'Contato'])->id,
        'vehicle_id' => $vehicle->id,
        'title' => 'Conferir identificação',
        'description' => 'Descrição do problema.',
    ]);
    $vehicleUrl = '/api/vehicles/'.$vehicle->id;
    $ticketUrl = '/api/support-tickets/'.$ticket->id;
    $this->getJson($ticketUrl)->assertOk()
        ->assertJsonPath('data.vehicle.brand_id', $this->brand->id)
        ->assertJsonPath('data.vehicle.brand.name', $this->brand->name)
        ->assertJsonPath('data.vehicle.vehicle_line', 'light');

    $this->patchJson($vehicleUrl, ['brand_id' => null, 'vehicle_line' => null])
        ->assertUnprocessable()->assertJsonValidationErrors(['brand_id', 'vehicle_line']);
    $this->patchJson($vehicleUrl, ['brand_id' => $other->id, 'vehicle_line' => 'heavy'])->assertOk()
        ->assertJsonPath('data.brand.id', $other->id)->assertJsonPath('data.vehicle_line', 'heavy')
        ->assertJsonPath('data.support_tickets_count', 1);
    $this->patchJson('/api/vehicle-brands/'.$other->id, ['name' => 'Outra Marca Atualizada'])->assertOk();
    $this->getJson($ticketUrl)->assertOk()
        ->assertJsonPath('data.vehicle.brand_id', $other->id)
        ->assertJsonPath('data.vehicle.brand.name', 'Outra Marca Atualizada')
        ->assertJsonPath('data.vehicle.vehicle_line', 'heavy');
    $this->getJson('/api/support-tickets')->assertOk()
        ->assertJsonPath('data.0.vehicle.brand.name', 'Outra Marca Atualizada');
});
