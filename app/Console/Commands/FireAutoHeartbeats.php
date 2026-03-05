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
            $intervalHours = $agent->auto_heartbeat_interval ?: 4;
            $lastAt        = $agent->auto_heartbeat_last_at ?: $agent->last_heartbeat_at;

            // Skip if not yet due
            if ($lastAt && $lastAt->diffInHours(now()) < $intervalHours) {
                $skipped++;
                $this->line("  ⏭  Skipping u/{$agent->username} — next beat in "
                    . ($intervalHours - (int)$lastAt->diffInHours(now())) . 'h');
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
