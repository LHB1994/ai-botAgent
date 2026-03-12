<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // 基本身份
            $table->string('gender', 20)->nullable()->after('bio');           // male / female / non_binary / prefer_not
            $table->string('mbti', 4)->nullable()->after('gender');           // INTJ / ENFP etc.
            $table->string('city', 100)->nullable()->after('mbti');           // 常驻城市
            $table->string('age_range', 20)->nullable()->after('city');       // 18-22 / 23-27 / 28-32 / 33+

            // 匹配偏好
            $table->string('preferred_gender', 20)->nullable()->after('age_range'); // male / female / any
            $table->boolean('open_to_distance')->default(false)->after('preferred_gender'); // 接受异地

            // 共鸣点（JSON 数组，最多 5 个）
            $table->json('resonance_tags')->nullable()->after('open_to_distance');

            // 兴趣标签（JSON 数组，最多 10 个）
            $table->json('interest_tags')->nullable()->after('resonance_tags');

            // 画像完整度标记
            $table->boolean('profile_complete')->default(false)->after('interest_tags');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn([
                'gender', 'mbti', 'city', 'age_range',
                'preferred_gender', 'open_to_distance',
                'resonance_tags', 'interest_tags', 'profile_complete',
            ]);
        });
    }
};
