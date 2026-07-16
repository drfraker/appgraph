<?php

namespace AppGraph\Query;

final class LaravelExecutionSemantics
{
    public const MAX_DISPATCH_OCCURRENCES = 256;

    private const MAX_AGGREGATE_KINDS = 8;

    /** @var array<int, string> */
    public const EDGE_TYPES = [
        'calls',
        'validates_with',
        'framework_invokes',
        'dispatches',
        'handled_by',
    ];

    public function __construct(private GraphIndex $index)
    {
    }

    /** @return array<int, string> */
    public function executionEdgeTypes(): array
    {
        return self::EDGE_TYPES;
    }

    /**
     * Apply the framework-specific causal rules for an execution traversal.
     *
     * Forward traversal records the active event/job role on a dispatch bridge
     * and permits only a matching active handler. Reverse traversal infers the
     * role from an active handler and permits only a causal dispatch of that
     * same role. The state partition keeps paths through dual-role classes from
     * lending confidence to one another.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $edge
     * @return array<string, mixed>|false
     */
    public function transition(array $state, array $edge, string $neighborId, string $direction): array|false
    {
        return match ($direction) {
            'out' => $this->forwardTransition($state, $edge, $neighborId),
            'in' => $this->reverseTransition($state, $edge, $neighborId),
            default => false,
        };
    }

    /** @param array<string, mixed> $edge */
    public function dispatchCausalExecutionProven(array $edge): bool
    {
        return $this->dispatchAnalysis($edge, null)['causalExecutionProven'];
    }

    /**
     * Return only event/job roles supported by causal occurrence evidence.
     *
     * @param array<string, mixed> $edge
     * @param array<string, mixed>|null $target
     * @return array<int, string>
     */
    public function dispatchKinds(array $edge, ?array $target): array
    {
        return $this->dispatchAnalysis($edge, $target)['kinds'];
    }

