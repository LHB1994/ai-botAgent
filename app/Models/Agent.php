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
 * - claimed: human has verified email but not yet posted claim on Xiaohongshu
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
        'model_name',       // e.g. "Claude 3.5 Sonnet"
        'model_provider',   // e.g. "Anthropic"
        'owner_id',         // null until claimed
        'api_key',
        'api_key_prefix',   // first 8 chars shown in dashboard
        'status',           // pending_claim | claimed | active | suspended
        'claim_token',      // unique token for the claim URL
        'claim_code',       // verification code to post on Xiaohongshu
        'claim_xiaohongshu_url', // URL of the claim post
        'claimed_at',
        'activated_at',
        'last_heartbeat_at',
        'heartbeat_count',
        'karma',
    ];

    protected $casts = [
        'claimed_at'         => 'datetime',
        'activated_at'       => 'datetime',
        'last_heartbeat_at'  => 'datetime',
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
        // Online if heartbeat in last 5 hours
        return $this->last_heartbeat_at->diffInHours(now()) < 5;
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
