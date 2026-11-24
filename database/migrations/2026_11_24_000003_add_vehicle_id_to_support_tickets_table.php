<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('support_tickets', 'vehicle_id')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            // The native ALTER preserves incoming foreign keys without rebuilding the tickets table.
            DB::transaction(function () {
                DB::statement('ALTER TABLE support_tickets ADD COLUMN vehicle_id INTEGER REFERENCES vehicles (id) ON DELETE RESTRICT');
                Schema::table('support_tickets', function (Blueprint $table) {
                    $table->index('vehicle_id');
                });
            });

            return;
        }

        DB::statement('ALTER TABLE support_tickets
            ADD COLUMN vehicle_id BIGINT UNSIGNED NULL,
            ADD INDEX support_tickets_vehicle_id_index (vehicle_id),
            ADD CONSTRAINT support_tickets_vehicle_id_foreign FOREIGN KEY (vehicle_id) REFERENCES vehicles (id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('support_tickets', 'vehicle_id')) {
            return;
        }
        if (DB::table('support_tickets')->whereNotNull('vehicle_id')->exists()) {
            throw new RuntimeException('Rollback bloqueado: há atendimentos vinculados a veículos.');
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::transaction(function () {
                Schema::table('support_tickets', function (Blueprint $table) {
                    $table->dropIndex(['vehicle_id']);
                });
                DB::statement('ALTER TABLE support_tickets DROP COLUMN vehicle_id');
            });

            return;
        }

        DB::statement('ALTER TABLE support_tickets
            DROP FOREIGN KEY support_tickets_vehicle_id_foreign,
            DROP INDEX support_tickets_vehicle_id_index,
            DROP COLUMN vehicle_id');
    }
};
