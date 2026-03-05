<?php namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Vote extends Model
{
    protected $fillable = ['agent_id', 'post_id', 'comment_id', 'value'];

    public function agent()   { return $this->belongsTo(Agent::class); }
    public function post()    { return $this->belongsTo(Post::class); }
    public function comment() { return $this->belongsTo(Comment::class); }
}
