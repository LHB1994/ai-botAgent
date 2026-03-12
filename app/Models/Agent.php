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
    ];

    protected $casts = [
        'claimed_at'             => 'datetime',
        'activated_at'           => 'datetime',
        'last_heartbeat_at'      => 'datetime',
        'auto_heartbeat'         => 'boolean',
        'auto_heartbeat_last_at' => 'datetime',
    ];

    protected $hidden = ['api_key'];

    // ── Status constants ────────────────────────────────────────────────────
    const STATUS_PENDING  = 'pending_claim';
    const STATUS_CLAIMED  = 'claimed';
    const STATUS_ACTIVE   = 'active';
    const STATUS_SUSPENDED = 'suspended';

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

    // Agents this agent is following
    public function following()
    {
        return $this->belongsToMany(Agent::class, 'agent_follows', 'follower_id', 'following_id')
                    ->withTimestamps();
    }

    // Agents that follow this agent
    public function followers()
    {
        return $this->belongsToMany(Agent::class, 'agent_follows', 'following_id', 'follower_id')
                    ->withTimestamps();
    }

    public function isFollowing(Agent $agent): bool
    {
        return \DB::table('agent_follows')
            ->where('follower_id', $this->id)
            ->where('following_id', $agent->id)
            ->exists();
    }

    // ── Helper methods ───────────────────────────────────────────────────────
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

    /**
     * Three-state heartbeat status based on 4-hour heartbeat interval:
     *   online  — last heartbeat < 5h ago   (just checked in)
     *   idle    — last heartbeat 5–28h ago  (within normal range, next beat due soon)
     *   offline — last heartbeat > 28h ago  (missed at least one full cycle)
     *   never   — never sent a heartbeat
     *
     * Returns array: ['state' => string, 'color' => string, 'dot' => string, 'label' => string, 'hint' => string]
     */
    public function getHeartbeatStatusAttribute(): array
    {
        if (!$this->last_heartbeat_at) {
            return [
                'state' => 'never',
                'color' => 'var(--text3)',
                'dot'   => '○',
                'label' => '从未心跳',
                'hint'  => '该代理尚未发送过心跳信号',
            ];
        }

        $hours = $this->last_heartbeat_at->diffInHours(now());

        if ($hours < 5) {
            return [
                'state' => 'online',
                'color' => 'var(--green)',
                'dot'   => '●',
                'label' => '在线',
                'hint'  => '最后心跳 ' . $this->last_heartbeat_at->diffForHumans() . '（< 5小时视为在线）',
            ];
        }

        if ($hours < 28) {
            return [
                'state' => 'idle',
                'color' => 'var(--amber)',
                'dot'   => '●',
                'label' => '最近活跃',
                'hint'  => '最后心跳 ' . $this->last_heartbeat_at->diffForHumans() . '（5–28小时，下次心跳未到）',
            ];
        }

        return [
            'state' => 'offline',
            'color' => 'var(--red)',
            'dot'   => '●',
            'label' => '已失联',
            'hint'  => '最后心跳 ' . $this->last_heartbeat_at->diffForHumans() . '（> 28小时未响应）',
        ];
    }

    public function getMaskedApiKeyAttribute(): string
    {
        return $this->api_key_prefix . '••••••••••••••••••••••••';
    }

    /**
     * Generate a fresh API key
     */
    public static function generateApiKey(): string
    {
        return 'mb_' . Str::random(48);
    }

    /**
     * Rotate the agent's API key
     */
    public function rotateApiKey(): string
    {
        $newKey = self::generateApiKey();
        $this->update([
            'api_key'        => $newKey,
            'api_key_prefix' => substr($newKey, 0, 8),
        ]);
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
