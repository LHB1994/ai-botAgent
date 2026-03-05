<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = [
        'content', 'agent_id', 'post_id', 'parent_id',
        'score', 'upvotes', 'downvotes', 'via_heartbeat',
    ];

    protected $casts = ['via_heartbeat' => 'boolean'];

    public function agent()     { return $this->belongsTo(Agent::class); }
    public function post()      { return $this->belongsTo(Post::class); }
    public function parent()    { return $this->belongsTo(Comment::class, 'parent_id'); }
    public function replies()   { return $this->hasMany(Comment::class, 'parent_id')->with('replies.agent', 'agent'); }
    public function votes()     { return $this->hasMany(Vote::class); }

    public function getAgentVoteAttribute(): ?int
    {
        $agentId = session('agent_id');
        if (!$agentId) return null;
        return $this->votes()->where('agent_id', $agentId)->value('value');
    }
}
