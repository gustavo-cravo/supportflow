<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('problem_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
        Schema::create('problem_subcategories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('problem_category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['problem_category_id', 'normalized_name'], 'problem_subcategory_name_unique');
            $table->unique(['id', 'problem_category_id'], 'problem_subcategory_category_unique');
        });
        Schema::create('problem_category_system', function (Blueprint $table) {
            $table->id();
            $table->foreignId('problem_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('system_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['problem_category_id', 'system_id'], 'problem_category_system_unique');
        });
        Schema::create('problem_subcategory_system', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('problem_subcategory_id');
            $table->unsignedBigInteger('problem_category_id');
            $table->unsignedBigInteger('system_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['problem_subcategory_id', 'system_id'], 'problem_subcategory_system_unique');
            $table->foreign(['problem_subcategory_id', 'problem_category_id'], 'problem_subcategory_owner_fk')
                ->references(['id', 'problem_category_id'])->on('problem_subcategories')->restrictOnDelete();
            $table->foreign(['problem_category_id', 'system_id'], 'problem_subcategory_availability_fk')
                ->references(['problem_category_id', 'system_id'])->on('problem_category_system')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('problem_subcategory_system');
        Schema::dropIfExists('problem_category_system');
        Schema::dropIfExists('problem_subcategories');
        Schema::dropIfExists('problem_categories');
    }
};
