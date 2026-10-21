<?php

namespace App\Http\Requests\Api;

use App\Enums\VerificationSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return list<string> */
    protected function presence(): array
    {
        return $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];
    }

    /** @return array<string, list<mixed>> */
    protected function verificationRules(): array
    {
        return [
            'resolution' => ['nullable', 'string', 'max:16000'],
            'verification_source' => ['nullable', 'required_with:verified_at', Rule::enum(VerificationSource::class)],
            'verified_at' => ['nullable', 'required_with:verification_source', 'date_format:Y-m-d H:i:s'],
            'verified_by' => ['nullable', 'string', 'max:255'],
            'verification_notes' => ['nullable', 'string', 'max:16000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'O campo :attribute é obrigatório.',
            'required_with' => 'Informe :attribute junto com :values.',
            'string' => 'O campo :attribute deve ser um texto.',
            'integer' => 'O campo :attribute deve ser um número inteiro.',
            'boolean' => 'O campo :attribute deve ser um booleano válido.',
            'email' => 'Informe um e-mail válido em :attribute.',
            'exists' => 'O registro informado em :attribute não existe.',
            'enum' => 'O valor informado em :attribute não é permitido.',
            'in' => 'O valor informado em :attribute não é permitido.',
            'date_format' => 'O campo :attribute deve usar o formato :format.',
            'after_or_equal' => 'O campo :attribute deve ser igual ou posterior a :date.',
            'prohibited' => 'O campo :attribute é controlado pela operação e não pode ser informado.',
            'max.string' => 'O campo :attribute aceita no máximo :max caracteres.',
            'max.numeric' => 'O campo :attribute deve ser no máximo :max.',
            'min.numeric' => 'O campo :attribute deve ser no mínimo :min.',
        ];
    }
}
