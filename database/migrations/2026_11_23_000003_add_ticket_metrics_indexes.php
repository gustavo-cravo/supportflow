<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->index('created_at', 'tickets_metrics_created_index');
            $table->index(['system_id', 'created_at'], 'tickets_metrics_system_created_index');
            $table->index(['problem_category_id', 'created_at'], 'tickets_metrics_category_created_index');
            $table->index(['problem_subcategory_id', 'created_at'], 'tickets_metrics_subcategory_created_index');
        });
    }

    public function down(): void
    {
        // InnoDB may replace the original automatic FK index with the new
        // category/date index. Restore it before removing the metrics index.
        if (Schema::getConnection()->getDriverName() === 'mysql'
            && ! Schema::hasIndex('support_tickets', 'support_tickets_problem_category_id_foreign')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->index('problem_category_id', 'support_tickets_problem_category_id_foreign');
            });
        }
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_metrics_subcategory_created_index');
            $table->dropIndex('tickets_metrics_category_created_index');
            $table->dropIndex('tickets_metrics_system_created_index');
            $table->dropIndex('tickets_metrics_created_index');
        });
    }
};
