<?php

use App\Enums\TicketStatus;
use App\Models\Contact;
use App\Models\SupportTicket;
use App\Models\System;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Support\VehiclePlate;
use Carbon\CarbonImmutable;
use Database\Seeders\SupportFlowCommercialDataset;
use Database\Seeders\SupportFlowCommercialDemoSeeder;
use Database\Seeders\SupportFlowValidationSeeder;
use Database\Seeders\VehicleBrandSeeder;
use Database\Seeders\VehicleDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\ConfiguredMetricsPdfFixture;

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-11-26 15:00:00', 'America/Sao_Paulo')));
afterEach(function () {
    $this->travelBack();
    // Release cyclic report objects before the next test in the same PHP process.
    gc_collect_cycles();
});

function commercialManifest(): array
{
    return json_decode(DB::table('vehicle_demo_batches')->where('id', 2)->value('manifest'), true, flags: JSON_THROW_ON_ERROR);
}

function commercialGuidedTickets()
{
    return SupportTicket::with(['vehicle.brand', 'problemSubcategories', 'pendingTasks', 'reminders', 'followUpTickets'])
        ->whereHas('reminders', fn ($query) => $query->where('title', 'Retorno à operação para validar a correção'))
        ->orderBy('id')->get();
}

test('creates the requested commercial volume and coherent classification and vehicle distributions', function () {
    $this->seed(VehicleBrandSeeder::class);
    $this->artisan('db:seed', ['--class' => 'SupportFlowCommercialDemoSeeder'])
        ->expectsOutputToContain('lote criado com 250 atendimentos')
        ->expectsOutputToContain('a 2026-11-26')
        ->assertSuccessful();

    foreach ([
        'systems' => 3, 'customers' => 15, 'contacts' => 25, 'problem_categories' => 8,
        'problem_subcategories' => 24, 'support_tickets' => 250, 'vehicles' => 60,
        'pending_tasks' => 20, 'reminders' => 15, 'knowledge_articles' => 15, 'vehicle_brands' => 125,
    ] as $table => $count) {
        $this->assertDatabaseCount($table, $count);
    }
    expect(commercialManifest())->toHaveKeys(['profile', 'version', 'from', 'to', 'records'])
        ->and(commercialManifest()['profile'])->toBe('SFT-COMERCIAL')
        ->and(commercialManifest()['version'])->toBe(1)
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);

    $tickets = SupportTicket::with(['vehicle.brand', 'problemCategory.systems', 'problemSubcategories.systems', 'previousTicket'])->get();
    $linked = $tickets->whereNotNull('vehicle_id');
    $fleet = Vehicle::all();
    expect($linked)->toHaveCount(150)
        ->and($tickets->whereNull('vehicle_id'))->toHaveCount(100)
        ->and($linked->pluck('vehicle_id')->unique())->toHaveCount(60)
        ->and($fleet->pluck('brand_id')->unique())->toHaveCount(20)
        ->and($fleet->whereNull('model_description'))->toHaveCount(5)
        ->and($fleet->whereNotNull('model_description')->groupBy(['brand_id', 'model_description'])->flatten(1)->filter(fn ($rows) => $rows->count() === 2))->toHaveCount(20)
        ->and($fleet->countBy(fn (Vehicle $vehicle) => $vehicle->vehicle_line->value)->all())->toBe(['light' => 42, 'heavy' => 18])
        ->and($fleet->every(fn (Vehicle $vehicle) => preg_match(substr(VehiclePlate::FORMAT, 6), $vehicle->plate) === 1
            && VehiclePlate::normalize($vehicle->plate) === $vehicle->plate))->toBeTrue()
        ->and($fleet->filter(fn (Vehicle $vehicle) => ctype_digit($vehicle->plate[4])))->toHaveCount(20)
        ->and($tickets->pluck('title')->unique())->toHaveCount(250);

    expect($tickets->groupBy(fn (SupportTicket $ticket) => $ticket->created_at->format('Y-m'))->sortKeys()->map->count()->values()->all())
        ->toBe([12, 16, 14, 19, 15, 21, 18, 25, 23, 28, 24, 35])
        ->and($tickets->filter(fn ($ticket) => $ticket->created_at->gte('2026-06-01 00:00:00')))->toHaveCount(153)
        ->and($tickets->where('created_at', '>', now()))->toBeEmpty()
        ->and($tickets->pluck('system_id')->unique())->toHaveCount(3)
        ->and($tickets->pluck('customer_id')->unique())->toHaveCount(15)
        ->and($tickets->pluck('contact_id')->unique())->toHaveCount(25)
        ->and($tickets->pluck('status')->unique())->toHaveCount(count(TicketStatus::cases()))
        ->and($tickets->countBy('problem_category_id'))->toHaveCount(8)
        ->and($tickets->countBy('problem_category_id')->values()->unique()->count())->toBeGreaterThan(3)
        ->and($tickets->flatMap(fn (SupportTicket $ticket) => $ticket->problemSubcategories->pluck('id'))->unique())->toHaveCount(24)
        ->and($tickets->filter(fn (SupportTicket $ticket) => $ticket->problemSubcategories->count() > 1)->count())->toBeGreaterThan(40);

    foreach ($tickets as $ticket) {
        $finished = in_array($ticket->status, ['resolved', 'closed'], true);
        expect($ticket->problemCategory->systems->contains($ticket->system_id))->toBeTrue()
            ->and($ticket->problemSubcategories->every(fn ($subcategory) => $subcategory->problem_category_id === $ticket->problem_category_id
                && $subcategory->systems->contains($ticket->system_id)))->toBeTrue()
            ->and($ticket->closed_at !== null)->toBe($ticket->status === 'closed')
            ->and($ticket->verified_at !== null && $ticket->resolution !== null && $ticket->verification_notes !== null)->toBe($finished)
            ->and($ticket->updated_at->betweenIncluded($ticket->created_at, now()))->toBeTrue();
    }
    $recurrence = $linked->groupBy('vehicle_id');
    expect($recurrence->map->count()->max())->toBeGreaterThan(2)
        ->and($recurrence->contains(fn ($events) => $events->pluck('system_id')->unique()->count() > 1
            && $events->pluck('problem_category_id')->unique()->count() > 1))->toBeTrue();

    $returns = $tickets->whereNotNull('previous_ticket_id');
    expect($returns)->toHaveCount(20)
        ->and($returns->every(fn (SupportTicket $ticket) => $ticket->vehicle_id !== null && $ticket->previousTicket->vehicle_id === $ticket->vehicle_id
            && $ticket->previousTicket->customer_id === $ticket->customer_id && $ticket->previousTicket->created_at->lt($ticket->created_at)))->toBeTrue();
});

