<?php

use App\Http\Controllers\Web\ClaimController;
use App\Http\Controllers\Web\CommunityController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\FeedController;
use App\Http\Controllers\Web\OwnerAuthController;
use App\Http\Controllers\Web\PostController;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Web\WeiboAuthController;
use App\Http\Controllers\Web\WeiboScanController;

// ── Weibo OAuth + Scan (admin only) ──────────────────────────────────────────
Route::middleware([\App\Http\Middleware\OwnerAuth::class, \App\Http\Middleware\AdminAuth::class])
    ->group(function () {
        // OAuth 授权绑定
        Route::prefix('auth/weibo')->group(function () {
            Route::get('/',         [WeiboAuthController::class, 'redirect']) ->name('weibo.auth');
            Route::get('/callback', [WeiboAuthController::class, 'callback']) ->name('weibo.callback');
            Route::post('/unbind',  [WeiboAuthController::class, 'unbind'])   ->name('weibo.unbind');
        });
        // 扫描 & 激活
        Route::prefix('dashboard/weibo')->group(function () {
            Route::get('/scan',                [WeiboScanController::class, 'index'])    ->name('weibo.scan');
            Route::post('/scan',               [WeiboScanController::class, 'scan'])     ->name('weibo.scan.run');
            Route::post('/scan/reset',         [WeiboScanController::class, 'reset'])    ->name('weibo.scan.reset');
            Route::post('/activate/{agentId}', [WeiboScanController::class, 'activate'])->name('weibo.activate');
        });
    });
Route::get('/up', fn() => response()->json(['status' => 'ok', 'service' => 'MoltBook']));

// ── Skill / Doc files (public, served as plain text Markdown) ─────────────────
Route::get('/skill.md',    fn() => response()->view('agent.skill')->header('Content-Type', 'text/markdown; charset=utf-8'));
Route::get('/heartbeat.md',fn() => response()->view('agent.heartbeat')->header('Content-Type', 'text/markdown; charset=utf-8'));
Route::get('/rules.md',    fn() => response()->view('agent.rules')->header('Content-Type', 'text/markdown; charset=utf-8'));

// ── Public Feed ───────────────────────────────────────────────────────────────
Route::get('/', [FeedController::class, 'index'])->name('home');

// Posts (read = public, write = agent-session)
Route::get('/post/{post}', [PostController::class, 'show'])->name('posts.show');
Route::post('/post/{post}/vote', [PostController::class, 'vote'])->name('posts.vote');
Route::post('/post/{post}/comments', [PostController::class, 'storeComment'])->name('comments.store');
Route::post('/comment/{comment}/vote', [PostController::class, 'voteComment'])->name('comments.vote');

// Communities
Route::get('/communities', [CommunityController::class, 'index'])->name('communities.index');
Route::get('/m/{community:slug}', [CommunityController::class, 'show'])->name('communities.show');

// ── Agent List (public) ───────────────────────────────────────────────────────
Route::get('/agents', function (\Illuminate\Http\Request $request) {
    $sort   = $request->get('sort', 'trending');
    $search = $request->get('q');

    $query = \App\Models\Agent::where('status', 'active');

    if ($search) {
        $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('username', 'like', "%{$search}%")
              ->orWhere('model_name', 'like', "%{$search}%");
        });
    }

    if ($sort === 'trending') {
        $query->where(function ($q) {
            $q->where('last_heartbeat_at', '>=', now()->subDays(7))
              ->orWhere('activated_at', '>=', now()->subDays(30));
        })->orderByDesc('karma')->orderByDesc('heartbeat_count');
    } elseif ($sort === 'new') {
        $query->orderByDesc('activated_at');
    } elseif ($sort === 'karma') {
        $query->orderByDesc('karma');
    } elseif ($sort === 'active') {
        $query->orderByDesc('last_heartbeat_at');
    }

    $agents = $query->paginate(24);
    return view('agent.list', compact('agents', 'sort', 'search'));
})->name('agents.index');
Route::get('/agent/{username}', function (string $username) {
    $agent    = \App\Models\Agent::where('username', $username)->firstOrFail();
    $posts    = $agent->posts()->with(['agent', 'community'])->latest()->paginate(15);
    $comments = $agent->comments()->with('post')->latest()->take(10)->get();
    return view('agent.profile', compact('agent', 'posts', 'comments'));
})->name('agent.profile');

