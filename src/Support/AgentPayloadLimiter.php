<?php

namespace AppGraph\Support;

use JsonException;

/**
 * Last-line defense for agent-facing structured responses. Query-specific
 * limits remain authoritative; this guard prevents bounded row counts from
 * hiding unbounded nested metadata or very large persisted identifiers.
 */
final class AgentPayloadLimiter
{
    public const MAX_BYTES = 1000000;

    private const MAX_STRING_BYTES = 4096;

    private const MAX_DEPTH = 12;

    private const METADATA_RESERVE_BYTES = 8192;

    /**
     * These values are references that an agent may feed back into another
     * query or use to open source. Returning a shortened value would invent a
     * different node or path, so oversized rows containing them are omitted
     * atomically instead.
     *
     * @var array<string, true>
     */
    private const EXACT_STRING_KEYS = [
        'action' => true,
        'absoluteFile' => true,
        'absolute_path' => true,
        'abstract' => true,
        'afterNode' => true,
        'alias' => true,
        'aliasDirectTarget' => true,
        'aliasTarget' => true,
        'baselineGeneration' => true,
        'beforeNode' => true,
        'bindingAbstract' => true,
        'bindingConcrete' => true,
        'bindingConsumer' => true,
        'boundaryClass' => true,
        'calledMethod' => true,
        'caller' => true,
        'chainQueue' => true,
        'changed_file' => true,
        'class' => true,
        'column' => true,
        'command' => true,
        'configuredConnection' => true,
        'configuredQueue' => true,
        'connection' => true,
        'concrete' => true,
        'consumer' => true,
        'contextualTarget' => true,
        'controller' => true,
        'controllerMethod' => true,
        'database' => true,
        'declared' => true,
        'declaredBy' => true,
        'declaredMethod' => true,
        'declaredOn' => true,
        'declaredParameterType' => true,
        'declared_alias' => true,
        'declaringClass' => true,
        'declaring_class' => true,
        'dispatchMethod' => true,
        'effectiveListener' => true,
        'elementClass' => true,
        'entrypoint' => true,
        'event' => true,
        'executable' => true,
        'expanded' => true,
        'expanded_alias' => true,
        'file' => true,
        'field' => true,
        'foreignTable' => true,
        'from' => true,
        'handler' => true,
        'handlerClass' => true,
        'handlerFile' => true,
        'handleParamType' => true,
        'httpMethod' => true,
        'id' => true,
        'identity' => true,
        'invalid_file' => true,
        'invocation_method' => true,
        'invokedBy' => true,
        'invokedMethod' => true,
        'job' => true,
        'listener' => true,
        'method' => true,
        'missing_file' => true,
        'middleware' => true,
        'model' => true,
        'name' => true,
        'nodeId' => true,
        'namespace' => true,
        'originClass' => true,
        'parameter' => true,
        'parent' => true,
        'path' => true,
        'payloadClass' => true,
        'pivotTable' => true,
        'policy' => true,
        'queue' => true,
        'reference' => true,
        'relationship' => true,
        'relationshipMethod' => true,
        'relativePath' => true,
        'resolved' => true,
        'resolved_alias' => true,
        'resolved_class' => true,
        'requested' => true,
        'requestedAbstract' => true,
        'requestedClass' => true,
        'requestedController' => true,
        'requestedHandlerClass' => true,
        'requestedMethod' => true,
        'requestedRequest' => true,
        'resolutionClass' => true,
        'resource' => true,
        'returnType' => true,
        'revision' => true,
        'route' => true,
        'routeName' => true,
        'routeParameter' => true,
        'runtimeClass' => true,
        'runtimeController' => true,
        'runtimeRequest' => true,
        'scanner' => true,
        'seed' => true,
        'subject' => true,
        'table' => true,
        'target' => true,
        'targetClass' => true,
        'targetTable' => true,
        'to' => true,
        'topTrait' => true,
        'trait' => true,
        'type' => true,
        'uri' => true,
        'via' => true,
        'viaTrait' => true,
    ];

