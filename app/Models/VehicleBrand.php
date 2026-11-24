<?php

namespace App\Models;

use App\Models\Concerns\NormalizesProblemName;
use App\Support\ProblemName;
use Database\Factories\VehicleBrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class VehicleBrand extends Model
{
    /** @use HasFactory<VehicleBrandFactory> */
    use HasFactory;

    use NormalizesProblemName;

    /** @return HasMany<Vehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'brand_id');
    }

    /** @param Builder<VehicleBrand> $query */
    #[Scope]
    protected function matchingName(Builder $query, string $term): void
    {
        $name = strtr(ProblemName::key($term), ['!' => '!!', '%' => '!%', '_' => '!_']);
        $query->whereRaw("normalized_name LIKE ? ESCAPE '!'", ['%'.$name.'%']);
    }
}
