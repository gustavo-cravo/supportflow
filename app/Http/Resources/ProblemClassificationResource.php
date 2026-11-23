<?php

namespace App\Http\Resources;

use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Models\System;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;

class ProblemClassificationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $record = $this->resource;
        assert($record instanceof ProblemCategory || $record instanceof ProblemSubcategory);
        $associations = $record->systems->map(function (System $system) use ($request): array {
            $pivot = $system->getRelation('pivot');
            assert($pivot instanceof Pivot);

            return [...(new SystemResource($system))->resolve($request),
                'association_active' => (bool) $pivot->getAttribute('is_active'),
                'associated_at' => $this->date($pivot->getAttribute('created_at')),
                'association_updated_at' => $this->date($pivot->getAttribute('updated_at')),
            ];
        });
        $systems = $associations->where('association_active', true)->values();
        $data = [
            'id' => $record->id,
            'name' => $record->name,
            'description' => $record->description,
            'is_active' => $record->is_active,
            'systems' => $systems->all(),
            'system_ids' => $systems->pluck('id')->all(),
            'system_associations' => $associations->all(),
            'created_at' => $this->date($record->created_at),
            'updated_at' => $this->date($record->updated_at),
        ];
        if ($record instanceof ProblemSubcategory) {
            $data['problem_category_id'] = $record->problem_category_id;
            $data['category'] = $this->whenLoaded('category', fn () => [
                'id' => $record->category->id, 'name' => $record->category->name, 'is_active' => $record->category->is_active,
            ]);
        } else {
            $data['subcategories_count'] = $this->whenCounted('subcategories');
        }

        return $data;
    }
}
