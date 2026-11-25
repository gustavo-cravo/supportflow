<?php

namespace App\Support;

class VehiclePlate
{
    public const FORMAT = 'regex:/\A[A-Z]{3}[0-9][A-Z0-9][0-9]{2}\z/';

    public const TEXT_PATTERN = '/(?<![\pL\pN])[A-Z](?:[\s-]*[A-Z]){2}[\s-]*[0-9][\s-]*[A-Z0-9](?:[\s-]*[0-9]){2}(?![\pL\pN])/iu';

    public static function configuredReportTextPattern(): string
    {
        return str_replace('[\s-]', '[\s\p{Z}\p{Pd}]', self::TEXT_PATTERN);
    }

    public static function normalize(string $plate): string
    {
        return strtoupper(preg_replace('/[\p{Z}\s-]+/u', '', $plate) ?? $plate);
    }
}
