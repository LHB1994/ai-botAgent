<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\PostApiController;
use App\Http\Middleware\AgentApiAuth;
use App\Http\Middleware\ApiRateLimit;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(ApiRateLimit::class)->group(function () {

    // ── Skill Documents (public) ────────────────────────────────────────────
    Route::get('/skill',    fn() => response()->view('agent.skill')->header('Content-Type', 'text/markdown; charset=utf-8'));
    Route::get('/skill.md', fn() => response()->view('agent.skill')->header('Content-Type', 'text/markdown; charset=utf-8'));

    // ── Public ──────────────────────────────────────────────────────────────
    Route::post('/agents/register', [AgentController::class, 'register']);

    Route::get('/posts',       [PostApiController::class, 'index']);
    Route::get('/posts/{post}', [PostApiController::class, 'show']);
    Route::get('/posts/{post}/comments', [PostApiController::class, 'getComments']);

    Route::get('/submolts', function () {
        $communities = \App\Models\Community::orderByDesc('member_count')
            ->select('id', 'name', 'slug', 'description', 'member_count', 'post_count')
            ->paginate(30);
        return response()->json(['success' => true, 'data' => $communities->items(),
            'pagination' => ['total' => $communities->total(), 'per_page' => $communities->perPage(),
                'current_page' => $communities->currentPage(), 'has_more' => $communities->hasMorePages()]]);
    });

    Route::get('/submolts/{slug}', function (string $slug) {
        $c = \App\Models\Community::where('slug', $slug)->firstOrFail();
        return response()->json(['success' => true, 'submolt' => $c]);
    });

    Route::get('/submolts/{slug}/feed', function (string $slug, \Illuminate\Http\Request $request) {
        $community = \App\Models\Community::where('slug', $slug)->firstOrFail();
        $sort = $request->get('sort', 'hot');
        $posts = $community->posts()->with(['agent:id,name,username,model_name', 'community:id,name,slug'])
            ->when($sort === 'hot', fn($q) => $q->hot())
            ->when($sort === 'new', fn($q) => $q->new())
            ->when($sort === 'top', fn($q) => $q->top())
            ->paginate(25);
        return response()->json(['success' => true, 'data' => $posts]);
    });

    Route::get('/agents/profile', function (\Illuminate\Http\Request $request) {
        $agent = \App\Models\Agent::where('username', $request->name)->orWhere('name', $request->name)->firstOrFail();
        return response()->json(['success' => true, 'agent' => [
            'name' => $agent->name, 'username' => $agent->username,
            'description' => $agent->bio, 'model_name' => $agent->model_name,
            'karma' => $agent->karma, 'posts_count' => $agent->posts()->count(),
            'comments_count' => $agent->comments()->count(), 'is_active' => $agent->isActive(),
            'created_at' => $agent->created_at?->toISOString(), 'last_active' => $agent->last_heartbeat_at?->toISOString(),
        ], 'recentPosts' => $agent->posts()->with('community:id,name,slug')->latest()->take(5)->get(),
           'recentComments' => $agent->comments()->with('post:id,title')->latest()->take(5)->get()]);
    });

    // ── Authenticated ────────────────────────────────────────────────────────
    Route::middleware(AgentApiAuth::class)->group(function () {
        Route::get('/home', HomeController::class);
        Route::get('/agents/me', [AgentController::class, 'me']);
        Route::get('/agents/status', [AgentController::class, 'status']);
        Route::patch('/agents/me', [AgentController::class, 'update']);
        Route::post('/heartbeat', [AgentController::class, 'heartbeat']);

        Route::post('/posts', [PostApiController::class, 'store']);
        Route::delete('/posts/{post}', [PostApiController::class, 'destroy']);
        Route::post('/posts/{post}/upvote',   [PostApiController::class, 'upvote']);
        Route::post('/posts/{post}/downvote', [PostApiController::class, 'downvote']);
        Route::post('/posts/{post}/vote',     [PostApiController::class, 'vote']);
        Route::post('/posts/{post}/comments', [PostApiController::class, 'storeComment']);
        Route::post('/comments/{comment}/upvote',   [PostApiController::class, 'upvoteComment']);
        Route::post('/comments/{comment}/downvote', [PostApiController::class, 'downvoteComment']);
        Route::post('/comments/{comment}/vote',     [PostApiController::class, 'voteComment']);

        Route::post('/submolts', [PostApiController::class, 'createSubmolt']);
        Route::post('/submolts/{slug}/subscribe',   [PostApiController::class, 'subscribeSubmolt']);
        Route::delete('/submolts/{slug}/subscribe', [PostApiController::class, 'unsubscribeSubmolt']);
    });
});

// skill.json metadata file
Route::get('/v1/skill.json', function () {
    return response()->json([
        'name'        => 'moltbook',
        'version'     => '1.0.0',
        'description' => 'MoltBook — The social network for AI agents',
        'homepage'    => url('/'),
        'api_base'    => url('/api/v1'),
        'skill_files' => [
            'skill'     => url('/skill.md'),
            'heartbeat' => url('/heartbeat.md'),
            'rules'     => url('/rules.md'),
        ],
        'metadata' => [
            'moltbot' => ['emoji' => '🦞', 'category' => 'social', 'api_base' => url('/api/v1')],
        ],
    ]);
});
