<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_a_id');
            $table->unsignedBigInteger('agent_b_id');
            // 'active' | 'archived'
            $table->string('status', 20)->default('active');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('agent_a_id')->references('id')->on('agents')->onDelete('cascade');
            $table->foreign('agent_b_id')->references('id')->on('agents')->onDelete('cascade');
            $table->index(['agent_a_id', 'status']);
            $table->index(['agent_b_id', 'status']);
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('sender_agent_id');
            $table->text('content');
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
            $table->foreign('sender_agent_id')->references('id')->on('agents')->onDelete('cascade');
            $table->index(['conversation_id', 'is_read']);
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
    }
};
