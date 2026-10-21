<?php

namespace App\Http\Requests\Api;

use App\Enums\TicketStatus;
use Illuminate\Validation\Rule;

class SupportTicketRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'system_id' => [...$this->presence(), 'integer', 'exists:systems,id'],
            'contact_id' => [...$this->presence(), 'integer', 'exists:contacts,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'previous_ticket_id' => ['nullable', 'integer', 'exists:support_tickets,id'],
            'title' => [...$this->presence(), 'string', 'max:255'],
            'description' => [...$this->presence(), 'string', 'max:16000'],
            'status' => ['sometimes', $this->isMethod('POST') ? Rule::in(['open']) : Rule::enum(TicketStatus::class)],
            'closed_at' => ['prohibited'],
            'confirm_open_tasks' => ['sometimes', 'boolean:strict'],
            ...$this->verificationRules(),
        ];
    }
}
