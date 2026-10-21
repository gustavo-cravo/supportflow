<?php

namespace App\Http\Resources;

use App\Models\System;
use Illuminate\Http\Request;

/** @mixin System */
class SystemResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_at' => $this->date($this->created_at),
            'updated_at' => $this->date($this->updated_at),
        ];
    }
}
