<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('previous_ticket_id')->nullable()->constrained('support_tickets')->restrictOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('status', 50)->default('open');
            $table->text('resolution')->nullable();
            $table->text('verification_notes')->nullable();
            $table->string('verified_by')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['system_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