test('guides three complete vehicle cases with coherent task, reminder, closure and follow-up dates', function () {
    $this->seed([VehicleBrandSeeder::class, SupportFlowCommercialDemoSeeder::class]);

    $cases = commercialGuidedTickets();
    expect($cases->pluck('vehicle.plate')->all())->toBe(['SFC9C01', 'SFC9C43', 'SFC9C46']);
    foreach ($cases as $initial) {
        $task = $initial->pendingTasks->sole();
        $reminder = $initial->reminders->sole();
        $followUp = $initial->followUpTickets->sole();

        expect($initial->status)->toBe('closed')
            ->and($initial->problemSubcategories)->toHaveCount(2)
            ->and($initial->resolution)->not->toBeEmpty()
            ->and($initial->verification_source)->not->toBeEmpty()
            ->and($task->status)->toBe('completed')
            ->and($task->created_at->gt($initial->created_at))->toBeTrue()
            ->and($task->completed_at->betweenIncluded($task->created_at, $task->due_at))->toBeTrue()
            ->and($reminder->created_at->gt($initial->created_at))->toBeTrue()
            ->and($reminder->scheduled_at->gt($task->completed_at))->toBeTrue()
            ->and($reminder->completed_at->betweenIncluded($reminder->scheduled_at, $initial->verified_at))->toBeTrue()
            ->and($initial->verified_at->lt($initial->closed_at))->toBeTrue()
            ->and($initial->closed_at->lt($followUp->created_at))->toBeTrue()
            ->and($followUp->vehicle_id)->toBe($initial->vehicle_id)
            ->and($followUp->system_id)->not->toBe($initial->system_id);

        $this->getJson('/api/support-tickets/'.$initial->id.'/related')->assertOk()->assertJsonPath('data.0.id', $followUp->id);
        $history = $this->getJson('/api/vehicles/'.$initial->vehicle_id.'/support-tickets')->assertOk();
        expect(array_column($history->json('data'), 'id'))->toContain($initial->id, $followUp->id);
    }
});

