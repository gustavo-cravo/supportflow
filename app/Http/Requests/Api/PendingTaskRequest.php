<?php

namespace App\Http\Requests\Api;

use App\Enums\PendingTaskStatus;
use Illuminate\Validation\Rule;

class PendingTaskRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'support_ticket_id' => ['nullable', 'integer', 'exists:support_tickets,id'],
            'description' => [...$this->presence(), 'string', 'max:16000'],
            'due_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'status' => ['sometimes', $this->isMethod('POST') ? Rule::in(['pending']) : Rule::enum(PendingTaskStatus::class)],
            'completion_notes' => ['nullable', 'string', 'max:16000'],
            'completed_at' => ['prohibited'],
        ];
    }
}
