<?php

namespace App\Support;

use App\Models\Vehicle;

final class ReportPlateRedaction
{
    /**
     * Registered plates sometimes get pasted into otherwise publishable labels.
     *
     * @template T of array
     *
     * @param  T  $projection
     * @return T
     */
    public static function redact(array $projection, ?string $filterPlate = null, bool $typographicSeparators = false): array
    {
        $pattern = $typographicSeparators ? VehiclePlate::configuredReportTextPattern() : VehiclePlate::TEXT_PATTERN;
        $normalize = static fn (string $text): string => VehiclePlate::normalize(preg_replace('/\p{Pd}/u', '-', $text) ?? $text);
        $candidates = [];
        array_walk_recursive($projection, static function ($value) use (&$candidates, $pattern, $normalize): void {
            if (is_string($value) && preg_match_all($pattern, $value, $matches)) {
                foreach ($matches[0] as $candidate) {
                    $candidates[$normalize($candidate)] = true;
                }
            }
        });
        $registered = [];
        foreach (array_chunk(array_keys($candidates), 250) as $chunk) {
            foreach (Vehicle::query()->whereIn('plate', $chunk)->pluck('plate') as $plate) {
                $registered[$plate] = true;
            }
        }
        $separator = $typographicSeparators ? '[\s\pZ\p{Pd}]*' : '[\s\pZ-]*';
        $filtered = $filterPlate === null ? null : '/(?<![\pL\pN])'
            .implode($separator, array_map(static fn (string $part): string => preg_quote($part, '/'), str_split($filterPlate)))
            .'(?![\pL\pN])/iu';
        // Short search tokens can coincide with actual counts or words in generated prose.
        $filterInMixedText = $filterPlate !== null && strlen($filterPlate) >= (ctype_digit($filterPlate) ? 4 : 3);
        if ($registered !== [] || $filtered !== null) {
            array_walk_recursive($projection, static function (&$value, string|int $key) use ($registered, $filtered, $filterInMixedText, $pattern, $normalize): void {
                if (! is_string($value)) {
                    return;
                }
                if ($registered !== []) {
                    $value = preg_replace_callback($pattern, static fn (array $match) => isset($registered[$normalize($match[0])])
                        ? '[placa omitida]' : $match[0], $value) ?? $value;
                }
                if ($filtered !== null && (in_array($key, ['title', 'subtitle', 'label', 'name', 'table_context'], true)
                    || ($filterInMixedText && (is_int($key) || $key === 'text')))) {
                    $value = preg_replace($filtered, '[placa omitida]', $value) ?? $value;
                }
            });
        }

        return $projection;
    }
}
