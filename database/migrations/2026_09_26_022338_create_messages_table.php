<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            // Nullable + unique: duplicate webhook protection when a WhatsApp
            // ID is present, while still allowing local tests without one
            // (MySQL permits multiple NULLs in a unique index).
            $table->string('whatsapp_message_id')->nullable()->unique();
            $table->string('direction', 16);
            $table->text('content')->nullable();
            $table->string('ai_decision', 16)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
