<?php

namespace App\Http\Requests\Api;

use App\Enums\TicketStatus;
use Illuminate\Validation\Rule;

class TicketStatusRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TicketStatus::class)],
            'confirm_open_tasks' => ['sometimes', 'boolean:strict'],
        ];
    }
}
