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
            'vehicle_id' => ['bail', 'sometimes', 'nullable', 'integer:strict', 'exists:vehicles,id'],
            'previous_ticket_id' => ['nullable', 'integer', 'exists:support_tickets,id'],
            'problem_category_id' => ['nullable', 'integer', 'exists:problem_categories,id'],
            'problem_subcategory_id' => ['nullable', 'integer', 'exists:problem_subcategories,id'],
            'problem_subcategory_ids' => ['sometimes', 'array', 'list'],
            'problem_subcategory_ids.*' => ['bail', 'required', 'integer:strict', 'min:1', 'distinct'],
            'title' => [...$this->presence(), 'string', 'max:255'],
            'description' => [...$this->presence(), 'string', 'max:16000'],
            'status' => ['sometimes', $this->isMethod('POST') ? Rule::in(['open']) : Rule::enum(TicketStatus::class)],
            'closed_at' => ['prohibited'],
            'confirm_open_tasks' => ['sometimes', 'boolean:strict'],
            ...$this->verificationRules(),
        ];
    }
}
