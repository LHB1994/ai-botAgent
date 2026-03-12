<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Http\Request;

/**
 * Owner Dashboard — manage AI agents
 */
class DashboardController extends Controller
{
    // GET /dashboard
    public function index(Request $request)
    {
        $owner  = $request->attributes->get('owner');
        $agents = $owner->agents()->with(['heartbeats' => fn($q) => $q->latest()->take(5)])->get();

        return view('dashboard.index', [
            'authOwner' => $owner,
            'agents'    => $agents,
        ]);
    }

    // GET /dashboard/agents/{agent}
    public function agentDetail(Request $request, Agent $agent)
    {
        $owner = $request->attributes->get('owner');
        if ($agent->owner_id !== $owner->id) abort(403, 'Not your agent.');

        $logs       = $agent->activityLogs()->latest()->paginate(30);
        $heartbeats = $agent->heartbeats()->latest()->paginate(10);

        return view('dashboard.agent', compact('agent', 'logs', 'heartbeats', 'owner'));
    }

    // POST /dashboard/agents/{agent}/rotate-key
    public function rotateApiKey(Request $request, Agent $agent)
    {
        $owner = $request->attributes->get('owner');
        if ($agent->owner_id !== $owner->id) abort(403);

        $newKey = $agent->rotateApiKey();

        \App\Models\ActivityLog::create([
            'agent_id'    => $agent->id,
            'action'      => 'api_key_rotated',
            'description' => 'API key rotated by owner',
            'meta'        => ['rotated_by' => $owner->email],
        ]);

        return back()->with(['success' => 'API key rotated successfully.', 'new_api_key' => $newKey]);
    }

    // POST /dashboard/agents/{agent}/suspend
    public function suspendAgent(Request $request, Agent $agent)
    {
        $owner = $request->attributes->get('owner');
        if ($agent->owner_id !== $owner->id) abort(403);

        $agent->update(['status' => Agent::STATUS_SUSPENDED]);
        return back()->with('success', "Agent {$agent->name} suspended.");
    }

    // POST /dashboard/agents/{agent}/reactivate
    public function reactivateAgent(Request $request, Agent $agent)
    {
        $owner = $request->attributes->get('owner');
        if ($agent->owner_id !== $owner->id) abort(403);

        if ($agent->activated_at) {
            $agent->update(['status' => Agent::STATUS_ACTIVE]);
            return back()->with('success', "Agent {$agent->name} reactivated.");
        }
        return back()->with('error', 'Agent was never activated.');
    }

    // POST /dashboard/agents/{agent}/auto-heartbeat
    public function toggleAutoHeartbeat(Request $request, Agent $agent)
    {
        $owner = $request->attributes->get('owner');
        if ($agent->owner_id !== $owner->id) abort(403);

        $enable        = $request->input('enable') === '1';
        $rawInterval   = (int) $request->input('interval', 4);
        $interval      = ($rawInterval === 0) ? 0 : max(1, min(24, $rawInterval));
        $intervalLabel = ($interval === 0) ? '1分钟（测试用）' : "{$interval}h";

        $agent->update(['auto_heartbeat' => $enable, 'auto_heartbeat_interval' => $interval]);

        \App\Models\ActivityLog::create([
            'agent_id'    => $agent->id,
            'action'      => $enable ? 'auto_heartbeat_enabled' : 'auto_heartbeat_disabled',
            'description' => $enable ? "Auto-heartbeat enabled, interval {$intervalLabel}" : "Auto-heartbeat disabled",
            'meta'        => ['interval' => $interval, 'by' => $owner->email],
        ]);

        $msg = $enable ? "✅ 已开启自动心跳，每 {$intervalLabel} 由服务器代为发送。" : "🔕 已关闭自动心跳。";
        return back()->with('success', $msg);
    }

    // ── 搭子画像 ─────────────────────────────────────────────────────────────

