<?php

namespace App\Http\Resources;

use App\Models\Vehicle;
use Illuminate\Http\Request;

/** @mixin Vehicle */
class VehicleResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'brand_id' => $this->brand_id,
            'brand' => VehicleBrandResource::make($this->whenLoaded('brand')),
            'vehicle_line' => $this->vehicle_line?->value,
            'plate' => $this->plate,
            'model_description' => $this->model_description,
            'support_tickets_count' => $this->whenCounted('supportTickets'),
            'created_at' => $this->date($this->created_at),
            'updated_at' => $this->date($this->updated_at),
        ];
    }
}
