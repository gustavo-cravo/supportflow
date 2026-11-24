<?php

use App\Enums\PendingTaskStatus;
use App\Enums\TicketStatus;
use App\Enums\VerificationSource;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\KnowledgeArticle;
use App\Models\PendingTask;
use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Models\Reminder;
use App\Models\SupportTicket;
use App\Models\System;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SupportFlowDemoSeeder;
use Database\Seeders\SupportFlowValidationReference;
use Database\Seeders\SupportFlowValidationSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 15:00:00', 'America/Sao_Paulo'));
});
afterEach(function () {
    $this->travelBack();
    DB::disableQueryLog();
    DB::flushQueryLog();
});

function validationCounts(): array
{
    return ['systems' => 3, 'problem_categories' => 3, 'problem_subcategories' => 7, 'contacts' => 5, 'customers' => 3, 'support_tickets' => 24, 'pending_tasks' => 8, 'reminders' => 8, 'knowledge_articles' => 5, 'problem_category_system' => 6, 'problem_subcategory_system' => 14];
}
function validationSnapshot(): array
{
    $data = [];
    foreach (array_keys(validationCounts()) as $table) {
        $data[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    return $data;
}
function validationTicket(int $number): SupportTicket
{
    return SupportTicket::whereLike('title', sprintf('SFT-QA ATE-%03d | %%', $number))->sole();
}

test('validation seeder runs by its requested command and populates every domain entity', function () {
    $this->artisan('db:seed', ['--class' => 'SupportFlowValidationSeeder'])->assertSuccessful();
    foreach (validationCounts() as $table => $count) {
        $this->assertDatabaseCount($table, $count);
    }
    expect(User::count())->toBe(0);
    expect(System::where('is_active', true)->count())->toBe(2);
    expect(ProblemCategory::where('is_active', false)->count())->toBe(1);
    expect(ProblemSubcategory::where('is_active', false)->count())->toBe(2);
    expect(SupportTicket::whereNull('customer_id')->count())->toBe(6);
    expect(SupportTicket::whereNotNull('previous_ticket_id')->count())->toBe(3);
    expect(KnowledgeArticle::whereNull('system_id')->count())->toBe(2);
    expect(Customer::whereNull('document')->count())->toBe(1);
    foreach ([Contact::class, Customer::class] as $class) {
        expect($class::whereNotNull('email')->where('email', 'not like', '%@sft-qa.example.test')->count())->toBe(0);
    }
    expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);
});

test('legacy demo command delegates to the same dataset without duplication', function () {
    $this->seed(SupportFlowDemoSeeder::class);
    $before = validationSnapshot();
    $this->seed(SupportFlowValidationSeeder::class);
    expect(validationSnapshot())->toBe($before);
});

test('default DatabaseSeeder keeps validation explicitly separate', function () {
    $this->seed(DatabaseSeeder::class);
    expect(User::count())->toBe(1);
    foreach (validationCounts() as $table => $count) {
        $this->assertDatabaseCount($table, 0);
    }
});

test('seed timestamps closures historical states and follow-up chains remain coherent', function () {
    $this->seed(SupportFlowValidationSeeder::class);
    foreach (SupportTicket::with(['system', 'contact', 'customer', 'problemCategory', 'problemSubcategories', 'previousTicket'])->get() as $ticket) {
        expect($ticket->created_at->lte(now()))->toBeTrue();
        expect($ticket->created_at->lte($ticket->updated_at))->toBeTrue();
        expect($ticket->updated_at->lte(now()))->toBeTrue();
        expect(TicketStatus::tryFrom($ticket->status))->not->toBeNull();
        expect($ticket->system)->not->toBeNull();
        expect($ticket->contact)->not->toBeNull();
        expect($ticket->closed_at !== null)->toBe($ticket->status === 'closed');
        if (in_array($ticket->status, ['closed', 'resolved'], true)) {
            expect($ticket->resolution)->not->toBeEmpty();
            expect(VerificationSource::tryFrom($ticket->verification_source))->not->toBeNull();
            expect($ticket->verified_at->betweenIncluded($ticket->created_at, $ticket->updated_at))->toBeTrue();
        }
        if ($ticket->closed_at) {
            expect($ticket->closed_at->betweenIncluded($ticket->created_at, now()))->toBeTrue();
        }
        if (! $ticket->system->is_active) {
            expect($ticket->status)->toBe('closed');
            expect($ticket->closed_at->lt($ticket->system->updated_at))->toBeTrue();
        }
        foreach ($ticket->problemSubcategories as $subcategory) {
            expect($subcategory->problem_category_id)->toBe($ticket->problem_category_id);
        }
        expect($ticket->problemSubcategories->modelKeys())
            ->toBe($ticket->problem_subcategory_id === null ? [] : [$ticket->problem_subcategory_id]);
        if (! $ticket->problem_category_id) {
            expect($ticket->problem_subcategory_id)->toBeNull();
        }
        if ($parent = $ticket->previousTicket) {
            expect($ticket->created_at->gt($parent->created_at))->toBeTrue();
            expect([$ticket->system_id, $ticket->contact_id, $ticket->customer_id])->toBe([$parent->system_id, $parent->contact_id, $parent->customer_id]);
            expect($parent->previous_ticket_id)->not->toBe($ticket->id);
        }
    }
    foreach (DB::table('problem_subcategory_system')->get() as $pivot) {
        expect(DB::table('problem_category_system')->where('problem_category_id', $pivot->problem_category_id)->where('system_id', $pivot->system_id)->exists())->toBeTrue();
        expect(ProblemSubcategory::findOrFail($pivot->problem_subcategory_id)->problem_category_id)->toBe($pivot->problem_category_id);
        if ($pivot->is_active) {
            expect(DB::table('problem_category_system')->where('problem_category_id', $pivot->problem_category_id)->where('system_id', $pivot->system_id)->value('is_active'))->toBe(1);
        }
    }
});

test('reference statistics independently match API totals without duplicate pivot counts', function () {
    $this->seed(SupportFlowValidationSeeder::class);
    $reference = (new SupportFlowValidationReference)->build();
    expect($reference['counts'])->toBe(validationCounts());
    expect($reference['summary'])->toBe(['total' => 24, 'closed' => 10, 'not_closed' => 14, 'unclassified' => 3, 'subcategory_occurrences' => 17]);
    expect(array_column($reference['systems'], 'total'))->toBe([13, 7, 4]);
    expect(array_column($reference['categories'], 'total'))->toBe([12, 5, 4, 3]);
    expect(array_column($reference['subcategories'], 'total'))->toBe([5, 3, 3, 2, 2, 2, 2, 2, 1, 1, 1]);
    expect($reference['statuses'])->toMatchArray(['open' => 4, 'in_progress' => 3, 'waiting' => 4, 'resolved' => 3, 'closed' => 10]);
    expect(array_values($reference['age_buckets']))->toBe([3, 1, 3, 3, 4, 3, 3, 4]);
    expect($reference['monthly'])->toBe(['2025-10' => 1, '2026-05' => 1, '2026-07' => 1, '2026-08' => 2, '2026-09' => 3, '2026-10' => 4, '2026-11' => 12]);
    $this->getJson('/api/metrics/summary?period=all')->assertOk()->assertJsonPath('data', ['total' => 24, 'closed' => 10, 'not_closed' => 14, 'systems_count' => 3, 'unclassified' => 3, 'subcategory_occurrences' => 17]);
    foreach ($reference['filters'] as $filter) {
        $this->getJson('/api/metrics/summary?'.http_build_query($filter['params']))->assertOk()->assertJsonPath('data.total', $filter['total'])->assertJsonPath('data.closed', $filter['closed']);
    }
    foreach (['systems', 'categories', 'subcategories', 'monthly'] as $endpoint) {
        $rows = $this->getJson('/api/metrics/'.$endpoint.'?period=all')->assertOk()->json('data');
        expect(array_sum(array_column($rows, 'total')))->toBe(24);
    }
    $this->getJson('/api/metrics/monthly')->assertOk()->assertJsonPath('data', [
        ['month' => '2026-06', 'total' => 0], ['month' => '2026-07', 'total' => 1], ['month' => '2026-08', 'total' => 2], ['month' => '2026-09', 'total' => 3], ['month' => '2026-10', 'total' => 4], ['month' => '2026-11', 'total' => 12],
    ]);
});

test('relative and custom periods contain the planned quantities', function (string $period, int $total) {
    $this->seed(SupportFlowValidationSeeder::class);
    $this->getJson('/api/metrics/summary?period='.$period)->assertOk()->assertJsonPath('data.total', $total);
})->with([['all', 24], ['this_month', 12], ['last_30_days', 13], ['last_3_months', 19], ['last_6_months', 22], ['this_year', 23]]);

test('dependent options respect active shared and exclusive classification availability', function () {
    $this->seed(SupportFlowValidationSeeder::class);
    $systems = System::orderBy('name')->get();
    $access = ProblemCategory::whereLike('name', 'SFT-QA CAT-001 | %')->sole();
    $plate = ProblemCategory::whereLike('name', 'SFT-QA CAT-002 | %')->sole();
    foreach ([$systems[0]->id => 2, $systems[1]->id => 1, $systems[2]->id => 0] as $id => $count) {
        $this->getJson('/api/problem-categories?selectable=1&system_id='.$id)->assertOk()->assertJsonPath('meta.total', $count);
    }
    $this->getJson('/api/problem-subcategories?selectable=1&system_id='.$systems[0]->id.'&problem_category_id='.$access->id)->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/problem-subcategories?selectable=1&system_id='.$systems[1]->id.'&problem_category_id='.$plate->id)->assertOk()->assertJsonPath('meta.total', 0);
    $this->postJson('/api/support-tickets', ['system_id' => $systems[2]->id, 'contact_id' => Contact::first()->id, 'title' => 'Não criar em sistema inativo', 'description' => 'Regra preservada.'])->assertUnprocessable()->assertJsonValidationErrors('system_id');
    $legacy = validationTicket(21);
    $this->patchJson('/api/support-tickets/'.$legacy->id, ['description' => 'Edição não relacionada preserva classificação.'])->assertOk()->assertJsonPath('data.problem_category_id', $legacy->problem_category_id);
});

test('activities dashboard pagination and knowledge search have meaningful data', function () {
    $this->seed(SupportFlowValidationSeeder::class);
    $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('data.counts', ['open' => 4, 'in_progress' => 3, 'waiting' => 4, 'overdue_tasks' => 2, 'today_reminders' => 2]);
    $this->getJson('/api/support-tickets?q=SFT-QA&per_page=15')->assertOk()->assertJsonPath('meta.total', 24)->assertJsonCount(15, 'data')->assertJsonPath('meta.last_page', 2);
    $this->getJson('/api/knowledge-articles?q=placa')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/pending-tasks?filter=overdue')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/reminders?filter=today')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/reminders?filter=upcoming')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/reminders?filter=completed')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/reminders?filter=overdue')->assertOk()->assertJsonPath('meta.total', 4);
    expect(PendingTask::where('status', PendingTaskStatus::Pending->value)->count())->toBe(5);
    expect(PendingTask::where('status', PendingTaskStatus::Completed->value)->count())->toBe(3);
    expect(Reminder::whereNotNull('completed_at')->count())->toBe(2);
    expect(Reminder::whereNull('support_ticket_id')->count())->toBe(4);
    expect(PendingTask::whereHas('supportTicket', fn ($q) => $q->where('status', 'closed'))->where('status', 'pending')->count())->toBeGreaterThan(0);
    foreach (PendingTask::with('supportTicket')->get() as $task) {
        expect($task->created_at->lte($task->updated_at))->toBeTrue();
        if ($task->supportTicket) {
            expect($task->created_at->gte($task->supportTicket->created_at))->toBeTrue();
        }
        if ($task->completed_at) {
            expect($task->completed_at->betweenIncluded($task->created_at, now()))->toBeTrue();
        }
    }
    foreach (Reminder::with('supportTicket')->get() as $reminder) {
        expect($reminder->created_at->lt($reminder->scheduled_at))->toBeTrue();
        if ($reminder->supportTicket) {
            expect($reminder->created_at->gte($reminder->supportTicket->created_at))->toBeTrue();
        }
        if ($reminder->completed_at) {
            expect($reminder->completed_at->betweenIncluded($reminder->scheduled_at, now()))->toBeTrue();
        }
    }
});

