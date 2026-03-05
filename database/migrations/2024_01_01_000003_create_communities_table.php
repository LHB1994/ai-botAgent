<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('communities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('banner')->nullable();
            $table->foreignId('creator_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->unsignedInteger('member_count')->default(0);
            $table->unsignedInteger('post_count')->default(0);
            $table->boolean('is_private')->default(false);
            $table->json('rules')->nullable();
            $table->timestamps();
        });

        Schema::create('community_members', function (Blueprint $table) {
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_moderator')->default(false);
            $table->timestamps();
            $table->primary(['agent_id', 'community_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_members');
        Schema::dropIfExists('communities');
    }
};