test('feeds Dashboard metrics and renders statistical exports', function () {
    $this->seed([VehicleBrandSeeder::class, SupportFlowCommercialDemoSeeder::class]);
    $this->getJson('/api/dashboard/summary')->assertOk()
        ->assertJsonPath('data.counts.overdue_tasks', 4)
        ->assertJsonPath('data.counts.today_reminders', 3);
    $this->getJson('/api/metrics/summary')->assertOk()->assertJsonPath('data.total', 153);
    $this->getJson('/api/metrics/vehicles?period=all')->assertOk()
        ->assertJsonPath('data.summary.with_vehicle', 150)
        ->assertJsonPath('data.summary.without_vehicle', 100)
        ->assertJsonPath('data.summary.identification_percentage', 60)
        ->assertJsonPath('data.summary.distinct_vehicles', 60)
        ->assertJsonCount(20, 'data.brands');
    $this->getJson('/api/metrics/monthly?period=all')->assertOk()->assertJsonCount(12, 'data');

    $pdf = $this->getJson('/api/metrics/export?period=last_30_days&format=pdf&include_vehicle_statistics=1')
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->getContent())->toStartWith('%PDF-');
    gc_collect_cycles();
    $workbook = $this->getJson('/api/metrics/export?period=all&format=xlsx&include_vehicle_statistics=1')
        ->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $file = tempnam(sys_get_temp_dir(), 'sft-commercial');
    try {
        file_put_contents($file, $workbook->getContent());
        $book = IOFactory::load($file);
        expect($book->getSheetByName('Resumo Geral')->getCell('D13')->getValue())->toBe(250)
            ->and($book->getSheetByName('Resumo veicular')->getCell('B5')->getValue())->toBe(150);
        $book->disconnectWorksheets();
    } finally {
        unlink($file);
    }
});

test('renders configured, analytical and individual PDFs from the commercial cases', function () {
    $this->seed([VehicleBrandSeeder::class, SupportFlowCommercialDemoSeeder::class]);
    $configured = $this->postJson('/api/metrics/export/pdf', [
        'period' => 'all', 'configuration' => ['sections' => [
            'summary' => ConfiguredMetricsPdfFixture::section(['total']),
            'vehicles' => ConfiguredMetricsPdfFixture::section(['brands' => 'table']),
        ]],
    ])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($configured->getContent())->toStartWith('%PDF-');

    $cases = commercialGuidedTickets();
    $analytical = $this->postJson('/api/metrics/analytical-report/pdf', [
        'period' => 'all', 'configuration' => [
            'grouping' => 'model', 'selection' => ['mode' => 'only', 'ids' => $cases->modelKeys()],
            'sections' => [
                'ticket' => ['enabled' => true, 'fields' => ['title', 'description', 'problem_subcategories']],
                'vehicle' => ['enabled' => true, 'fields' => ['vehicle_brand', 'vehicle_model']],
                'resolution' => ['enabled' => true, 'fields' => ['resolution', 'verification_notes']],
            ],
        ],
    ])->assertOk()->assertHeader('X-Report-Selected-Count', '3');
    $individual = $this->postJson('/api/support-tickets/'.$cases->first()->id.'/export/pdf')
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($analytical->getContent())->toStartWith('%PDF-')
        ->and($individual->getContent())->toStartWith('%PDF-');
});

test('preserves existing records and both legacy masses and reruns without changing dates or manual edits', function () {
    $this->seed([VehicleBrandSeeder::class, SupportFlowValidationSeeder::class, VehicleDemoSeeder::class]);
    $system = System::create(['name' => 'Sistema preexistente']);
    $contact = Contact::create(['name' => 'Contato preexistente']);
    $system->supportTickets()->create(['contact_id' => $contact->id, 'title' => 'Acompanhamento', 'description' => 'Conteúdo preexistente']);
    $tables = ['systems', 'contacts', 'customers', 'problem_categories', 'problem_subcategories', 'problem_category_system',
        'problem_subcategory_system', 'support_tickets', 'pending_tasks', 'reminders', 'knowledge_articles', 'vehicles',
        'vehicle_brands', 'vehicle_demo_batches'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->all()]);
    $this->artisan('db:seed', ['--class' => 'SupportFlowCommercialDemoSeeder'])->assertSuccessful();
    $this->assertDatabaseCount('support_tickets', 283);
    $this->assertDatabaseCount('vehicle_demo_batches', 2);
    $manifest = commercialManifest();
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->whereIn('id', array_column($rows, 'id'))->orderBy('id')->get()->all())->toEqual($rows);
    }

    $ticket = SupportTicket::findOrFail($manifest['records']['support_tickets'][0]);
    $ticket->update(['description' => 'Edição efetuada durante a demonstração.']);
    $snapshot = collect($tables)->map(fn ($table) => DB::table($table)->orderBy('id')->get()->all())->all();
    $this->travel(12)->days();
    $this->artisan('db:seed', ['--class' => 'SupportFlowCommercialDemoSeeder'])
        ->expectsOutputToContain('lote existente')->assertSuccessful();
    expect(collect($tables)->map(fn ($table) => DB::table($table)->orderBy('id')->get()->all())->all())->toEqual($snapshot);
    $this->artisan('demo:vehicles:clean')->assertSuccessful();
    expect(commercialManifest())->toBe($manifest);
    $this->assertDatabaseCount('support_tickets', 275);
    $this->assertDatabaseCount('vehicle_brands', 125);
});

