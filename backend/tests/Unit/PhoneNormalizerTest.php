<?php

namespace Tests\Unit;

use App\Support\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    public function test_normalizes_standard_indonesian_phone_formats(): void
    {
        $this->assertEquals('+6281234567890', PhoneNormalizer::normalize('081234567890'));
        $this->assertEquals('+6281234567890', PhoneNormalizer::normalize('+6281234567890'));
        $this->assertEquals('+6281234567890', PhoneNormalizer::normalize('6281234567890'));
        $this->assertEquals('+6281234567890', PhoneNormalizer::normalize('81234567890'));
        $this->assertEquals('+6281234567890', PhoneNormalizer::normalize('0812-3456-7890'));
        $this->assertEquals('+6281234567890', PhoneNormalizer::normalize('(0812) 3456 7890'));
    }

    public function test_validation_passes_for_valid_numbers(): void
    {
        $this->assertTrue(PhoneNormalizer::isValid('081234567890'));
        $this->assertTrue(PhoneNormalizer::isValid('+6281987654321'));
        $this->assertTrue(PhoneNormalizer::isValid('628571234567'));
    }

    public function test_validation_fails_for_invalid_numbers(): void
    {
        $this->assertFalse(PhoneNormalizer::isValid('12345'));
        $this->assertFalse(PhoneNormalizer::isValid('0215551234')); // Landline
        $this->assertFalse(PhoneNormalizer::isValid('invalid-text'));
        $this->assertFalse(PhoneNormalizer::isValid(null));
        $this->assertFalse(PhoneNormalizer::isValid(''));
    }
}