test('seeded return closures are independent and retain open pending task rules', function () {
    $this->seed(SupportFlowValidationSeeder::class);
    $parent = validationTicket(9);
    $return = validationTicket(1);
    $before = $parent->getRawOriginal();
    $this->postJson('/api/support-tickets/'.$return->id.'/close')->assertOk();
    expect($parent->refresh()->getRawOriginal())->toBe($before);
    $pending = validationTicket(4);
    $this->postJson('/api/support-tickets/'.$pending->id.'/close')->assertConflict();
    $this->postJson('/api/support-tickets/'.$pending->id.'/close', ['confirm_open_tasks' => true])->assertOk();
    expect($pending->pendingTasks()->where('status', 'pending')->count())->toBe(1);
});

test('reexecution keeps IDs dates manual edits and all preexisting data untouched', function () {
    $system = System::create(['name' => 'Sistema real', 'is_active' => false]);
    $contact = Contact::create(['name' => 'Contato real']);
    $customer = Customer::create(['name' => 'Empresa real']);
    $category = ProblemCategory::create(['name' => 'Falha de acesso']);
    $ticket = SupportTicket::create(['system_id' => $system->id, 'contact_id' => $contact->id, 'customer_id' => $customer->id, 'title' => 'Atendimento real', 'description' => 'Preservar integralmente.']);
    $task = PendingTask::create(['support_ticket_id' => $ticket->id, 'description' => 'Pendência real']);
    $reminder = Reminder::create(['title' => 'Lembrete real', 'scheduled_at' => now()->addDay()]);
    $article = KnowledgeArticle::create(['problem' => 'Artigo real', 'resolution_procedure' => 'Preservar.']);
    $records = [$system, $contact, $customer, $category, $ticket, $task, $reminder, $article];
    $before = array_map(fn ($r) => $r->refresh()->getRawOriginal(), $records);
    $this->seed(SupportFlowValidationSeeder::class);
    expect(array_map(fn ($r) => $r->refresh()->getRawOriginal(), $records))->toBe($before);
    validationTicket(1)->update(['description' => validationTicket(1)->description."\nEdição manual preservada.", 'status' => 'waiting']);
    $snapshot = validationSnapshot();
    $this->seed(SupportFlowValidationSeeder::class);
    $this->travel(5)->days();
    DB::enableQueryLog();
    $this->seed(SupportFlowValidationSeeder::class);
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();
    expect(validationSnapshot())->toBe($snapshot);
    foreach ($queries as $query) {
        expect($query)->not->toMatch('/^\s*(insert|update|delete)\b/i');
    }
    expect((new SupportFlowValidationReference)->build()['summary']['total'])->toBe(24);
});

