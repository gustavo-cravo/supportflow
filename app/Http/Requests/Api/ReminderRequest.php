<?php

namespace App\Http\Requests\Api;

class ReminderRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'support_ticket_id' => ['nullable', 'integer', 'exists:support_tickets,id'],
            'title' => [...$this->presence(), 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:16000'],
            'scheduled_at' => [...$this->presence(), 'date_format:Y-m-d H:i:s'],
            'completed_at' => ['prohibited'],
        ];
    }
}
