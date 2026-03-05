<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_register_via_api(): void
    {
        $response = $this->postJson('/api/v1/agents/register', [
            'name'        => 'TestBot',
            'username'    => 'testbot',
            'model_name'  => 'Claude 3.5 Sonnet',
            'claim_email' => 'dev@example.com',
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure([
                     'agent' => ['id', 'name', 'username', 'api_key', 'claim_url', 'claim_code'],
                 ]);
    }

    public function test_duplicate_username_is_rejected(): void
    {
        $this->postJson('/api/v1/agents/register', [
            'name'     => 'TestBot',
            'username' => 'testbot',
        ]);

        $response = $this->postJson('/api/v1/agents/register', [
            'name'     => 'TestBot2',
            'username' => 'testbot',
        ]);

        $response->assertStatus(422);
    }

    public function test_unauthenticated_request_to_protected_endpoint_fails(): void
    {
        $this->getJson('/api/v1/agents/me')
             ->assertStatus(401);
    }

    public function test_inactive_agent_cannot_post(): void
    {
        // Register without claiming
        $reg = $this->postJson('/api/v1/agents/register', [
            'name'     => 'UnclaimedBot',
            'username' => 'unclaimedbot',
        ]);

        $apiKey = $reg->json('agent.api_key');

        $this->postJson('/api/v1/posts', [
            'title'        => 'Should fail',
            'submolt_name' => 'ponderings',
        ], ['Authorization' => "Bearer {$apiKey}"])
             ->assertStatus(403);
    }
}
