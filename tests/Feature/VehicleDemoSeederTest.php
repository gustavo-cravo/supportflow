<?php

use App\Actions\SaveSupportTicket;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\PendingTask;
use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Models\SupportTicket;
use App\Models\System;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\VehicleDemoDataset;
use Database\Seeders\VehicleDemoSeeder;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-11-24 15:00', 'America/Sao_Paulo')));
afterEach(fn () => $this->travelBack());

test('uses the real metrics API to verify a deterministic eight-ticket fixture', function () {
    $this->artisan('db:seed', ['--class' => 'VehicleDemoSeeder'])->expectsOutputToContain('2026-11-16 a 2026-11-23')->assertSuccessful();
    $system = System::where('name', VehicleDemoDataset::SYSTEM_NAME)->sole();
    $tickets = $system->supportTickets()->with(['vehicle', 'problemSubcategories'])->orderBy('id')->get();
    expect($tickets->map(fn ($ticket) => [$ticket->vehicle?->plate, $ticket->problemSubcategories->pluck('name')->all()])->all())->toBe([
        ['TST1A01', ['Erro de identificação', 'Informações técnicas incorretas']],
        ['TST1A01', ['Erro de identificação']],
        ['TST1A02', ['Informações técnicas incorretas']],
        ['TST2B01', ['Erro de identificação', 'Informações técnicas incorretas']],
        ['TST2B01', ['Erro de identificação']],
        ['TST2B02', ['Informações técnicas incorretas']],
        [null, ['Erro de identificação']],
        [null, []],
    ]);
    foreach ($tickets as $ticket) {
        expect($ticket->created_at->lt(now()))->toBeTrue();
        if ($ticket->status === 'closed') {
            expect($ticket->closed_at->equalTo($ticket->created_at->copy()->addHours(2)))->toBeTrue();
            expect($ticket->verified_at->equalTo($ticket->closed_at))->toBeTrue();
            expect($ticket->resolution)->not->toBeEmpty();
        } else {
            expect($ticket->status)->toBe('open');
            expect($ticket->closed_at)->toBeNull();
        }
    }
    $other = System::create(['name' => 'Outro sistema']);
    $other->supportTickets()->create(['contact_id' => $tickets[0]->contact_id, 'title' => 'Fora do filtro', 'description' => 'Fora do filtro']);
    $query = http_build_query(['system_id' => $system->id, 'period' => 'custom', 'date_from' => '2026-11-16', 'date_to' => '2026-11-23']);
    $this->getJson('/api/metrics/summary?'.$query)->assertOk()
        ->assertJsonPath('data.total', 8)->assertJsonPath('data.closed', 4)->assertJsonPath('data.not_closed', 4)
        ->assertJsonPath('data.subcategory_occurrences', 9);
    $this->getJson('/api/metrics/vehicles?'.$query)->assertOk()
        ->assertJsonPath('data.summary', [
            'with_vehicle' => 6, 'without_vehicle' => 2, 'identification_percentage' => 75,
            'distinct_vehicles' => 4, 'distinct_models' => 2, 'vehicles_without_model' => 1, 'light_tickets' => 3, 'heavy_tickets' => 3,
        ])
        ->assertJsonPath('data.lines.0.percentage', 50)->assertJsonPath('data.lines.1.percentage', 50)
        ->assertJsonPath('data.brands.0.brand_name', 'Volkswagen')->assertJsonPath('data.brands.0.total', 3)
        ->assertJsonPath('data.brands.1.brand_name', 'Volvo')->assertJsonPath('data.brands.1.total', 2)
        ->assertJsonPath('data.brands.2.brand_name', 'Scania')->assertJsonPath('data.brands.2.total', 1)
        ->assertJsonPath('data.models.0.model_description', 'SPACEFOX 1.6/ 1.6 Trend Total Flex 8V 5p 2007')->assertJsonPath('data.models.0.total', 3)
        ->assertJsonPath('data.models.1.model_description', 'FH 540 6x4 2022')->assertJsonPath('data.models.1.total', 2)
        ->assertJsonPath('data.models.2.model_description', null)->assertJsonPath('data.models.2.total', 1)
        ->assertJsonPath('data.recurrence', ['repeat_vehicles' => 2, 'repeat_tickets' => 4, 'max_tickets_per_vehicle' => 2]);
    $this->getJson('/api/metrics/subcategories?'.$query)->assertOk()
        ->assertJsonPath('data.0.subcategory_name', 'Erro de identificação')->assertJsonPath('data.0.total', 5)
        ->assertJsonPath('data.1.subcategory_name', 'Informações técnicas incorretas')->assertJsonPath('data.1.total', 4)
        ->assertJsonPath('meta.occurrences', 9);
    $this->assertDatabaseCount('vehicle_brands', 3);
    $this->assertDatabaseCount('vehicles', 4);
    expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);
});

