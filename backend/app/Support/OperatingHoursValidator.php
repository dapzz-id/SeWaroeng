<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class OperatingHoursValidator
{
    public const DAYS = [
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];

    /**
     * Validates and normalizes weekly operating hours.
     *
     * @param  array<string, mixed>  $hours
     * @return array<string, array{closed: bool, open_time: ?string, close_time: ?string}>
     *
     * @throws ValidationException
     */
    public static function validate(array $hours): array
    {
        $normalized = [];

        foreach ($hours as $day => $config) {
            $lowerDay = strtolower((string) $day);

            if (! in_array($lowerDay, self::DAYS, true)) {
                throw ValidationException::withMessages([
                    "operating_hours.{$day}" => ["Invalid day [{$day}]. Allowed days are monday through sunday."],
                ]);
            }

            if (! is_array($config)) {
                throw ValidationException::withMessages([
                    "operating_hours.{$day}" => ["Configuration for [{$day}] must be an object."],
                ]);
            }

            $closed = (bool) ($config['closed'] ?? false);

            if ($closed) {
                $normalized[$lowerDay] = [
                    'closed' => true,
                    'open_time' => null,
                    'close_time' => null,
                ];

                continue;
            }

            $openTime = $config['open_time'] ?? null;
            $closeTime = $config['close_time'] ?? null;

            if (empty($openTime) || empty($closeTime)) {
                throw ValidationException::withMessages([
                    "operating_hours.{$lowerDay}" => ["Open time and close time are required when outlet is open on {$lowerDay}."],
                ]);
            }

            if (! is_string($openTime) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $openTime)) {
                throw ValidationException::withMessages([
                    "operating_hours.{$lowerDay}.open_time" => ['Open time must be in 24-hour HH:mm format.'],
                ]);
            }

            if (! is_string($closeTime) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $closeTime)) {
                throw ValidationException::withMessages([
                    "operating_hours.{$lowerDay}.close_time" => ['Close time must be in 24-hour HH:mm format.'],
                ]);
            }

            $normalized[$lowerDay] = [
                'closed' => false,
                'open_time' => $openTime,
                'close_time' => $closeTime,
            ];
        }

        return $normalized;
    }
}
