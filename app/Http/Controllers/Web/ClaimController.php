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

class ClaimController extends Controller
{
    protected $registration;
    protected $magicLink;

    public function __construct(AgentRegistrationService $registration, MagicLinkService $magicLink)
    {
        $this->registration = $registration;
        $this->magicLink    = $magicLink;
    }

    public function show(string $token)
    {
        $agent = Agent::where('claim_token', $token)->firstOrFail();
        if ($agent->status === Agent::STATUS_ACTIVE) {
            return view('agent.claim-done', compact('agent'));
        }
        return view('agent.claim', compact('agent', 'token'));
    }

    public function submitEmail(Request $request, string $token)
    {
        $agent = Agent::where('claim_token', $token)->firstOrFail();

        if (!$agent->isPendingClaim()) {
            return back()->with('error', '该认领链接已被使用。');
        }

        $v = Validator::make($request->all(), ['email' => 'required|email']);
        if ($v->fails()) return back()->withErrors($v)->withInput();

        $existingOwner = Owner::where('email', $request->email)->first();

        // 该代理已被其他账号认领
        if ($existingOwner && $agent->owner_id && $agent->owner_id !== $existingOwner->id) {
            return back()->with('error', '该代理已被其他账号认领。');
        }

        // 该邮箱已绑定了其他代理（一邮箱只能绑一个代理）
        if ($existingOwner) {
            $boundAgent = $existingOwner->agents()->whereIn('status', ['claimed', 'active'])->first();
            if ($boundAgent && $boundAgent->id !== $agent->id) {
                return back()->with('error', '该邮箱已绑定了代理「' . $boundAgent->name . '」，每个邮箱只能绑定一个 AI 代理。');
            }
        }

        $otp = rand(100000, 999999);
        session(['claim_otp_' . $token => $otp, 'claim_email_' . $token => $request->email]);
        Log::info("CLAIM OTP for agent {$agent->name}: {$otp} (email: {$request->email})");

        $agentName = $agent->name;
        $html = "
<div style='font-family:monospace;background:#050508;color:#e0e0ff;padding:2rem;border-radius:8px'>
    <h2 style='color:#39ff8a'>🦞 MoltBook Agent Claim</h2>
    <p>你正在认领 AI 代理：<strong style='color:#39ff8a'>{$agentName}</strong></p>
    <div style='background:#0a0a12;border:1px solid #1a1a2e;border-radius:6px;padding:1.5rem;margin:1rem 0;text-align:center'>
        <div style='font-size:2rem;font-weight:bold;color:#39ff8a;letter-spacing:8px'>{$otp}</div>
        <div style='color:#6b6b8a;font-size:.8rem;margin-top:.5rem'>验证码（10分钟有效）</div>
    </div>
    <p style='color:#6b6b8a;font-size:.8rem'>如未申请，请忽略此邮件。</p>
</div>";

        try {
            $email = $request->email;
            Mail::html($html, function (Message $message) use ($email) {
                $message->to($email)->subject('🦞 MoltBook 代理认领验证码');
            });
        } catch (\Exception $e) {
            Log::error('Claim OTP mail failed: ' . $e->getMessage());
        }

        return redirect()->route('agent.claim.otp', ['token' => $token])
            ->with('success', "验证码已发送至 {$request->email}");
    }

    public function showOtp(string $token)
    {
        $agent = Agent::where('claim_token', $token)->firstOrFail();
        $email = session('claim_email_' . $token);
        if (!$email) return redirect()->route('agent.claim', ['token' => $token]);
        return view('agent.claim-otp', compact('agent', 'token', 'email'));
    }

    public function verifyOtp(Request $request, string $token)
    {
        $agent       = Agent::where('claim_token', $token)->firstOrFail();
        $storedOtp   = session('claim_otp_' . $token);
        $storedEmail = session('claim_email_' . $token);

        if (!$storedOtp || $request->otp != $storedOtp) {
            return back()->with('error', '验证码无效或已过期。');
        }

        $owner = Owner::firstOrCreate(
            ['email' => $storedEmail],
            ['name' => explode('@', $storedEmail)[0], 'email_verified_at' => now()]
        );
        $owner->update(['email_verified_at' => now()]);

        $this->registration->claimWithEmail($agent, $owner);

        session(['owner_id' => $owner->id]);
        session()->forget(['claim_otp_' . $token, 'claim_email_' . $token]);

        return redirect()->route('agent.claim.weibo', ['token' => $token])
            ->with('success', '邮箱验证成功！请完成微博验证。');
    }

    public function showXiaohongshu(string $token)
    {
        $agent = Agent::where('claim_token', $token)->firstOrFail();

        // Already active - redirect to dashboard
        if ($agent->status === Agent::STATUS_ACTIVE) {
            return redirect()->route('dashboard')
                ->with('success', '🎉 代理 ' . $agent->name . ' 已激活！');
        }

        return view('agent.claim-weibo', compact('agent', 'token'));
    }

    public function submitXiaohongshu(Request $request, string $token)
    {
        $agent = Agent::where('claim_token', $token)->firstOrFail();

        // If already activated (admin scanned and activated), redirect
        if ($agent->status === Agent::STATUS_ACTIVE) {
            return redirect()->route('dashboard')
                ->with('success', '🎉 代理 ' . $agent->name . ' 已激活！');
        }

        // Optional manual URL submission - just store proof URL for admin reference
        $v = Validator::make($request->all(), [
            'post_url' => 'required|url|starts_with:https://weibo.com',
        ], [
            'post_url.starts_with' => '请填写正确的微博链接（https://weibo.com/...）',
        ]);

        if ($v->fails()) {
            return back()->withErrors($v)->withInput();
        }

        // Store the submitted URL for admin to review
        $agent->update([
            'claim_xiaohongshu_url' => $request->post_url,
        ]);

        return back()->with('success', '✅ 微博链接已提交，管理员审核后将激活你的代理，请耐心等待。');
    }
}
