<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('problem');
            $table->text('resolution_procedure');
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_articles');
    }
};
