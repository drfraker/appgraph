<?php

namespace AppGraph\Support;

final class BoundedText
{
    /**
     * Truncate valid UTF-8 to a byte budget without splitting a code point.
     */
    public static function utf8Bytes(string $value, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }

        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        if (function_exists('mb_strcut')) {
            return mb_strcut($value, 0, $maxBytes, 'UTF-8');
        }

        $bounded = substr($value, 0, $maxBytes);

        // A UTF-8 code point is at most four bytes, so this fallback performs
        // at most three repairs for otherwise-valid input.
        while ($bounded !== '' && preg_match('//u', $bounded) !== 1) {
            $bounded = substr($bounded, 0, -1);
        }

        return $bounded;
    }
}
