<?php

namespace App\Http\Resources;

use App\Models\KnowledgeArticle;
use Illuminate\Http\Request;

/** @mixin KnowledgeArticle */
class KnowledgeArticleResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'system_id' => $this->system_id,
            'problem' => $this->problem,
            'resolution_procedure' => $this->resolution_procedure,
            'created_at' => $this->date($this->created_at),
            'updated_at' => $this->date($this->updated_at),
            'system' => SystemResource::make($this->whenLoaded('system')),
        ];
    }
}
