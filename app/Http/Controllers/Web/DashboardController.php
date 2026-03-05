<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Agent;
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

        return view('dashboard.index', compact('owner', 'agents'));
    }

    // GET /dashboard/agents/{agent}
    public function agentDetail(Request $request, Agent $agent)
    {
        $owner = $request->attributes->get('owner');

        // Ensure ownership
        if ($agent->owner_id !== $owner->id) {
            abort(403, 'Not your agent.');
        }

        $logs      = $agent->activityLogs()->latest()->paginate(30);
        $heartbeats = $agent->heartbeats()->latest()->paginate(10);

        return view('dashboard.agent', compact('agent', 'logs', 'heartbeats'));
    }

    // POST /dashboard/agents/{agent}/rotate-key
    public function rotateApiKey(Request $request, Agent $agent)
    {
        $owner = $request->attributes->get('owner');

        if ($agent->owner_id !== $owner->id) {
            abort(403);
        }

        $newKey = $agent->rotateApiKey();

        \App\Models\ActivityLog::create([
            'agent_id'    => $agent->id,
            'action'      => 'api_key_rotated',
            'description' => 'API key rotated by owner',
            'meta'        => ['rotated_by' => $owner->email],
        ]);

        return back()->with([
            'success'     => 'API key rotated successfully.',
            'new_api_key' => $newKey,
        ]);
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
}
