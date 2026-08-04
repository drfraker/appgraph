<?php

namespace AppGraph\Runtime\Laravel;

use AppGraph\Runtime\RuntimeEvidenceCollector;
use Throwable;

/**
 * Adapted from Pest v5.0.2's TIA BladeEdges collector.
 * Pest is MIT licensed; see THIRD_PARTY_NOTICES.md.
 */
final class BladeTracker
{
    private const MARKER = 'appgraph.runtime.blade-tracker-armed';

    public static function arm(object $application, RuntimeEvidenceCollector $collector): void
    {
        if (! method_exists($application, 'bound')
            || ! method_exists($application, 'make')
            || ! method_exists($application, 'instance')
            || $application->bound(self::MARKER)
            || ! $application->bound('view')) {
            return;
        }

        $factory = $application->make('view');

        if (! is_object($factory) || ! method_exists($factory, 'composer')) {
            return;
        }

        // Latch only after the composer can actually be registered, otherwise a
        // rejected factory would permanently disarm blade tracking for this app.
        $application->instance(self::MARKER, true);

        $factory->composer('*', static function (object $view) use ($collector): void {
            try {
                if (! method_exists($view, 'getPath')) {
                    return;
                }

                $path = $view->getPath();

                if (is_string($path) && $path !== '') {
                    $collector->recordBlade($path);
                }
            } catch (Throwable) {
                // Runtime evidence is best-effort and must never fail the test.
            }
        });
    }
}
