<?php

namespace AppGraph\Runtime\Laravel;

use AppGraph\Runtime\RuntimeEvidenceCollector;
use Throwable;

/**
 * Adapted from Pest v5.0.2's TIA InertiaEdges collector.
 * Pest is MIT licensed; see THIRD_PARTY_NOTICES.md.
 */
final class InertiaTracker
{
    private const MARKER = 'appgraph.runtime.inertia-tracker-armed';

    private const REQUEST_HANDLED_EVENT = 'Illuminate\\Foundation\\Http\\Events\\RequestHandled';

    private const MAX_RESPONSE_BYTES = 2_097_152;

    public static function arm(object $application, RuntimeEvidenceCollector $collector): void
    {
        if (! method_exists($application, 'bound')
            || ! method_exists($application, 'make')
            || ! method_exists($application, 'instance')
            || $application->bound(self::MARKER)
            || ! $application->bound('events')) {
            return;
        }

        $application->instance(self::MARKER, true);
        $events = $application->make('events');

        if (! is_object($events) || ! method_exists($events, 'listen')) {
            return;
        }

        $events->listen(self::REQUEST_HANDLED_EVENT, static function (object $event) use ($collector): void {
            try {
                if (! property_exists($event, 'response') || ! is_object($event->response)) {
                    return;
                }

                $component = self::componentFromResponse($event->response);

                if ($component !== null) {
                    $collector->recordInertiaComponent($component);
                }
            } catch (Throwable) {
                // Runtime evidence is best-effort and must never fail the test.
            }
        });
    }

    public static function componentFromResponse(object $response): ?string
    {
        $content = self::readContent($response);

        if ($content === null) {
            return null;
        }

        if (self::isInertiaJsonResponse($response)) {
            return self::componentFromJson($content);
        }

        if (str_contains($content, 'type="application/json"')
            && preg_match('#<script\b(?=[^>]*\bdata-page="app")(?=[^>]*\btype="application/json")[^>]*>(.+?)</script>#s', $content, $match) === 1) {
            $component = self::componentFromJson(html_entity_decode($match[1]));

            if ($component !== null) {
                return $component;
            }
        }

        if (str_contains($content, 'data-page=')
            && preg_match('/\sdata-page="(\{[^"]+\})"/', $content, $match) === 1) {
            return self::componentFromJson(html_entity_decode($match[1]));
        }

        return null;
    }

    private static function isInertiaJsonResponse(object $response): bool
    {
        if (! property_exists($response, 'headers') || ! is_object($response->headers)) {
            return false;
        }

        return method_exists($response->headers, 'has')
            && $response->headers->has('X-Inertia') === true;
    }

    private static function componentFromJson(string $json): ?string
    {
        $decoded = json_decode($json, true);

        return is_array($decoded)
            && isset($decoded['component'])
            && is_string($decoded['component'])
            && $decoded['component'] !== ''
                ? $decoded['component']
                : null;
    }

    private static function readContent(object $response): ?string
    {
        if (! method_exists($response, 'getContent')) {
            return null;
        }

        $content = $response->getContent();

        return is_string($content) && strlen($content) <= self::MAX_RESPONSE_BYTES
            ? $content
            : null;
    }
}
