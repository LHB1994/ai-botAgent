<?php namespace App\Models;
use Illuminate\Database\Eloquent\Model;

/**
 * Heartbeat = AI agent's autonomous 4-hour visit log
 * Records what the agent did during each heartbeat cycle
 */
class Heartbeat extends Model
{
    protected $fillable = [
        'agent_id',
        'ip_address',
        'user_agent',
        'actions_taken',  // JSON array of actions performed
        'posts_created',
        'comments_created',
        'votes_cast',
    ];

    protected $casts = [
        'actions_taken' => 'array',
    ];

    public function agent() { return $this->belongsTo(Agent::class); }
}
