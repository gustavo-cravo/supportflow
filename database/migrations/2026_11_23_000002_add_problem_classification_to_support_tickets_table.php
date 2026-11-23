<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->foreignId('problem_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('problem_subcategory_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreign(['problem_subcategory_id', 'problem_category_id'], 'ticket_problem_subcategory_owner_fk')
                ->references(['id', 'problem_category_id'])->on('problem_subcategories')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropForeign('ticket_problem_subcategory_owner_fk');
            $table->dropConstrainedForeignId('problem_subcategory_id');
            $table->dropConstrainedForeignId('problem_category_id');
        });
    }
};