test('generates vehicle statistics in both export formats for the demo system', function () {
    $this->seed(VehicleDemoSeeder::class);
    $query = http_build_query(['system_id' => System::where('name', VehicleDemoDataset::SYSTEM_NAME)->sole()->id,
        'period' => 'all', 'include_vehicle_statistics' => 1]);
    $pdf = $this->getJson('/api/metrics/export?format=pdf&'.$query)->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
    expect($pdf)->toStartWith('%PDF-');
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
    $contents = implode("\n", array_map(fn ($stream) => @gzuncompress($stream) ?: $stream, $streams[1]));
    expect($contents)->toContain(mb_convert_encoding('75,00%', 'UTF-16BE', 'UTF-8'))
        ->not->toContain(mb_convert_encoding('TST1A01', 'UTF-16BE', 'UTF-8'));

    $bytes = $this->getJson('/api/metrics/export?format=xlsx&'.$query)->assertOk()->getContent();
    $file = tempnam(sys_get_temp_dir(), 'sf-vehicle-demo-');
    try {
        file_put_contents($file, $bytes);
        $book = IOFactory::load($file);
        $summary = $book->getSheetByName('Resumo veicular');
        expect($summary->getCell('B5')->getValue())->toBe(6)
            ->and($summary->getCell('B6')->getValue())->toBe(2)
            ->and($summary->getCell('B7')->getValue())->toBe(0.75)
            ->and($book->getSheetByName('Resumo Geral')->getCell('D13')->getValue())->toBe(8);
        $book->disconnectWorksheets();
    } finally {
        unlink($file);
    }
});

test('reuses normalized existing brands and leaves rows and dates untouched on rerun', function () {
    $brand = VehicleBrand::create(['name' => 'volkswagen']);
    $before = $brand->refresh()->getRawOriginal();
    $this->seed(VehicleDemoSeeder::class);
    $tickets = DB::table('support_tickets')->orderBy('id')->get()->toArray();
    $vehicles = DB::table('vehicles')->orderBy('id')->get()->toArray();
    $this->travel(35)->days();
    $this->artisan('db:seed', ['--class' => 'VehicleDemoSeeder'])
        ->expectsOutputToContain('já existente')->expectsOutputToContain('2026-11-16 a 2026-11-23')->assertSuccessful();
    expect(DB::table('support_tickets')->orderBy('id')->get()->toJson())->toBe(json_encode($tickets, JSON_THROW_ON_ERROR))
        ->and(DB::table('vehicles')->orderBy('id')->get()->toJson())->toBe(json_encode($vehicles, JSON_THROW_ON_ERROR))
        ->and($brand->fresh()->getRawOriginal())->toBe($before);
    expect($brand->vehicles()->count())->toBe(2);
    $this->assertDatabaseCount('vehicle_demo_batches', 1);
});

test('reports plate collisions without claiming or modifying the existing vehicle', function () {
    $vehicle = Vehicle::factory()->create(['plate' => 'tst-2b01']);
    $original = $vehicle->refresh()->getRawOriginal();
    expect(fn () => app(VehicleDemoDataset::class)->seed())->toThrow(RuntimeException::class, 'TST2B01');
    $this->assertDatabaseCount('vehicle_demo_batches', 0);
    $this->assertDatabaseCount('systems', 0);
    $this->assertDatabaseCount('support_tickets', 0);
    expect($vehicle->fresh()->getRawOriginal())->toBe($original);
    $this->artisan('demo:vehicles:clean')->assertSuccessful();
    $this->assertDatabaseCount('vehicles', 1);
});

test('cleans only tracked rows, preserves reused brands and allows a new seed after complete cleanup', function () {
    $brand = VehicleBrand::create(['name' => 'volkswagen']);
    $untracked = Contact::create(['name' => 'Outro SFT-VEIC', 'notes' => 'SFT-VEIC:metrics:v1 - Dados fictícios para validação.']);
    $this->seed(DatabaseSeeder::class);
    $this->assertDatabaseEmpty('vehicle_demo_batches');
    $this->seed(VehicleDemoSeeder::class);
    $this->artisan('demo:vehicles:clean')->assertSuccessful();
    foreach (['vehicle_demo_batches', 'vehicles', 'systems', 'support_tickets', 'problem_categories', 'problem_subcategories',
        'problem_category_system', 'problem_subcategory_system', SupportTicket::SUBCATEGORY_PIVOT, 'customers'] as $table) {
        $this->assertDatabaseEmpty($table);
    }
    $this->assertDatabaseCount('contacts', 1);
    $this->assertDatabaseHas('contacts', ['id' => $untracked->id]);
    $this->assertDatabaseCount('vehicle_brands', 125);
    $this->assertDatabaseHas('vehicle_brands', ['id' => $brand->id, 'name' => 'VOLKSWAGEN']);
    $this->artisan('demo:vehicles:clean')->expectsOutputToContain('Nenhum registro')->assertSuccessful();
    $this->seed(VehicleDemoSeeder::class);
    $this->assertDatabaseCount('support_tickets', 8);
    $this->assertDatabaseCount('vehicles', 4);
});

