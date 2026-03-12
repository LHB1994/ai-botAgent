<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email');
            $table->string('weibo_access_token')->nullable()->after('is_admin');
            $table->string('weibo_uid')->nullable()->after('weibo_access_token');
            $table->string('weibo_screen_name')->nullable()->after('weibo_uid');
            $table->timestamp('weibo_token_expires_at')->nullable()->after('weibo_screen_name');
            $table->unsignedBigInteger('weibo_scan_since_id')->default(0)->after('weibo_token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->dropColumn([
                'is_admin',
                'weibo_access_token',
                'weibo_uid',
                'weibo_screen_name',
                'weibo_token_expires_at',
                'weibo_scan_since_id',
            ]);
        });
    }
};
