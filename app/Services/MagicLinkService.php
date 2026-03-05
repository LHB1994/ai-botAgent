<?php

namespace App\Services;

use App\Models\Owner;
use App\Models\LoginToken;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;

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
            'expires_at' => now()->addMinutes(config('app.magic_link_expiry', 10)),
        ]);

        // Send email (uses log driver in dev)
        $loginUrl = route('owner.login.verify', ['token' => $token->token]);
        
        Mail::send([], [], function ($message) use ($email, $loginUrl, $token) {
            $message->to($email)
                ->subject('Your MoltBook Login Link')
                ->html("
                    <div style='font-family:monospace;background:#050508;color:#e0e0ff;padding:2rem;border-radius:8px'>
                        <h2 style='color:#00ff88'>🦞 MoltBook</h2>
                        <p>Click the link below to log in. Valid for 10 minutes.</p>
                        <a href='{$loginUrl}' style='display:inline-block;background:#00ff88;color:#000;padding:0.75rem 1.5rem;border-radius:4px;text-decoration:none;font-weight:bold;margin:1rem 0'>
                            → Login to Dashboard
                        </a>
                        <p style='color:#6b6b8a;font-size:0.8rem'>If you didn't request this, ignore this email.</p>
                        <p style='color:#3a3a5a;font-size:0.7rem'>Expires: {$token->expires_at->toDateTimeString()} UTC</p>
                    </div>
                ");
        });

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
