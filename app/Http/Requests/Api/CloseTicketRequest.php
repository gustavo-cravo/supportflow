<?php

namespace App\Http\Requests\Api;

class CloseTicketRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'confirm_open_tasks' => ['sometimes', 'boolean:strict'],
            ...$this->verificationRules(),
        ];
    }
}
