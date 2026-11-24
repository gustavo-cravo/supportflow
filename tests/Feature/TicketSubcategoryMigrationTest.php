<?php

use App\Models\Contact;
use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Models\SupportTicket;
use App\Models\System;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the additive migration preserves legacy tickets including inactive and missing classifications', function () {
    $migration = require database_path('migrations/2026_11_24_000001_create_problem_subcategory_support_ticket_table.php');
    $migration->down();
    $category = ProblemCategory::create(['name' => 'Categoria antiga', 'is_active' => false]);
    $old = ProblemSubcategory::create(['name' => 'Subcategoria antiga', 'is_active' => false, 'problem_category_id' => $category->id]);
    $base = [
        'title' => 'Histórico', 'description' => 'Preservar', 'system_id' => System::create(['name' => 'Legado', 'is_active' => false])->id,
        'contact_id' => Contact::create(['name' => 'Contato histórico'])->id,
    ];
    $historic = SupportTicket::create([...$base, 'problem_category_id' => $category->id, 'problem_subcategory_id' => $old->id]);
    $uncategorized = SupportTicket::create($base);
    $categoryOnly = SupportTicket::create([...$base, 'problem_category_id' => $category->id]);
    $before = DB::table('support_tickets')->orderBy('id')->get()->toJson();

    $migration->up();
    $migration->up(); // A retry after MySQL has created the schema cannot duplicate links.
    expect(DB::table('support_tickets')->orderBy('id')->get()->toJson())->toBe($before);
    expect($historic->problemSubcategories()->sole()->is_active)->toBeFalse();
    expect($uncategorized->problemSubcategories()->count())->toBe(0);
    expect($categoryOnly->problemSubcategories()->count())->toBe(0);
    $this->assertDatabaseCount(SupportTicket::SUBCATEGORY_PIVOT, 1);

    $pivot = ['problem_category_id' => $category->id];
    expect(fn () => $historic->problemSubcategories()->attach($old->id, $pivot))->toThrow(QueryException::class);
    expect(fn () => $uncategorized->problemSubcategories()->attach($old->id, $pivot))->toThrow(QueryException::class);
    $otherCategory = ProblemCategory::create(['name' => 'Outra categoria']);
    $unrelated = ProblemSubcategory::create(['name' => 'Subcategoria incompatível', 'problem_category_id' => $otherCategory->id]);
    expect(fn () => $historic->problemSubcategories()->attach($unrelated->id, $pivot))->toThrow(QueryException::class);

    $second = ProblemSubcategory::create(['name' => 'Segundo vínculo', 'problem_category_id' => $category->id]);
    $historic->problemSubcategories()->attach($second->id, $pivot);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'múltiplas subcategorias');
    $this->assertDatabaseCount(SupportTicket::SUBCATEGORY_PIVOT, 2);
    $historic->problemSubcategories()->detach($second->id);
    $migration->down();
    expect(Schema::hasTable(SupportTicket::SUBCATEGORY_PIVOT))->toBeFalse();
    expect(DB::table('support_tickets')->orderBy('id')->get()->toJson())->toBe($before);
    $migration->up();
});
