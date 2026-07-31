<?php

namespace AppGraph\Runtime\Laravel;

use AppGraph\Runtime\RuntimeEvidenceCollector;
use Throwable;

/**
 * Collector orchestration adapted from Pest v5.0.2's TIA Collectors.
 * Pest is MIT licensed; see THIRD_PARTY_NOTICES.md.
 */
final class LaravelRuntimeCollectors
{
    /** @var list<class-string> */
    private const COLLECTORS = [
        BladeTracker::class,
        TableTracker::class,
        InertiaTracker::class,
    ];

    public static function arm(object $application, RuntimeEvidenceCollector $collector): void
    {
        foreach (self::COLLECTORS as $runtimeCollector) {
            try {
                $runtimeCollector::arm($application, $collector);
            } catch (Throwable) {
                // One optional framework surface must not disable the others.
            }
        }
    }
}
