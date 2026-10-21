<?php

use App\Models\Contact;
use App\Models\PendingTask;
use App\Models\Reminder;
use App\Models\SupportTicket;
use App\Models\System;
use Illuminate\Support\Facades\DB;

// Optional pagination/dashboard fixtures for the isolated browser server only.
require __DIR__.'/isolated-app.php';
if (! is_file(config('database.connections.sqlite.database'))) {
    throw new RuntimeException('Initialize the isolated database first.');
}
if (SupportTicket::where('title', 'QA Painel 01')->exists()) {
    throw new RuntimeException('Fixtures already exist; no records were changed.');
}
$system = System::where('name', 'QA ERP')->sole();
$contact = Contact::where('name', 'QA Ana')->sole();
DB::transaction(function () use ($system, $contact): void {
    for ($index = 1; $index <= 16; $index++) {
        SupportTicket::create([
            'system_id' => $system->id, 'contact_id' => $contact->id,
            'title' => sprintf('QA Painel %02d', $index),
            'description' => 'Registro fictício para paginação e indicadores.',
            'status' => ['open', 'in_progress', 'waiting'][($index - 1) % 3],
        ]);
        Contact::create(['name' => sprintf('QA Paginação %02d', $index)]);
    }
    Reminder::create(['title' => 'QA Retorno atrasado', 'scheduled_at' => now()->subDay()]);
});
echo json_encode([
    'timezone' => config('app.timezone'), 'now' => now()->toIso8601String(),
    'ticket_statuses' => SupportTicket::select('status')->selectRaw('count(*) as total')->groupBy('status')->pluck('total', 'status'),
    'pending_tasks' => PendingTask::count(), 'reminders' => Reminder::count(),
], JSON_PRETTY_PRINT).PHP_EOL;
