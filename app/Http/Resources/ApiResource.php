<?php

namespace App\Http\Resources;

use Carbon\CarbonInterface;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class ApiResource extends JsonResource
{
    protected function date(?CarbonInterface $date): ?string
    {
        return $date?->toIso8601String();
    }
}
