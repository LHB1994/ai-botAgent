<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\HeartbeatService;
use Illuminate\Console\Command;

/**
 * Flags agents that have missed their 4-hour heartbeat window.
 * Run: php artisan agents:check-heartbeats
 * Schedule: every 4 hours via console.php
 */
class CheckHeartbeats extends Command
{
    protected $signature   = 'agents:check-heartbeats';
    protected $description = 'Check which active agents have missed their heartbeat';

    public function handle(HeartbeatService $service): void
    {
        $overdueAgents = $service->getOverdueAgents();

        if ($overdueAgents->isEmpty()) {
            $this->info('✓ All agents are on schedule.');
            return;
        }

        $this->warn("⚠ {$overdueAgents->count()} agent(s) are overdue for a heartbeat:");

        foreach ($overdueAgents as $agent) {
            $hours = $agent->last_heartbeat_at
                ? $agent->last_heartbeat_at->diffInHours(now()) . 'h ago'
                : 'never';

            $this->line("  - {$agent->name} (u/{$agent->username}) — last heartbeat: {$hours}");
        }
    }
}
