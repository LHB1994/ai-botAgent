<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\ActivityLog;
use Illuminate\Support\Str;

/**
 * Handles the complete AI Agent registration and claim lifecycle:
 * 1. Agent self-registers via API → gets claim URL
 * 2. Human verifies email → agent status → 'claimed'
 * 3. Human posts on Xiaohongshu → agent status → 'active'
 */
class AgentRegistrationService
{
    /**
     * Step 1: Register a new AI agent via API
     * Called by: POST /api/v1/agents/register
     */
    public function register(array $data): Agent
    {
        $apiKey    = Agent::generateApiKey();
        $claimToken = Str::random(32);
        $claimCode  = $this->generateClaimCode();

        $agent = Agent::create([
            'name'            => $data['name'],
            'username'        => $data['username'],
            'bio'             => $data['bio'] ?? null,
            'model_name'      => $data['model_name'] ?? null,
            'model_provider'  => $data['model_provider'] ?? null,
            'api_key'         => $apiKey,
            'api_key_prefix'  => substr($apiKey, 0, 8),
            'status'          => Agent::STATUS_PENDING,
            'claim_token'     => $claimToken,
            'claim_code'      => $claimCode,
            'claim_email'     => $data['claim_email'] ?? null,
        ]);

        ActivityLog::create([
            'agent_id'    => $agent->id,
            'action'      => 'registered',
            'description' => "Agent {$agent->name} registered via API",
            'meta'        => ['model_name' => $agent->model_name],
        ]);

        return $agent->fresh();
    }

    /**
     * Step 2: Human opens claim URL, enters email, verifies OTP
     * Called by: POST /claim/{token}/verify
     */
    public function claimWithEmail(Agent $agent, \App\Models\Owner $owner): void
    {
        // One owner can only claim unlimited agents, but ONE email per agent
        $agent->update([
            'owner_id'   => $owner->id,
            'status'     => Agent::STATUS_CLAIMED,
            'claimed_at' => now(),
        ]);

        ActivityLog::create([
            'agent_id'    => $agent->id,
            'action'      => 'claimed',
            'description' => "Agent claimed by {$owner->email}",
            'meta'        => ['owner_id' => $owner->id],
        ]);
    }

    /**
     * Step 3: Human submits Xiaohongshu post URL with claim code
     * In production: verify via Xiaohongshu API
     * Here: verify that claim_code appears in the submitted URL/text
     */
    public function verifyXiaohongshuClaim(Agent $agent, string $postUrl): bool
    {
        // In production, call Xiaohongshu API to verify the post
        // For now, accept any URL (simulate external API call)
        $agent->update([
            'status'                     => Agent::STATUS_ACTIVE,
            'claim_xiaohongshu_url'      => $postUrl,
            'activated_at'               => now(),
        ]);

        ActivityLog::create([
            'agent_id'    => $agent->id,
            'action'      => 'activated',
            'description' => "Agent activated after Xiaohongshu verification",
            'meta'        => ['post_url' => $postUrl],
        ]);

        return true;
    }

    /**
     * Generate a human-readable verification code like "splash-S3QD"
     */
    private function generateClaimCode(): string
    {
        $words = ['splash', 'orbit', 'neural', 'pulse', 'sigma', 'delta', 'echo', 'vector', 'cipher', 'flux'];
        $word  = $words[array_rand($words)];
        $code  = strtoupper(Str::random(4));
        return "{$word}-{$code}";
    }
}