    /** @var array<string, true> */
    private const EXACT_STRING_COLLECTION_KEYS = [
        'aliases' => true,
        'ambiguousLogicalTableSamples' => true,
        'bindingResolutionPath' => true,
        'candidates' => true,
        'changedFiles' => true,
        'changedFields' => true,
        'columns' => true,
        'commands' => true,
        'connections' => true,
        'dispatchKinds' => true,
        'events' => true,
        'fields' => true,
        'files' => true,
        'foreignColumns' => true,
        'formRequests' => true,
        'handlers' => true,
        'ids' => true,
        'kinds' => true,
        'listeners' => true,
        'localTypes' => true,
        'methods' => true,
        'middleware' => true,
        'models' => true,
        'nodeIds' => true,
        'nodes' => true,
        'parameters' => true,
        'path' => true,
        'possibleModels' => true,
        'precedences' => true,
        'propertyTypes' => true,
        'readers' => true,
        'relationships' => true,
        'requestedFiles' => true,
        'requestedTargets' => true,
        'resolutionPath' => true,
        'resolution_path' => true,
        'resolved_middleware' => true,
        'routeParameters' => true,
        'routes' => true,
        'samplePaths' => true,
        'tables' => true,
        'targets' => true,
        'traits' => true,
        'traitUses' => true,
        'unmappedChangedFiles' => true,
        'writers' => true,
    ];

    /** @var array<string, int> */
    private const KEY_PRIORITY = [
        'query' => 0,
        'target' => 1,
        'revision' => 2,
        'generation' => 2,
        'generationUnavailable' => 3,
        'baseline' => 4,
        'assessment' => 5,
        'counts' => 6,
        'scannerWarnings' => 7,
        'budget' => 7,
        'omitted' => 8,
        'verification' => 9,
    ];

    public static function isExactStringKey(?string $key): bool
    {
        return $key !== null && isset(self::EXACT_STRING_KEYS[$key]);
    }

    public static function isExactStringCollectionKey(?string $key): bool
    {
        return $key !== null && isset(self::EXACT_STRING_COLLECTION_KEYS[$key]);
    }

    /** @return array<string, mixed> */
    public static function limit(array $payload): array
    {
        $encoded = self::json($payload);
        $originalBytes = strlen($encoded);

        if ($originalBytes <= self::MAX_BYTES) {
            return $payload;
        }

        foreach ([
            self::MAX_BYTES - self::METADATA_RESERVE_BYTES,
            750000,
            500000,
        ] as $valueBudget) {
            $remaining = $valueBudget;
            $stats = [
                'omittedValues' => 0,
                'truncatedStrings' => 0,
                'depthTruncations' => 0,
            ];
            $semanticOmitted = false;
            $bounded = self::compact(
                $payload,
                0,
                $remaining,
                $stats,
                null,
                false,
                $semanticOmitted,
            );
            $bounded = is_array($bounded) ? $bounded : [];
            $bounded['responseTruncated'] = true;
            $bounded['responseBounds'] = [
                'maxBytes' => self::MAX_BYTES,
                'originalBytes' => $originalBytes,
                'omittedValues' => $stats['omittedValues'],
                'truncatedStrings' => $stats['truncatedStrings'],
                'depthTruncations' => $stats['depthTruncations'],
            ];
            $bounded['responseBounds']['returnedBytes'] = strlen(self::json($bounded));
            // The returnedBytes field can add a digit; measure once more after
            // inserting it so the evidence describes the final representation.
            $bounded['responseBounds']['returnedBytes'] = strlen(self::json($bounded));
            $returnedBytes = strlen(self::json($bounded));

            if ($returnedBytes <= self::MAX_BYTES) {
                return $bounded;
            }
        }

        // The recursive accounting is deliberately conservative, but keep a
        // small deterministic fallback so the byte ceiling remains a hard
        // contract even if a future PHP JSON representation surprises it.
        $fallback = array_filter([
            'query' => is_string($payload['query'] ?? null)
                ? BoundedText::utf8Bytes($payload['query'], 128)
                : null,
            'target' => is_string($payload['target'] ?? null)
                ? self::fallbackExactString($payload['target'])
                : null,
            'generation' => is_array($payload['generation'] ?? null)
                ? self::smallGeneration($payload['generation'])
                : null,
            'generationUnavailable' => is_array($payload['generationUnavailable'] ?? null)
                ? self::smallGenerationUnavailable($payload['generationUnavailable'])
                : null,
            'responseTruncated' => true,
            'responseBounds' => [
                'maxBytes' => self::MAX_BYTES,
                'originalBytes' => $originalBytes,
                'returnedBytes' => 0,
                'omittedValues' => 1,
                'truncatedStrings' => 0,
                'depthTruncations' => 0,
                'fallback' => true,
            ],
        ], static fn (mixed $value): bool => $value !== null);
        $fallback['responseBounds']['returnedBytes'] = strlen(self::json($fallback));
        $fallback['responseBounds']['returnedBytes'] = strlen(self::json($fallback));

        return $fallback;
    }

