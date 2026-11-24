<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\SQLiteConnection;
use PDO;

final class VehicleModelGroup
{
    /** @return literal-string */
    public static function expression(Builder $query): string
    {
        $connection = $query->getConnection();
        // Group on a fixed-width key to retain the entire description under MySQL's max_sort_length.
        if (! $connection instanceof SQLiteConnection) {
            return "SHA2(NULLIF(LOWER(TRIM(REGEXP_REPLACE(vehicles.model_description, '[[:space:]]+', ' '))), ''), 256)";
        }

        $connection->getPdo()->sqliteCreateFunction('vehicle_model_key', self::sqliteKey(...), 1, PDO::SQLITE_DETERMINISTIC);

        return 'vehicle_model_key(vehicles.model_description)';
    }

    private static function sqliteKey(?string $description): ?string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $description ?? '') ?? '');

        return $normalized === '' ? null : hash('sha256', mb_strtolower($normalized, 'UTF-8'));
    }
}
