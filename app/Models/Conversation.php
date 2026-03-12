<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = [
        'agent_a_id',
        'agent_b_id',
        'status',
        'last_message_at',
        'archived_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'archived_at'     => 'datetime',
    ];

    // ── 关联 ────────────────────────────────────────────

    public function agentA()
    {
        return $this->belongsTo(Agent::class, 'agent_a_id');
    }

    public function agentB()
    {
        return $this->belongsTo(Agent::class, 'agent_b_id');
    }

    public function messages()
    {
        return $this->hasMany(ConversationMessage::class, 'conversation_id')->orderBy('created_at');
    }

    // ── 工具方法 ─────────────────────────────────────────

    /**
     * 返回对方 Agent（相对于给定 agent_id）
     */
    public function otherAgent(int $agentId): Agent
    {
        return $this->agent_a_id === $agentId ? $this->agentB : $this->agentA;
    }

    /**
     * 查询某 agent 参与的所有对话
     */
    public static function forAgent(int $agentId)
    {
        return static::where('agent_a_id', $agentId)
            ->orWhere('agent_b_id', $agentId);
    }

    /**
     * 查找两个 agent 之间的活跃对话（若有多个取最新）
     */
    public static function activeBetween(int $agentAId, int $agentBId): ?self
    {
        return static::where('status', 'active')
            ->where(function ($q) use ($agentAId, $agentBId) {
                $q->where(function ($q2) use ($agentAId, $agentBId) {
                    $q2->where('agent_a_id', $agentAId)->where('agent_b_id', $agentBId);
                })->orWhere(function ($q2) use ($agentAId, $agentBId) {
                    $q2->where('agent_a_id', $agentBId)->where('agent_b_id', $agentAId);
                });
            })
            ->latest('last_message_at')
            ->first();
    }

    /**
     * 获取该 agent 在此对话中的未读消息数
     */
    public function unreadCountFor(int $agentId): int
    {
        return $this->messages()
            ->where('sender_agent_id', '!=', $agentId)
            ->where('is_read', false)
            ->count();
    }

    /**
     * 归档该对话（7天无消息后触发）
     */
    public function archive(): void
    {
        $this->update([
            'status'      => 'archived',
            'archived_at' => now(),
        ]);
    }
}
