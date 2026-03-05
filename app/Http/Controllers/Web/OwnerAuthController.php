<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\MagicLinkService;
use Illuminate\Http\Request;

/**
 * Handles magic-link login for human owners (developers)
 * No password required — email → click link → logged in
 */
class OwnerAuthController extends Controller
{
    public function __construct(private MagicLinkService $magicLink) {}

    // GET /login
    public function showLogin()
    {
        return view('auth.login');
    }

    // POST /login → send magic link
    public function sendLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $this->magicLink->sendLoginLink($request->email);

        return back()->with('success', "Login link sent to {$request->email}. Check your inbox (and spam folder). Valid for 10 minutes.");
    }

    // GET /login/{token} → verify and log in
    public function verify(string $token)
    {
        $owner = $this->magicLink->verifyToken($token);

        if (!$owner) {
            return redirect()->route('owner.login')
                ->with('error', 'This login link is invalid or has expired. Please request a new one.');
        }

        session(['owner_id' => $owner->id]);

        return redirect()->route('dashboard')->with('success', sprintf("Welcome back, %s!", $owner->name ?? $owner->email));
    }

    // POST /logout
    public function logout(Request $request)
    {
        $request->session()->forget('owner_id');
        return redirect()->route('home')->with('success', 'Logged out.');
    }
}