test('reserved namespace conflicts cause no overwrites or partial writes', function () {
    $collision = KnowledgeArticle::create(['problem' => 'SFT-QA ART-001 | Cadastro manual', 'resolution_procedure' => 'Dado que não pertence ao seeder.']);
    $before = validationSnapshot();
    expect(fn () => $this->seed(SupportFlowValidationSeeder::class))->toThrow(RuntimeException::class);
    expect(validationSnapshot())->toBe($before);
    expect($collision->refresh()->resolution_procedure)->toBe('Dado que não pertence ao seeder.');
});

test('renamed or duplicated identities fail safely without reconstructing or duplicating the dataset', function (bool $duplicate) {
    $this->seed(SupportFlowValidationSeeder::class);
    if ($duplicate) {
        $record = Contact::whereLike('name', 'SFT-QA CON-001 | %')->sole();
        $record->replicate()->save();
    } else {
        validationTicket(1)->update(['title' => 'Nome alterado sem a chave']);
    }
    $before = validationSnapshot();
    expect(fn () => $this->seed(SupportFlowValidationSeeder::class))->toThrow(RuntimeException::class);
    expect(validationSnapshot())->toBe($before);
})->with([false, true]);

test('a late insertion failure rolls back the complete dataset', function () {
    KnowledgeArticle::creating(function () {
        throw new RuntimeException('Falha de teste no final da população.');
    });
    try {
        expect(fn () => $this->seed(SupportFlowValidationSeeder::class))->toThrow(RuntimeException::class, 'Falha de teste');
        foreach (validationCounts() as $table => $count) {
            $this->assertDatabaseCount($table, 0);
        }
    } finally {
        KnowledgeArticle::flushEventListeners();
    }
});

