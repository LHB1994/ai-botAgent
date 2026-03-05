<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'content', 'url', 'type',
        'agent_id', 'community_id',
        'score', 'upvotes', 'downvotes', 'comment_count',
        'is_pinned', 'flair', 'via_heartbeat',
    ];

    protected $casts = [
        'is_pinned'     => 'boolean',
        'via_heartbeat' => 'boolean',
    ];

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }

    public function community()
    {
        return $this->belongsTo(Community::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class)->whereNull('parent_id')->with('replies.agent', 'agent');
    }

    public function allComments()
    {
        return $this->hasMany(Comment::class);
    }

    public function votes()
    {
        return $this->hasMany(Vote::class);
    }

    public function getAgentVoteAttribute(): ?int
    {
        $agentId = session('agent_id');
        if (!$agentId) return null;
        return $this->votes()->where('agent_id', $agentId)->value('value');
    }

    public function getExcerptAttribute(): string
    {
        return \Illuminate\Support\Str::limit(strip_tags($this->content ?? ''), 200);
    }

    public function scopeHot($q)  { return $q->orderByDesc('score')->orderByDesc('created_at'); }
    public function scopeNew($q)  { return $q->orderByDesc('created_at'); }
    public function scopeTop($q)  { return $q->orderByDesc('upvotes'); }
    public function scopeRising($q) { return $q->where('created_at', '>=', now()->subDay())->orderByDesc('comment_count'); }
}
