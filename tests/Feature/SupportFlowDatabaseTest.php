<?php

use App\Models\Contact;
use App\Models\Customer;
use App\Models\KnowledgeArticle;
use App\Models\PendingTask;
use App\Models\Reminder;
use App\Models\SupportTicket;
use App\Models\System;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function createSupportFlowTicket(array $attributes = []): SupportTicket
{
    return SupportTicket::create(array_merge([
        'system_id' => System::create(['name' => 'ERP'])->getKey(),
        'contact_id' => Contact::create(['name' => 'Ana'])->getKey(),
        'title' => 'Falha ao emitir nota',
        'description' => 'Erro na emissão da nota fiscal.',
    ], $attributes));
}

test('tickets relate to independent contacts, customers and systems', function () {
    $customer = Customer::create(['name' => 'Loja Central']);
    $ticket = createSupportFlowTicket(['customer_id' => $customer->getKey()]);
    $withoutCustomer = createSupportFlowTicket();

    expect($ticket->system->supportTickets->modelKeys())->toBe([$ticket->getKey()])
        ->and($ticket->contact->supportTickets->modelKeys())->toBe([$ticket->getKey()])
        ->and($ticket->customer->is($customer))->toBeTrue()
        ->and($customer->supportTickets->modelKeys())->toBe([$ticket->getKey()])
        ->and($withoutCustomer->customer)->toBeNull()
        ->and($ticket->status)->toBe('open');
});

test('tickets require a system and a contact', function (string $missing) {
    $attributes = [
        'system_id' => System::create(['name' => 'ERP'])->getKey(),
        'contact_id' => Contact::create(['name' => 'Ana'])->getKey(),
        'title' => 'Falha',
        'description' => 'Descrição',
    ];
    unset($attributes[$missing]);

    expect(fn () => SupportTicket::create($attributes))->toThrow(QueryException::class);
})->with(['system_id', 'contact_id']);

test('ticket foreign keys reject nonexistent records', function (string $foreignKey) {
    expect(fn () => createSupportFlowTicket([$foreignKey => 999999]))->toThrow(QueryException::class);
})->with(['system_id', 'contact_id', 'customer_id', 'previous_ticket_id']);

test('follow-up tickets keep independent statuses and closure dates', function () {
    $previous = createSupportFlowTicket([
        'status' => 'closed',
        'resolution' => 'Corrigida a configuração fiscal.',
        'verification_notes' => 'Emissão testada e confirmada pelo setor fiscal.',
        'verified_by' => 'Setor fiscal da Loja Central',
        'verified_at' => '2026-10-20 15:00:00',
        'closed_at' => '2026-10-20 15:10:00',
    ]);
    $followUp = createSupportFlowTicket(['previous_ticket_id' => $previous->getKey()]);

    expect($followUp->previousTicket->is($previous))->toBeTrue()
        ->and($previous->followUpTickets->modelKeys())->toBe([$followUp->getKey()])
        ->and($followUp->closed_at)->toBeNull()
        ->and($followUp->status)->toBe('open')
        ->and($previous->fresh()->closed_at->format('Y-m-d H:i:s'))->toBe('2026-10-20 15:10:00')
        ->and($previous->verified_at->format('Y-m-d H:i:s'))->toBe('2026-10-20 15:00:00');

    $followUp->update(['status' => 'closed', 'closed_at' => '2026-10-21 10:00:00']);

    expect($previous->fresh()->closed_at->format('Y-m-d H:i:s'))->toBe('2026-10-20 15:10:00')
        ->and($previous->fresh()->verification_notes)->toBe('Emissão testada e confirmada pelo setor fiscal.');

    $previous->update(['status' => 'open', 'closed_at' => null]);

    expect($followUp->fresh()->status)->toBe('closed')
        ->and($followUp->fresh()->closed_at->format('Y-m-d H:i:s'))->toBe('2026-10-21 10:00:00');
});

test('systems can be deactivated without hiding history', function () {
    $ticket = createSupportFlowTicket();
    $system = $ticket->system;

    expect($system->is_active)->toBeTrue();

    $system->update(['is_active' => false]);

    expect($system->fresh()->is_active)->toBeFalse()
        ->and($ticket->fresh()->system->is($system))->toBeTrue()
        ->and($system->fresh()->supportTickets->modelKeys())->toBe([$ticket->getKey()]);
});

test('systems cannot be deleted through Eloquent', function (string $operation) {
    $system = System::create(['name' => 'ERP']);

    $delete = match ($operation) {
        'delete' => fn () => $system->delete(),
        'deleteQuietly' => fn () => $system->deleteQuietly(),
        'forceDelete' => fn () => $system->forceDelete(),
        'destroy' => fn () => System::destroy($system->getKey()),
        'queryDelete' => fn () => System::whereKey($system->getKey())->delete(),
        'queryForceDelete' => fn () => System::whereKey($system->getKey())->forceDelete(),
        'truncate' => fn () => System::query()->truncate(),
    };

    expect($delete)->toThrow(LogicException::class)
        ->and(System::find($system->getKey()))->not->toBeNull();
})->with(['delete', 'deleteQuietly', 'forceDelete', 'destroy', 'queryDelete', 'queryForceDelete', 'truncate']);

