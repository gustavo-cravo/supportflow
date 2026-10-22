<?php

use App\Enums\PendingTaskStatus;
use App\Enums\TicketStatus;
use App\Enums\VerificationSource;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\KnowledgeArticle;
use App\Models\PendingTask;
use App\Models\Reminder;
use App\Models\SupportTicket;
use App\Models\System;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\SupportFlowDemoSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::now('America/Sao_Paulo')->startOfDay()->addHours(14));
});

afterEach(function () {
    $this->travelBack();
});

function demoTableCounts(): array
{
    return ['systems' => 8, 'contacts' => 26, 'customers' => 20, 'support_tickets' => 90, 'pending_tasks' => 48, 'reminders' => 48, 'knowledge_articles' => 20];
}

test('demo seeder is independently executable and creates realistic related histories', function () {
    $this->artisan('db:seed', ['--class' => SupportFlowDemoSeeder::class])->assertSuccessful();
    foreach (demoTableCounts() as $table => $count) {
        $this->assertDatabaseCount($table, $count);
    }
    expect(User::count())->toBe(0);
    expect(System::where('is_active', true)->count())->toBe(6);
    expect(System::where('is_active', false)->count())->toBe(2);
    expect(SupportTicket::whereNull('customer_id')->count())->toBeGreaterThan(0);
    expect(SupportTicket::whereNotNull('previous_ticket_id')->count())->toBe(15);
    expect(KnowledgeArticle::whereNull('system_id')->count())->toBe(4);
    expect(Contact::whereNotNull('email')->where('email', 'not like', '%@supportflow.example.test')->count())->toBe(0);
    expect(Customer::whereNotNull('email')->where('email', 'not like', '%@supportflow.example.test')->count())->toBe(0);
    foreach (Customer::all() as $customer) {
        expect($customer->document)->toMatch('/^[0-9]{14}$/');
    }
    foreach (TicketStatus::cases() as $status) {
        expect(SupportTicket::where('status', $status->value)->exists())->toBeTrue();
    }

    $totals = System::withCount('supportTickets')->get()->pluck('support_tickets_count');
    expect($totals->min())->toBeGreaterThan(0);
    expect($totals->unique()->count())->toBeGreaterThan(1);
    expect(SupportTicket::where('created_at', '<', now()->subDays(120))->exists())->toBeTrue();
    expect(SupportTicket::where('created_at', '>=', today())->exists())->toBeTrue();

    $sharedCustomer = Customer::whereLike('name', '[DEMO SF] CLI-001 | %')->sole();
    expect($sharedCustomer->supportTickets()->distinct()->count('contact_id'))->toBeGreaterThan(1);

    foreach (SupportTicket::with(['system', 'contact', 'customer', 'previousTicket'])->get() as $ticket) {
        expect($ticket->system)->not->toBeNull();
        expect($ticket->contact)->not->toBeNull();
        expect(mb_strlen($ticket->title))->toBeLessThanOrEqual(255);
        expect(mb_strlen($ticket->description))->toBeLessThanOrEqual(16000);
        expect($ticket->created_at->lte($ticket->updated_at))->toBeTrue();
        expect($ticket->updated_at->lte(now()))->toBeTrue();
        expect($ticket->closed_at !== null)->toBe($ticket->status === TicketStatus::Closed->value);
        if (in_array($ticket->status, [TicketStatus::Resolved->value, TicketStatus::Closed->value], true)) {
            expect($ticket->resolution)->not->toBeEmpty();
            expect(VerificationSource::tryFrom($ticket->verification_source))->not->toBeNull();
            expect($ticket->verified_at->betweenIncluded($ticket->created_at, $ticket->updated_at))->toBeTrue();
            expect($ticket->verified_by)->not->toBeEmpty();
        } else {
            expect($ticket->resolution)->toBeNull();
            expect($ticket->verified_at)->toBeNull();
            expect($ticket->verification_source)->toBeNull();
        }
        if (! $ticket->system->is_active) {
            expect($ticket->status)->toBe(TicketStatus::Closed->value);
            expect($ticket->closed_at->lt($ticket->system->updated_at))->toBeTrue();
        }
        if ($parent = $ticket->previousTicket) {
            expect($ticket->system_id)->toBe($parent->system_id);
            expect($ticket->contact_id)->toBe($parent->contact_id);
            expect($ticket->customer_id)->toBe($parent->customer_id);
            expect($ticket->created_at->gt($parent->created_at))->toBeTrue();
            expect($ticket->description)->toContain($parent->description);
            expect($parent->previous_ticket_id)->toBeNull();
        }
    }
});

test('demo seeder preserves existing customers without documents', function () {
    $customer = Customer::create(['name' => '[DEMO SF] CLI-001 | Cliente antigo']);
    $this->artisan('db:seed', ['--class' => SupportFlowDemoSeeder::class])->assertSuccessful();

    expect($customer->fresh()->document)->toBeNull();
    expect(Customer::whereNotNull('document')->count())->toBe(19);
});

