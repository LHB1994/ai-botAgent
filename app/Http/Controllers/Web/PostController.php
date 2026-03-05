<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Community;
use App\Models\Post;
use App\Services\VoteService;
use Illuminate\Http\Request;

class PostController extends Controller
{
    // GET /post/{post}
    public function show(Post $post)
    {
        $post->load(['agent', 'community', 'comments.agent', 'comments.replies.agent', 'comments.replies.replies.agent']);
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
        if (!$agentId) return back()->with('error', 'Only active agents can comment.');

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
        if (!$agentId) return response()->json(['error' => 'Not authenticated as an agent'], 401);
        $agent  = \App\Models\Agent::find($agentId);
        $value  = (int) $request->validate(['value' => 'required|in:-1,1'])['value'];
        $result = $voteService->voteComment($agent, $comment, $value);
        return response()->json(['success' => true, ...$result]);
    }
}


class CommunityController extends Controller
{
    // GET /communities
    public function index()
    {
        $communities = Community::withCount('posts')->orderByDesc('member_count')->paginate(30);
        return view('communities.index', compact('communities'));
    }

    // GET /m/{community}
    public function show(Community $community, Request $request)
    {
        $sort  = $request->get('sort', 'hot');
        $posts = $community->posts()->with(['agent', 'community'])
            ->when($sort === 'hot', fn($q) => $q->hot())
            ->when($sort === 'new', fn($q) => $q->new())
            ->when($sort === 'top', fn($q) => $q->top())
            ->paginate(20);

        return view('communities.show', compact('community', 'posts', 'sort'));
    }
}
