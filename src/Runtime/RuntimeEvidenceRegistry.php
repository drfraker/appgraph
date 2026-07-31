<?php

namespace AppGraph\Runtime;

use AppGraph\Runtime\Laravel\LaravelRuntimeCollectors;
use Throwable;

/**
 * Process-local bridge between the PHPUnit extension and Laravel providers.
 *
 * PHPUnit activates the collector before a Laravel test application boots.
 * AppGraph's service provider can then attach framework listeners without
 * introducing any work in normal web, queue, console, or test processes.
 */
final class RuntimeEvidenceRegistry
{
    private static ?RuntimeEvidenceCollector $collector = null;

    public static function activate(RuntimeEvidenceCollector $collector): void
    {
        self::$collector = $collector;
    }

    public static function collector(): ?RuntimeEvidenceCollector
    {
        return self::$collector;
    }

    public static function deactivate(?RuntimeEvidenceCollector $collector = null): void
    {
        if ($collector === null || self::$collector === $collector) {
            self::$collector = null;
        }
    }

    public static function armLaravel(object $application): void
    {
        $collector = self::$collector;

        if ($collector === null) {
            return;
        }

        try {
            LaravelRuntimeCollectors::arm($application, $collector);
        } catch (Throwable) {
            // Runtime evidence must never change whether an application boots.
        }
    }
}
