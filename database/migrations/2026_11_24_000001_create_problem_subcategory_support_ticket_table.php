<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PIVOT = 'problem_subcategory_support_ticket';

    private const TICKET_OWNER = 'tickets_subcategory_owner_unique';

    public function up(): void
    {
        if (DB::table('support_tickets')->whereNotNull('problem_subcategory_id')->whereNull('problem_category_id')->exists()) {
            throw new RuntimeException('Há vínculos legados de subcategoria sem categoria. Revise-os antes de migrar.');
        }

        // MySQL commits DDL implicitly. Allow retry if the schema was created before a failed backfill.
        if (! Schema::hasIndex('support_tickets', self::TICKET_OWNER)) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->unique(['id', 'problem_category_id'], self::TICKET_OWNER);
            });
        }
        if (! Schema::hasTable(self::PIVOT)) {
            Schema::create(self::PIVOT, function (Blueprint $table) {
                $table->foreignId('support_ticket_id');
                $table->foreignId('problem_subcategory_id');
                $table->foreignId('problem_category_id');
                $table->primary(['support_ticket_id', 'problem_subcategory_id'], 'ticket_subcategory_primary');
                $table->index(['support_ticket_id', 'problem_category_id'], 'ticket_subcategory_ticket_owner_idx');
                $table->index(['problem_subcategory_id', 'problem_category_id', 'support_ticket_id'], 'ticket_subcategory_lookup_idx');
                $table->foreign(['support_ticket_id', 'problem_category_id'], 'ticket_subcategory_ticket_owner_fk')
                    ->references(['id', 'problem_category_id'])->on('support_tickets')->cascadeOnDelete();
                $table->foreign(['problem_subcategory_id', 'problem_category_id'], 'ticket_subcategory_owner_fk')
                    ->references(['id', 'problem_category_id'])->on('problem_subcategories')->restrictOnDelete();
            });
        }

        DB::transaction(function () {
            $legacy = DB::table('support_tickets')->whereNotNull('problem_subcategory_id');
            $missing = DB::table('support_tickets')->leftJoin(self::PIVOT.' as existing_link', function ($join) {
                $join->on('existing_link.support_ticket_id', '=', 'support_tickets.id')
                    ->on('existing_link.problem_subcategory_id', '=', 'support_tickets.problem_subcategory_id');
            })->whereNotNull('support_tickets.problem_subcategory_id')->whereNull('existing_link.support_ticket_id');
            DB::table(self::PIVOT)->insertUsing(
                ['support_ticket_id', 'problem_subcategory_id', 'problem_category_id'],
                (clone $missing)->select('support_tickets.id', 'support_tickets.problem_subcategory_id', 'support_tickets.problem_category_id'),
            );

            if ($missing->exists() || DB::table(self::PIVOT)->count() !== $legacy->count()) {
                throw new RuntimeException('A conferência dos vínculos históricos de subcategoria falhou.');
            }
        });
    }

    public function down(): void
    {
        $links = DB::table(self::PIVOT);
        if ((clone $links)->select('support_ticket_id')->groupBy('support_ticket_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Rollback bloqueado: há atendimentos com múltiplas subcategorias.');
        }

        $legacyCount = DB::table('support_tickets')->whereNotNull('problem_subcategory_id')->count();
        $matchingCount = $links->join('support_tickets', 'support_tickets.id', '=', self::PIVOT.'.support_ticket_id')
            ->whereColumn('support_tickets.problem_subcategory_id', self::PIVOT.'.problem_subcategory_id')->count();
        if ($matchingCount !== $legacyCount || $matchingCount !== DB::table(self::PIVOT)->count()) {
            throw new RuntimeException('Rollback bloqueado: os vínculos e a coluna legada divergem.');
        }

        Schema::drop(self::PIVOT);
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropUnique(self::TICKET_OWNER);
        });
    }
};
