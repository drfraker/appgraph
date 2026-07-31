<?php

namespace AppGraph\Runtime\PHPUnit;

use Throwable;

/**
 * Driver detection adapted from Pest v5.0.2's TIA Recorder.
 * Pest is MIT licensed; see THIRD_PARTY_NOTICES.md.
 */
final class CoverageDriver
{
    public const PCOV = 'pcov';

    public const XDEBUG = 'xdebug';

    public static function detect(): ?string
    {
        if (function_exists('pcov\\start')
            && filter_var((string) ini_get('pcov.enabled'), FILTER_VALIDATE_BOOL)) {
            return self::PCOV;
        }

        if (! function_exists('xdebug_start_code_coverage') || ! function_exists('xdebug_info')) {
            return null;
        }

        try {
            $modes = xdebug_info('mode');

            return is_array($modes) && in_array('coverage', $modes, true)
                ? self::XDEBUG
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function available(): bool
    {
        return self::detect() !== null;
    }
}
