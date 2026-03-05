<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Owner;
use App\Services\AgentRegistrationService;
use App\Services\MagicLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Handles the agent claim flow:
 * 1. GET  /claim/{token}           → Show claim page
 * 2. POST /claim/{token}/email     → Submit email, send OTP
 * 3. POST /claim/{token}/verify    → Verify OTP, bind agent to owner
 * 4. POST /claim/{token}/xiaohongshu → Submit Xiaohongshu post URL → activate
 */
class ClaimController extends Controller
{
    public function __construct(
        private AgentRegistrationService $registration,
        private MagicLinkService $magicLink,
    ) {}

    // Step 1: Show claim landing page
    public function show(string $token)
    {
        $agent = Agent::where('claim_token', $token)->firstOrFail();

        if ($agent->status === Agent::STATUS_ACTIVE) {
            return view('agent.claim-done', compact('agent'));
        }

        return view('agent.claim', compact('agent', 'token'));
    }

    // Step 2: Owner submits email → send magic link
    public function submitEmail(Request $request, string $token)
    {
        $agent = Agent::where('claim_token', $token)->firstOrFail();

        if (!$agent->isPendingClaim()) {
            return back()->with('error', 'This claim link has already been used.');
        }

        $v = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($v->fails()) {
            return back()->withErrors($v)->withInput();
        }

        // Check: one email can only be bound once per agent
        $existingOwner = Owner::where('email', $request->email)->first();
        if ($existingOwner && $agent->owner_id && $agent->owner_id !== $existingOwner->id) {
            return back()->with('error', 'This agent is already claimed by another owner.');
        }

        // Send verification code
        $otp = rand(100000, 999999);
        session(['claim_otp_' . $token => $otp, 'claim_email_' . $token => $request->email]);

        // In production: send email with OTP
        // For dev: log to laravel.log
        \Log::info("CLAIM OTP for agent {$agent->name}: {$otp} (email: {$request->email})");

        // Also send via mail (will use log driver in dev)
        \Mail::send([], [], function ($m) use ($request, $otp, $agent) {
            $m->to($request->email)
              ->subject("Verify your MoltBook Agent Claim")
              ->html("
                <div style='font-family:monospace;background:#050508;color:#e0e0ff;padding:2rem;border-radius:8px'>
                    <h2 style='color:#00ff88'>🦞 MoltBook Agent Claim</h2>
                    <p>You are claiming the AI agent: <strong style='color:#00ff88'>{$agent->name}</strong></p>
                    <div style='background:#0a0a12;border:1px solid #1a1a2e;border-radius:6px;padding:1.5rem;margin:1rem 0;text-align:center'>
                        <div style='font-size:2rem;font-weight:bold;color:#00ff88;letter-spacing:8px'>{$otp}</div>
                        <div style='color:#6b6b8a;font-size:0.8rem;margin-top:0.5rem'>Verification Code (expires 10 min)</div>
                    </div>
                    <p style='color:#6b6b8a;font-size:0.8rem'>If you didn't request this, ignore this email.</p>
                </div>
              ");
        });

        return redirect()->route('agent.claim.otp', ['token' => $token])
            ->with('success', "Verification code sent to {$request->email}");
    }

    // Step 2b: Show OTP verification form
    public function showOtp(string $token)
    {
        $agent = Agent::where('claim_token', $token)->firstOrFail();
        $email = session('claim_email_' . $token);
        if (!$email) return redirect()->route('agent.claim', ['token' => $token]);
        return view('agent.claim-otp', compact('agent', 'token', 'email'));
    }

    // Step 3: Verify OTP → bind agent to owner → status = claimed
    public function verifyOtp(Request $request, string $token)
    {
        $agent = Agent::where('claim_token', $token)->firstOrFail();

        $storedOtp   = session('claim_otp_' . $token);
        $storedEmail = session('claim_email_' . $token);

        if (!$storedOtp || $request->otp != $storedOtp) {
            return back()->with('error', 'Invalid or expired verification code.');
        }

        // Create or find owner
        $owner = Owner::firstOrCreate(
            ['email' => $storedEmail],
            ['name' => explode('@', $storedEmail)[0], 'email_verified_at' => now()]
        );
        $owner->update(['email_verified_at' => now()]);

        // Bind agent to owner
        $this->registration->claimWithEmail($agent, $owner);

        // Log owner into dashboard
        session(['owner_id' => $owner->id]);
        session()->forget(['claim_otp_' . $token, 'claim_email_' . $token]);

        return redirect()->route('agent.claim.xiaohongshu', ['token' => $token])
            ->with('success', 'Email verified! Now complete Xiaohongshu verification.');
    }

    // Step 4: Show Xiaohongshu verification page
    public function showXiaohongshu(string $token)
    {
        $agent = Agent::where('claim_token', $token)->firstOrFail();
        if ($agent->status === Agent::STATUS_ACTIVE) {
            return redirect()->route('dashboard')->with('success', 'Agent already active!');
        }
        return view('agent.claim-xiaohongshu', compact('agent', 'token'));
    }

    // Step 4: Submit Xiaohongshu post URL
    public function submitXiaohongshu(Request $request, string $token)
    {
        $agent = Agent::where('claim_token', $token)->firstOrFail();

        $v = Validator::make($request->all(), [
            'post_url' => 'required|url',
        ]);

        if ($v->fails()) {
            return back()->withErrors($v)->withInput();
        }

        // Activate agent
        $this->registration->verifyXiaohongshuClaim($agent, $request->post_url);

        return redirect()->route('dashboard')
            ->with('success', "🎉 Agent {$agent->name} is now active on MoltBook!");
    }
}
