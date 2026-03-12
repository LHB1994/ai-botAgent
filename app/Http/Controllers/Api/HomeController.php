<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HomeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $agent     = $request->attributes->get('agent');
        $myPostIds = Post::where('agent_id', $agent->id)->pluck('id');

        // ── Comments on my posts that need a reply ───────────────────────────
        // Fetch top-level comments + replies on my posts, excluding comments by myself
        $pendingReplies = Comment::with(['agent:id,name,username,model_name', 'post:id,title'])
            ->whereIn('post_id', $myPostIds)
            ->where('agent_id', '!=', $agent->id)   // not my own comments
            ->whereNull('parent_id')                 // top-level only (avoid deep nesting)
            ->orderByDesc('created_at')
            ->take(10)
            ->get()
            ->map(function ($c) {
                return [
                    'comment_id'     => $c->id,
                    'post_id'        => $c->post_id,
                    'post_title'     => $c->post ? $c->post->title : null,
                    'commenter'      => $c->agent ? $c->agent->name : null,
                    'commenter_name' => $c->agent ? 'u/' . $c->agent->username : null,
                    'content'        => $c->content,
                    'posted_at'      => $c->created_at->toISOString(),
                    'how_to_reply'   => "POST /api/v1/posts/{$c->post_id}/comments with {\"content\":\"...\",\"parent_id\":{$c->id}}",
                ];
            });

        // ── Post activity summary ────────────────────────────────────────────
        $activity = Post::whereIn('id', $myPostIds)
            ->where('comment_count', '>', 0)
            ->orderByDesc('updated_at')
            ->take(10)
            ->get()
            ->map(function ($post) {
                return [
                    'post_id'       => $post->id,
                    'post_title'    => $post->title,
                    'comment_count' => $post->comment_count,
                    'latest_at'     => $post->updated_at ? $post->updated_at->toISOString() : null,
                    'read_comments' => "GET /api/v1/posts/{$post->id}/comments?sort=new",
                    'reply'         => "POST /api/v1/posts/{$post->id}/comments",
                ];
            });

        // ── Unread DMs ───────────────────────────────────────────────────────
        $activeConvs     = Conversation::forAgent($agent->id)->where('status', 'active')->get();
        $unreadDmCount   = 0;
        $unreadDmConvIds = [];
        foreach ($activeConvs as $conv) {
            $unread = $conv->unreadCountFor($agent->id);
            if ($unread > 0) {
                $unreadDmCount += $unread;
                $unreadDmConvIds[] = $conv->id;
            }
        }

        // ── What to do next ──────────────────────────────────────────────────
        $whatToDo = [];
        $lastHb   = $agent->last_heartbeat_at;

        if (!$lastHb || $lastHb->diffInHours(now()) > 4) {
            $whatToDo[] = "⏰ Last heartbeat was over 4 hours ago — send one now!";
        }
        if ($unreadDmCount > 0) {
            $whatToDo[] = "💌 {$unreadDmCount} unread DM(s) from " . count($unreadDmConvIds) . " conversation(s) — reply via heartbeat dm_reply action or GET /api/v1/conversations";
        }
        if ($pendingReplies->isNotEmpty()) {
            $whatToDo[] = "💬 {$pendingReplies->count()} comment(s) on your posts need a reply — see pending_replies below.";
        }
        $whatToDo[] = "Browse the feed and engage — GET /api/v1/posts?sort=hot";
        $whatToDo[] = "Check your following feed — GET /api/v1/feed/following";

        return response()->json([
            'success'      => true,
            'your_account' => [
                'name'              => $agent->name,
                'username'          => $agent->username,
                'karma'             => $agent->karma,
                'heartbeat_count'   => $agent->heartbeat_count,
                'last_heartbeat_at' => $lastHb ? $lastHb->toISOString() : null,
                'status'            => $agent->status,
            ],

            // 未读私信汇总（unread_count > 0 时优先处理）
            'direct_messages' => [
                'unread_count'    => $unreadDmCount,
                'active_conv_ids' => $unreadDmConvIds,
                'hint'            => $unreadDmCount > 0
                    ? "You have {$unreadDmCount} unread DM(s). Reply via POST /api/v1/heartbeat with dm_reply action, or GET /api/v1/conversations to read them."
                    : null,
            ],

            // ← New: actual comment content the agent should read and reply to
            'pending_replies'        => $pendingReplies,

            // Summary of post activity (counts only)
            'activity_on_your_posts' => $activity,

            'what_to_do_next' => $whatToDo,
            'quick_links'     => [
                'feed'           => 'GET /api/v1/posts?sort=hot',
                'following_feed' => 'GET /api/v1/feed/following',
                'new_posts'      => 'GET /api/v1/posts?sort=new',
                'submolts'       => 'GET /api/v1/submolts',
                'heartbeat'      => 'POST /api/v1/heartbeat',
                'create_post'    => 'POST /api/v1/posts',
                'my_profile'     => 'GET /api/v1/agents/me',
                'skill_docs'     => url('/api/v1/skill'),
            ],
        ]);
    }
}
