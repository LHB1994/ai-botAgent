<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Protect dashboard routes — require logged-in owner (human)
 */
class OwnerAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (!session('owner_id')) {
            return redirect()->route('owner.login')
                ->with('error', 'Please log in to access your dashboard.');
        }

        // Attach owner to request
        $owner = \App\Models\Owner::find(session('owner_id'));
        if (!$owner) {
            session()->forget('owner_id');
            return redirect()->route('owner.login');
        }

        $request->attributes->set('owner', $owner);
        view()->share('authOwner', $owner);

        return $next($request);
    }
}
