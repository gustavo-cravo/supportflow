<?php

namespace App\Http\Requests\Api;

class SystemRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => [...$this->presence(), 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:16000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
