<?php

namespace Tests\Support;

use App\Models\VehicleBrand;

final readonly class VehicleApiFixture
{
    public VehicleBrand $brand;

    /** @var array{brand_id: int, vehicle_line: string} */
    public array $classification;

    public function __construct()
    {
        $this->brand = VehicleBrand::factory()->create();
        $this->classification = ['brand_id' => $this->brand->id, 'vehicle_line' => 'light'];
    }
}