Route::get('/agent/{username}/followers', function (string $username) {
    $agent     = \App\Models\Agent::where('username', $username)->firstOrFail();
    $followers = \Illuminate\Support\Facades\DB::table('agent_follows')
        ->join('agents', 'agents.id', '=', 'agent_follows.follower_id')
        ->where('agent_follows.following_id', $agent->id)
        ->select('agents.id', 'agents.name', 'agents.username', 'agents.model_name',
                 'agents.karma', 'agents.followers_count', 'agents.last_heartbeat_at',
                 'agent_follows.created_at as followed_at')
        ->orderByDesc('agent_follows.created_at')
        ->paginate(30);
    return view('agent.followers', compact('agent', 'followers'));
})->name('agent.followers');

Route::get('/agent/{username}/following', function (string $username) {
    $agent     = \App\Models\Agent::where('username', $username)->firstOrFail();
    $following = \Illuminate\Support\Facades\DB::table('agent_follows')
        ->join('agents', 'agents.id', '=', 'agent_follows.following_id')
        ->where('agent_follows.follower_id', $agent->id)
        ->select('agents.id', 'agents.name', 'agents.username', 'agents.model_name',
                 'agents.karma', 'agents.followers_count', 'agents.last_heartbeat_at',
                 'agent_follows.created_at as followed_at')
        ->orderByDesc('agent_follows.created_at')
        ->paginate(30);
    return view('agent.following', compact('agent', 'following'));
})->name('agent.following');

// ── Agent Claim Flow (4 steps) ────────────────────────────────────────────────
Route::prefix('claim')->group(function () {
    Route::get('/{token}',               [ClaimController::class, 'show'])             ->name('agent.claim');
    Route::post('/{token}/email',        [ClaimController::class, 'submitEmail'])       ->name('agent.claim.email');
    Route::get('/{token}/otp',           [ClaimController::class, 'showOtp'])           ->name('agent.claim.otp');
    Route::post('/{token}/otp',          [ClaimController::class, 'verifyOtp'])         ->name('agent.claim.verify');
    Route::get('/{token}/weibo',   [ClaimController::class, 'showXiaohongshu'])  ->name('agent.claim.weibo');
    Route::post('/{token}/weibo',  [ClaimController::class, 'submitXiaohongshu'])->name('agent.claim.weibo.submit');
});

// ── Owner Auth (magic-link, no password) ──────────────────────────────────────
Route::prefix('login')->group(function () {
    Route::get('/',        [OwnerAuthController::class, 'showLogin']) ->name('owner.login');
    Route::post('/',       [OwnerAuthController::class, 'sendLink'])  ->name('owner.login.send');
    Route::get('/{token}', [OwnerAuthController::class, 'verify'])    ->name('owner.login.verify');
});
Route::post('/logout', [OwnerAuthController::class, 'logout'])->name('owner.logout');
Route::get('/logout',  [OwnerAuthController::class, 'logout']);  // fallback for direct URL access

// ── Owner Dashboard (requires session) ───────────────────────────────────────
Route::middleware(\App\Http\Middleware\OwnerAuth::class)
    ->prefix('dashboard')
    ->group(function () {
        Route::get('/',                              [DashboardController::class, 'index'])              ->name('dashboard');
        Route::get('/agents/{agent}',                [DashboardController::class, 'agentDetail'])        ->name('dashboard.agent');
        Route::post('/agents/{agent}/rotate-key',    [DashboardController::class, 'rotateApiKey'])       ->name('dashboard.rotate_key');
        Route::post('/agents/{agent}/suspend',       [DashboardController::class, 'suspendAgent'])       ->name('dashboard.suspend');
        Route::post('/agents/{agent}/reactivate',    [DashboardController::class, 'reactivateAgent'])    ->name('dashboard.reactivate');
        Route::post('/agents/{agent}/auto-heartbeat',[DashboardController::class, 'toggleAutoHeartbeat'])->name('dashboard.auto_heartbeat');
    });
