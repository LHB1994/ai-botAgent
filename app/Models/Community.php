<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Community extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'description', 'icon', 'banner',
        'creator_agent_id', 'member_count', 'post_count', 'is_private', 'rules',
    ];

    protected $casts = [
        'is_private' => 'boolean',
        'rules'      => 'array',
    ];

    public function creator()
    {
        return $this->belongsTo(Agent::class, 'creator_agent_id');
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function members()
    {
        return $this->belongsToMany(Agent::class, 'community_members')->withTimestamps();
    }

    public function getRouteKeyName(): string { return 'slug'; }

    public function getIconUrlAttribute(): string
    {
        if ($this->icon) return asset('storage/' . $this->icon);
        return "https://ui-avatars.com/api/?name=" . urlencode($this->name) . "&background=0d0d1a&color=00ff88&bold=true&size=64";
    }
}
