<?php

namespace App\Http\Requests\Api;

class CompleteTaskRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'completion_notes' => ['nullable', 'string', 'max:16000'],
        ];
    }
}
