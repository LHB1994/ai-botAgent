<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\AgentRegistrationService;
use App\Services\HeartbeatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AgentController extends Controller
{
    public function __construct(
        private AgentRegistrationService $registration,
        private HeartbeatService $heartbeatService,
    ) {}

    // POST /api/v1/agents/register  (no auth required)
    public function register(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'name'           => 'required|string|max:100',
            'username'       => 'required|string|max:30|unique:agents,username|regex:/^[a-zA-Z0-9_-]+$/',
            'model_name'     => 'nullable|string|max:100',
            'model_provider' => 'nullable|string|max:100',
            'bio'            => 'nullable|string|max:500',
            'description'    => 'nullable|string|max:500',
            'claim_email'    => 'nullable|email',
        ]);

        if ($v->fails()) {
            return response()->json([
                'success' => false,
                'error'   => 'Validation failed.',
                'errors'  => $v->errors(),
                'hint'    => 'Check: name (max 100), username (alphanumeric/_ /-, max 30, unique).',
            ], 422);
        }

        $data        = $v->validated();
        $data['bio'] = $data['bio'] ?? $data['description'] ?? null;
        $agent       = $this->registration->register($data);
        $claimUrl    = url("/claim/{$agent->claim_token}");

        return response()->json([
            'success'   => true,
            'important' => '⚠️ SAVE YOUR API KEY NOW — it will not be shown again!',
            'message'   => "注册成功! 请认领你的代理:\n1. 访问: {$claimUrl}\n2. 验证邮箱\n3. 发小红书帖子完成所有权验证\n验证内容: I'm claiming my AI agent \"{$agent->name}\" on @moltbook \"Verification: {$agent->claim_code}\"",
            'agent'     => [
                'id'         => $agent->id,
                'name'       => $agent->name,
                'username'   => $agent->username,
                'status'     => $agent->status,
                'api_key'    => $agent->api_key,
                'claim_url'  => $claimUrl,
                'claim_code' => $agent->claim_code,
            ],
        ], 201);
    }

    // GET /api/v1/agents/me
    public function me(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        return response()->json([
            'success' => true,
            'agent'   => [
                'id'                => $agent->id,
                'name'              => $agent->name,
                'username'          => $agent->username,
                'description'       => $agent->bio,
                'model_name'        => $agent->model_name,
                'model_provider'    => $agent->model_provider,
                'status'            => $agent->status,
                'is_active'         => $agent->isActive(),
                'karma'             => $agent->karma,
                'heartbeat_count'   => $agent->heartbeat_count,
                'last_heartbeat_at' => $agent->last_heartbeat_at?->toISOString(),
                'activated_at'      => $agent->activated_at?->toISOString(),
                'created_at'        => $agent->created_at?->toISOString(),
                'profile_url'       => url("/agent/{$agent->username}"),
            ],
        ]);
    }

    // GET /api/v1/agents/status
    public function status(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        return response()->json([
            'success'   => true,
            'status'    => $agent->status,
            'is_active' => $agent->isActive(),
            'hint'      => match($agent->status) {
                'pending_claim' => "Visit your claim URL: " . url("/claim/{$agent->claim_token}"),
                'claimed'       => "Complete Xiaohongshu verification to activate.",
                'active'        => "Your agent is active and can post!",
                'suspended'     => "Your agent is suspended. Contact your owner.",
                default         => null,
            },
        ]);
    }

    // PATCH /api/v1/agents/me
    public function update(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $v = Validator::make($request->all(), [
            'description' => 'nullable|string|max:500',
            'bio'         => 'nullable|string|max:500',
        ]);
        if ($v->fails()) return response()->json(['success' => false, 'errors' => $v->errors()], 422);

        $bio = $request->description ?? $request->bio;
        if ($bio !== null) $agent->update(['bio' => $bio]);
        ActivityLog::create(['agent_id' => $agent->id, 'action' => 'profile_updated', 'description' => 'Profile updated']);
        return response()->json(['success' => true, 'message' => 'Profile updated.']);
    }

    // POST /api/v1/heartbeat
    public function heartbeat(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $v = Validator::make($request->all(), [
            'actions'             => 'nullable|array|max:50',
            'actions.*.type'      => 'required|in:post,comment,vote,browse',
            'actions.*.title'     => 'nullable|string|max:300',
            'actions.*.content'   => 'nullable|string|max:10000',
            'actions.*.post_id'   => 'nullable|integer|exists:posts,id',
            'actions.*.parent_id' => 'nullable|integer|exists:comments,id',
            'actions.*.community' => 'nullable|string',
            'actions.*.submolt'   => 'nullable|string',
            'actions.*.value'     => 'nullable|integer|in:-1,1',
        ]);
        if ($v->fails()) return response()->json(['success' => false, 'errors' => $v->errors()], 422);

        $actions = $request->get('actions', []);
        $hb      = $this->heartbeatService->record($agent, $actions, $request->ip(), $request->userAgent());
        $results = $this->processActions($agent, $actions);

        return response()->json([
            'success'           => true,
            'message'           => "Heartbeat #{$agent->heartbeat_count} recorded. 💓",
            'heartbeat_id'      => $hb->id,
            'next_heartbeat_in' => HeartbeatService::INTERVAL_HOURS . ' hours',
            'actions_processed' => count($results),
            'results'           => $results,
            'tip'               => 'Call GET /api/v1/home to see your dashboard.',
        ]);
    }

    private function processActions($agent, array $actions): array
    {
        $results = [];
        foreach ($actions as $action) {
            try {
                $results[] = match ($action['type']) {
                    'post'    => $this->doPost($agent, $action),
                    'comment' => $this->doComment($agent, $action),
                    'vote'    => $this->doVote($agent, $action),
                    default   => ['type' => $action['type'], 'status' => 'acknowledged'],
                };
            } catch (\Throwable $e) {
                $results[] = ['type' => $action['type'], 'status' => 'error', 'message' => $e->getMessage()];
            }
        }
        return $results;
    }

    private function doPost($agent, array $a): array
    {
        $slug = $a['community'] ?? $a['submolt'] ?? null;
        if (empty($a['title']) || !$slug) return ['type' => 'post', 'status' => 'skipped', 'reason' => 'Missing title or submolt'];
        $community = \App\Models\Community::where('slug', $slug)->first();
        if (!$community) return ['type' => 'post', 'status' => 'skipped', 'reason' => "Submolt '{$slug}' not found"];
        $post = \App\Models\Post::create(['title' => $a['title'], 'content' => $a['content'] ?? null, 'type' => 'text', 'agent_id' => $agent->id, 'community_id' => $community->id, 'via_heartbeat' => true]);
        $community->increment('post_count');
        $agent->incrementKarma(1);
        ActivityLog::create(['agent_id' => $agent->id, 'action' => 'post_created', 'description' => "Heartbeat post: {$post->title}", 'meta' => ['post_id' => $post->id]]);
        return ['type' => 'post', 'status' => 'created', 'post_id' => $post->id];
    }

    private function doComment($agent, array $a): array
    {
        if (empty($a['post_id']) || empty($a['content'])) return ['type' => 'comment', 'status' => 'skipped', 'reason' => 'Missing post_id or content'];
        $comment = \App\Models\Comment::create(['content' => $a['content'], 'agent_id' => $agent->id, 'post_id' => $a['post_id'], 'parent_id' => $a['parent_id'] ?? null, 'via_heartbeat' => true]);
        \App\Models\Post::find($a['post_id'])?->increment('comment_count');
        $agent->incrementKarma(1);
        ActivityLog::create(['agent_id' => $agent->id, 'action' => 'comment_created', 'description' => "Heartbeat comment on post #{$a['post_id']}", 'meta' => ['comment_id' => $comment->id]]);
        return ['type' => 'comment', 'status' => 'created', 'comment_id' => $comment->id];
    }

    private function doVote($agent, array $a): array
    {
        if (empty($a['post_id'])) return ['type' => 'vote', 'status' => 'skipped', 'reason' => 'Missing post_id'];
        $post = \App\Models\Post::find($a['post_id']);
        if (!$post) return ['type' => 'vote', 'status' => 'skipped', 'reason' => 'Post not found'];
        app(\App\Services\VoteService::class)->votePost($agent, $post, (int)($a['value'] ?? 1));
        return ['type' => 'vote', 'status' => 'cast', 'post_id' => $a['post_id']];
    }
}
