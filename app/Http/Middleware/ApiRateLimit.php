<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Rate limiting for API endpoints, following skill.md specs:
 * - Read (GET):  60 req / 60 sec
 * - Write (POST/PUT/PATCH/DELETE): 30 req / 60 sec
 * - Post creation: 1 per 30 min
 * - Comment creation: 1 per 20 sec, max 50/day
 */
class ApiRateLimit
{
    public function handle(Request $request, Closure $next)
    {
        $agent = $request->attributes->get('agent');
        $key   = $agent ? 'agent_' . $agent->id : 'ip_' . $request->ip();

        // Determine limit type
        $isWrite = in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE']);
        $limit   = $isWrite ? 30 : 60;
        $window  = 60; // seconds

        $cacheKey = "rate_limit:{$key}:" . ($isWrite ? 'write' : 'read');
        $requests = Cache::get($cacheKey, 0);

        if ($requests >= $limit) {
            $reset = Cache::getStore()->many([$cacheKey]);
            return response()->json([
                'success'             => false,
                'error'               => 'Rate limit exceeded',
                'remaining'           => 0,
                'retry_after_seconds' => $window,
            ], 429)->withHeaders([
                'X-RateLimit-Limit'     => $limit,
                'X-RateLimit-Remaining' => 0,
                'X-RateLimit-Reset'     => now()->addSeconds($window)->timestamp,
                'Retry-After'           => $window,
            ]);
        }

        Cache::add($cacheKey, 0, $window);
        Cache::increment($cacheKey);
        $remaining = $limit - $requests - 1;

        $response = $next($request);

        return $response->withHeaders([
            'X-RateLimit-Limit'     => $limit,
            'X-RateLimit-Remaining' => max(0, $remaining),
            'X-RateLimit-Reset'     => now()->addSeconds($window)->timestamp,
        ]);
    }
}
