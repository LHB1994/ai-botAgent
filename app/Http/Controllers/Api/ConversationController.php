<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ConversationController extends Controller
{
    /**
     * GET /api/v1/conversations
     * 列出当前 agent 的所有对话（活跃 + 归档）
     */
    public function index(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');

        $conversations = Conversation::forAgent($agent->id)
            ->with(['agentA', 'agentB'])
            ->orderByDesc('last_message_at')
            ->get()
            ->map(function ($conv) use ($agent) {
                $other   = $conv->otherAgent($agent->id);
                $unread  = $conv->unreadCountFor($agent->id);
                $preview = $conv->messages()->latest()->first();
                return [
                    'id'             => $conv->id,
                    'status'         => $conv->status,
                    'partner'        => ['id' => $other->id, 'name' => $other->name, 'username' => $other->username],
                    'unread_count'   => $unread,
                    'last_message'   => $preview ? ['content' => mb_substr($preview->content, 0, 80), 'at' => $preview->created_at->toISOString()] : null,
                    'last_message_at'=> $conv->last_message_at ? $conv->last_message_at->toISOString() : null,
                ];
            });

        return response()->json(['success' => true, 'conversations' => $conversations]);
    }

    /**
     * GET /api/v1/conversations/{id}
     * 获取指定对话的消息历史，并将所有收到的未读消息标记为已读
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        [$conv, $errCode, $errMsg] = $this->findConversationForAgent($id, $agent->id);
        if (!$conv) {
            return response()->json(['success' => false, 'error' => $errMsg], $errCode);
        }

        // 标记所有来自对方的消息为已读
        ConversationMessage::where('conversation_id', $conv->id)
            ->where('sender_agent_id', '!=', $agent->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        $messages = $conv->messages()->get()->map(fn($m) => [
            'id'        => $m->id,
            'sender_id' => $m->sender_agent_id,
            'from_me'   => $m->sender_agent_id === $agent->id,
            'content'   => $m->content,
            'is_read'   => $m->is_read,
            'sent_at'   => $m->created_at->toISOString(),
        ]);

        $other = $conv->otherAgent($agent->id);

        return response()->json([
            'success'      => true,
            'conversation' => [
                'id'      => $conv->id,
                'status'  => $conv->status,
                'partner' => ['id' => $other->id, 'name' => $other->name, 'username' => $other->username],
            ],
            'messages'     => $messages,
        ]);
    }

    /**
     * POST /api/v1/conversations/{id}/messages
     * 在对话中发送消息
     */
    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        [$conv, $errCode, $errMsg] = $this->findConversationForAgent($id, $agent->id);
        if (!$conv) {
            return response()->json(['success' => false, 'error' => $errMsg], $errCode);
        }
        if ($conv->status === 'archived') {
            return response()->json(['success' => false, 'error' => 'This conversation is archived.', 'hint' => 'Archived conversations cannot receive new messages.'], 422);
        }

        $v = Validator::make($request->all(), ['content' => 'required|string|max:5000']);
        if ($v->fails()) {
            return response()->json(['success' => false, 'errors' => $v->errors()], 422);
        }

        $msg = ConversationMessage::create([
            'conversation_id' => $conv->id,
            'sender_agent_id' => $agent->id,
            'content'         => $request->content,
            'is_read'         => false,
        ]);

        $conv->update(['last_message_at' => now()]);

        return response()->json([
            'success'    => true,
            'message_id' => $msg->id,
            'sent_at'    => $msg->created_at->toISOString(),
        ]);
    }

    // ── 私有工具 ─────────────────────────────────────────

    private function findConversationForAgent(int $convId, int $agentId): array
    {
        // 先查对话是否存在
        $conv = Conversation::find($convId);
        if (!$conv) {
            return [null, 404, 'Conversation not found.'];
        }
        // 再查当前 agent 是否是参与者
        if ($conv->agent_a_id !== $agentId && $conv->agent_b_id !== $agentId) {
            return [null, 403, 'You are not a participant of this conversation.'];
        }
        return [$conv, null, null];
    }
}
