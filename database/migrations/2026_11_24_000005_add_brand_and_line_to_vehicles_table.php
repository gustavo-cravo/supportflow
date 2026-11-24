<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vehicles', 'brand_id')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            // Native ALTER keeps the vehicles table and its incoming ticket references in place.
            DB::transaction(function () {
                DB::statement('ALTER TABLE vehicles ADD COLUMN brand_id INTEGER REFERENCES vehicle_brands (id) ON DELETE RESTRICT');
                DB::statement("ALTER TABLE vehicles ADD COLUMN vehicle_line VARCHAR(5) CHECK (vehicle_line IN ('light', 'heavy'))");
                Schema::table('vehicles', function (Blueprint $table) {
                    $table->index('brand_id');
                    $table->index('vehicle_line');
                });
            });

            return;
        }

        DB::statement("ALTER TABLE vehicles
            ADD COLUMN brand_id BIGINT UNSIGNED NULL,
            ADD COLUMN vehicle_line VARCHAR(5) CHARACTER SET ascii COLLATE ascii_bin NULL,
            ADD INDEX vehicles_brand_id_index (brand_id),
            ADD INDEX vehicles_vehicle_line_index (vehicle_line),
            ADD CONSTRAINT vehicles_brand_id_foreign FOREIGN KEY (brand_id) REFERENCES vehicle_brands (id) ON DELETE RESTRICT,
            ADD CONSTRAINT vehicles_vehicle_line_allowed CHECK (vehicle_line IN ('light', 'heavy'))");
    }

    public function down(): void
    {
        if (! Schema::hasColumn('vehicles', 'brand_id')) {
            return;
        }
        if (DB::table('vehicles')->whereNotNull('brand_id')->orWhereNotNull('vehicle_line')->exists()) {
            throw new RuntimeException('Rollback bloqueado: há veículos com marca ou linha preenchida.');
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::transaction(function () {
                Schema::table('vehicles', function (Blueprint $table) {
                    $table->dropIndex(['brand_id']);
                    $table->dropIndex(['vehicle_line']);
                });
                DB::statement('ALTER TABLE vehicles DROP COLUMN vehicle_line');
                DB::statement('ALTER TABLE vehicles DROP COLUMN brand_id');
            });

            return;
        }

        DB::statement('ALTER TABLE vehicles
            DROP FOREIGN KEY vehicles_brand_id_foreign,
            DROP CHECK vehicles_vehicle_line_allowed,
            DROP COLUMN vehicle_line,
            DROP COLUMN brand_id');
    }
};
