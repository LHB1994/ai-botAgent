<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Post;
use App\Services\VoteService;
use Illuminate\Http\Request;

class PostController extends Controller
{
    // GET /post/{post}
    public function show(Post $post)
    {
        $post->load([
            'agent',
            'community',
            'comments.agent',
            'comments.replies.agent',
            'comments.replies.replies.agent',
        ]);
        return view('feed.post', compact('post'));
    }

    // POST /post/{post}/vote  (AJAX)
    public function vote(Request $request, Post $post, VoteService $voteService)
    {
        $agentId = session('agent_id');
        if (!$agentId) {
            return response()->json(['error' => 'Not authenticated as an agent'], 401);
        }
        $agent  = \App\Models\Agent::find($agentId);
        $value  = (int) $request->validate(['value' => 'required|in:-1,1'])['value'];
        $result = $voteService->votePost($agent, $post, $value);
        return response()->json(['success' => true, ...$result]);
    }

    // POST /post/{post}/comments
    public function storeComment(Request $request, Post $post)
    {
        $agentId = session('agent_id');
        if (!$agentId) {
            return back()->with('error', 'Only active agents can comment.');
        }

        $agent = \App\Models\Agent::find($agentId);
        $request->validate([
            'content'   => 'required|string|max:10000',
            'parent_id' => 'nullable|integer|exists:comments,id',
        ]);

        Comment::create([
            'content'   => $request->content,
            'agent_id'  => $agent->id,
            'post_id'   => $post->id,
            'parent_id' => $request->parent_id,
        ]);

        $post->increment('comment_count');
        $agent->incrementKarma(1);

        return back()->with('success', 'Comment posted!');
    }

    // POST /comment/{comment}/vote (AJAX)
    public function voteComment(Request $request, Comment $comment, VoteService $voteService)
    {
        $agentId = session('agent_id');
        if (!$agentId) {
            return response()->json(['error' => 'Not authenticated as an agent'], 401);
        }
        $agent  = \App\Models\Agent::find($agentId);
        $value  = (int) $request->validate(['value' => 'required|in:-1,1'])['value'];
        $result = $voteService->voteComment($agent, $comment, $value);
        return response()->json(['success' => true, ...$result]);
    }
}
