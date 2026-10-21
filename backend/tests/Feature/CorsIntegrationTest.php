<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsIntegrationTest extends TestCase
{
    private const PLANNED_PRODUCTION_ORIGIN = 'https://sewaroeng.neuracakrawira.asia';

    private const UNAPPROVED_ORIGIN = 'https://unauthorized-attacker.example.com';

    public function test_approved_production_origin_receives_cors_headers_on_api_request(): void
    {
        $response = $this->withHeaders([
            'Origin' => self::PLANNED_PRODUCTION_ORIGIN,
        ])->getJson('/api/v1/health');

        $response->assertStatus(200);
        $response->assertHeader('Access-Control-Allow-Origin', self::PLANNED_PRODUCTION_ORIGIN);
        $this->assertNotEquals('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_unapproved_origin_does_not_receive_allow_origin_header(): void
    {
        $response = $this->withHeaders([
            'Origin' => self::UNAPPROVED_ORIGIN,
        ])->getJson('/api/v1/health');

        $response->assertStatus(200);
        $this->assertFalse(
            $response->headers->has('Access-Control-Allow-Origin'),
            'Unapproved origin should not receive Access-Control-Allow-Origin header.'
        );
    }

    public function test_preflight_options_request_succeeds_for_approved_origin(): void
    {
        $response = $this->withHeaders([
            'Origin' => self::PLANNED_PRODUCTION_ORIGIN,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Authorization, Content-Type, Idempotency-Key, X-Tenant-ID, X-Request-ID',
        ])->options('/api/v1/auth/login');

        // Preflight OPTIONS typically returns 200 or 204
        $this->assertTrue(in_array($response->getStatusCode(), [200, 204], true));
        $response->assertHeader('Access-Control-Allow-Origin', self::PLANNED_PRODUCTION_ORIGIN);

        $allowedMethods = $response->headers->get('Access-Control-Allow-Methods');
        $this->assertNotNull($allowedMethods);
        $this->assertStringContainsString('POST', $allowedMethods);
        $this->assertStringContainsString('OPTIONS', $allowedMethods);

        $allowedHeaders = strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('authorization', $allowedHeaders);
        $this->assertStringContainsString('content-type', $allowedHeaders);
        $this->assertStringContainsString('idempotency-key', $allowedHeaders);
        $this->assertStringContainsString('x-tenant-id', $allowedHeaders);
        $this->assertStringContainsString('x-request-id', $allowedHeaders);

        // Preflight max-age should be set
        $this->assertTrue($response->headers->has('Access-Control-Max-Age'));
        $this->assertEquals('86400', $response->headers->get('Access-Control-Max-Age'));
    }

    public function test_preflight_options_request_fails_to_allow_unapproved_origin(): void
    {
        $response = $this->withHeaders([
            'Origin' => self::UNAPPROVED_ORIGIN,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Authorization, Content-Type',
        ])->options('/api/v1/auth/login');

        $this->assertFalse(
            $response->headers->has('Access-Control-Allow-Origin'),
            'Preflight for unapproved origin must not receive Access-Control-Allow-Origin.'
        );
    }

    public function test_protected_route_returns_401_with_cors_headers_for_approved_origin(): void
    {
        // When client makes an unauthenticated request from approved origin,
        // it must return 401 unauthenticated while retaining CORS header so browser does not mask the error.
        $response = $this->withHeaders([
            'Origin' => self::PLANNED_PRODUCTION_ORIGIN,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/tenants');

        $response->assertStatus(401);
        $response->assertHeader('Access-Control-Allow-Origin', self::PLANNED_PRODUCTION_ORIGIN);
    }

    public function test_invalid_bearer_token_fails_authentication_without_leaking_secrets(): void
    {
        $invalidToken = 'invalid-plain-text-token-xyz-123456';

        $response = $this->withHeaders([
            'Origin' => self::PLANNED_PRODUCTION_ORIGIN,
            'Authorization' => 'Bearer '.$invalidToken,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/tenants');

        $response->assertStatus(401);
        $response->assertHeader('Access-Control-Allow-Origin', self::PLANNED_PRODUCTION_ORIGIN);

        $body = $response->getContent();
        $this->assertStringNotContainsString($invalidToken, $body, 'Tokens must never be echoed in error payloads.');
    }

    public function test_cors_does_not_permit_credentials_with_wildcards(): void
    {
        $this->assertFalse(
            config('cors.supports_credentials'),
            'Bearer token architecture must have supports_credentials set to false to prevent CSRF and wildcard leakage.'
        );
    }

    public function test_webhook_endpoints_work_without_origin_header(): void
    {
        // Server-to-server webhooks do not send browser Origin headers.
        // The API must handle them without CORS interference.
        $response = $this->postJson('/api/v1/webhooks/midtrans', [
            'order_id' => 'NON-EXISTENT-ORDER-TEST',
            'transaction_status' => 'settlement',
        ]);

        // Route was reached and processed (e.g. 400 or 404 or 422 depending on signature/order check, not blocked by CORS)
        $this->assertNotEquals(403, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_staging_environment_requires_explicit_origin_and_fails_closed(): void
    {
        // 1. Unconfigured staging fails closed: No origins allowed, no CORS header returned
        config([
            'cors.allowed_origins' => [],
            'cors.allowed_origins_patterns' => [],
        ]);

        $responseUnconfigured = $this->withHeaders([
            'Origin' => 'https://some-staging-frontend.internal',
        ])->getJson('/api/v1/health');

        $responseUnconfigured->assertStatus(200);
        $this->assertFalse(
            $responseUnconfigured->headers->has('Access-Control-Allow-Origin'),
            'Unconfigured staging must fail closed and emit no Access-Control-Allow-Origin.'
        );

        // 2. Explicitly configured staging origin allows only that origin
        $explicitStagingOrigin = 'https://staging-frontend.test-fixture.internal';

        config([
            'cors.allowed_origins' => [$explicitStagingOrigin],
            'cors.allowed_origins_patterns' => [],
        ]);

        // Request from approved staging origin
        $responseApproved = $this->withHeaders([
            'Origin' => $explicitStagingOrigin,
        ])->getJson('/api/v1/health');

        $responseApproved->assertStatus(200);
        $responseApproved->assertHeader('Access-Control-Allow-Origin', $explicitStagingOrigin);

        // Request from unapproved origin in staging: must never grant access to unapproved origin
        $responseRejected = $this->withHeaders([
            'Origin' => 'https://random-unapproved.example.com',
        ])->getJson('/api/v1/health');

        $responseRejected->assertStatus(200);
        $this->assertNotEquals(
            'https://random-unapproved.example.com',
            $responseRejected->headers->get('Access-Control-Allow-Origin'),
            'Unapproved origin must never match Access-Control-Allow-Origin.'
        );
    }
}
