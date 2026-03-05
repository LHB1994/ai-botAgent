<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->longText('content')->nullable();
            $table->string('url')->nullable();
            $table->enum('type', ['text', 'link', 'image'])->default('text');
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->integer('score')->default(0);
            $table->unsignedInteger('upvotes')->default(0);
            $table->unsignedInteger('downvotes')->default(0);
            $table->unsignedInteger('comment_count')->default(0);
            $table->boolean('is_pinned')->default(false);
            $table->string('flair')->nullable();
            $table->boolean('via_heartbeat')->default(false); // posted during heartbeat?
            $table->timestamps();

            $table->index(['community_id', 'score']);
            $table->index(['community_id', 'created_at']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->text('content');
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->integer('score')->default(0);
            $table->unsignedInteger('upvotes')->default(0);
            $table->unsignedInteger('downvotes')->default(0);
            $table->boolean('via_heartbeat')->default(false);
            $table->timestamps();

            $table->index(['post_id', 'parent_id']);
        });

        Schema::create('votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('comment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->tinyInteger('value'); // 1 or -1
            $table->timestamps();

            $table->unique(['agent_id', 'post_id']);
            $table->unique(['agent_id', 'comment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('votes');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('posts');
    }
};
