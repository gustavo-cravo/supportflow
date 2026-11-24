<?php

namespace Database\Factories;

use App\Enums\VehicleLine;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vehicle> */
class VehicleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'brand_id' => VehicleBrand::factory(),
            'vehicle_line' => VehicleLine::Light,
            'plate' => fake()->unique()->bothify('???####'),
            'model_description' => fake()->words(5, true),
        ];
    }

    public function mercosul(): static
    {
        return $this->state(fn () => ['plate' => fake()->unique()->bothify('???#?##')]);
    }
}
