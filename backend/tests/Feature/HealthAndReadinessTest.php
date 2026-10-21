<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthAndReadinessTest extends TestCase
{
    public function test_liveness_probe_returns_http_200(): void
    {
        $response = $this->get('/up');
        $response->assertStatus(200);
    }

    public function test_readiness_probe_returns_healthy_status_and_sanitized_payload(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'timestamp',
                ],
                'meta',
                'message',
            ]);

        // Verify zero sensitive infrastructure details are leaked
        $content = $response->getContent();
        $this->assertStringNotContainsString('password', $content);
        $this->assertStringNotContainsString('pgsql', $content);
        $this->assertStringNotContainsString('sewaroeng', $content);
        $this->assertStringNotContainsString('redis', $content);
        $this->assertStringNotContainsString('secret', $content);
    }
}
