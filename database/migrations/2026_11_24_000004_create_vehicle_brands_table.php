<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicle_brands')) {
            Schema::create('vehicle_brands', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $normalizedName = $table->string('normalized_name', 512);
                if (DB::getDriverName() === 'mysql') {
                    $normalizedName->charset('utf8mb4')->collation('utf8mb4_bin');
                }
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('vehicle_brands', ['normalized_name'], 'unique')) {
            Schema::table('vehicle_brands', function (Blueprint $table) {
                $table->unique('normalized_name');
            });
        }
    }

    public function down(): void
    {
        if (DB::table('vehicle_brands')->exists()) {
            throw new RuntimeException('Rollback bloqueado: há marcas cadastradas.');
        }

        Schema::drop('vehicle_brands');
    }
};