test('guard blocks nonlocal environments even when command force is supplied', function (string $environment) {
    $this->app['env'] = $environment;
    try {
        expect(fn () => (new SupportFlowValidationSeeder)->run())->toThrow(RuntimeException::class, 'só pode ser executado');
        expect(fn () => (new SupportFlowDemoSeeder)->run())->toThrow(RuntimeException::class, 'só pode ser executado');
        expect(fn () => Artisan::call('db:seed', ['--class' => SupportFlowValidationSeeder::class, '--force' => true]))->toThrow(RuntimeException::class, 'só pode ser executado');
        foreach (validationCounts() as $table => $count) {
            $this->assertDatabaseCount($table, 0);
        }
    } finally {
        $this->app['env'] = 'testing';
    }
})->with(['production', 'staging']);

test('opening and reminder dates remain coherent around midnight and year boundaries', function (string $date) {
    $this->travelTo(CarbonImmutable::parse($date, 'America/Sao_Paulo'));
    $this->seed(SupportFlowValidationSeeder::class);
    expect(SupportTicket::where('created_at', '>', now())->count())->toBe(0);
    expect(SupportTicket::whereColumn('closed_at', '<', 'created_at')->count())->toBe(0);
    expect(SupportTicket::whereDate('created_at', today())->count())->toBe(3);
    expect(Reminder::whereNull('completed_at')->whereDate('scheduled_at', today())->count())->toBe(2);
    expect(Reminder::where('completed_at', '>', now())->count())->toBe(0);
})->with(['2026-11-23 00:00:00', '2027-01-01 00:05:00', '2028-02-29 23:59:59']);

