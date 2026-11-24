<?php

namespace App\Http\Resources;

use App\Models\VehicleBrand;
use Illuminate\Http\Request;

/** @mixin VehicleBrand */
class VehicleBrandResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'vehicles_count' => $this->whenCounted('vehicles'),
            'created_at' => $this->date($this->created_at),
            'updated_at' => $this->date($this->updated_at),
        ];
    }
}
