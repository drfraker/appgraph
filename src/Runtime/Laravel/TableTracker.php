<?php

namespace AppGraph\Runtime\Laravel;

use AppGraph\Runtime\RuntimeEvidenceCollector;
use Throwable;

/**
 * Adapted from Pest v5.0.2's TIA TableTracker.
 * Pest is MIT licensed; see THIRD_PARTY_NOTICES.md.
 */
final class TableTracker
{
    private const MARKER = 'appgraph.runtime.table-tracker-armed';

    public static function arm(object $application, RuntimeEvidenceCollector $collector): void
    {
        if (! method_exists($application, 'bound')
            || ! method_exists($application, 'make')
            || ! method_exists($application, 'instance')
            || $application->bound(self::MARKER)
            || ! $application->bound('db')) {
            return;
        }

        $application->instance(self::MARKER, true);
        $listener = static function (object $query) use ($collector): void {
            try {
                if (! property_exists($query, 'sql') || ! is_string($query->sql) || $query->sql === '') {
                    return;
                }

                foreach (TableExtractor::fromSql($query->sql) as $table) {
                    $collector->recordTable($table);
                }
            } catch (Throwable) {
                // Runtime evidence is best-effort and must never fail the test.
            }
        };
        $database = $application->make('db');

        if (is_object($database) && is_callable([$database, 'listen'])) {
            $database->listen($listener);

            return;
        }

        if (! $application->bound('events')) {
            return;
        }

        $events = $application->make('events');

        if (is_object($events) && method_exists($events, 'listen')) {
            $events->listen('Illuminate\\Database\\Events\\QueryExecuted', $listener);
        }
    }
}
