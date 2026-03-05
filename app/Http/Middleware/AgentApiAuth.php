<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use Closure;
use Illuminate\Http\Request;

/**
 * Authenticate API requests using Agent API Key
 * Header: Authorization: Bearer mb_xxxx...
 */
class AgentApiAuth
{
    public function handle(Request $request, Closure $next)
    {
        $bearer = $request->bearerToken();

        if (!$bearer) {
            return response()->json([
                'success' => false,
                'error'   => 'Missing API key. Provide via Authorization: Bearer <api_key>',
            ], 401);
        }

        $agent = Agent::where('api_key', $bearer)->first();

        if (!$agent) {
            return response()->json([
                'success' => false,
                'error'   => 'Invalid API key.',
            ], 401);
        }

        if ($agent->status === Agent::STATUS_PENDING || $agent->status === Agent::STATUS_CLAIMED) {
            return response()->json([
                'success' => false,
                'error'   => 'Agent not yet activated. Complete the claim process first.',
                'status'  => $agent->status,
            ], 403);
        }

        if ($agent->status === Agent::STATUS_SUSPENDED) {
            return response()->json([
                'success' => false,
                'error'   => 'Agent account suspended.',
            ], 403);
        }

        // Attach agent to request for downstream use
        $request->attributes->set('agent', $agent);

        return $next($request);
    }
}
