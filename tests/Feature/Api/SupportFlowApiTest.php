<?php

use App\Models\Contact;
use App\Models\Customer;
use App\Models\KnowledgeArticle;
use App\Models\PendingTask;
use App\Models\Reminder;
use App\Models\SupportTicket;
use App\Models\System;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00', 'America/Sao_Paulo'));
    $this->system = System::create(['name' => 'ERP']);
    $this->contact = Contact::create(['name' => 'Ana', 'phone' => '11999999999', 'email' => 'ana@example.com']);
    $this->customer = Customer::create(['name' => 'Loja Central']);
    $this->ticketData = [
        'system_id' => $this->system->id,
        'contact_id' => $this->contact->id,
        'title' => 'Falha na nota',
        'description' => 'Erro na emissão fiscal.',
    ];
});

afterEach(function () {
    $this->travelBack();
    Model::preventLazyLoading(false);
});

function supportFlowApiPayload(string $resource, array $ticketData): array
{
    return match ($resource) {
        'systems' => ['name' => 'Financeiro', 'description' => 'Contas a pagar'],
        'contacts' => ['name' => 'Bruno', 'phone' => '1133334444', 'email' => 'bruno@example.com'],
        'customers' => ['name' => 'Loja Norte', 'notes' => 'Matriz'],
        'support-tickets' => $ticketData,
        'pending-tasks' => ['description' => 'Solicitar log', 'due_at' => '2026-10-22 09:00:00'],
        'reminders' => ['title' => 'Retornar ligação', 'scheduled_at' => '2026-10-22 09:00:00'],
        'knowledge-articles' => ['problem' => 'Sem rede', 'resolution_procedure' => 'Verificar o cabo'],
    };
}

dataset('api resources', ['systems', 'contacts', 'customers', 'support-tickets', 'pending-tasks', 'reminders', 'knowledge-articles']);

test('resources support creation, detail, update, pagination and reject deletion', function (string $resource) {
    $payload = supportFlowApiPayload($resource, $this->ticketData);
    $created = $this->postJson('/api/'.$resource, $payload)->assertCreated()->assertJsonStructure(['data' => ['id', 'created_at', 'updated_at']]);
    $id = $created->json('data.id');
    $this->getJson('/api/'.$resource.'/'.$id)->assertOk()->assertJsonPath('data.id', $id);
    $field = array_key_first($payload);
    if ($resource === 'support-tickets') {
        $field = 'title';
    }
    $payload[$field] = 'Atualizado';
    $this->patchJson('/api/'.$resource.'/'.$id, [$field => 'Atualizado'])->assertOk()->assertJsonPath('data.'.$field, 'Atualizado');
    $this->putJson('/api/'.$resource.'/'.$id, $payload)->assertOk();
    $this->getJson('/api/'.$resource.'?per_page=1')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
    $this->deleteJson('/api/'.$resource.'/'.$id)->assertStatus(405);
    $this->getJson('/api/'.$resource.'/999999')->assertNotFound();
})->with('api resources');

test('creation validates required fields', function (string $resource, array $fields) {
    $this->postJson('/api/'.$resource, [])->assertUnprocessable()->assertJsonValidationErrors($fields);
})->with([
    ['systems', ['name']], ['contacts', ['name']], ['customers', ['name']],
    ['support-tickets', ['system_id', 'contact_id', 'title', 'description']],
    ['pending-tasks', ['description']], ['reminders', ['title', 'scheduled_at']],
    ['knowledge-articles', ['problem', 'resolution_procedure']],
]);

test('API errors are JSON even without an Accept header', function () {
    $this->get('/api/systems/999999')->assertNotFound()->assertHeader('Content-Type', 'application/json');
    $this->post('/api/systems', [])->assertUnprocessable()->assertJsonValidationErrors('name');
});

