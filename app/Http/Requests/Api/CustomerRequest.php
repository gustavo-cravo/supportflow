<?php

namespace App\Http\Requests\Api;

class CustomerRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => [...$this->presence(), 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:16000'],
        ];
    }
}
