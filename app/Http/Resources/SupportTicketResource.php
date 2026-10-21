<?php

namespace App\Http\Resources;

use App\Models\SupportTicket;
use Illuminate\Http\Request;

/** @mixin SupportTicket */
class SupportTicketResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'system_id' => $this->system_id,
            'contact_id' => $this->contact_id,
            'customer_id' => $this->customer_id,
            'previous_ticket_id' => $this->previous_ticket_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'resolution' => $this->resolution,
            'verification_source' => $this->verification_source,
            'verification_notes' => $this->verification_notes,
            'verified_by' => $this->verified_by,
            'verified_at' => $this->date($this->verified_at),
            'closed_at' => $this->date($this->closed_at),
            'created_at' => $this->date($this->created_at),
            'updated_at' => $this->date($this->updated_at),
            'system' => SystemResource::make($this->whenLoaded('system')),
            'contact' => ContactResource::make($this->whenLoaded('contact')),
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'previous_ticket' => self::make($this->whenLoaded('previousTicket')),
            'follow_up_tickets' => self::collection($this->whenLoaded('followUpTickets')),
            'pending_tasks' => PendingTaskResource::collection($this->whenLoaded('pendingTasks')),
            'reminders' => ReminderResource::collection($this->whenLoaded('reminders')),
        ];
    }
}
