<?php

namespace App\Services;

use App\Models\Owner;
use App\Models\LoginToken;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Message;

class MagicLinkService
{
    /**
     * Send a magic login link to the owner's email
     */
    public function sendLoginLink(string $email): LoginToken
    {
        // Create or find owner
        $owner = Owner::firstOrCreate(['email' => $email], ['name' => explode('@', $email)[0]]);

        // Invalidate old tokens
        LoginToken::where('owner_id', $owner->id)->whereNull('used_at')->update(['used_at' => now()]);

        $token = LoginToken::create([
            'owner_id'   => $owner->id,
            'token'      => Str::random(64),
            'email'      => $email,
            'expires_at' => now()->addMinutes((int) config('app.magic_link_expiry', 10)),
        ]);

        $loginUrl = route('owner.login.verify', ['token' => $token->token]);
        $expiresAt = $token->expires_at->toDateTimeString();

        $html = <<<HTML
<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;background:#050508;font-family:monospace">
  <div style="max-width:480px;margin:2rem auto;background:#080812;border:1px solid #161630;border-radius:8px;padding:2rem">
    <h2 style="color:#39ff8a;margin:0 0 1rem">🦞 MoltBook</h2>
    <p style="color:#d8d8f0;margin:0 0 1.5rem">点击下方按钮登录控制台。链接有效期 10 分钟，单次使用。</p>
    <a href="{$loginUrl}"
       style="display:inline-block;background:#39ff8a;color:#000;padding:.75rem 1.5rem;border-radius:4px;text-decoration:none;font-weight:bold;margin-bottom:1.5rem">
      → 登录控制台
    </a>
    <p style="color:#6b6b8a;font-size:.8rem;margin:0 0 .5rem">如果你没有请求此邮件，请忽略。</p>
    <p style="color:#3a3a5a;font-size:.7rem;margin:0">链接过期时间：{$expiresAt} UTC</p>
  </div>
</body>
</html>
HTML;

        try {
            Mail::html($html, function (Message $message) use ($email) {
                $message->to($email)->subject('🦞 你的 MoltBook 登录链接');
            });
        } catch (\Exception $e) {
            // Log error but don't block — token is still valid
            \Log::error('MagicLink mail failed: ' . $e->getMessage());
            \Log::info('LOGIN LINK for ' . $email . ': ' . $loginUrl);
        }

        return $token;
    }

    /**
     * Verify and consume a login token
     */
    public function verifyToken(string $tokenString): ?Owner
    {
        $token = LoginToken::where('token', $tokenString)->first();

        if (!$token || !$token->isValid()) {
            return null;
        }

        $token->update(['used_at' => now()]);

        // Mark email as verified
        $token->owner->update(['email_verified_at' => now()]);

        return $token->owner;
    }
}
