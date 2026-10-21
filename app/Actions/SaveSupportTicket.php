<?php

namespace App\Actions;

use App\Enums\PendingTaskStatus;
use App\Enums\TicketStatus;
use App\Exceptions\OpenTasksConflict;
use App\Models\SupportTicket;
use App\Models\System;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveSupportTicket
{
    /** @param array<string, mixed> $data */
    public function execute(?SupportTicket $ticket, array $data): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $data): SupportTicket {
            $ticket = $ticket === null
                ? new SupportTicket
                : SupportTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            $systemId = (int) ($data['system_id'] ?? $ticket->system_id);
            if (! $ticket->exists || $systemId !== $ticket->system_id) {
                $system = System::query()->lockForUpdate()->findOrFail($systemId);
                if (! $system->is_active) {
                    throw ValidationException::withMessages(['system_id' => 'Selecione um sistema ativo para o atendimento.']);
                }
            }

            if (array_key_exists('previous_ticket_id', $data)) {
                $this->validatePreviousTicket($ticket, $data['previous_ticket_id'] === null ? null : (int) $data['previous_ticket_id']);
            }

            $confirmOpenTasks = ($data['confirm_open_tasks'] ?? false) === true;
            unset($data['confirm_open_tasks'], $data['closed_at']);
            $status = $data['status'] ?? $ticket->status;
            if ($status === TicketStatus::Closed->value) {
                if ($ticket->exists && $ticket->status !== TicketStatus::Closed->value) {
                    $tasks = $ticket->pendingTasks()
                        ->where('status', PendingTaskStatus::Pending->value)
                        ->orderBy('id')->lockForUpdate()->get();
                    if ($tasks->isNotEmpty() && ! $confirmOpenTasks) {
                        throw new OpenTasksConflict($tasks);
                    }
                }
                $ticket->closed_at ??= now();
            } else {
                $ticket->closed_at = null;
            }

            $ticket->fill($data);
            if (array_key_exists('verification_source', $data) || array_key_exists('verified_at', $data)) {
                if (($ticket->verification_source === null) !== ($ticket->verified_at === null)) {
                    throw ValidationException::withMessages([
                        'verification_source' => 'Informe ou remova a fonte e a data da confirmação juntas.',
                        'verified_at' => 'Informe ou remova a fonte e a data da confirmação juntas.',
                    ]);
                }
            }
            $ticket->save();

            return $ticket;
        }, attempts: 3);
    }

    private function validatePreviousTicket(SupportTicket $ticket, ?int $previousId): void
    {
        $seen = [];
        while ($previousId !== null) {
            if ($previousId === $ticket->getKey() || isset($seen[$previousId])) {
                throw ValidationException::withMessages([
                    'previous_ticket_id' => 'O vínculo não pode apontar para o próprio atendimento nem formar um ciclo.',
                ]);
            }
            $seen[$previousId] = true;
            $previous = SupportTicket::query()->lockForUpdate()->findOrFail($previousId);
            $previousId = $previous->previous_ticket_id;
        }
    }
}