test('preserves shared vehicles, classifications and demo tickets with real dependencies during cleanup', function () {
    $this->seed(VehicleDemoSeeder::class);
    $system = System::where('name', VehicleDemoDataset::SYSTEM_NAME)->sole();
    $category = ProblemCategory::sole();
    $sub = ProblemSubcategory::where('name', 'Informações técnicas incorretas')->sole();
    $truck = Vehicle::where('plate', 'TST2B01')->sole();
    $ticket = SupportTicket::where('title', 'like', 'SFT-VEIC ATE-007%')->sole();
    $pending = PendingTask::create(['support_ticket_id' => $ticket->id, 'description' => 'Pendência registrada pelo usuário']);
    $real = app(SaveSupportTicket::class)->execute(null, [
        'system_id' => $system->id, 'contact_id' => Contact::sole()->id, 'customer_id' => Customer::sole()->id,
        'vehicle_id' => $truck->id, 'problem_category_id' => $category->id, 'problem_subcategory_ids' => [$sub->id],
        'title' => 'Chamado do usuário', 'description' => 'Manter este registro.',
    ]);
    $this->artisan('demo:vehicles:clean')->expectsOutputToContain('preservado(s)')->assertSuccessful();
    $this->assertDatabaseCount('support_tickets', 2);
    $this->assertDatabaseHas('support_tickets', ['id' => $real->id, 'vehicle_id' => $truck->id]);
    $this->assertDatabaseHas('support_tickets', ['id' => $ticket->id]);
    $this->assertDatabaseHas('pending_tasks', ['id' => $pending->id, 'support_ticket_id' => $ticket->id]);
    $this->assertDatabaseHas('problem_subcategory_system', ['system_id' => $system->id, 'problem_subcategory_id' => $sub->id]);
    $this->assertDatabaseCount('vehicles', 1);
    $this->assertDatabaseHas('vehicle_brands', ['name' => 'Volvo']);
    $this->assertDatabaseCount('vehicle_brands', 1);
    expect(fn () => app(VehicleDemoDataset::class)->seed())->toThrow(RuntimeException::class, 'TST2B01');
    $this->artisan('demo:vehicles:clean')->expectsOutputToContain('Nenhum registro')->assertSuccessful();
    $this->assertDatabaseCount('support_tickets', 2);
    expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);
});

test('can start another batch when the only retained demo record is a brand used by a real vehicle', function () {
    $this->seed(VehicleDemoSeeder::class);
    $brand = VehicleBrand::where('name', 'Volvo')->sole();
    $real = Vehicle::factory()->for($brand, 'brand')->create(['plate' => 'REA2026']);
    $this->artisan('demo:vehicles:clean')->assertSuccessful();
    $this->assertDatabaseCount('vehicle_brands', 1);
    $this->assertDatabaseCount('vehicles', 1);
    $this->assertDatabaseEmpty('vehicle_demo_batches');
    $this->seed(VehicleDemoSeeder::class);
    $this->assertDatabaseCount('support_tickets', 8);
    $this->assertDatabaseCount('vehicle_brands', 3);
    expect($brand->vehicles()->pluck('plate')->all())->toContain('TST2B01', $real->plate);
});

test('does not cascade through a new classification link manually added to a fixture ticket', function () {
    $this->seed(VehicleDemoSeeder::class);
    $ticket = SupportTicket::where('title', 'like', 'SFT-VEIC ATE-002%')->sole();
    $subcategory = ProblemSubcategory::where('name', 'Informações técnicas incorretas')->sole();
    $ticket->problemSubcategories()->attach($subcategory, ['problem_category_id' => $ticket->problem_category_id]);
    $this->artisan('demo:vehicles:clean')->assertSuccessful();
    $this->assertDatabaseCount('support_tickets', 1);
    $this->assertDatabaseHas(SupportTicket::SUBCATEGORY_PIVOT, ['support_ticket_id' => $ticket->id, 'problem_subcategory_id' => $subcategory->id]);
});

test('refuses both write operations outside local and testing', function () {
    app()->detectEnvironment(fn () => 'production');
    try {
        expect(fn () => app(VehicleDemoDataset::class)->seed())->toThrow(RuntimeException::class, 'local ou testing');
        expect(fn () => app(VehicleDemoDataset::class)->clean())->toThrow(RuntimeException::class, 'local ou testing');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
    $this->assertDatabaseEmpty('vehicle_demo_batches');
});
