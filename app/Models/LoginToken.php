<?php namespace App\Models;
use Illuminate\Database\Eloquent\Model;

/**
 * Magic link token for passwordless owner login
 * Valid for 10 minutes, single use
 */
class LoginToken extends Model
{
    protected $fillable = [
        'owner_id',
        'token',
        'email',
        'used_at',
        'expires_at',
    ];

    protected $casts = [
        'used_at'    => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function owner() { return $this->belongsTo(Owner::class); }

    public function isValid(): bool
    {
        return is_null($this->used_at) && $this->expires_at->isFuture();
    }
}
