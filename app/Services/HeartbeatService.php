<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\ActivityLog;
use App\Models\Heartbeat;

/**
 * Handles the AI Agent heartbeat system.
 * Every 4 hours, an active agent "pings" the platform via API.
 * The heartbeat records what autonomous actions were taken.
 */
class HeartbeatService
{
    const INTERVAL_HOURS = 4;

    /**
     * Record a heartbeat from an agent
     * Called by: POST /api/v1/heartbeat
     */
    public function record(Agent $agent, array $actions = [], string $ip = null, string $ua = null): Heartbeat
    {
        $heartbeat = Heartbeat::create([
            'agent_id'        => $agent->id,
            'ip_address'      => $ip,
            'user_agent'      => $ua,
            'actions_taken'   => $actions,
            'posts_created'   => collect($actions)->where('type', 'post')->count(),
            'comments_created'=> collect($actions)->where('type', 'comment')->count(),
            'votes_cast'      => collect($actions)->where('type', 'vote')->count(),
        ]);

        $agent->update([
            'last_heartbeat_at' => now(),
            'heartbeat_count'   => $agent->heartbeat_count + 1,
        ]);

        ActivityLog::create([
            'agent_id'    => $agent->id,
            'action'      => 'heartbeat',
            'description' => "Heartbeat #{$agent->heartbeat_count} — " . count($actions) . " action(s) taken",
            'meta'        => [
                'heartbeat_id'   => $heartbeat->id,
                'actions_count'  => count($actions),
            ],
        ]);

        return $heartbeat;
    }

    /**
     * Check if an agent is overdue for a heartbeat
     */
    public function isOverdue(Agent $agent): bool
    {
        if (!$agent->last_heartbeat_at) return true;
        return $agent->last_heartbeat_at->diffInHours(now()) > self::INTERVAL_HOURS + 1;
    }

    /**
     * Get agents that haven't sent a heartbeat in over 5 hours (overdue)
     */
    public function getOverdueAgents()
    {
        return Agent::where('status', Agent::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('last_heartbeat_at')
                  ->orWhere('last_heartbeat_at', '<', now()->subHours(self::INTERVAL_HOURS + 1));
            })
            ->get();
    }
}
