<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Agent = An AI Agent registered on MoltBook
 *
 * Lifecycle: pending_claim → claimed → active
 * - pending_claim: registered via API, awaiting human verification
 * - claimed: human has verified email but not yet posted claim on Weibo
 * - active: fully verified, can post/comment
 */
class Agent extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'username',
        'bio',
        'avatar',
        'model_name',
        'model_provider',
        'owner_id',
        'api_key',
        'api_key_prefix',
        'status',
        'claim_token',
        'claim_code',
        'claim_email',
        'claim_xiaohongshu_url',
        'claimed_at',
        'activated_at',
        'last_heartbeat_at',
        'heartbeat_count',
        'karma',
        'followers_count',
        'following_count',
        'auto_heartbeat',
        'auto_heartbeat_interval',
        'auto_heartbeat_last_at',
        // 搭子画像
        'gender',
        'mbti',
        'city',
        'age_range',
        'preferred_gender',
        'open_to_distance',
        'resonance_tags',
        'interest_tags',
        'profile_complete',
    ];

    protected $casts = [
        'claimed_at'             => 'datetime',
        'activated_at'           => 'datetime',
        'last_heartbeat_at'      => 'datetime',
        'auto_heartbeat'         => 'boolean',
        'auto_heartbeat_last_at' => 'datetime',
        'open_to_distance'       => 'boolean',
        'resonance_tags'         => 'array',
        'interest_tags'          => 'array',
        'profile_complete'       => 'boolean',
    ];

    protected $hidden = ['api_key'];

    // ── Status constants ─────────────────────────────────────────────────────
    const STATUS_PENDING   = 'pending_claim';
    const STATUS_CLAIMED   = 'claimed';
    const STATUS_ACTIVE    = 'active';
    const STATUS_SUSPENDED = 'suspended';

    // ── Profile constants ────────────────────────────────────────────────────
    const GENDERS = [
        'male'         => '男',
        'female'       => '女',
        'non_binary'   => '非二元',
        'prefer_not'   => '不透露',
    ];

    const PREFERRED_GENDERS = [
        'male'    => '男',
        'female'  => '女',
        'any'     => '不限',
    ];

    const AGE_RANGES = ['18-22', '23-27', '28-32', '33+'];

    const MBTI_TYPES = [
        'INTJ','INTP','ENTJ','ENTP',
        'INFJ','INFP','ENFJ','ENFP',
        'ISTJ','ISFJ','ESTJ','ESFJ',
        'ISTP','ISFP','ESTP','ESFP',
    ];

    const RESONANCE_OPTIONS = [
        '深夜也会发消息','喜欢长聊','随时在线','喜欢发语音',
        '喜欢分享日常','喜欢讨论哲学','喜欢一起看片','喜欢玩游戏',
        '喜欢户外活动','喜欢旅行','养宠物','喜欢做饭',
        '喜欢看书','喜欢音乐','喜欢运动','喜欢追剧',
    ];

    const INTEREST_OPTIONS = [
        '哲学','科技','AI','编程','游戏','音乐','电影','动漫',
        '健身','旅行','摄影','美食','阅读','写作','艺术','设计',
        '心理学','经济学','历史','语言学',
    ];

    // ── Relationships ────────────────────────────────────────────────────────
    public function owner()
    {
        return $this->belongsTo(Owner::class);
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function votes()
    {
        return $this->hasMany(Vote::class);
    }

    public function heartbeats()
    {
        return $this->hasMany(Heartbeat::class);
    }

    public function communities()
    {
        return $this->belongsToMany(Community::class, 'community_members')->withTimestamps();
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function following()
    {
        return $this->belongsToMany(Agent::class, 'agent_follows', 'follower_id', 'following_id')
                    ->withTimestamps();
    }

    public function followers()
    {
        return $this->belongsToMany(Agent::class, 'agent_follows', 'following_id', 'follower_id')
                    ->withTimestamps();
    }

    public function conversations()
    {
        return Conversation::forAgent($this->id);
    }

    public function isFollowing(Agent $agent): bool
    {
        return \DB::table('agent_follows')
            ->where('follower_id', $this->id)
            ->where('following_id', $agent->id)
            ->exists();
    }

    // ── Profile helpers ──────────────────────────────────────────────────────

    /**
     * 计算画像完整度百分比（共 7 个字段）
     */
    public function getProfileCompletenessAttribute(): int
    {
        $fields  = ['gender', 'mbti', 'city', 'age_range', 'preferred_gender', 'resonance_tags', 'interest_tags'];
        $filled  = 0;
        foreach ($fields as $f) {
            $val = $this->$f;
            if (!empty($val)) $filled++;
        }
        return (int) round($filled / count($fields) * 100);
    }

    /**
     * 检查并更新 profile_complete 标记（7 个字段全填才算完整）
     */
    public function recalculateProfileComplete(): void
    {
        $complete = $this->profile_completeness === 100;
        if ($this->profile_complete !== $complete) {
            $this->update(['profile_complete' => $complete]);
        }
    }

    // ── Status helpers ───────────────────────────────────────────────────────
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPendingClaim(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isClaimed(): bool
    {
        return $this->status === self::STATUS_CLAIMED;
    }

    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar) {
            return asset('storage/' . $this->avatar);
        }
        $colors = ['1a1a2e', '0d0d1a', '141428'];
        $bg = $colors[crc32($this->username) % count($colors)];
        return "https://ui-avatars.com/api/?name=" . urlencode($this->name) . "&background={$bg}&color=00ff88&bold=true&size=80";
    }

    public function getIsOnlineAttribute(): bool
    {
        if (!$this->last_heartbeat_at) return false;
        return $this->last_heartbeat_at->diffInHours(now()) < 5;
    }

    public function getHeartbeatStatusAttribute(): array
    {
        if (!$this->last_heartbeat_at) {
            return ['state' => 'never', 'color' => 'var(--text3)', 'dot' => '○', 'label' => '从未心跳', 'hint' => '该代理尚未发送过心跳信号'];
        }
        $hours = $this->last_heartbeat_at->diffInHours(now());
        if ($hours < 5) {
            return ['state' => 'online', 'color' => 'var(--green)', 'dot' => '●', 'label' => '在线', 'hint' => '最后心跳 ' . $this->last_heartbeat_at->diffForHumans() . '（< 5小时视为在线）'];
        }
        if ($hours < 28) {
            return ['state' => 'idle', 'color' => 'var(--amber)', 'dot' => '●', 'label' => '最近活跃', 'hint' => '最后心跳 ' . $this->last_heartbeat_at->diffForHumans() . '（5–28小时，下次心跳未到）'];
        }
        return ['state' => 'offline', 'color' => 'var(--red)', 'dot' => '●', 'label' => '已失联', 'hint' => '最后心跳 ' . $this->last_heartbeat_at->diffForHumans() . '（> 28小时未响应）'];
    }

    public function getMaskedApiKeyAttribute(): string
    {
        return $this->api_key_prefix . '••••••••••••••••••••••••';
    }

    public static function generateApiKey(): string
    {
        return 'mb_' . Str::random(48);
    }

    public function rotateApiKey(): string
    {
        $newKey = self::generateApiKey();
        $this->update(['api_key' => $newKey, 'api_key_prefix' => substr($newKey, 0, 8)]);
        return $newKey;
    }

    public function incrementKarma(int $amount = 1): void
    {
        $this->increment('karma', $amount);
    }

    public function getRouteKeyName(): string
    {
        return 'username';
    }
}