test('foreign keys preserve referenced history even for direct SQL deletes', function (string $relation) {
    $customer = Customer::create(['name' => 'Loja Central']);
    $ticket = createSupportFlowTicket(['customer_id' => $customer->getKey()]);

    if ($relation === 'previousTicket') {
        createSupportFlowTicket(['previous_ticket_id' => $ticket->getKey()]);
        $table = 'support_tickets';
        $id = $ticket->getKey();
    } elseif (in_array($relation, ['pendingTasks', 'reminders'], true)) {
        if ($relation === 'pendingTasks') {
            $ticket->pendingTasks()->create(['description' => 'Solicitar retorno']);
        } else {
            $ticket->reminders()->create(['title' => 'Retorno', 'scheduled_at' => now()]);
        }
        $table = 'support_tickets';
        $id = $ticket->getKey();
    } else {
        $parent = $ticket->{$relation};
        $table = $parent->getTable();
        $id = $parent->getKey();
    }

    expect(fn () => DB::table($table)->where('id', $id)->delete())->toThrow(QueryException::class);
})->with(['system', 'contact', 'customer', 'previousTicket', 'pendingTasks', 'reminders']);

test('pending tasks and reminders can be independent or attached to a ticket', function () {
    $ticket = createSupportFlowTicket();
    $independentTask = PendingTask::create(['description' => 'Consultar fornecedor']);
    $independentReminder = Reminder::create(['title' => 'Revisar pendências', 'scheduled_at' => '2026-10-22 09:00:00']);
    $task = $ticket->pendingTasks()->create([
        'description' => 'Confirmar retorno',
        'due_at' => '2026-10-22 10:00:00',
        'status' => 'completed',
        'completion_notes' => 'Retorno confirmado.',
        'completed_at' => '2026-10-21 14:00:00',
    ]);
    $reminder = $ticket->reminders()->create([
        'title' => 'Telefonar',
        'scheduled_at' => '2026-10-22 11:00:00',
        'completed_at' => '2026-10-21 14:00:00',
    ]);

    expect($independentTask->supportTicket)->toBeNull()
        ->and($independentTask->status)->toBe('pending')
        ->and($independentTask->completed_at)->toBeNull()
        ->and($independentReminder->supportTicket)->toBeNull()
        ->and($independentReminder->completed_at)->toBeNull()
        ->and($task->supportTicket->is($ticket))->toBeTrue()
        ->and($reminder->supportTicket->is($ticket))->toBeTrue()
        ->and($ticket->pendingTasks->modelKeys())->toBe([$task->getKey()])
        ->and($ticket->reminders->modelKeys())->toBe([$reminder->getKey()])
        ->and($task->completed_at->format('Y-m-d H:i:s'))->toBe('2026-10-21 14:00:00')
        ->and($task->due_at->format('Y-m-d H:i:s'))->toBe('2026-10-22 10:00:00')
        ->and($task->completion_notes)->toBe('Retorno confirmado.')
        ->and($reminder->scheduled_at->format('Y-m-d H:i:s'))->toBe('2026-10-22 11:00:00')
        ->and($reminder->completed_at->format('Y-m-d H:i:s'))->toBe('2026-10-21 14:00:00');
});

test('knowledge articles store procedures with an optional system', function () {
    $system = System::create(['name' => 'ERP']);
    $article = $system->knowledgeArticles()->create([
        'problem' => 'Erro na emissão da nota',
        'resolution_procedure' => 'Revisar configuração fiscal e testar emissão.',
    ]);
    $general = KnowledgeArticle::create([
        'problem' => 'Sem conexão de rede',
        'resolution_procedure' => 'Verificar cabo e conexão.',
    ]);

    expect($article->system->is($system))->toBeTrue()
        ->and($system->knowledgeArticles->modelKeys())->toBe([$article->getKey()])
        ->and($general->system)->toBeNull();
});

test('optional associations still enforce foreign keys', function (string $model, string $key, array $attributes) {
    expect(fn () => $model::create([$key => 999999, ...$attributes]))->toThrow(QueryException::class);
})->with([
    [PendingTask::class, 'support_ticket_id', ['description' => 'Retorno']],
    [Reminder::class, 'support_ticket_id', ['title' => 'Retorno', 'scheduled_at' => '2026-10-22 09:00:00']],
    [KnowledgeArticle::class, 'system_id', ['problem' => 'Erro', 'resolution_procedure' => 'Revisar']],
]);