test('rejects production before writes even with force', function () {
    $this->seed(VehicleBrandSeeder::class);
    $this->app->instance('env', 'production');
    expect(fn () => $this->artisan('db:seed', ['--class' => 'SupportFlowCommercialDemoSeeder', '--force' => true])->run())
        ->toThrow(RuntimeException::class, 'local ou testing');
    $this->assertDatabaseCount('vehicle_demo_batches', 0);
    $this->assertDatabaseCount('support_tickets', 0);
});

test('uses Laravel default connection in local with a shared SQLite file', function () {
    $file = tempnam(sys_get_temp_dir(), 'sft-commercial-local');
    $default = DB::getDefaultConnection();
    $environment = $this->app->environment();
    config(['database.connections.commercial_isolated' => [...config('database.connections.sqlite'), 'database' => $file]]);
    DB::setDefaultConnection('commercial_isolated');
    $this->app->instance('env', 'local');
    try {
        expect(fn () => app(SupportFlowCommercialDataset::class)->seed())
            ->toThrow(RuntimeException::class, 'requer o esquema completo');
        $this->artisan('migrate', ['--database' => 'commercial_isolated'])->assertSuccessful();
        $this->seed([VehicleBrandSeeder::class, SupportFlowValidationSeeder::class, VehicleDemoSeeder::class]);
        $this->artisan('db:seed', ['--class' => 'SupportFlowCommercialDemoSeeder'])
            ->expectsOutputToContain('lote criado com 250 atendimentos')
            ->assertSuccessful();
        expect(DB::table('support_tickets')->count())->toBe(282)
            ->and(DB::table('vehicle_demo_batches')->orderBy('id')->pluck('id')->all())->toBe([1, 2])
            ->and(DB::connection($default)->table('support_tickets')->count())->toBe(0);
    } finally {
        $this->app->instance('env', $environment);
        DB::setDefaultConnection($default);
        DB::purge('commercial_isolated');
        unlink($file);
    }
});

test('requires canonical catalog brands and refuses reserved plate collisions without adopting them', function () {
    expect(fn () => $this->seed(SupportFlowCommercialDemoSeeder::class))
        ->toThrow(RuntimeException::class, 'VehicleBrandSeeder');
    $this->seed(VehicleBrandSeeder::class);
    $brand = VehicleBrand::where('name', 'VOLKSWAGEN')->sole();
    $existing = Vehicle::create(['plate' => 'SFC9C01', 'brand_id' => $brand->id, 'vehicle_line' => 'light', 'model_description' => 'Registro independente']);

    expect(fn () => $this->seed(SupportFlowCommercialDemoSeeder::class))
        ->toThrow(RuntimeException::class, 'SFC9C01');
    $this->assertDatabaseCount('vehicle_demo_batches', 0);
    $this->assertDatabaseCount('systems', 0);
    $this->assertDatabaseCount('vehicles', 1);
    expect($existing->refresh()->model_description)->toBe('Registro independente');
});

test('refuses a foreign or incomplete manifest without replacing it or reseeding the rows', function () {
    $this->seed(VehicleBrandSeeder::class);
    DB::table('vehicle_demo_batches')->insert(['id' => 2, 'manifest' => '{"profile":"outro","version":1}']);
    expect(fn () => $this->seed(SupportFlowCommercialDemoSeeder::class))
        ->toThrow(RuntimeException::class, 'manifesto incompatível');
    DB::table('vehicle_demo_batches')->where('id', 2)->delete();
    $this->seed(SupportFlowCommercialDemoSeeder::class);
    $id = commercialManifest()['records']['knowledge_articles'][0];
    DB::table('knowledge_articles')->where('id', $id)->delete();

    expect(fn () => $this->seed(SupportFlowCommercialDemoSeeder::class))
        ->toThrow(RuntimeException::class, 'incompleto em knowledge_articles');
    $this->assertDatabaseCount('support_tickets', 250);
    $this->assertDatabaseCount('knowledge_articles', 14);
});

test('rolls back the lot and domain records if a write fails near the end', function () {
    $this->seed(VehicleBrandSeeder::class);
    DB::unprepared("CREATE TRIGGER fail_commercial_article BEFORE INSERT ON knowledge_articles BEGIN SELECT RAISE(ABORT, 'falha comercial simulada'); END");

    expect(fn () => $this->seed(SupportFlowCommercialDemoSeeder::class))
        ->toThrow(QueryException::class, 'falha comercial simulada');
    foreach (['systems', 'contacts', 'customers', 'problem_categories', 'problem_subcategories', 'problem_category_system',
        'problem_subcategory_system', 'support_tickets', 'problem_subcategory_support_ticket', 'pending_tasks',
        'reminders', 'knowledge_articles', 'vehicles', 'vehicle_demo_batches'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    $this->assertDatabaseCount('vehicle_brands', 125);
});
