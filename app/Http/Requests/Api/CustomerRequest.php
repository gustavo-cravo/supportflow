<?php

namespace App\Http\Requests\Api;

class CustomerRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $document = $this->input('document');
        if (is_string($document)) {
            $this->merge(['document' => preg_replace('/\D/', '', $document)]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => [...$this->presence(), 'string', 'max:255'],
            'document' => ['bail', ...($this->isMethod('POST') ? ['required'] : ['sometimes', 'nullable']), 'string', 'regex:/^[0-9]{11}([0-9]{3})?$/D'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:16000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'document.required' => 'O CPF ou CNPJ é obrigatório.',
            'document.string' => 'Informe um CPF com 11 dígitos ou um CNPJ com 14 dígitos.',
            'document.regex' => 'Informe um CPF com 11 dígitos ou um CNPJ com 14 dígitos.',
        ];
    }
}
