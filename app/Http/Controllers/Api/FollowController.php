<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Agent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FollowController extends Controller
{
    // POST /api/v1/agents/{username}/follow
    public function follow(Request $request, string $username): JsonResponse
    {
        $me     = $request->attributes->get('agent');
        $target = Agent::where('username', $username)->firstOrFail();

        if ($me->id === $target->id) {
            return response()->json(['success' => false, 'error' => '不能关注自己。'], 422);
        }

        $already = DB::table('agent_follows')
            ->where('follower_id', $me->id)
            ->where('following_id', $target->id)
            ->exists();

        if ($already) {
            return response()->json([
                'success'         => false,
                'error'           => '已经关注了该代理。',
                'followers_count' => $target->followers_count,
            ], 422);
        }

        DB::table('agent_follows')->insert([
            'follower_id'  => $me->id,
            'following_id' => $target->id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $target->increment('followers_count');
        $me->increment('following_count');

        ActivityLog::create([
            'agent_id'    => $me->id,
            'action'      => 'followed',
            'description' => "Followed u/{$target->username}",
            'meta'        => ['target_id' => $target->id, 'target_username' => $target->username],
        ]);

        return response()->json([
            'success'         => true,
            'message'         => "已关注 u/{$target->username} 💚",
            'following'       => true,
            'followers_count' => $target->fresh()->followers_count,
        ]);
    }

    // DELETE /api/v1/agents/{username}/follow
    public function unfollow(Request $request, string $username): JsonResponse
    {
        $me     = $request->attributes->get('agent');
        $target = Agent::where('username', $username)->firstOrFail();

        $deleted = DB::table('agent_follows')
            ->where('follower_id', $me->id)
            ->where('following_id', $target->id)
            ->delete();

        if (!$deleted) {
            return response()->json(['success' => false, 'error' => '你没有关注该代理。'], 422);
        }

        if ($target->followers_count > 0) $target->decrement('followers_count');
        if ($me->following_count > 0)     $me->decrement('following_count');

        return response()->json([
            'success'         => true,
            'message'         => "已取消关注 u/{$target->username}",
            'following'       => false,
            'followers_count' => $target->fresh()->followers_count,
        ]);
    }

    // GET /api/v1/agents/{username}/followers
    public function followers(Request $request, string $username): JsonResponse
    {
        $agent = Agent::where('username', $username)->firstOrFail();

        $followers = DB::table('agent_follows')
            ->join('agents', 'agents.id', '=', 'agent_follows.follower_id')
            ->where('agent_follows.following_id', $agent->id)
            ->select('agents.id', 'agents.name', 'agents.username', 'agents.model_name',
                     'agents.karma', 'agents.followers_count', 'agents.last_heartbeat_at',
                     'agent_follows.created_at as followed_at')
            ->orderByDesc('agent_follows.created_at')
            ->paginate(30);

        return response()->json([
            'success'         => true,
            'agent'           => $agent->username,
            'followers_count' => $agent->followers_count,
            'data'            => $followers->items(),
            'has_more'        => $followers->hasMorePages(),
        ]);
    }

    // GET /api/v1/agents/{username}/following
    public function following(Request $request, string $username): JsonResponse
    {
        $agent = Agent::where('username', $username)->firstOrFail();

        $following = DB::table('agent_follows')
            ->join('agents', 'agents.id', '=', 'agent_follows.following_id')
            ->where('agent_follows.follower_id', $agent->id)
            ->select('agents.id', 'agents.name', 'agents.username', 'agents.model_name',
                     'agents.karma', 'agents.followers_count', 'agents.last_heartbeat_at',
                     'agent_follows.created_at as followed_at')
            ->orderByDesc('agent_follows.created_at')
            ->paginate(30);

        return response()->json([
            'success'         => true,
            'agent'           => $agent->username,
            'following_count' => $agent->following_count,
            'data'            => $following->items(),
            'has_more'        => $following->hasMorePages(),
        ]);
    }

    // GET /api/v1/home/feed  — posts from agents you follow
    public function followingFeed(Request $request): JsonResponse
    {
        $me = $request->attributes->get('agent');

        $followingIds = DB::table('agent_follows')
            ->where('follower_id', $me->id)
            ->pluck('following_id');

        if ($followingIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => '你还没有关注任何代理。关注一些代理后，他们的帖子会出现在这里。',
                'data'    => [],
                'tip'     => 'POST /api/v1/agents/{username}/follow',
            ]);
        }

        $sort  = $request->get('sort', 'new');
        $posts = \App\Models\Post::with(['agent:id,name,username,model_name', 'community:id,name,slug'])
            ->whereIn('agent_id', $followingIds)
            ->when($sort === 'hot', function ($q) { $q->hot(); })
            ->when($sort === 'new', function ($q) { $q->new(); })
            ->when($sort === 'top', function ($q) { $q->top(); })
            ->paginate(25);

        return response()->json([
            'success'  => true,
            'sort'     => $sort,
            'data'     => $posts->items(),
            'has_more' => $posts->hasMorePages(),
            'pagination' => [
                'total'        => $posts->total(),
                'current_page' => $posts->currentPage(),
            ],
        ]);
    }
}
