<?php

use Illuminate\Support\Carbon;

test('isolated integrated workflow preserves history and uses relative Sao Paulo dates', function () {
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $date = Carbon::now('America/Sao_Paulo')->startOfDay()->addHours(10);
    $this->travelTo($date);
    try {
        $system = $this->postJson('/api/systems', ['name' => 'QA ERP'])->assertCreated()->json('data.id');
        $secondSystem = $this->postJson('/api/systems', ['name' => 'QA Fiscal'])->assertCreated()->json('data.id');
        $inactive = $this->postJson('/api/systems', ['name' => 'QA Legado', 'is_active' => false])->assertCreated()->json('data.id');
        $contact = $this->postJson('/api/contacts', ['name' => 'QA Ana'])->assertCreated()->json('data.id');
        $customer = $this->postJson('/api/customers', ['name' => 'QA Loja', 'document' => '12345678000190'])->assertCreated()->json('data.id');
        $this->getJson('/api/systems?is_active=1')->assertJsonCount(2, 'data');
        $payload = ['system_id' => $system, 'contact_id' => $contact, 'customer_id' => $customer, 'title' => 'QA Fiscal', 'description' => 'Erro fictício'];
        $this->postJson('/api/support-tickets', [])->assertUnprocessable()->assertJsonValidationErrors(['system_id', 'contact_id', 'title', 'description']);
        $this->postJson('/api/support-tickets', [...$payload, 'system_id' => $inactive])->assertUnprocessable()->assertJsonValidationErrors('system_id');
        $ticket = $this->postJson('/api/support-tickets', $payload)->assertCreated()->json('data.id');
        $followUp = $this->postJson('/api/support-tickets', [...$payload, 'system_id' => $secondSystem, 'customer_id' => null, 'previous_ticket_id' => $ticket])->assertCreated()->assertJsonPath('data.customer_id', null)->json('data.id');
        $this->patchJson('/api/support-tickets/'.$ticket, ['title' => 'QA Revisado'])->assertOk();
        $task = $this->postJson('/api/pending-tasks', ['support_ticket_id' => $ticket, 'description' => 'QA Log', 'due_at' => $date->copy()->subDay()->format('Y-m-d H:i:s')])->assertCreated()->json('data.id');
        $independentTask = $this->postJson('/api/pending-tasks', ['description' => 'QA Independente'])->assertCreated()->assertJsonPath('data.support_ticket_id', null)->json('data.id');
        $this->postJson('/api/pending-tasks/'.$independentTask.'/complete')->assertOk();
        $this->postJson('/api/reminders', ['title' => 'QA Hoje', 'support_ticket_id' => $ticket, 'scheduled_at' => $date->copy()->addHour()->format('Y-m-d H:i:s')])->assertCreated();
        $this->postJson('/api/reminders', ['title' => 'QA Amanhã', 'scheduled_at' => $date->copy()->addDay()->format('Y-m-d H:i:s')])->assertCreated();
        $completedReminder = $this->postJson('/api/reminders', ['title' => 'QA Feito', 'scheduled_at' => $date->format('Y-m-d H:i:s')])->assertCreated()->json('data.id');
        $this->postJson('/api/reminders/'.$completedReminder.'/complete')->assertOk();
        $closure = ['resolution' => 'QA Corrigido', 'verification_source' => 'responsible_department', 'verified_at' => $date->format('Y-m-d H:i:s'), 'verified_by' => 'QA Setor'];
        $this->postJson('/api/support-tickets/'.$ticket.'/close', $closure)->assertConflict()->assertJsonPath('code', 'open_pending_tasks')->assertJsonPath('open_tasks_count', 1);
        $this->getJson('/api/support-tickets/'.$ticket)->assertJsonPath('data.status', 'open')->assertJsonPath('data.closed_at', null)->assertJsonPath('data.resolution', null);
        $this->postJson('/api/support-tickets/'.$ticket.'/close', [...$closure, 'confirm_open_tasks' => true])->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.verification_source', 'responsible_department');
        $this->getJson('/api/pending-tasks/'.$task)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.completed_at', null);
        $this->getJson('/api/support-tickets/'.$followUp)->assertJsonPath('data.status', 'open')->assertJsonPath('data.closed_at', null)->assertJsonPath('data.previous_ticket.id', $ticket);
        $this->getJson('/api/support-tickets/'.$ticket.'/related')->assertJsonPath('data.0.id', $followUp);
        foreach (['in_progress', 'waiting'] as $status) {
            $id = $this->postJson('/api/support-tickets', $payload)->assertCreated()->json('data.id');
            $this->patchJson('/api/support-tickets/'.$id.'/status', ['status' => $status])->assertOk();
        }
        $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('data.counts', [
            'open' => 1, 'in_progress' => 1, 'waiting' => 1, 'overdue_tasks' => 1, 'today_reminders' => 1,
        ])->assertJsonCount(1, 'data.upcoming_reminders');
        $this->getJson('/api/pending-tasks?filter=overdue')->assertJsonCount(1, 'data');
        $this->getJson('/api/reminders?filter=completed')->assertJsonCount(1, 'data');
        $this->getJson('/api/reminders?filter=today')->assertJsonCount(1, 'data');
        $this->getJson('/api/support-tickets?status=closed&per_page=1')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $ticket);
        $article = $this->postJson('/api/knowledge-articles', ['system_id' => $system, 'problem' => 'QA Fiscal', 'resolution_procedure' => 'QA Revisar configuração'])->assertCreated()->json('data.id');
        $this->postJson('/api/knowledge-articles', ['problem' => 'QA Rede', 'resolution_procedure' => 'QA Conferir cabo'])->assertCreated()->assertJsonPath('data.system_id', null);
        $this->patchJson('/api/knowledge-articles/'.$article, ['resolution_procedure' => 'QA Procedimento revisado'])->assertOk();
        $this->getJson('/api/knowledge-articles?q=Procedimento')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $article);
    } finally {
        $this->travelBack();
    }
});
