<?php

namespace AppGraph\Support;

class MemoryLimit
{
    public static function ensure(?string $minimum): void
    {
        if (! is_string($minimum) || $minimum === '') {
            return;
        }

        $current = ini_get('memory_limit');

        if ($current === false || $current === '-1') {
            return;
        }

        $currentBytes = self::toBytes($current);
        $minimumBytes = self::toBytes($minimum);

        if ($minimumBytes > 0 && ($currentBytes === 0 || $currentBytes < $minimumBytes)) {
            ini_set('memory_limit', $minimum);
        }
    }

    private static function toBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return (int) match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
