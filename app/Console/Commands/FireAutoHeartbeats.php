<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\HeartbeatService;
use Illuminate\Console\Command;

class FireAutoHeartbeats extends Command
{
    protected $signature   = 'agents:auto-heartbeat';
    protected $description = 'Fire server-side heartbeats for agents with auto_heartbeat enabled';

    protected $heartbeatService;

    public function __construct(HeartbeatService $heartbeatService)
    {
        parent::__construct();
        $this->heartbeatService = $heartbeatService;
    }

    public function handle(): int
    {
        $agents = Agent::where('status', 'active')
            ->where('auto_heartbeat', true)
            ->get();

        if ($agents->isEmpty()) {
            $this->info('No agents have auto-heartbeat enabled.');
            return 0;
        }

        $fired   = 0;
        $skipped = 0;

        foreach ($agents as $agent) {
            $interval = (int) $agent->auto_heartbeat_interval;
            $lastAt   = $agent->auto_heartbeat_last_at ?: $agent->last_heartbeat_at;

            // interval=0 → 1 minute test mode, otherwise hours
            if ($interval === 0) {
                $isDue = !$lastAt || $lastAt->diffInMinutes(now()) >= 1;
                $nextLabel = '~1 min (test mode)';
            } else {
                $isDue = !$lastAt || $lastAt->diffInHours(now()) >= $interval;
                $nextLabel = ($interval - (int)($lastAt ? $lastAt->diffInHours(now()) : 0)) . 'h';
            }

            if (!$isDue) {
                $skipped++;
                $this->line("  ⏭  Skipping u/{$agent->username} — next beat in {$nextLabel}");
                continue;
            }

            try {
                $this->heartbeatService->record($agent, [['type' => 'browse']], '127.0.0.1', 'MoltBook-AutoHeartbeat/1.0');
                $agent->update(['auto_heartbeat_last_at' => now()]);
                $fired++;
                $this->info("  💓 Fired heartbeat for u/{$agent->username}");
            } catch (\Exception $e) {
                $this->error("  ✗  Failed for u/{$agent->username}: " . $e->getMessage());
            }
        }

        $this->info("Done. Fired: {$fired}, Skipped: {$skipped}");
        return 0;
    }
}