test('demo follow-ups keep independent closures through the existing API', function () {
    $this->seed(SupportFlowDemoSeeder::class);
    $followUp = SupportTicket::with('previousTicket')->whereNotNull('previous_ticket_id')->where('status', TicketStatus::Waiting->value)->firstOrFail();
    $parent = $followUp->previousTicket;
    $before = $parent->getRawOriginal();

    $this->postJson('/api/support-tickets/'.$followUp->id.'/close')->assertOk()->assertJsonPath('data.status', 'closed');
    expect($parent->refresh()->getRawOriginal())->toBe($before);

    $openReturn = SupportTicket::whereHas('previousTicket', fn ($query) => $query->where('status', 'closed'))
        ->where('status', 'open')->firstOrFail();
    expect($openReturn->closed_at)->toBeNull();
});

test('demo produces coherent pending tasks and reminders with independent records', function () {
    $this->seed(SupportFlowDemoSeeder::class);
    expect(PendingTask::whereNull('support_ticket_id')->count())->toBe(12);
    expect(Reminder::whereNull('support_ticket_id')->count())->toBe(12);
    expect(PendingTask::where('status', 'pending')->where('due_at', '<', now())->count())->toBe(12);
    expect(PendingTask::where('status', 'pending')->where('due_at', '>', now())->count())->toBe(12);
    expect(PendingTask::where('status', 'pending')->whereNull('due_at')->count())->toBe(12);
    expect(PendingTask::where('status', 'completed')->count())->toBe(12);

    foreach (PendingTask::with('supportTicket')->get() as $task) {
        expect(PendingTaskStatus::tryFrom($task->status))->not->toBeNull();
        expect($task->completed_at !== null)->toBe($task->status === 'completed');
        expect($task->created_at->lte($task->updated_at))->toBeTrue();
        if ($task->completed_at) {
            expect($task->completion_notes)->not->toBeEmpty();
            expect($task->completed_at->betweenIncluded($task->created_at, now()))->toBeTrue();
        }
        if ($task->support_ticket_id !== null) {
            expect($task->supportTicket)->not->toBeNull();
            expect($task->created_at->gte($task->supportTicket->created_at))->toBeTrue();
        }
    }
    foreach (Reminder::with('supportTicket')->get() as $reminder) {
        expect($reminder->created_at->lt($reminder->scheduled_at))->toBeTrue();
        if ($reminder->completed_at) {
            expect($reminder->completed_at->betweenIncluded($reminder->scheduled_at, now()))->toBeTrue();
        }
        if ($reminder->support_ticket_id !== null) {
            expect($reminder->supportTicket)->not->toBeNull();
            expect($reminder->created_at->gte($reminder->supportTicket->created_at))->toBeTrue();
        }
    }
});

