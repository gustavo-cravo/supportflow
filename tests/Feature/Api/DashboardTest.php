<?php

use App\Models\Contact;
use App\Models\PendingTask;
use App\Models\Reminder;
use App\Models\SupportTicket;
use App\Models\System;
use Illuminate\Support\Carbon;

test('dashboard returns empty real counters and sections', function () {
    $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('data.counts', [
        'open' => 0, 'in_progress' => 0, 'waiting' => 0, 'overdue_tasks' => 0, 'today_reminders' => 0,
    ])->assertJsonCount(0, 'data.recent_tickets')->assertJsonCount(0, 'data.overdue_tasks')
        ->assertJsonCount(0, 'data.today_reminders')->assertJsonCount(0, 'data.overdue_reminders')->assertJsonCount(0, 'data.upcoming_reminders');
});
test('dashboard counts full datasets and bounds sections using local time', function () {
    $this->travelTo(Carbon::parse('2026-10-21 23:30:00', 'America/Sao_Paulo'));
    $system = System::create(['name' => 'Histórico', 'is_active' => false]);
    $contact = Contact::create(['name' => 'Ana']);
    foreach (['open', 'open', 'open', 'open', 'open', 'open', 'in_progress', 'waiting', 'resolved', 'closed'] as $status) {
        SupportTicket::create(['system_id' => $system->id, 'contact_id' => $contact->id, 'title' => 'Chamado', 'description' => 'Descrição', 'status' => $status]);
    }
    foreach (range(1, 7) as $number) {
        PendingTask::create(['description' => 'Atrasada', 'due_at' => now()->subHour()]);
        Reminder::create(['title' => 'Hoje', 'scheduled_at' => '2026-10-21 23:59:59']);
    }
    PendingTask::create(['description' => 'Concluída', 'status' => 'completed', 'due_at' => now()->subDay(), 'completed_at' => now()]);
    PendingTask::create(['description' => 'Sem prazo']);
    Reminder::create(['title' => 'Atrasado', 'scheduled_at' => '2026-10-21 00:00:00']);
    Reminder::create(['title' => 'Amanhã', 'scheduled_at' => '2026-10-22 00:00:00']);
    Reminder::create(['title' => 'Feito', 'scheduled_at' => now(), 'completed_at' => now()]);
    $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('data.counts', [
        'open' => 6, 'in_progress' => 1, 'waiting' => 1, 'overdue_tasks' => 7, 'today_reminders' => 8,
    ])->assertJsonCount(5, 'data.recent_tickets')->assertJsonPath('data.recent_tickets.0.system.is_active', false)
        ->assertJsonCount(5, 'data.overdue_tasks')->assertJsonCount(5, 'data.today_reminders')
        ->assertJsonCount(1, 'data.overdue_reminders')->assertJsonCount(1, 'data.upcoming_reminders')
        ->assertJsonPath('data.upcoming_reminders.0.scheduled_at', '2026-10-22T00:00:00-03:00');
    $this->travelBack();
});
