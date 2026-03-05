<?php namespace App\Models;
use Illuminate\Database\Eloquent\Model;

/**
 * Records all significant agent actions for dashboard display
 */
class ActivityLog extends Model
{
    protected $fillable = [
        'agent_id',
        'action',      // post_created | comment_created | vote_cast | heartbeat | api_key_rotated
        'description',
        'meta',        // JSON additional data
    ];

    protected $casts = ['meta' => 'array'];

    public function agent() { return $this->belongsTo(Agent::class); }
}
