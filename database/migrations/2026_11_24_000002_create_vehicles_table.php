<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL may commit the table creation before creating the unique index.
        if (! Schema::hasTable('vehicles')) {
            Schema::create('vehicles', function (Blueprint $table) {
                $table->id();
                $table->string('plate', 7);
                $table->text('model_description')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('vehicles', ['plate'], 'unique')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->unique('plate');
            });
        }
    }

    public function down(): void
    {
        if (DB::table('vehicles')->exists()) {
            throw new RuntimeException('Rollback bloqueado: há veículos cadastrados.');
        }

        Schema::drop('vehicles');
    }
};
