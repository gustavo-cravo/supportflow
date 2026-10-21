<?php

namespace App\Http\Requests\Api;

class KnowledgeArticleRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'system_id' => ['nullable', 'integer', 'exists:systems,id'],
            'problem' => [...$this->presence(), 'string', 'max:16000'],
            'resolution_procedure' => [...$this->presence(), 'string', 'max:16000'],
        ];
    }
}
