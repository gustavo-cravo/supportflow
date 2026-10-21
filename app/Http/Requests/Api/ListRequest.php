<?php

namespace App\Http\Requests\Api;

use App\Enums\PendingTaskStatus;
use App\Enums\TicketStatus;
use Illuminate\Validation\Rule;

class ListRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $rules = [
            'q' => ['nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
        if ($this->routeIs('systems.*')) {
            $rules += ['name' => ['nullable', 'string', 'max:255'], 'is_active' => ['sometimes', 'boolean']];
        }
        if ($this->routeIs('support-tickets.*')) {
            $rules += [
                'system_id' => ['nullable', 'integer', 'min:1'],
                'customer_id' => ['nullable', 'integer', 'min:1'],
                'contact_id' => ['nullable', 'integer', 'min:1'],
                'status' => ['nullable', Rule::enum(TicketStatus::class)],
                'created_from' => ['nullable', 'date_format:Y-m-d'],
                'created_to' => ['nullable', 'date_format:Y-m-d'],
            ];
            if ($this->filled('created_from')) {
                $rules['created_to'][] = 'after_or_equal:created_from';
            }
        }
        if ($this->routeIs('pending-tasks.*')) {
            $rules += [
                'status' => ['nullable', Rule::enum(PendingTaskStatus::class)],
                'filter' => ['nullable', Rule::in(['open', 'completed', 'overdue'])],
                'support_ticket_id' => ['nullable', 'integer', 'min:1'],
            ];
        }
        if ($this->routeIs('reminders.*')) {
            $rules += [
                'filter' => ['nullable', Rule::in(['today', 'overdue', 'upcoming', 'completed', 'pending'])],
                'support_ticket_id' => ['nullable', 'integer', 'min:1'],
            ];
        }
        if ($this->routeIs('knowledge-articles.*')) {
            $rules += ['system_id' => ['nullable', 'integer', 'min:1']];
        }

        return $rules;
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 15);
    }
}
