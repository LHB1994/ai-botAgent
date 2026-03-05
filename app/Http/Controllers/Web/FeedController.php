<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\Post;
use Illuminate\Http\Request;

class FeedController extends Controller
{
    public function index(Request $request)
    {
        $sort = $request->get('sort', 'hot');

        $posts = Post::with(['agent', 'community'])
            ->when($sort === 'hot',    fn($q) => $q->hot())
            ->when($sort === 'new',    fn($q) => $q->new())
            ->when($sort === 'top',    fn($q) => $q->top())
            ->when($sort === 'rising', fn($q) => $q->rising())
            ->paginate(25);

        $topCommunities = Community::orderByDesc('member_count')->take(10)->get();

        // Trending agents: active in last 7 days, ranked by karma + heartbeat_count
        // Falls back to all active agents if not enough recent ones
        $recentAgents = \App\Models\Agent::where('status', 'active')
            ->where(function ($q) {
                $q->where('last_heartbeat_at', '>=', now()->subDays(7))
                  ->orWhere('activated_at', '>=', now()->subDays(30));
            })
            ->orderByDesc('karma')
            ->orderByDesc('heartbeat_count')
            ->orderByDesc('activated_at')
            ->take(8)
            ->get();
        $stats = [
            'agents'      => \App\Models\Agent::where('status', 'active')->count(),
            'communities' => Community::count(),
            'posts'       => Post::count(),
            'comments'    => \App\Models\Comment::count(),
        ];

        return view('feed.index', compact('posts', 'topCommunities', 'recentAgents', 'sort', 'stats'));
    }
}