test('demo supports dashboard counts, pagination and existing API filters', function () {
    $this->seed(SupportFlowDemoSeeder::class);
    $this->getJson('/api/dashboard/summary')->assertOk()
        ->assertJsonPath('data.counts', ['open' => 19, 'in_progress' => 16, 'waiting' => 18, 'overdue_tasks' => 12, 'today_reminders' => 12])
        ->assertJsonCount(5, 'data.recent_tickets')->assertJsonCount(5, 'data.today_reminders')
        ->assertJsonCount(5, 'data.upcoming_reminders')->assertJsonCount(5, 'data.overdue_reminders');
    foreach (['open', 'in_progress', 'waiting'] as $status) {
        $response = $this->getJson('/api/support-tickets?status='.$status)->assertOk();
        expect($response->json('meta.total'))->toBeGreaterThan(0);
        foreach ($response->json('data') as $ticket) {
            expect($ticket['status'])->toBe($status);
        }
    }
    $first = $this->getJson('/api/support-tickets?q=DEMO%20SF&per_page=15')->assertOk()
        ->assertJsonPath('meta.total', 90)->assertJsonPath('meta.last_page', 6)->assertJsonCount(15, 'data');
    $second = $this->getJson('/api/support-tickets?q=DEMO%20SF&per_page=15&page=2')->assertOk()->assertJsonCount(15, 'data');
    expect(array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')))->toBe([]);
    $this->getJson('/api/pending-tasks?filter=overdue')->assertOk()->assertJsonPath('meta.total', 12);
    $this->getJson('/api/reminders?filter=today')->assertOk()->assertJsonPath('meta.total', 12);
    $this->getJson('/api/reminders?filter=upcoming')->assertOk()->assertJsonPath('meta.total', 12);
    $this->getJson('/api/reminders?filter=completed')->assertOk()->assertJsonPath('meta.total', 12);
    $this->getJson('/api/knowledge-articles?q=placa')->assertOk()->assertJsonPath('meta.total', 3);

    $inactive = System::where('is_active', false)->firstOrFail();
    $this->getJson('/api/support-tickets?system_id='.$inactive->id)->assertOk()->assertJsonPath('meta.total', 6);
    $this->postJson('/api/support-tickets', [
        'system_id' => $inactive->id, 'contact_id' => Contact::firstOrFail()->id,
        'title' => 'Tentativa em sistema inativo', 'description' => 'A regra da API permanece válida.',
    ])->assertUnprocessable()->assertJsonValidationErrors('system_id');
    expect(SupportTicket::count())->toBe(90);
});

test('replaying demo preserves real records and IDs while refreshing relative dates', function () {
    $system = System::create(['name' => 'Sistema real', 'is_active' => false]);
    $contact = Contact::create(['name' => '[DEMO SF] cadastro manual sem chave reservada', 'email' => 'real@example.test']);
    $customer = Customer::create(['name' => 'Empresa real']);
    $ticket = SupportTicket::create(['system_id' => $system->id, 'contact_id' => $contact->id, 'customer_id' => $customer->id, 'title' => 'Histórico real', 'description' => 'Manter integralmente.']);
    $task = PendingTask::create(['support_ticket_id' => $ticket->id, 'description' => 'Pendência real', 'due_at' => now()->subDays(2)]);
    $reminder = Reminder::create(['title' => 'Lembrete real', 'scheduled_at' => now()->addDays(10)]);
    $article = KnowledgeArticle::create(['system_id' => $system->id, 'problem' => 'Problema real', 'resolution_procedure' => 'Procedimento real.']);
    $records = [$system, $contact, $customer, $ticket, $task, $reminder, $article];
    $before = array_map(fn ($record) => $record->refresh()->getRawOriginal(), $records);

    $this->seed(SupportFlowDemoSeeder::class);
    $ids = [];
    foreach (demoTableCounts() as $table => $count) {
        $this->assertDatabaseCount($table, $count + 1);
        $ids[$table] = DB::table($table)->orderBy('id')->pluck('id')->all();
    }
    $this->seed(SupportFlowDemoSeeder::class);
    foreach ($ids as $table => $expected) {
        expect(DB::table($table)->orderBy('id')->pluck('id')->all())->toBe($expected);
    }

    $edited = Contact::whereLike('name', '[DEMO SF] CON-001 | %')->sole();
    $edited->update(['name' => '[DEMO SF] CON-001 | Nome editado da demonstração']);
    $this->travelTo(CarbonImmutable::now('America/Sao_Paulo')->addDays(5));
    $this->seed(SupportFlowDemoSeeder::class);

    expect($edited->refresh()->name)->not->toContain('Nome editado');
    foreach ($ids as $table => $expected) {
        expect(DB::table($table)->orderBy('id')->pluck('id')->all())->toBe($expected);
    }
    expect(array_map(fn ($record) => $record->refresh()->getRawOriginal(), $records))->toBe($before);
    $this->getJson('/api/reminders?filter=today')->assertOk()->assertJsonPath('meta.total', 12);
    $this->getJson('/api/reminders?filter=upcoming')->assertOk()->assertJsonPath('meta.total', 13);
    expect(PendingTask::whereLike('description', '[DEMO SF] PEN-%')->where('status', 'pending')->where('due_at', '<', now())->count())->toBe(12);
});

test('duplicate reserved demo keys abort and roll back the entire generation', function () {
    $one = KnowledgeArticle::create(['problem' => '[DEMO SF] ART-001 | Duplicado A', 'resolution_procedure' => 'Não modificar A.']);
    $two = KnowledgeArticle::create(['problem' => '[DEMO SF] ART-001 | Duplicado B', 'resolution_procedure' => 'Não modificar B.']);
    $before = [$one->refresh()->getRawOriginal(), $two->refresh()->getRawOriginal()];

    expect(fn () => $this->seed(SupportFlowDemoSeeder::class))->toThrow(RuntimeException::class, 'Chave de demonstração duplicada');
    foreach (array_keys(demoTableCounts()) as $table) {
        $this->assertDatabaseCount($table, $table === 'knowledge_articles' ? 2 : 0);
    }
    expect([$one->refresh()->getRawOriginal(), $two->refresh()->getRawOriginal()])->toBe($before);
});

test('demo seeder refuses environments other than local and testing before writing', function (string $environment) {
    $this->app['env'] = $environment;
    try {
        expect(fn () => (new SupportFlowDemoSeeder)->run())->toThrow(RuntimeException::class, 'só pode ser executado');
        foreach (array_keys(demoTableCounts()) as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    } finally {
        $this->app['env'] = 'testing';
    }
})->with(['production', 'staging']);

test('demo also keeps today and future reminders valid near local midnight', function () {
    $this->travelTo(CarbonImmutable::now('America/Sao_Paulo')->startOfDay()->addMinutes(5));
    $this->seed(SupportFlowDemoSeeder::class);
    $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('data.counts.today_reminders', 12)->assertJsonPath('data.counts.overdue_tasks', 12);
    $this->getJson('/api/reminders?filter=upcoming')->assertOk()->assertJsonPath('meta.total', 12);
    expect(Reminder::whereNotNull('completed_at')->where('completed_at', '>', now())->count())->toBe(0);
});
