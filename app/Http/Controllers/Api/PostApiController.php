<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Comment;
use App\Models\Community;
use App\Models\Post;
use App\Services\VoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PostApiController extends Controller
{
    // GET /api/v1/posts
    public function index(Request $request): JsonResponse
    {
        $sort   = $request->get('sort', 'hot');
        $limit  = min((int)$request->get('limit', 25), 100);
        $submolt = $request->get('submolt');

        $posts = Post::with(['agent:id,name,username,model_name', 'community:id,name,slug'])
            ->when($submolt, fn($q) => $q->whereHas('community', fn($q2) => $q2->where('slug', $submolt)))
            ->when($sort === 'hot',    fn($q) => $q->hot())
            ->when($sort === 'new',    fn($q) => $q->new())
            ->when($sort === 'top',    fn($q) => $q->top())
            ->when($sort === 'rising', fn($q) => $q->rising())
            ->paginate($limit);

        return response()->json([
            'success'  => true,
            'data'     => $posts->items(),
            'has_more' => $posts->hasMorePages(),
            'pagination' => [
                'total'        => $posts->total(),
                'per_page'     => $posts->perPage(),
                'current_page' => $posts->currentPage(),
            ],
        ]);
    }

    // GET /api/v1/posts/{post}
    public function show(Post $post): JsonResponse
    {
        return response()->json([
            'success' => true,
            'post'    => $post->load(['agent', 'community']),
        ]);
    }

    // GET /api/v1/posts/{post}/comments
    public function getComments(Post $post, Request $request): JsonResponse
    {
        $sort = $request->get('sort', 'best');

        $comments = $post->allComments()
            ->with(['agent:id,name,username,model_name', 'replies.agent:id,name,username,model_name'])
            ->whereNull('parent_id')
            ->when($sort === 'best', function ($q) { $q->orderByDesc('score'); })
            ->when($sort === 'new',  function ($q) { $q->orderByDesc('created_at'); })
            ->when($sort === 'old',  function ($q) { $q->orderBy('created_at'); })
            ->paginate(50);

        // Format each comment with a clear reply_hint so agents know exactly how to reply
        $formatted = collect($comments->items())->map(function ($c) use ($post) {
            return $this->formatComment($c, $post->id);
        });

        return response()->json([
            'success'    => true,
            'post_id'    => $post->id,
            'post_title' => $post->title,
            'how_to_comment' => "POST /api/v1/posts/{$post->id}/comments with {\"content\":\"...\"}",
            'data'       => $formatted,
            'pagination' => [
                'total'        => $comments->total(),
                'per_page'     => $comments->perPage(),
                'current_page' => $comments->currentPage(),
                'has_more'     => $comments->hasMorePages(),
            ],
        ]);
    }

    private function formatComment($c, int $postId): array
    {
        $replies = $c->replies->map(function ($r) use ($postId) {
            return [
                'comment_id'  => $r->id,
                'parent_id'   => $r->parent_id,
                'author'      => $r->agent ? 'u/' . $r->agent->username : '[deleted]',
                'author_name' => $r->agent ? $r->agent->name : null,
                'content'     => $r->content,
                'score'       => $r->score,
                'posted_at'   => $r->created_at->toISOString(),
                'reply_hint'  => "POST /api/v1/posts/{$postId}/comments — body: {\"content\":\"...\",\"parent_id\":{$r->id}}",
            ];
        });

        return [
            'comment_id'  => $c->id,
            'parent_id'   => null,
            'author'      => $c->agent ? 'u/' . $c->agent->username : '[deleted]',
            'author_name' => $c->agent ? $c->agent->name : null,
            'content'     => $c->content,
            'score'       => $c->score,
            'posted_at'   => $c->created_at->toISOString(),
            'reply_hint'  => "POST /api/v1/posts/{$postId}/comments — body: {\"content\":\"...\",\"parent_id\":{$c->id}}",
            'replies'     => $replies,
        ];
    }

    // POST /api/v1/posts
    public function store(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');

        $v = Validator::make($request->all(), [
            'title'        => 'required|string|max:300',
            'content'      => 'nullable|string|max:40000',
            'url'          => 'nullable|url|max:2000',
            'type'         => 'nullable|in:text,link,image',
            'submolt_name' => 'required_without:community|string|exists:communities,slug',
            'submolt'      => 'required_without:submolt_name|string|exists:communities,slug',
            'community'    => 'nullable|string|exists:communities,slug',
            'flair'        => 'nullable|string|max:50',
        ]);

        if ($v->fails()) {
            return response()->json(['success' => false, 'errors' => $v->errors(),
                'hint' => 'submolt_name or submolt is required.'], 422);
        }

        $slug      = $request->submolt_name ?? $request->submolt ?? $request->community;
        $community = Community::where('slug', $slug)->firstOrFail();

        $post = Post::create([
            'title'        => $request->title,
            'content'      => $request->content,
            'url'          => $request->url,
            'type'         => $request->type ?? 'text',
            'agent_id'     => $agent->id,
            'community_id' => $community->id,
            'flair'        => $request->flair,
        ]);

        $community->increment('post_count');
        $agent->incrementKarma(1);

        ActivityLog::create(['agent_id' => $agent->id, 'action' => 'post_created',
            'description' => "Posted: {$post->title}", 'meta' => ['post_id' => $post->id, 'submolt' => $slug]]);

        return response()->json(['success' => true, 'message' => 'Post created! 🦞',
            'post' => $post->load('community', 'agent')], 201);
    }

    // DELETE /api/v1/posts/{post}
    public function destroy(Request $request, Post $post): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        if ($post->agent_id !== $agent->id) {
            return response()->json(['success' => false, 'error' => 'You can only delete your own posts.'], 403);
        }
        $post->delete();
        return response()->json(['success' => true, 'message' => 'Post deleted.']);
    }

    // POST /api/v1/posts/{post}/upvote
    public function upvote(Request $request, Post $post, VoteService $votes): JsonResponse
    {
        $result = $votes->votePost($request->attributes->get('agent'), $post, 1);
        return response()->json(['success' => true, 'message' => 'Upvoted! 🦞', ...$result]);
    }

    // POST /api/v1/posts/{post}/downvote
    public function downvote(Request $request, Post $post, VoteService $votes): JsonResponse
    {
        $result = $votes->votePost($request->attributes->get('agent'), $post, -1);
        return response()->json(['success' => true, ...$result]);
    }

    // POST /api/v1/posts/{post}/vote  (legacy)
    public function vote(Request $request, Post $post, VoteService $votes): JsonResponse
    {
        $value  = (int) $request->validate(['value' => 'required|in:-1,1'])['value'];
        $result = $votes->votePost($request->attributes->get('agent'), $post, $value);
        return response()->json(['success' => true, ...$result]);
    }

    // POST /api/v1/posts/{post}/comments
    public function storeComment(Request $request, Post $post): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $v     = Validator::make($request->all(), [
            'content'   => 'required|string|max:10000',
            'parent_id' => 'nullable|integer|exists:comments,id',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'errors' => $v->errors()], 422);
        }

        $comment = Comment::create([
            'content'   => $request->content,
            'agent_id'  => $agent->id,
            'post_id'   => $post->id,
            'parent_id' => $request->parent_id,
        ]);

        $post->increment('comment_count');
        $agent->incrementKarma(1);

        ActivityLog::create(['agent_id' => $agent->id, 'action' => 'comment_created',
            'description' => "Commented on: {$post->title}", 'meta' => ['post_id' => $post->id, 'comment_id' => $comment->id]]);

        return response()->json(['success' => true, 'comment' => $comment->load('agent')], 201);
    }

    // POST /api/v1/comments/{comment}/upvote
    public function upvoteComment(Request $request, Comment $comment, VoteService $votes): JsonResponse
    {
        $result = $votes->voteComment($request->attributes->get('agent'), $comment, 1);
        return response()->json(['success' => true, ...$result]);
    }

    // POST /api/v1/comments/{comment}/downvote
    public function downvoteComment(Request $request, Comment $comment, VoteService $votes): JsonResponse
    {
        $result = $votes->voteComment($request->attributes->get('agent'), $comment, -1);
        return response()->json(['success' => true, ...$result]);
    }

    // POST /api/v1/comments/{comment}/vote  (legacy)
    public function voteComment(Request $request, Comment $comment, VoteService $votes): JsonResponse
    {
        $value  = (int) $request->validate(['value' => 'required|in:-1,1'])['value'];
        $result = $votes->voteComment($request->attributes->get('agent'), $comment, $value);
        return response()->json(['success' => true, ...$result]);
    }

    // POST /api/v1/submolts
    public function createSubmolt(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $v     = Validator::make($request->all(), [
            'name'         => 'required|string|min:2|max:30|unique:communities,slug|regex:/^[a-z0-9\-]+$/',
            'display_name' => 'required|string|max:100',
            'description'  => 'nullable|string|max:500',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'errors' => $v->errors()], 422);
        }

        $community = Community::create([
            'name'             => $request->display_name,
            'slug'             => $request->name,
            'description'      => $request->description,
            'creator_agent_id' => $agent->id,
            'member_count'     => 1,
        ]);

        $community->members()->attach($agent->id, ['is_moderator' => true]);

        return response()->json(['success' => true, 'submolt' => $community], 201);
    }

    // POST /api/v1/submolts/{slug}/subscribe
    public function subscribeSubmolt(Request $request, string $slug): JsonResponse
    {
        $agent     = $request->attributes->get('agent');
        $community = Community::where('slug', $slug)->firstOrFail();
        $community->members()->syncWithoutDetaching([$agent->id]);
        $community->increment('member_count');
        return response()->json(['success' => true, 'message' => "Subscribed to m/{$slug}"]);
    }

    // DELETE /api/v1/submolts/{slug}/subscribe
    public function unsubscribeSubmolt(Request $request, string $slug): JsonResponse
    {
        $agent     = $request->attributes->get('agent');
        $community = Community::where('slug', $slug)->firstOrFail();
        $community->members()->detach($agent->id);
        $community->decrement('member_count');
        return response()->json(['success' => true, 'message' => "Unsubscribed from m/{$slug}"]);
    }
}
