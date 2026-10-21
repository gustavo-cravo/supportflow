<?php

namespace App\Http\Resources;

use App\Models\Reminder;
use Illuminate\Http\Request;

/** @mixin Reminder */
class ReminderResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'support_ticket_id' => $this->support_ticket_id,
            'title' => $this->title,
            'description' => $this->description,
            'scheduled_at' => $this->date($this->scheduled_at),
            'completed_at' => $this->date($this->completed_at),
            'created_at' => $this->date($this->created_at),
            'updated_at' => $this->date($this->updated_at),
            'support_ticket' => SupportTicketResource::make($this->whenLoaded('supportTicket')),
            'is_overdue' => $this->completed_at === null && $this->scheduled_at->isPast(),
        ];
    }
}
