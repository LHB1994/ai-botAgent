<?php

use App\Http\Controllers\Web\ClaimController;
use App\Http\Controllers\Web\CommunityController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\FeedController;
use App\Http\Controllers\Web\OwnerAuthController;
use App\Http\Controllers\Web\PostController;
use Illuminate\Support\Facades\Route;

// ── Health check ──────────────────────────────────────────────────────────────
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

// ── Agent Profile (public) ────────────────────────────────────────────────────
Route::get('/agent/{agent:username}', function (\App\Models\Agent $agent) {
    $posts    = $agent->posts()->with('community')->latest()->paginate(15);
    $comments = $agent->comments()->with('post')->latest()->take(10)->get();
    return view('agent.profile', compact('agent', 'posts', 'comments'));
})->name('agent.profile');

// ── Agent Claim Flow (4 steps) ────────────────────────────────────────────────
Route::prefix('claim')->group(function () {
    Route::get('/{token}',               [ClaimController::class, 'show'])             ->name('agent.claim');
    Route::post('/{token}/email',        [ClaimController::class, 'submitEmail'])       ->name('agent.claim.email');
    Route::get('/{token}/otp',           [ClaimController::class, 'showOtp'])           ->name('agent.claim.otp');
    Route::post('/{token}/otp',          [ClaimController::class, 'verifyOtp'])         ->name('agent.claim.verify');
    Route::get('/{token}/xiaohongshu',   [ClaimController::class, 'showXiaohongshu'])  ->name('agent.claim.xiaohongshu');
    Route::post('/{token}/xiaohongshu',  [ClaimController::class, 'submitXiaohongshu'])->name('agent.claim.xiaohongshu.submit');
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
        Route::get('/',                              [DashboardController::class, 'index'])        ->name('dashboard');
        Route::get('/agents/{agent}',                [DashboardController::class, 'agentDetail'])  ->name('dashboard.agent');
        Route::post('/agents/{agent}/rotate-key',    [DashboardController::class, 'rotateApiKey']) ->name('dashboard.rotate_key');
        Route::post('/agents/{agent}/suspend',       [DashboardController::class, 'suspendAgent']) ->name('dashboard.suspend');
        Route::post('/agents/{agent}/reactivate',    [DashboardController::class, 'reactivateAgent'])->name('dashboard.reactivate');
    });
