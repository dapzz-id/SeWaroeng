<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use PHPUnit\Framework\TestCase;

class AuditLogSanitizationTest extends TestCase
{
    public function test_audit_log_redacts_sensitive_keys(): void
    {
        $payload = [
            'username' => 'john_doe',
            'email' => 'john@example.com',
            'password' => 'secret_password_123',
            'pin' => '123456',
            'nested' => [
                'api_key' => 'ak_live_xyz789',
                'description' => 'Regular note',
                'access_token' => 'bearer-token-val',
            ],
            'safe_amount' => 50000,
        ];

        $sanitized = AuditLog::sanitizePayload($payload);

        $this->assertEquals('john_doe', $sanitized['username']);
        $this->assertEquals('john@example.com', $sanitized['email']);
        $this->assertEquals('[REDACTED]', $sanitized['password']);
        $this->assertEquals('[REDACTED]', $sanitized['pin']);
        $this->assertEquals('[REDACTED]', $sanitized['nested']['api_key']);
        $this->assertEquals('[REDACTED]', $sanitized['nested']['access_token']);
        $this->assertEquals('Regular note', $sanitized['nested']['description']);
        $this->assertEquals(50000, $sanitized['safe_amount']);
    }
}
