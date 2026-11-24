<?php

namespace App\Models;

use App\Enums\VehicleLine;
use App\Support\VehiclePlate;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $brand_id
 * @property VehicleLine|null $vehicle_line
 */
#[Fillable(['brand_id', 'vehicle_line', 'plate', 'model_description'])]
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory;

    public function setPlateAttribute(string $value): void
    {
        $this->attributes['plate'] = VehiclePlate::normalize($value);
    }

    /** @param Builder<Vehicle> $query */
    #[Scope]
    protected function matchingPlate(Builder $query, string $term): void
    {
        $query->whereLike('plate', '%'.VehiclePlate::normalize($term).'%');
    }

    /** @param Builder<Vehicle> $query */
    #[Scope]
    protected function matchingModel(Builder $query, string $term): void
    {
        $description = strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']);
        $query->whereRaw("model_description LIKE ? ESCAPE '!'", ['%'.$description.'%']);
    }

    /** @return BelongsTo<VehicleBrand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(VehicleBrand::class);
    }

    /** @return HasMany<SupportTicket, $this> */
    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['vehicle_line' => VehicleLine::class];
    }
}
