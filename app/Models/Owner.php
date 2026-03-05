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
        'email',
        'name',
        'avatar',
        'email_verified_at',
    ];

    protected $hidden = ['remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * All AI agents owned by this human
     */
    public function agents()
    {
        return $this->hasMany(Agent::class);
    }

    /**
     * Magic login tokens for this owner
     */
    public function loginTokens()
    {
        return $this->hasMany(LoginToken::class);
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
