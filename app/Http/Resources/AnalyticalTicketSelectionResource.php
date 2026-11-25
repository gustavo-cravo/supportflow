<?php

namespace App\Http\Resources;

use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** @mixin SupportTicket */
final class AnalyticalTicketSelectionResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $vehicle = $this->vehicle;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'created_at' => $this->date($this->created_at),
            'system' => $this->system?->name,
            'category' => $this->problemCategory?->name,
            'subcategories' => $this->problemSubcategories->pluck('name')->all(),
            'vehicle' => $vehicle === null ? null : [
                'brand' => $vehicle->brand?->name,
                'model' => $vehicle->model_description === null ? null : Str::limit($vehicle->model_description, 160),
            ],
        ];
    }
}