    /**
     * Inspect dispatch metadata without allowing one edge to hide unbounded
     * work inside its occurrence list. When the bounded prefix cannot prove a
     * causal occurrence, truncation fails closed rather than fabricating one.
     *
     * `causalDispatchKinds` is an optional causality-aware aggregate for graph
     * producers. The ordinary dispatch kind aggregate is only complete enough
     * to trust when the aggregate causality value proves every occurrence.
     *
     * @param array<string, mixed> $edge
     * @param array<string, mixed>|null $target
     * @return array{causalExecutionProven: bool, kinds: array<int, string>, truncated: bool, examinedOccurrences: int}
     */
    public function dispatchAnalysis(array $edge, ?array $target): array
    {
        $metadata = is_array($edge['metadata'] ?? null) ? $edge['metadata'] : [];
        $occurrences = is_array($metadata['dispatchOccurrences'] ?? null)
            ? $metadata['dispatchOccurrences']
            : [];
        $kinds = [];
        $truncated = false;
        $examined = 0;
        $causalOccurrenceFound = false;
        $aggregateCausalityConsistent = true;

        if ($occurrences !== []) {
            $truncated = count($occurrences) > self::MAX_DISPATCH_OCCURRENCES;

            foreach ($occurrences as $occurrence) {
                if ($examined >= self::MAX_DISPATCH_OCCURRENCES) {
                    break;
                }

                $examined++;

                if (! is_array($occurrence)) {
                    continue;
                }

                if (($occurrence['causalExecutionProven'] ?? null) === false) {
                    $aggregateCausalityConsistent = false;

                    continue;
                }

                $kind = $occurrence['kind'] ?? null;

                if ($this->isDispatchKind($kind)) {
                    $kinds[$kind] = $kind;
                    $causalOccurrenceFound = true;
                }
            }

            if ($truncated) {
                $aggregateTruncated = false;
                $aggregateKinds = $this->safeCausalAggregateKinds(
                    $metadata,
                    $aggregateCausalityConsistent,
                    $aggregateTruncated,
                );
                $truncated = $truncated || $aggregateTruncated;

                foreach ($aggregateKinds as $kind) {
                    $kinds[$kind] = $kind;
                }

                $causalOccurrenceFound = $causalOccurrenceFound || $aggregateKinds !== [];
            }

            ksort($kinds);

            return [
                'causalExecutionProven' => ($metadata['causalExecutionProven'] ?? null) !== false
                    && $causalOccurrenceFound,
                'kinds' => array_values($kinds),
                'truncated' => $truncated,
                'examinedOccurrences' => $examined,
            ];
        }

        $aggregateTruncated = false;
        $kinds = $this->metadataKinds($metadata, 'dispatchKinds', 'kind', $aggregateTruncated);

        if ($kinds === [] && ! $aggregateTruncated) {
            $nodeTruncated = false;
            $kinds = $this->nodeKinds($target, $nodeTruncated);
            $aggregateTruncated = $aggregateTruncated || $nodeTruncated;
        }

        return [
            'causalExecutionProven' => ($metadata['causalExecutionProven'] ?? null) !== false,
            'kinds' => $kinds,
            'truncated' => $aggregateTruncated,
            'examinedOccurrences' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $edge
     * @return array<string, mixed>|false
     */
    private function forwardTransition(array $state, array $edge, string $neighborId): array|false
    {
        if (($edge['type'] ?? null) === 'dispatches') {
            $analysis = $this->dispatchAnalysis($edge, $this->index->node($neighborId));

            if (! $analysis['causalExecutionProven']) {
                return false;
            }

            $kinds = $analysis['kinds'];

            // A dispatch bridge must resolve an actual event/job role. This
            // also rejects malformed occurrence evidence and raw dispatches
            // aimed at an ordinary method node.
            if ($kinds === []) {
                return false;
            }

            return $this->bridgeState(
                $kinds,
                $analysis['truncated'] || $this->stateMetadataTruncated($state),
            );
        }

        if (($edge['type'] ?? null) !== 'handled_by') {
            return $this->truncationState($state);
        }

        if (! $this->handlerCausalExecutionProven($edge)) {
            return false;
        }

        $activeKinds = $this->stateKinds($state);
        $requiredDispatchKind = $this->handlerDispatchKind($edge);

        if ($requiredDispatchKind !== null
            && $activeKinds !== []
            && ! in_array($requiredDispatchKind, $activeKinds, true)) {
            return false;
        }

        return $this->bridgeState([], $this->stateMetadataTruncated($state));
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $edge
     * @return array<string, mixed>|false
     */
    private function reverseTransition(array $state, array $edge, string $neighborId): array|false
    {
        if (($edge['type'] ?? null) === 'handled_by') {
            if (! $this->handlerCausalExecutionProven($edge)) {
                return false;
            }

            $requiredKind = $this->handlerDispatchKind($edge);
            $nodeTruncated = false;
            $bridgeKinds = $requiredKind === null
                ? $this->nodeKinds($this->index->node($neighborId), $nodeTruncated)
                : [$requiredKind];

            // Reverse traversal must not turn an untyped structural relation
            // into execution unless the bridge itself proves an event/job role.
            if ($bridgeKinds === []) {
                return false;
            }

            return $this->bridgeState(
                $bridgeKinds,
                $nodeTruncated || $this->stateMetadataTruncated($state),
            );
        }

        if (($edge['type'] ?? null) !== 'dispatches') {
            return $this->truncationState($state);
        }

        $analysis = $this->dispatchAnalysis(
            $edge,
            $this->index->node((string) ($edge['to'] ?? '')),
        );

        if (! $analysis['causalExecutionProven']) {
            return false;
        }

        // On an incoming dispatch edge, the current node is the dispatch
        // target; neighborId is the method that dispatched it.
        $dispatchKinds = $analysis['kinds'];
        $requiredKinds = $this->stateKinds($state);

        if ($dispatchKinds === []) {
            return false;
        }

        if ($requiredKinds !== [] && array_intersect($requiredKinds, $dispatchKinds) === []) {
            return false;
        }

        return $this->bridgeState(
            [],
            $analysis['truncated'] || $this->stateMetadataTruncated($state),
        );
    }

    /** @param array<string, mixed> $edge */
    private function handlerCausalExecutionProven(array $edge): bool
    {
        return ($edge['metadata']['causalExecutionProven'] ?? null) !== false;
    }

    /** @param array<string, mixed> $edge */
    private function handlerDispatchKind(array $edge): ?string
    {
        return match ($edge['metadata']['kind'] ?? null) {
            'listener' => 'event',
            'job' => 'job',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $state
     * @return array<int, string>
     */
    private function stateKinds(array $state): array
    {
        $kinds = [];
        $examined = 0;
        $stateKinds = is_array($state['dispatchKinds'] ?? null)
            ? $state['dispatchKinds']
            : [];

        foreach ($stateKinds as $kind) {
            if ($examined >= self::MAX_AGGREGATE_KINDS) {
                break;
            }

            $examined++;

            if (is_string($kind) && in_array($kind, ['event', 'job'], true)) {
                $kinds[$kind] = $kind;
            }
        }

        ksort($kinds);

        return array_values($kinds);
    }

    /**
     * @param array<string, mixed>|null $node
     * @return array<int, string>
     */
    private function nodeKinds(?array $node, bool &$truncated): array
    {
        $kinds = [];
        $roles = is_array($node['metadata']['roles'] ?? null)
            ? $node['metadata']['roles']
            : [];
        $truncated = count($roles) > self::MAX_AGGREGATE_KINDS;
        $examined = 0;

        foreach ($roles as $role) {
            if ($examined >= self::MAX_AGGREGATE_KINDS) {
                break;
            }

            $examined++;

            if ($this->isDispatchKind($role)) {
                $kinds[$role] = $role;
            }
        }

        $type = $node['type'] ?? null;

        if (is_string($type) && in_array($type, ['event', 'job'], true)) {
            $kinds[$type] = $type;
        }

        ksort($kinds);

        return array_values($kinds);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<int, string>
     */
    private function safeCausalAggregateKinds(
        array $metadata,
        bool $ordinaryAggregateConsistent,
        bool &$truncated,
    ): array {
        $kinds = $this->boundedKinds($metadata['causalDispatchKinds'] ?? [], $truncated);

        if ($ordinaryAggregateConsistent
            && ($metadata['causalExecutionProven'] ?? null) === true) {
            $ordinaryTruncated = false;

            foreach ($this->metadataKinds(
                $metadata,
                'dispatchKinds',
                'kind',
                $ordinaryTruncated,
            ) as $kind) {
                $kinds[$kind] = $kind;
            }

            $truncated = $truncated || $ordinaryTruncated;
        }

        ksort($kinds);

        return array_values($kinds);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<int, string>
     */
    private function metadataKinds(
        array $metadata,
        string $plural,
        string $singular,
        bool &$truncated,
    ): array {
        $kinds = $this->boundedKinds($metadata[$plural] ?? [], $truncated);
        $kind = $metadata[$singular] ?? null;

        if ($this->isDispatchKind($kind)) {
            $kinds[$kind] = $kind;
        }

        ksort($kinds);

        return array_values($kinds);
    }

    /**
     * @return array<int|string, string>
     */
    private function boundedKinds(mixed $values, bool &$truncated): array
    {
        if (! is_array($values)) {
            return [];
        }

        $truncated = count($values) > self::MAX_AGGREGATE_KINDS;
        $kinds = [];
        $examined = 0;

        foreach ($values as $kind) {
            if ($examined >= self::MAX_AGGREGATE_KINDS) {
                break;
            }

            $examined++;

            if ($this->isDispatchKind($kind)) {
                $kinds[$kind] = $kind;
            }
        }

        return $kinds;
    }

    private function isDispatchKind(mixed $kind): bool
    {
        return is_string($kind) && in_array($kind, ['event', 'job'], true);
    }

    /** @param array<string, mixed> $state */
    private function stateMetadataTruncated(array $state): bool
    {
        return ($state['metadataTruncated'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $state
     * @return array{metadataTruncated: true}|array{}
     */
    private function truncationState(array $state): array
    {
        return $this->stateMetadataTruncated($state)
            ? ['metadataTruncated' => true]
            : [];
    }

    /**
     * @param array<int, string> $kinds
     * @return array{dispatchKinds: array<int, string>, statePartition: string, metadataTruncated?: true}
     */
    private function bridgeState(array $kinds, bool $metadataTruncated = false): array
    {
        $state = [
            'dispatchKinds' => $kinds,
            'statePartition' => $kinds === [] ? '' : 'dispatch:'.implode(',', $kinds),
        ];

        if ($metadataTruncated) {
            $state['metadataTruncated'] = true;
        }

        return $state;
    }
}
