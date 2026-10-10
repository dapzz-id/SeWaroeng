<?php

namespace App\Support;

class PhoneNormalizer
{
    /**
     * Normalizes an Indonesian phone number to standard E.164 format (+628...).
     * Returns null if input is null or blank.
     */
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $cleaned = trim($phone);
        if ($cleaned === '') {
            return null;
        }

        // Remove all spaces, dashes, dots, parentheses
        $cleaned = preg_replace('/[\s\-\.\(\)]+/', '', $cleaned);

        // Normalize leading prefixes
        if (str_starts_with($cleaned, '08')) {
            $cleaned = '+62'.substr($cleaned, 1);
        } elseif (str_starts_with($cleaned, '628')) {
            $cleaned = '+'.$cleaned;
        } elseif (str_starts_with($cleaned, '8') && ! str_starts_with($cleaned, '+')) {
            $cleaned = '+62'.$cleaned;
        }

        return $cleaned;
    }

    /**
     * Validates if a phone number matches standard Indonesian mobile number pattern.
     */
    public static function isValid(?string $phone): bool
    {
        $normalized = self::normalize($phone);

        if ($normalized === null) {
            return false;
        }

        // Standard Indonesian mobile prefix: +628 followed by 7 to 11 digits
        return (bool) preg_match('/^\+628[1-9][0-9]{6,10}$/', $normalized);
    }
}