    // POST /dashboard/agents/{agent}/profile
    public function saveProfile(Request $request, Agent $agent)
    {
        $owner = $request->attributes->get('owner');
        if ($agent->owner_id !== $owner->id) abort(403);

        $v = $request->validate([
            'gender'           => 'nullable|in:male,female,non_binary,prefer_not',
            'mbti'             => 'nullable|in:INTJ,INTP,ENTJ,ENTP,INFJ,INFP,ENFJ,ENFP,ISTJ,ISFJ,ESTJ,ESFJ,ISTP,ISFP,ESTP,ESFP',
            'city'             => 'nullable|string|max:100',
            'age_range'        => 'nullable|in:18-22,23-27,28-32,33+',
            'preferred_gender' => 'nullable|in:male,female,any',
            'open_to_distance' => 'nullable|boolean',
            'resonance_tags'   => 'nullable|array|max:5',
            'resonance_tags.*' => 'string|max:30',
            'interest_tags'    => 'nullable|array|max:10',
            'interest_tags.*'  => 'string|max:30',
        ]);

        $v['open_to_distance'] = $request->boolean('open_to_distance');
        $agent->update($v);
        $agent->recalculateProfileComplete();

        \App\Models\ActivityLog::create([
            'agent_id'    => $agent->id,
            'action'      => 'profile_updated',
            'description' => 'Matchmaking profile updated by owner',
            'meta'        => ['by' => $owner->email, 'completeness' => $agent->profile_completeness],
        ]);

        return back()->with('success', "画像已保存（完整度 {$agent->profile_completeness}%）");
    }

    // ── 对话列表 & 详情 ───────────────────────────────────────────────────────

    // GET /dashboard/agents/{agent}/conversations
    public function conversations(Request $request, Agent $agent)
    {
        $owner = $request->attributes->get('owner');
        if ($agent->owner_id !== $owner->id) abort(403);

        $conversations = Conversation::forAgent($agent->id)
            ->with(['agentA', 'agentB'])
            ->orderByDesc('last_message_at')
            ->get()
            ->map(function ($conv) use ($agent) {
                $other  = $conv->otherAgent($agent->id);
                $unread = $conv->unreadCountFor($agent->id);
                $last   = $conv->messages()->latest()->first();
                return [
                    'id'              => $conv->id,
                    'status'          => $conv->status,
                    'partner'         => $other,
                    'unread_count'    => $unread,
                    'last_message'    => $last,
                    'last_message_at' => $conv->last_message_at,
                ];
            });

        return view('dashboard.conversations', compact('agent', 'owner', 'conversations'));
    }

    // GET /dashboard/agents/{agent}/conversations/{convId}
    public function conversationDetail(Request $request, Agent $agent, int $convId)
    {
        $owner = $request->attributes->get('owner');
        if ($agent->owner_id !== $owner->id) abort(403);

        $conv = Conversation::where('id', $convId)
            ->where(function ($q) use ($agent) {
                $q->where('agent_a_id', $agent->id)->orWhere('agent_b_id', $agent->id);
            })
            ->with(['agentA', 'agentB'])
            ->firstOrFail();

        $messages = $conv->messages()->with('sender')->get();
        $partner  = $conv->otherAgent($agent->id);

        return view('dashboard.conversation-detail', compact('agent', 'owner', 'conv', 'messages', 'partner'));
    }

    // ── 匹配搭子 ─────────────────────────────────────────────────────────────

    // GET /dashboard/agents/{agent}/match
    public function match(Request $request, Agent $agent)
    {
        $owner = $request->attributes->get('owner');
        if ($agent->owner_id !== $owner->id) abort(403);
        if ($agent->status !== Agent::STATUS_ACTIVE) abort(403, 'Agent must be active to match.');

        $matches = app(\App\Services\MatchingService::class)->findMatches($agent, limit: 10);

        return view('dashboard.match', compact('agent', 'owner', 'matches'));
    }

    // POST /dashboard/agents/{agent}/start-conversation
    public function startConversation(Request $request, Agent $agent)
    {
        $owner = $request->attributes->get('owner');
        if ($agent->owner_id !== $owner->id) abort(403);
        if ($agent->status !== Agent::STATUS_ACTIVE) abort(403);

        $partnerId = (int) $request->input('partner_id');
        $partner   = Agent::where('id', $partnerId)->where('status', Agent::STATUS_ACTIVE)->firstOrFail();

        // 检查是否已有对话
        $existing = Conversation::activeBetween($agent->id, $partner->id);
        if ($existing) {
            return redirect()->route('dashboard.conversations', $agent)
                ->with('success', "已有与 {$partner->name} 的对话！");
        }

        // 建立新对话
        $conv = Conversation::create([
            'agent_a_id'     => $agent->id,
            'agent_b_id'     => $partner->id,
            'status'         => 'active',
            'last_message_at' => now(),
        ]);

        \App\Models\ActivityLog::create([
            'agent_id'    => $agent->id,
            'action'      => 'conversation_started',
            'description' => "Owner started conversation with {$partner->name}",
            'meta'        => ['conversation_id' => $conv->id, 'partner_id' => $partner->id, 'by' => $owner->email],
        ]);

        return redirect()->route('dashboard.conversations', $agent)
            ->with('success', "已为 {$agent->name} 与 {$partner->name} 建立搭子对话！Agent 将在下次心跳时看到并发起第一条消息。");
    }
}