    /**
     * Encode a bounded payload. Pretty-printing is cosmetic: if its whitespace
     * alone would cross the response ceiling, return the equivalent compact JSON.
     */
    public static function encode(array $payload, bool $pretty = false): string
    {
        $bounded = self::limit($payload);
        $compact = self::json($bounded);

        if (! $pretty) {
            return $compact;
        }

        try {
            $formatted = json_encode(
                $bounded,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_PRETTY_PRINT
                    | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return $compact;
        }

        return strlen($formatted) <= self::MAX_BYTES ? $formatted : $compact;
    }

    /**
     * @param array{omittedValues: int, truncatedStrings: int, depthTruncations: int} $stats
     */
    private static function compact(
        mixed $value,
        int $depth,
        int &$remaining,
        array &$stats,
        ?string $key,
        bool $exactStringContext,
        bool &$semanticOmitted,
    ): mixed {
        if ($remaining <= 0) {
            $stats['omittedValues']++;

            if ($exactStringContext
                || self::isExactStringKey($key)
                || (is_array($value) && self::hasDirectExactEntry($value))) {
                $semanticOmitted = true;
            }

            return null;
        }

        if (is_string($value)) {
            if ($exactStringContext || self::isExactStringKey($key)) {
                $cost = strlen(self::json($value));

                if ($cost > $remaining) {
                    $stats['omittedValues']++;
                    $semanticOmitted = true;

                    return null;
                }

                $remaining -= $cost;

                return $value;
            }

            $bounded = BoundedText::utf8Bytes($value, self::MAX_STRING_BYTES);

            if ($bounded !== $value) {
                $stats['truncatedStrings']++;
            }

            $cost = strlen(self::json($bounded));

            if ($cost > $remaining) {
                $bounded = BoundedText::utf8Bytes($bounded, max(0, $remaining - 2));
                $stats['truncatedStrings']++;
                $cost = strlen(self::json($bounded));
            }

            if ($cost > $remaining) {
                $stats['omittedValues']++;

                return null;
            }

            $remaining -= $cost;

            return $bounded;
        }

        if (! is_array($value)) {
            $cost = strlen(self::json($value));

            if ($cost > $remaining) {
                $stats['omittedValues']++;

                return null;
            }

            $remaining -= $cost;

            return $value;
        }

        $entryRemaining = $remaining;
        $entryStats = $stats;
        $exactStringContext = $exactStringContext
            || self::isExactStringCollectionKey($key);

        $hasDirectExactEntry = self::hasDirectExactEntry($value);

        if ($depth >= self::MAX_DEPTH) {
            $stats['depthTruncations']++;
            $stats['omittedValues'] += count($value);

            if ($exactStringContext || $hasDirectExactEntry) {
                $semanticOmitted = true;

                return null;
            }

            return [];
        }

        if ($remaining < 2) {
            $stats['omittedValues'] += max(1, count($value));

            if ($exactStringContext || $hasDirectExactEntry) {
                $semanticOmitted = true;

                return null;
            }

            return [];
        }

        $remaining -= 2;
        $result = [];
        $entries = self::orderedEntries($value);
        $list = array_is_list($value);

        foreach ($entries as [$key, $item]) {
            $separatorCost = $result === [] ? 0 : 1;
            $keyCost = $list ? 0 : strlen(self::json((string) $key)) + 1;

            if ($remaining <= $separatorCost + $keyCost) {
                $entryKey = is_string($key) ? $key : null;

                if ($exactStringContext
                    || self::isExactStringKey($entryKey)
                    || self::isExactStringCollectionKey($entryKey)) {
                    $remaining = $entryRemaining;
                    $stats = $entryStats;
                    $stats['omittedValues']++;
                    $semanticOmitted = true;

                    return null;
                }

                $stats['omittedValues']++;
                continue;
            }

            $remaining -= $separatorCost + $keyCost;
            $before = $remaining;
            $childSemanticOmitted = false;
            $bounded = self::compact(
                $item,
                $depth + 1,
                $remaining,
                $stats,
                is_string($key) ? $key : null,
                $exactStringContext,
                $childSemanticOmitted,
            );

            if ($childSemanticOmitted) {
                if (! $list || $exactStringContext) {
                    $remaining = $entryRemaining;
                    $stats = $entryStats;
                    $stats['omittedValues']++;
                    $semanticOmitted = true;

                    return null;
                }

                // An ordinary result list is the atomic omission boundary. A
                // row whose identifiers do not fit is skipped as a whole.
                $remaining += $separatorCost + $keyCost;

                continue;
            }

            if ($before === $remaining && $bounded === null && $item !== null) {
                // Restore structural overhead when the value itself did not fit.
                $remaining += $separatorCost + $keyCost;
                continue;
            }

            if ($list) {
                $result[] = $bounded;
            } else {
                $result[$key] = $bounded;
            }
        }

        return $result;
    }

    private static function hasDirectExactEntry(array $value): bool
    {
        foreach ($value as $key => $_item) {
            if (is_string($key)
                && (self::isExactStringKey($key) || self::isExactStringCollectionKey($key))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array{0: int|string, 1: mixed}> */
    private static function orderedEntries(array $value): array
    {
        $entries = [];
        $index = 0;

        foreach ($value as $key => $item) {
            $entries[] = [$key, $item, $index++];
        }

        if (array_is_list($value)) {
            return array_map(
                static fn (array $entry): array => [$entry[0], $entry[1]],
                $entries,
            );
        }

        usort($entries, static function (array $left, array $right): int {
            $leftKey = (string) $left[0];
            $rightKey = (string) $right[0];
            $leftPriority = self::KEY_PRIORITY[$leftKey]
                ?? (isset(self::EXACT_STRING_KEYS[$leftKey]) || isset(self::EXACT_STRING_COLLECTION_KEYS[$leftKey]) ? 10 : 100);
            $rightPriority = self::KEY_PRIORITY[$rightKey]
                ?? (isset(self::EXACT_STRING_KEYS[$rightKey]) || isset(self::EXACT_STRING_COLLECTION_KEYS[$rightKey]) ? 10 : 100);

            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }

            $leftComplex = is_array($left[1]) ? 1 : 0;
            $rightComplex = is_array($right[1]) ? 1 : 0;

            return $leftComplex <=> $rightComplex ?: $left[2] <=> $right[2];
        });

        return array_map(
            static fn (array $entry): array => [$entry[0], $entry[1]],
            $entries,
        );
    }

    /** @param array<string, mixed> $generation @return array<string, mixed> */
    private static function smallGeneration(array $generation): array
    {
        return array_intersect_key($generation, array_flip([
            'id',
            'sequence',
            'parentGeneration',
            'generatedAt',
            'committedAt',
            'current',
        ]));
    }

    private static function fallbackExactString(string $value): ?string
    {
        return strlen(self::json($value)) <= 65536 ? $value : null;
    }

    /** @param array<string, mixed> $unavailable @return array<string, string> */
    private static function smallGenerationUnavailable(array $unavailable): array
    {
        return array_filter([
            'reason' => is_string($unavailable['reason'] ?? null)
                ? BoundedText::utf8Bytes($unavailable['reason'], 256)
                : null,
            'message' => is_string($unavailable['message'] ?? null)
                ? BoundedText::utf8Bytes($unavailable['message'], self::MAX_STRING_BYTES)
                : null,
        ], static fn (?string $value): bool => $value !== null);
    }

    private static function json(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return 'null';
        }
    }
}
