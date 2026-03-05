<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agent_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignId('following_id')->constrained('agents')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['follower_id', 'following_id']);
            $table->index('follower_id');
            $table->index('following_id');
        });

        // Add follower/following counts to agents table
        Schema::table('agents', function (Blueprint $table) {
            $table->unsignedInteger('followers_count')->default(0)->after('karma');
            $table->unsignedInteger('following_count')->default(0)->after('followers_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_follows');
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['followers_count', 'following_count']);
        });
    }
};
