<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite enforces required values without rebuilding a table referenced by support_tickets.
            DB::transaction(function () {
                DB::unprepared("CREATE TRIGGER IF NOT EXISTS vehicles_require_classification_on_insert
                    BEFORE INSERT ON vehicles
                    WHEN NEW.brand_id IS NULL OR NEW.vehicle_line IS NULL
                    BEGIN SELECT RAISE(ABORT, 'A marca e a linha veicular são obrigatórias.'); END");
                DB::unprepared("CREATE TRIGGER IF NOT EXISTS vehicles_require_classification_on_update
                    BEFORE UPDATE ON vehicles
                    WHEN NEW.brand_id IS NULL OR NEW.vehicle_line IS NULL
                    BEGIN SELECT RAISE(ABORT, 'A marca e a linha veicular são obrigatórias.'); END");
                $this->requireCompleteVehicles();
            });

            return;
        }

        $this->requireCompleteVehicles();
        DB::statement('ALTER TABLE vehicles
            MODIFY COLUMN brand_id BIGINT UNSIGNED NOT NULL,
            MODIFY COLUMN vehicle_line VARCHAR(5) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::transaction(function () {
                DB::unprepared('DROP TRIGGER IF EXISTS vehicles_require_classification_on_insert');
                DB::unprepared('DROP TRIGGER IF EXISTS vehicles_require_classification_on_update');
            });

            return;
        }

        DB::statement('ALTER TABLE vehicles
            MODIFY COLUMN brand_id BIGINT UNSIGNED NULL,
            MODIFY COLUMN vehicle_line VARCHAR(5) CHARACTER SET ascii COLLATE ascii_bin NULL');
    }

    private function requireCompleteVehicles(): void
    {
        $pending = DB::table('vehicles')->whereNull('brand_id')->orWhereNull('vehicle_line')->count();
        if ($pending > 0) {
            throw new RuntimeException("Há {$pending} veículo(s) sem marca ou linha. Complete os dados conforme docs/vehicle-identification.md e execute php artisan migrate novamente.");
        }
    }
};
