<?php

namespace App\Support;

use App\Models\Vehicle;

final class ReportPlateRedaction
{
    private const CANDIDATE = '/(?<![\pL\pN])[A-Z](?:[\s-]*[A-Z]){2}[\s-]*[0-9][\s-]*[A-Z0-9](?:[\s-]*[0-9]){2}(?![\pL\pN])/iu';

    /**
     * Registered plates sometimes get pasted into otherwise publishable labels.
     *
     * @template T of array
     *
     * @param  T  $projection
     * @return T
     */
    public static function redact(array $projection): array
    {
        $candidates = [];
        array_walk_recursive($projection, static function ($value) use (&$candidates): void {
            if (is_string($value) && preg_match_all(self::CANDIDATE, $value, $matches)) {
                foreach ($matches[0] as $candidate) {
                    $candidates[VehiclePlate::normalize($candidate)] = true;
                }
            }
        });
        $registered = [];
        foreach (array_chunk(array_keys($candidates), 250) as $chunk) {
            foreach (Vehicle::query()->whereIn('plate', $chunk)->pluck('plate') as $plate) {
                $registered[$plate] = true;
            }
        }
        if ($registered !== []) {
            array_walk_recursive($projection, static function (&$value) use ($registered): void {
                if (is_string($value)) {
                    $value = preg_replace_callback(self::CANDIDATE, static fn (array $match) => isset($registered[VehiclePlate::normalize($match[0])])
                        ? '[placa omitida]' : $match[0], $value) ?? $value;
                }
            });
        }

        return $projection;
    }
}