test('seeded reports keep metric totals and exclude individual identifying fixture details', function () {
    $this->seed(SupportFlowValidationSeeder::class);
    $before = validationSnapshot();
    $pdf = $this->getJson('/api/metrics/export?period=all&format=pdf')->assertOk()->getContent();
    expect($pdf)->toStartWith('%PDF-');
    $xlsx = $this->getJson('/api/metrics/export?period=all&format=xlsx')->assertOk()->getContent();
    $path = tempnam(sys_get_temp_dir(), 'sft-seeder-');
    file_put_contents($path, $xlsx);
    try {
        $book = IOFactory::load($path);
        expect($book->getSheetByName('Resumo Geral')->getCell('D13')->getValue())->toBe(24);
        expect($book->getSheetByName('Resumo Geral')->getCell('D14')->getValue())->toBe(10);
        foreach ($book->getAllSheets() as $sheet) {
            foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                $value = (string) $sheet->getCell($coordinate)->getValue();
                expect($value)->not->toContain('SFT-QA ATE-', 'SFT-QA CON-', 'SFT-QA CLI-', '@sft-qa.example.test', '00000000000100');
            }
        }
        $book->disconnectWorksheets();
    } finally {
        unlink($path);
    }
    expect(validationSnapshot())->toBe($before);
});
