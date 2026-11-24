<?php

namespace Database\Factories;

use App\Models\VehicleBrand;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VehicleBrand> */
class VehicleBrandFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => 'Marca '.fake()->unique()->numerify('######')];
    }
}
