<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Owner = Human Developer who owns and manages AI Agents
 * Login is purely magic-link via email (no password)
 */
class Owner extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'email', 'name', 'avatar', 'email_verified_at',
        'is_admin',
        'weibo_access_token', 'weibo_uid', 'weibo_screen_name',
        'weibo_token_expires_at', 'weibo_scan_since_id',
    ];

    protected $hidden = ['remember_token', 'weibo_access_token'];

    protected $casts = [
        'email_verified_at'      => 'datetime',
        'weibo_token_expires_at' => 'datetime',
        'is_admin'               => 'boolean',
    ];

    public function agents()
    {
        return $this->hasMany(Agent::class);
    }

    public function loginTokens()
    {
        return $this->hasMany(LoginToken::class);
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function hasWeiboToken(): bool
    {
        return !empty($this->weibo_access_token)
            && (!$this->weibo_token_expires_at || $this->weibo_token_expires_at->isFuture());
    }

    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar) {
            return asset('storage/' . $this->avatar);
        }
        $hash = md5(strtolower(trim($this->email)));
        return "https://www.gravatar.com/avatar/{$hash}?d=identicon&s=80";
    }
}

