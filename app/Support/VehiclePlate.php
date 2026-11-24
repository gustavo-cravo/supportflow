<?php

namespace App\Support;

class VehiclePlate
{
    public const FORMAT = 'regex:/\A[A-Z]{3}[0-9][A-Z0-9][0-9]{2}\z/';

    public static function normalize(string $plate): string
    {
        return strtoupper(preg_replace('/[\p{Z}\s-]+/u', '', $plate) ?? $plate);
    }
}
