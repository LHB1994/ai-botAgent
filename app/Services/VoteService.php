<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Post;
use App\Models\Comment;
use App\Models\Vote;
use App\Models\ActivityLog;

class VoteService
{
    /**
     * Cast or toggle a vote on a post
     * Returns ['score' => int, 'upvotes' => int, 'action' => 'added|removed|changed']
     */
    public function votePost(Agent $agent, Post $post, int $value): array
    {
        $existing = Vote::where('agent_id', $agent->id)->where('post_id', $post->id)->first();

        if ($existing) {
            if ($existing->value === $value) {
                // Toggle off
                $existing->delete();
                $post->decrement($value === 1 ? 'upvotes' : 'downvotes');
                $action = 'removed';
            } else {
                // Change vote direction
                $existing->update(['value' => $value]);
                if ($value === 1) { $post->increment('upvotes'); $post->decrement('downvotes'); }
                else              { $post->increment('downvotes'); $post->decrement('upvotes'); }
                $action = 'changed';
            }
        } else {
            Vote::create(['agent_id' => $agent->id, 'post_id' => $post->id, 'value' => $value]);
            $post->increment($value === 1 ? 'upvotes' : 'downvotes');
            $action = 'added';
            ActivityLog::create([
                'agent_id'    => $agent->id,
                'action'      => 'vote_cast',
                'description' => "Voted " . ($value === 1 ? 'up' : 'down') . " on post: {$post->title}",
                'meta'        => ['post_id' => $post->id],
            ]);
        }

        $post->update(['score' => $post->upvotes - $post->downvotes]);
        return ['score' => $post->score, 'upvotes' => $post->upvotes, 'action' => $action];
    }

    /**
     * Cast or toggle a vote on a comment
     */
    public function voteComment(Agent $agent, Comment $comment, int $value): array
    {
        $existing = Vote::where('agent_id', $agent->id)->where('comment_id', $comment->id)->first();

        if ($existing) {
            if ($existing->value === $value) {
                $existing->delete();
                $comment->decrement($value === 1 ? 'upvotes' : 'downvotes');
                $action = 'removed';
            } else {
                $existing->update(['value' => $value]);
                if ($value === 1) { $comment->increment('upvotes'); $comment->decrement('downvotes'); }
                else              { $comment->increment('downvotes'); $comment->decrement('upvotes'); }
                $action = 'changed';
            }
        } else {
            Vote::create(['agent_id' => $agent->id, 'comment_id' => $comment->id, 'value' => $value]);
            $comment->increment($value === 1 ? 'upvotes' : 'downvotes');
            $action = 'added';
        }

        $comment->update(['score' => $comment->upvotes - $comment->downvotes]);
        return ['score' => $comment->score, 'action' => $action];
    }
}
