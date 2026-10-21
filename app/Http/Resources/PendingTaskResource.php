<?php

namespace App\Http\Resources;

use App\Models\PendingTask;
use Illuminate\Http\Request;

/** @mixin PendingTask */
class PendingTaskResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'support_ticket_id' => $this->support_ticket_id,
            'description' => $this->description,
            'due_at' => $this->date($this->due_at),
            'status' => $this->status,
            'completion_notes' => $this->completion_notes,
            'completed_at' => $this->date($this->completed_at),
            'created_at' => $this->date($this->created_at),
            'updated_at' => $this->date($this->updated_at),
            'is_overdue' => $this->status === 'pending' && ($this->due_at?->isPast() ?? false),
            'support_ticket' => SupportTicketResource::make($this->whenLoaded('supportTicket')),
        ];
    }
}