test('systems can be filtered, activated and deactivated without losing history', function () {
    $ticket = SupportTicket::create($this->ticketData);
    System::create(['name' => 'Financeiro', 'is_active' => false]);
    $this->patchJson('/api/systems/'.$this->system->id, ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    $this->getJson('/api/systems?name=ERP&is_active=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->system->id);
    $this->getJson('/api/support-tickets/'.$ticket->id)->assertOk()->assertJsonPath('data.system.is_active', false);
    $this->getJson('/api/support-tickets?system_id='.$this->system->id)->assertOk()->assertJsonPath('data.0.id', $ticket->id);
    $this->patchJson('/api/support-tickets/'.$ticket->id, ['title' => 'Histórico atualizado', 'system_id' => $this->system->id])->assertOk();
    $this->postJson('/api/support-tickets', $this->ticketData)->assertUnprocessable()->assertJsonValidationErrors('system_id');
    $this->patchJson('/api/systems/'.$this->system->id, ['is_active' => true])->assertOk();
    $this->postJson('/api/support-tickets', $this->ticketData)->assertCreated()->assertJsonPath('data.status', 'open');
});

test('ticket reassignment cannot select an inactive system', function () {
    $ticket = SupportTicket::create($this->ticketData);
    $inactive = System::create(['name' => 'Legado', 'is_active' => false]);
    $this->patchJson('/api/support-tickets/'.$ticket->id, ['system_id' => $inactive->id])->assertUnprocessable()->assertJsonValidationErrors('system_id');
    expect($ticket->fresh()->system_id)->toBe($this->system->id);
});

test('ticket relationships reject nonexistent records', function (string $field) {
    $this->postJson('/api/support-tickets', array_merge($this->ticketData, [$field => 999999]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['system_id', 'contact_id', 'customer_id', 'previous_ticket_id']);

test('optional relationships also validate identifiers', function (string $resource, string $field) {
    $payload = supportFlowApiPayload($resource, $this->ticketData);
    $this->postJson('/api/'.$resource, [...$payload, $field => 999999])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([['pending-tasks', 'support_ticket_id'], ['reminders', 'support_ticket_id'], ['knowledge-articles', 'system_id']]);

test('related tickets close independently and expose their direct links', function () {
    $previous = SupportTicket::create($this->ticketData);
    $response = $this->postJson('/api/support-tickets', [...$this->ticketData, 'previous_ticket_id' => $previous->id, 'customer_id' => $this->customer->id])->assertCreated();
    $followUp = $response->json('data.id');
    $this->getJson('/api/support-tickets/'.$followUp)->assertOk()
        ->assertJsonPath('data.previous_ticket.id', $previous->id)->assertJsonPath('data.customer.id', $this->customer->id);
    $this->getJson('/api/support-tickets/'.$previous->id.'/related')->assertOk()->assertJsonPath('data.0.id', $followUp);
    $this->getJson('/api/support-tickets/'.$followUp.'/related')->assertOk()->assertJsonPath('data.0.id', $previous->id);
    $this->postJson('/api/support-tickets/'.$previous->id.'/close')->assertOk()->assertJsonPath('data.status', 'closed');
    expect(SupportTicket::findOrFail($followUp)->status)->toBe('open')
        ->and(SupportTicket::findOrFail($followUp)->closed_at)->toBeNull();
    $originalClosure = $previous->fresh()->closed_at->toDateTimeString();
    $this->travel(1)->hours();
    $this->postJson('/api/support-tickets/'.$followUp.'/close')->assertOk();
    expect($previous->fresh()->closed_at->toDateTimeString())->toBe($originalClosure);
});

test('ticket links reject self-references and cycles of multiple levels', function () {
    $first = SupportTicket::create($this->ticketData);
    $second = SupportTicket::create([...$this->ticketData, 'previous_ticket_id' => $first->id]);
    $third = SupportTicket::create([...$this->ticketData, 'previous_ticket_id' => $second->id]);
    foreach ([$first->id, $second->id, $third->id] as $parentId) {
        $this->patchJson('/api/support-tickets/'.$first->id, ['previous_ticket_id' => $parentId])
            ->assertUnprocessable()->assertJsonValidationErrors('previous_ticket_id');
    }
    expect($first->fresh()->previous_ticket_id)->toBeNull();
    $this->patchJson('/api/support-tickets/'.$third->id, ['previous_ticket_id' => null])->assertOk();
    $this->patchJson('/api/support-tickets/'.$first->id, ['previous_ticket_id' => $third->id])->assertOk();
});

test('every closing path warns about pending tasks and accepts only explicit confirmation', function (string $path) {
    $ticket = SupportTicket::create($this->ticketData);
    $task = PendingTask::create(['support_ticket_id' => $ticket->id, 'description' => 'Aguardar log']);
    PendingTask::create(['support_ticket_id' => $ticket->id, 'description' => 'Concluída', 'status' => 'completed', 'completed_at' => now()]);
    $payload = $path === '/close' ? [] : ['status' => 'closed'];
    $method = $path === '/close' ? 'POST' : ($path === '/put' ? 'PUT' : 'PATCH');
    $suffix = $path === '/put' ? '' : $path;
    if ($path === '/put') {
        $payload = [...$this->ticketData, ...$payload];
    }
    $url = '/api/support-tickets/'.$ticket->id.$suffix;
    foreach ([[], ['confirm_open_tasks' => false]] as $confirmation) {
        $this->json($method, $url, [...$payload, ...$confirmation])->assertConflict()
            ->assertJsonPath('code', 'open_pending_tasks')->assertJsonPath('open_tasks_count', 1)
            ->assertJsonPath('pending_tasks.0.id', $task->id);
    }
    $this->json($method, $url, [...$payload, 'confirm_open_tasks' => 'true'])->assertUnprocessable()->assertJsonValidationErrors('confirm_open_tasks');
    $this->json($method, $url, [...$payload, 'confirm_open_tasks' => 1])->assertUnprocessable()->assertJsonValidationErrors('confirm_open_tasks');
    expect($ticket->fresh()->status)->toBe('open')->and($ticket->fresh()->closed_at)->toBeNull();
    $this->json($method, $url, [...$payload, 'confirm_open_tasks' => true])->assertOk()
        ->assertJsonPath('data.status', 'closed')->assertJsonPath('data.closed_at', '2026-10-21T10:00:00-03:00');
    expect($task->fresh()->status)->toBe('pending')->and($task->fresh()->completed_at)->toBeNull();
})->with(['/close', '/status', '', '/put']);

test('a closing conflict rolls back all submitted changes', function () {
    $ticket = SupportTicket::create($this->ticketData);
    PendingTask::create(['support_ticket_id' => $ticket->id, 'description' => 'Retorno']);
    $this->patchJson('/api/support-tickets/'.$ticket->id, ['title' => 'Não deve persistir', 'status' => 'closed'])->assertConflict();
    expect($ticket->fresh()->title)->toBe($this->ticketData['title']);
});

test('completed and unrelated tasks do not block closing', function () {
    $ticket = SupportTicket::create($this->ticketData);
    PendingTask::create(['description' => 'Independente']);
    PendingTask::create(['support_ticket_id' => $ticket->id, 'description' => 'Terminada', 'status' => 'completed', 'completed_at' => now()]);
    Reminder::create(['support_ticket_id' => $ticket->id, 'title' => 'Lembrete aberto', 'scheduled_at' => now()->addDay()]);
    $this->postJson('/api/support-tickets/'.$ticket->id.'/close')->assertOk();
});

test('closure is idempotent and reopening clears only its own closure date', function () {
    $ticket = SupportTicket::create($this->ticketData);
    $first = $this->postJson('/api/support-tickets/'.$ticket->id.'/close')->assertOk()->json('data.closed_at');
    $this->travel(1)->days();
    $this->postJson('/api/support-tickets/'.$ticket->id.'/close')->assertOk()->assertJsonPath('data.closed_at', $first);
    $this->patchJson('/api/support-tickets/'.$ticket->id.'/status', ['status' => 'waiting'])->assertOk()->assertJsonPath('data.closed_at', null);
});

test('all defined statuses are accepted without imposing transitions', function (string $status) {
    $ticket = SupportTicket::create($this->ticketData);
    $this->patchJson('/api/support-tickets/'.$ticket->id.'/status', ['status' => $status])->assertOk()->assertJsonPath('data.status', $status);
})->with(['open', 'in_progress', 'waiting', 'resolved', 'closed']);

test('invalid statuses and manual closure dates are rejected', function () {
    $ticket = SupportTicket::create($this->ticketData);
    $this->patchJson('/api/support-tickets/'.$ticket->id.'/status', ['status' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->patchJson('/api/support-tickets/'.$ticket->id, ['status' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->patchJson('/api/support-tickets/'.$ticket->id, ['closed_at' => '2026-10-21 10:00:00'])->assertUnprocessable()->assertJsonValidationErrors('closed_at');
    $this->postJson('/api/support-tickets', [...$this->ticketData, 'status' => 'closed'])->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('resolution verification supports either source and keeps its date and notes', function (string $source) {
    $ticket = SupportTicket::create($this->ticketData);
    $this->postJson('/api/support-tickets/'.$ticket->id.'/close', [
        'resolution' => 'Configuração corrigida', 'verification_source' => $source,
        'verified_at' => '2026-10-21 09:45:00', 'verified_by' => 'Pessoa responsável',
        'verification_notes' => 'Emissão testada com sucesso.',
    ])->assertOk()->assertJsonPath('data.verification_source', $source)
        ->assertJsonPath('data.verified_at', '2026-10-21T09:45:00-03:00')
        ->assertJsonPath('data.verification_notes', 'Emissão testada com sucesso.');
    $this->patchJson('/api/support-tickets/'.$ticket->id, ['verification_notes' => 'Nova observação'])->assertOk();
    $this->patchJson('/api/support-tickets/'.$ticket->id, ['verification_source' => null])->assertUnprocessable()->assertJsonValidationErrors('verification_source');
    $this->patchJson('/api/support-tickets/'.$ticket->id, ['verified_at' => null])->assertUnprocessable()->assertJsonValidationErrors('verified_at');
    $this->patchJson('/api/support-tickets/'.$ticket->id, ['verification_source' => null, 'verified_at' => null])->assertOk()
        ->assertJsonPath('data.verification_source', null)->assertJsonPath('data.verified_at', null);
})->with(['customer', 'responsible_department']);

test('verification rejects unsupported sources and incomplete or invalid dates', function (array $verification, string $field) {
    $ticket = SupportTicket::create($this->ticketData);
    $this->postJson('/api/support-tickets/'.$ticket->id.'/close', $verification)->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($ticket->fresh()->status)->toBe('open');
})->with([
    [['verification_source' => 'other', 'verified_at' => '2026-10-21 10:00:00'], 'verification_source'],
    [['verification_source' => 'customer'], 'verified_at'],
    [['verified_at' => '2026-10-21 10:00:00'], 'verification_source'],
    [['verification_source' => 'customer', 'verified_at' => 'invalid'], 'verified_at'],
]);

test('tasks support completion, reopening and calculated overdue filtering', function () {
    $ticket = SupportTicket::create($this->ticketData);
    $past = PendingTask::create(['support_ticket_id' => $ticket->id, 'description' => 'Atrasada', 'due_at' => now()->subHour()]);
    PendingTask::create(['description' => 'Sem prazo']);
    PendingTask::create(['description' => 'Futura', 'due_at' => now()->addDay()]);
    PendingTask::create(['description' => 'Concluída', 'due_at' => now()->subDay(), 'status' => 'completed', 'completed_at' => now()]);
    $this->getJson('/api/pending-tasks?filter=overdue')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $past->id)->assertJsonPath('data.0.is_overdue', true);
    $this->getJson('/api/pending-tasks?filter=open')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/api/pending-tasks?filter=completed')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/pending-tasks?status=pending&support_ticket_id='.$ticket->id)->assertOk()->assertJsonCount(1, 'data');
    $result = $this->postJson('/api/pending-tasks/'.$past->id.'/complete', ['completion_notes' => 'Log recebido'])->assertOk()
        ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.is_overdue', false)->assertJsonPath('data.completion_notes', 'Log recebido');
    $this->travel(1)->hours();
    $this->postJson('/api/pending-tasks/'.$past->id.'/complete')->assertOk()->assertJsonPath('data.completed_at', $result->json('data.completed_at'));
    $this->patchJson('/api/pending-tasks/'.$past->id, ['status' => 'pending'])->assertOk()->assertJsonPath('data.completed_at', null);
    $this->patchJson('/api/pending-tasks/'.$past->id, ['status' => 'completed'])->assertOk()->assertJsonPath('data.completed_at', '2026-10-21T11:00:00-03:00');
    $this->patchJson('/api/pending-tasks/'.$past->id, ['status' => 'invalid'])->assertUnprocessable();
    $this->patchJson('/api/pending-tasks/'.$past->id, ['completed_at' => '2026-10-21 10:00:00'])->assertUnprocessable();
});

test('independent reminders use local calendar boundaries and completion is idempotent', function () {
    $this->travelTo(Carbon::parse('2026-10-21 23:30:00', 'America/Sao_Paulo'));
    $yesterday = Reminder::create(['title' => 'Ontem', 'scheduled_at' => '2026-10-20 23:59:59']);
    $today = Reminder::create(['title' => 'Hoje', 'scheduled_at' => '2026-10-21 23:59:59']);
    Reminder::create(['title' => 'Hoje cedo', 'scheduled_at' => '2026-10-21 00:00:00']);
    $tomorrow = Reminder::create(['title' => 'Amanhã', 'scheduled_at' => '2026-10-22 00:00:00']);
    Reminder::create(['title' => 'Feito hoje', 'scheduled_at' => now(), 'completed_at' => now()]);
    $this->getJson('/api/reminders?filter=today')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/reminders?filter=overdue')->assertOk()->assertJsonCount(2, 'data')->assertJsonFragment(['id' => $yesterday->id]);
    $this->getJson('/api/reminders?filter=upcoming')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $tomorrow->id);
    $this->getJson('/api/reminders?filter=completed')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/reminders?filter=pending')->assertOk()->assertJsonCount(4, 'data');
    $this->getJson('/api/reminders/'.$today->id)->assertOk()->assertJsonPath('data.support_ticket_id', null)->assertJsonPath('data.scheduled_at', '2026-10-21T23:59:59-03:00');
    $result = $this->postJson('/api/reminders/'.$today->id.'/complete')->assertOk();
    $this->travel(1)->hours();
    $this->postJson('/api/reminders/'.$today->id.'/complete')->assertOk()->assertJsonPath('data.completed_at', $result->json('data.completed_at'));
});

test('reminders and tasks can attach to and detach from tickets', function (string $resource) {
    $ticket = SupportTicket::create($this->ticketData);
    $payload = supportFlowApiPayload($resource, $this->ticketData);
    $created = $this->postJson('/api/'.$resource, [...$payload, 'support_ticket_id' => $ticket->id])->assertCreated()->assertJsonPath('data.support_ticket.id', $ticket->id);
    $this->getJson('/api/'.$resource.'?support_ticket_id='.$ticket->id)->assertOk()->assertJsonCount(1, 'data');
    $this->patchJson('/api/'.$resource.'/'.$created->json('data.id'), ['support_ticket_id' => null])->assertOk()->assertJsonPath('data.support_ticket', null);
})->with(['pending-tasks', 'reminders']);

test('ticket filters combine identifiers, statuses, text and inclusive calendar dates', function () {
    $matching = SupportTicket::create([...$this->ticketData, 'customer_id' => $this->customer->id, 'status' => 'waiting']);
    $matching->created_at = '2026-10-21 23:59:59';
    $matching->save();
    SupportTicket::create([...$this->ticketData, 'status' => 'resolved']);
    SupportTicket::create([...$this->ticketData, 'system_id' => System::create(['name' => 'Outro'])->id]);
    $query = http_build_query([
        'system_id' => $this->system->id, 'contact_id' => $this->contact->id, 'customer_id' => $this->customer->id,
        'status' => 'waiting', 'q' => 'fiscal', 'created_from' => '2026-10-21', 'created_to' => '2026-10-21',
    ]);
    $this->getJson('/api/support-tickets?'.$query)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id);
    $this->getJson('/api/support-tickets?created_to=2026-10-20')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/support-tickets?q=Falha')->assertOk()->assertJsonCount(3, 'data');
});

test('contacts and customers search every available contact field', function (string $resource, string $model) {
    $record = $model::create(['name' => 'Pessoa', 'phone' => '11987654321', 'email' => 'unique@example.com', 'notes' => 'Marcador especial']);
    foreach (['Pessoa', '987654321', 'unique@example.com', 'Marcador'] as $search) {
        $this->getJson('/api/'.$resource.'?q='.urlencode($search))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $record->id);
    }
})->with([['contacts', Contact::class], ['customers', Customer::class]]);

test('knowledge search covers problem, procedure and system and permits general articles', function () {
    $article = KnowledgeArticle::create(['system_id' => $this->system->id, 'problem' => 'Erro fiscal', 'resolution_procedure' => 'Revisar certificado']);
    KnowledgeArticle::create(['problem' => 'Sem rede', 'resolution_procedure' => 'Verificar cabo']);
    foreach (['fiscal', 'certificado', 'ERP'] as $search) {
        $this->getJson('/api/knowledge-articles?q='.$search)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $article->id);
    }
    $this->getJson('/api/knowledge-articles?system_id='.$this->system->id)->assertOk()->assertJsonCount(1, 'data');
    $this->patchJson('/api/knowledge-articles/'.$article->id, ['system_id' => null])->assertOk()->assertJsonPath('data.system', null);
});

test('pagination preserves filters and orders pages deterministically', function () {
    Contact::create(['name' => 'Ana B']);
    Contact::create(['name' => 'Ana C']);
    $result = $this->getJson('/api/contacts?q=Ana&per_page=1&page=2')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.current_page', 2);
    expect($result->json('links.next'))->toContain('q=Ana')->toContain('per_page=1');
});

test('invalid filters, dates, lengths and emails return validation errors', function (string $url, string $field) {
    $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['/api/systems?is_active=bad', 'is_active'], ['/api/contacts?per_page=101', 'per_page'],
    ['/api/contacts?page=0', 'page'], ['/api/support-tickets?status=bad', 'status'],
    ['/api/support-tickets?system_id=bad', 'system_id'], ['/api/support-tickets?created_from=bad', 'created_from'],
    ['/api/support-tickets?created_from=2026-10-22&created_to=2026-10-21', 'created_to'],
    ['/api/pending-tasks?filter=bad', 'filter'], ['/api/reminders?filter=bad', 'filter'],
]);

test('writes validate supplied fields rather than silently accepting invalid data', function () {
    $this->postJson('/api/contacts', ['name' => 'Ana', 'email' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->postJson('/api/systems', ['name' => str_repeat('a', 256)])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->patchJson('/api/contacts/'.$this->contact->id, ['name' => null])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson('/api/reminders', ['title' => 'Ligar', 'scheduled_at' => '2026-02-30 10:00:00'])->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');
    $this->postJson('/api/pending-tasks', ['description' => 'Ligar', 'status' => 'completed'])->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('eager loading keeps relationship serialization bounded as the list grows', function () {
    foreach (range(1, 5) as $number) {
        SupportTicket::create([...$this->ticketData, 'title' => 'Caso '.$number, 'customer_id' => $this->customer->id]);
    }
    Model::preventLazyLoading();
    DB::enableQueryLog();
    $this->getJson('/api/support-tickets?per_page=1')->assertOk()->assertJsonStructure(['data' => [['system', 'contact', 'customer']]]);
    $oneRowQueries = count(DB::getQueryLog());
    DB::flushQueryLog();
    $this->getJson('/api/support-tickets?per_page=5')->assertOk()->assertJsonCount(5, 'data');
    $fiveRowQueries = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($fiveRowQueries)->toBe($oneRowQueries);
});
