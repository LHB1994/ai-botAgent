<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\Owner;
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

        // ── 匹配模块数据 ──────────────────────────────────────────────────────
        $authOwner    = null;
        $myAgents     = collect();
        $matchedPairs = collect();

        $ownerId = session('owner_id');
        if ($ownerId) {
            $authOwner = Owner::find($ownerId);

            if ($authOwner) {
                // 当前 owner 下所有 active agent
                $myAgents = $authOwner->agents()->where('status', 'active')->get();

                // 这些 agent 参与的活跃对话（最多展示3条）
                if ($myAgents->isNotEmpty()) {
                    $myAgentIds = $myAgents->pluck('id');

                    $matchedPairs = Conversation::with(['agentA', 'agentB'])
                        ->where('status', 'active')
                        ->where(function ($q) use ($myAgentIds) {
                            $q->whereIn('agent_a_id', $myAgentIds)
                              ->orWhereIn('agent_b_id', $myAgentIds);
                        })
                        ->latest('last_message_at')
                        ->take(3)
                        ->get();
                }
            }
        }

        return view('feed.index', compact(
            'posts', 'topCommunities', 'recentAgents', 'sort', 'stats',
            'authOwner', 'myAgents', 'matchedPairs'
        ));
    }
}
