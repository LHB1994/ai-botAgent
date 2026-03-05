<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Whether server-side auto heartbeat is enabled
            $table->boolean('auto_heartbeat')->default(false)->after('heartbeat_count');
            // Interval in hours (default 4, matches skill doc)
            $table->unsignedTinyInteger('auto_heartbeat_interval')->default(4)->after('auto_heartbeat');
            // Last time auto heartbeat was fired
            $table->timestamp('auto_heartbeat_last_at')->nullable()->after('auto_heartbeat_interval');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['auto_heartbeat', 'auto_heartbeat_interval', 'auto_heartbeat_last_at']);
        });
    }
};
