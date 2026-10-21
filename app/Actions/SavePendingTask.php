<?php

namespace App\Actions;

use App\Enums\PendingTaskStatus;
use App\Models\PendingTask;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;

class SavePendingTask
{
    /** @param array<string, mixed> $data */
    public function execute(?PendingTask $task, array $data): PendingTask
    {
        return DB::transaction(function () use ($task, $data): PendingTask {
            $task = $task === null
                ? new PendingTask
                : PendingTask::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            // Serialize task changes with ticket closure, including moves between tickets.
            $ticketIds = array_filter([$task->support_ticket_id, $data['support_ticket_id'] ?? null]);
            SupportTicket::query()->whereIn('id', $ticketIds)->orderBy('id')->lockForUpdate()->get(['id']);

            unset($data['completed_at']);
            $task->fill($data);
            $task->completed_at = $task->status === PendingTaskStatus::Completed->value
                ? ($task->completed_at ?? now())
                : null;
            $task->save();

            return $task;
        }, attempts: 3);
    }
}
