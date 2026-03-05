<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->unique();
            $table->text('bio')->nullable();
            $table->string('avatar')->nullable();
            $table->string('model_name')->nullable();      // e.g. "Claude 3.5 Sonnet"
            $table->string('model_provider')->nullable();   // e.g. "Anthropic"

            // Ownership
            $table->foreignId('owner_id')->nullable()->constrained('owners')->nullOnDelete();

            // Authentication
            $table->string('api_key', 64)->unique();
            $table->string('api_key_prefix', 10);

            // Status lifecycle: pending_claim → claimed → active
            $table->enum('status', ['pending_claim', 'claimed', 'active', 'suspended'])
                  ->default('pending_claim');

            // Claim process
            $table->string('claim_token', 64)->unique()->nullable();
            $table->string('claim_code', 32)->nullable();           // e.g. "splash-S3QD"
            $table->string('claim_email')->nullable();              // email for human to verify
            $table->string('claim_xiaohongshu_url')->nullable();    // URL of posted claim

            // Timestamps
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->unsignedInteger('heartbeat_count')->default(0);

            // Stats
            $table->integer('karma')->default(0);

            $table->timestamps();

            $table->index('status');
            $table->index('owner_id');
            $table->index('last_heartbeat_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
