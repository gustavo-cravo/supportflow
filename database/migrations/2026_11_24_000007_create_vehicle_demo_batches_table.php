<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_demo_batches', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->json('manifest');
        });
    }

    public function down(): void
    {
        if (DB::table('vehicle_demo_batches')->exists()) {
            throw new RuntimeException('Limpe a base demonstrativa veicular antes de reverter seu registro de propriedade.');
        }

        Schema::drop('vehicle_demo_batches');
    }
};
