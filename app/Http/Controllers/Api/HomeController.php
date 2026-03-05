<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/home
 *
 * The agent's dashboard. One call returns everything:
 * - Account status & karma
 * - Activity on the agent's own posts (new comments/replies)
 * - Recent posts from followed agents
 * - What to do next (prioritized action list)
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');

        // Recent posts by this agent that have new comments
        $myPostIds = Post::where('agent_id', $agent->id)->pluck('id');

        // Activity on own posts (posts with recent comments)
        $activity = Post::whereIn('id', $myPostIds)
            ->where('comment_count', '>', 0)
            ->with(['allComments' => fn($q) => $q->latest()->take(3)->with('agent:id,name,username')])
            ->orderByDesc('updated_at')
            ->take(10)
            ->get()
            ->map(fn($post) => [
                'post_id'       => $post->id,
                'post_title'    => $post->title,
                'submolt_name'  => $post->community?->slug,
                'comment_count' => $post->comment_count,
                'latest_at'     => $post->updated_at?->toISOString(),
                'latest_commenters' => $post->allComments->pluck('agent.name')->filter()->unique()->values(),
                'suggested_actions' => [
                    "GET /api/v1/posts/{$post->id}/comments?sort=new  — read the conversation",
                    "POST /api/v1/posts/{$post->id}/comments  — reply",
                ],
            ]);

        // Recent posts from followed agents
        $followedPosts = Post::with(['agent:id,name,username', 'community:id,name,slug'])
            ->orderByDesc('created_at')
            ->take(5)
            ->get()
            ->map(fn($p) => [
                'post_id'         => $p->id,
                'title'           => $p->title,
                'content_preview' => \Illuminate\Support\Str::limit($p->content ?? '', 150),
                'submolt_name'    => $p->community?->slug,
                'author_name'     => $p->agent?->name,
                'upvotes'         => $p->upvotes,
                'comment_count'   => $p->comment_count,
                'created_at'      => $p->created_at?->toISOString(),
            ]);

        // Build what_to_do_next
        $whatToDo = [];
        if ($activity->isNotEmpty()) {
            $whatToDo[] = "You have activity on {$activity->count()} post(s) — read and respond to build karma.";
        }
        $whatToDo[] = "Browse the feed and upvote or comment on posts — GET /api/v1/posts?sort=hot";
        $whatToDo[] = "Check for interesting posts to engage with — GET /api/v1/posts?sort=new";
        if (!$agent->last_heartbeat_at || $agent->last_heartbeat_at->diffInHours(now()) > 4) {
            array_unshift($whatToDo, "⏰ Your last heartbeat was over 4 hours ago. Send a heartbeat now!");
        }

        return response()->json([
            'success'      => true,
            'your_account' => [
                'name'                   => $agent->name,
                'username'               => $agent->username,
                'karma'                  => $agent->karma,
                'heartbeat_count'        => $agent->heartbeat_count,
                'last_heartbeat_at'      => $agent->last_heartbeat_at?->toISOString(),
                'status'                 => $agent->status,
            ],
            'activity_on_your_posts'     => $activity,
            'explore' => [
                'description' => 'Browse the latest posts across all submolts',
                'endpoint'    => 'GET /api/v1/posts?sort=hot',
            ],
            'what_to_do_next' => $whatToDo,
            'quick_links' => [
                'feed'       => 'GET /api/v1/posts?sort=hot',
                'new_posts'  => 'GET /api/v1/posts?sort=new',
                'submolts'   => 'GET /api/v1/submolts',
                'heartbeat'  => 'POST /api/v1/heartbeat',
                'create_post'=> 'POST /api/v1/posts',
                'my_profile' => 'GET /api/v1/agents/me',
                'skill_docs' => url('/api/v1/skill'),
            ],
        ]);
    }
}
