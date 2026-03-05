<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Owner;
use App\Services\AgentRegistrationService;
use App\Services\MagicLinkService;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * Handles the agent claim flow:
 * 1. GET  /claim/{token}             → Show claim page (enter email)
 * 2. POST /claim/{token}/email       → Send OTP to email
 * 3. GET  /claim/{token}/otp         → Show OTP entry form
 * 4. POST /claim/{token}/otp         → Verify OTP, bind owner
 * 5. GET  /claim/{token}/xiaohongshu → Show Xiaohongshu step
 * 6. POST /claim/{token}/xiaohongshu → Submit URL, activate agent
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

    // Step 2: Owner submits email → send OTP
    public function submitEmail(Request $request, string $token)
    {
        $agent = Agent::where('claim_token', $token)->firstOrFail();

        if (!$agent->isPendingClaim()) {
            return back()->with('error', 'This claim link has already been used.');
        }

        $v = Validator::make($request->all(), ['email' => 'required|email']);
        if ($v->fails()) {
            return back()->withErrors($v)->withInput();
        }

        $existingOwner = Owner::where('email', $request->email)->first();
        if ($existingOwner && $agent->owner_id && $agent->owner_id !== $existingOwner->id) {
            return back()->with('error', 'This agent is already claimed by another owner.');
        }

        $otp = rand(100000, 999999);
        session(['claim_otp_' . $token => $otp, 'claim_email_' . $token => $request->email]);

        Log::info("CLAIM OTP for agent {$agent->name}: {$otp} (email: {$request->email})");

        $html = "
            <div style='font-family:monospace;background:#050508;color:#e0e0ff;padding:2rem;border-radius:8px'>
                <h2 style='color:#39ff8a'>🦞 MoltBook Agent Claim</h2>
                <p>你正在认领 AI 代理：<strong style='color:#39ff8a'>{$agent->name}</strong></p>
                <div style='background:#0a0a12;border:1px solid #1a1a2e;border-radius:6px;padding:1.5rem;margin:1rem 0;text-align:center'>
                    <div style='font-size:2rem;font-weight:bold;color:#39ff8a;letter-spacing:8px'>{$otp}</div>
                    <div style='color:#6b6b8a;font-size:0.8rem;margin-top:0.5rem'>验证码（10分钟内有效）</div>
                </div>
                <p style='color:#6b6b8a;font-size:0.8rem'>如果你没有请求此邮件，请忽略。</p>
            </div>
        ";

        try {
            Mail::html($html, function (Message $message) use ($request) {
                $message->to($request->email)->subject('🦞 MoltBook 代理认领验证码');
            });
        } catch (\Exception $e) {
            Log::error('Claim OTP mail failed: ' . $e->getMessage());
        }

        return redirect()->route('agent.claim.otp', ['token' => $token])
            ->with('success', "验证码已发送至 {$request->email}");
    }

    // Step 3: Show OTP verification form
    public function showOtp(string $token)
    {
        $agent = Agent::where('claim_token', $token)->firstOrFail();
        $email = session('claim_email_' . $token);
        if (!$email) {
            return redirect()->route('agent.claim', ['token' => $token]);
        }
        return view('agent.claim-otp', compact('agent', 'token', 'email'));
    }

    // Step 4: Verify OTP → bind owner → status = claimed
    public function verifyOtp(Request $request, string $token)
    {
        $agent = Agent::where('claim_token', $token)->firstOrFail();

        $storedOtp   = session('claim_otp_' . $token);
        $storedEmail = session('claim_email_' . $token);

        if (!$storedOtp || $request->otp != $storedOtp) {
            return back()->with('error', '验证码无效或已过期，请重新获取。');
        }

        $owner = Owner::firstOrCreate(
            ['email' => $storedEmail],
            ['name' => explode('@', $storedEmail)[0], 'email_verified_at' => now()]
        );
        $owner->update(['email_verified_at' => now()]);

        $this->registration->claimWithEmail($agent, $owner);

        session(['owner_id' => $owner->id]);
        session()->forget(['claim_otp_' . $token, 'claim_email_' . $token]);

        return redirect()->route('agent.claim.xiaohongshu', ['token' => $token])
            ->with('success', '邮箱验证成功！请完成小红书验证。');
    }

    // Step 5: Show Xiaohongshu verification page
    public function showXiaohongshu(string $token)
    {
        $agent = Agent::where('claim_token', $token)->firstOrFail();
        if ($agent->status === Agent::STATUS_ACTIVE) {
            return redirect()->route('dashboard')->with('success', '代理已激活！');
        }
        return view('agent.claim-xiaohongshu', compact('agent', 'token'));
    }

    // Step 6: Submit Xiaohongshu post URL → activate agent
    public function submitXiaohongshu(Request $request, string $token)
    {
        $agent = Agent::where('claim_token', $token)->firstOrFail();

        $v = Validator::make($request->all(), ['post_url' => 'required|url']);
        if ($v->fails()) {
            return back()->withErrors($v)->withInput();
        }

        $this->registration->verifyXiaohongshuClaim($agent, $request->post_url);

        return redirect()->route('dashboard')
            ->with('success', "🎉 代理 {$agent->name} 已成功在 MoltBook 激活！");
    }
}
