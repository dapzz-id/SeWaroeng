<?php

namespace Tests\Unit;

use App\Models\TenantTestRecord;
use PHPUnit\Framework\TestCase;

class UuidV7Test extends TestCase
{
    public function test_generated_id_is_valid_uuid_v7(): void
    {
        $model = new TenantTestRecord;
        $id = $model->newUniqueId();

        $this->assertIsString($id);
        $this->assertEquals(36, strlen($id));

        // UUID format: 8-4-4-4-12
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id,
            "Identifier [{$id}] is not a valid RFC 9562 UUIDv7"
        );
    }

    public function test_uuid_v7_generation_is_time_ordered(): void
    {
        $model = new TenantTestRecord;

        $id1 = $model->newUniqueId();
        usleep(5000); // 5ms sleep
        $id2 = $model->newUniqueId();
        usleep(5000);
        $id3 = $model->newUniqueId();

        // In UUIDv7, lexicographical sorting corresponds to chronological creation time
        $this->assertLessThan(0, strcmp($id1, $id2));
        $this->assertLessThan(0, strcmp($id2, $id3));
    }
}
