<?php

namespace AppGraph\Query;

use JsonException;
use RuntimeException;

class GraphIndex
{
    /**
     * @var array<string, array{signature: string, index: self}>
     */
    private static array $cache = [];

    public static function forget(string $path): void
    {
        unset(self::$cache[$path]);
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, array<string, mixed>> $nodesById
     * @param array<string, array<int, string>> $nodeIdsByType
     * @param array<string, array<int, array<string, mixed>>> $outEdges
     * @param array<string, array<int, array<string, mixed>>> $inEdges
     * @param array<string, array<string, array<int, array<string, mixed>>>> $rankedOutEdges
     * @param array<string, array<string, array<int, array<string, mixed>>>> $rankedInEdges
     */
    private function __construct(
        private array $meta,
        private array $nodesById,
        private array $nodeIdsByType,
        private array $outEdges,
        private array $inEdges,
        private array $rankedOutEdges,
        private array $rankedInEdges,
        private int $edgeCount,
        private ?string $sourcePath,
    ) {
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new RuntimeException("AppGraph graph not found at [{$path}]. Run `php artisan appgraph:scan` first.");
        }

        clearstatcache(true, $path);
        $signature = implode(':', [
            (int) filemtime($path),
            (int) filesize($path),
            (int) fileinode($path),
        ]);

        if (isset(self::$cache[$path]) && self::$cache[$path]['signature'] === $signature) {
            return self::$cache[$path]['index'];
        }

        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Unable to read AppGraph graph at [{$path}].");
        }

        try {
            $graph = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("AppGraph graph at [{$path}] is not valid JSON: {$exception->getMessage()}. Re-run `php artisan appgraph:scan`.");
        }

        if (! is_array($graph) || ! isset($graph['nodes'], $graph['edges'])) {
            throw new RuntimeException("AppGraph graph at [{$path}] is missing nodes/edges. Re-run `php artisan appgraph:scan`.");
        }

        $index = self::fromArray($graph, $path);

        self::$cache = [$path => ['signature' => $signature, 'index' => $index]];

        return $index;
    }

    /**
     * @param array<string, mixed> $graph
     */
    public static function fromArray(array $graph, ?string $sourcePath = null): self
    {
        $nodesById = [];
        $nodeIdsByType = [];

        foreach ($graph['nodes'] ?? [] as $node) {
            if (! is_array($node) || ! isset($node['id'], $node['type'])) {
                continue;
            }

            $nodesById[$node['id']] = $node;
            $nodeIdsByType[$node['type']][] = $node['id'];
        }

        $outEdges = [];
        $inEdges = [];
        $rankedOutEdges = [];
        $rankedInEdges = [];
        $edgeCount = 0;

        foreach ($graph['edges'] ?? [] as $edge) {
            if (! is_array($edge) || ! isset($edge['from'], $edge['to'], $edge['type'])) {
                continue;
            }

            $outEdges[$edge['from']][] = $edge;
            $inEdges[$edge['to']][] = $edge;
            $rankedOutEdges[$edge['from']][$edge['type']][] = $edge;
            $rankedInEdges[$edge['to']][$edge['type']][] = $edge;
            $edgeCount++;
        }

        foreach ($rankedOutEdges as &$edgesByType) {
            foreach ($edgesByType as &$edges) {
                usort($edges, static fn (array $a, array $b): int => self::compareRankedEdges($a, $b, 'out'));
            }
            unset($edges);
        }
        unset($edgesByType);

        foreach ($rankedInEdges as &$edgesByType) {
            foreach ($edgesByType as &$edges) {
                usort($edges, static fn (array $a, array $b): int => self::compareRankedEdges($a, $b, 'in'));
            }
            unset($edges);
        }
        unset($edgesByType);

        return new self(
            meta: $graph['meta'] ?? [],
            nodesById: $nodesById,
            nodeIdsByType: $nodeIdsByType,
            outEdges: $outEdges,
            inEdges: $inEdges,
            rankedOutEdges: $rankedOutEdges,
            rankedInEdges: $rankedInEdges,
            edgeCount: $edgeCount,
            sourcePath: $sourcePath,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    public function sourcePath(): ?string
    {
        return $this->sourcePath;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function node(string $id): ?array
    {
        return $this->nodesById[$id] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function nodesOfType(string $type): array
    {
        return array_map(
            fn (string $id): array => $this->nodesById[$id],
            $this->nodeIdsByType[$type] ?? []
        );
    }

    public function nodeCount(): int
    {
        return count($this->nodesById);
    }

    public function edgeCount(): int
    {
        return $this->edgeCount;
    }

    /**
     * @return array<string, int>
     */
    public function countsByNodeType(): array
    {
        $counts = array_map('count', $this->nodeIdsByType);
        ksort($counts);

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function countsByEdgeType(): array
    {
        $counts = [];

        foreach ($this->outEdges as $edges) {
            foreach ($edges as $edge) {
                $counts[$edge['type']] = ($counts[$edge['type']] ?? 0) + 1;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param array<int, string>|null $types
     * @return array<int, array<string, mixed>>
     */
    public function edgesFrom(string $id, ?array $types = null): array
    {
        return $this->filterEdges($this->outEdges[$id] ?? [], $types);
    }

    /**
     * @param array<int, string>|null $types
     * @return array<int, array<string, mixed>>
     */
    public function edgesTo(string $id, ?array $types = null): array
    {
        return $this->filterEdges($this->inEdges[$id] ?? [], $types);
    }

    /**
     * Return the strongest outgoing edges without copying or sorting the full
     * adjacency list. The per-type lists are pre-ranked, so selecting N edges
     * retains only O(types + N) query-local values.
     *
     * @param array<int, string> $types
     * @return array<int, array<string, mixed>>
     */
    public function rankedEdgesFrom(string $id, array $types, int $limit, bool &$truncated = false): array
    {
        return $this->rankedEdges($this->rankedOutEdges[$id] ?? [], $types, $limit, 'out', $truncated);
    }

    /**
     * Return the strongest incoming edges without copying or sorting the full
     * adjacency list.
     *
     * @param array<int, string> $types
     * @return array<int, array<string, mixed>>
     */
    public function rankedEdgesTo(string $id, array $types, int $limit, bool &$truncated = false): array
    {
        return $this->rankedEdges($this->rankedInEdges[$id] ?? [], $types, $limit, 'in', $truncated);
    }

    /**
     * Return the globally strongest outgoing facts from several weighted source
     * paths. Each source/type adjacency contributes one lazy cursor, so a tight
     * budget does not materialize or repeatedly scan the union of their edges.
     *
     * @param array<int, array{id: string, confidence: float, depth: int, path: array<int, string>}> $sources
     * @param array<int, string> $types
     * @return array<int, array{source: string, edge: array<string, mixed>}>
     */
    public function rankedEdgesFromSources(
        array $sources,
        array $types,
        int $limit,
        bool &$truncated = false,
    ): array {
        $types = $this->normalizedEdgeTypes($types);
        $limit = max(0, $limit);
        $sourcesById = [];

        foreach ($sources as $source) {
            if (! isset($source['id']) || ! is_string($source['id'])) {
                continue;
            }

            $path = $source['path'] ?? [$source['id']];

            if (! is_array($path)) {
                $path = [$source['id']];
            }

            $normalized = [
                'id' => $source['id'],
                'confidence' => round((float) ($source['confidence'] ?? 1.0), 4),
                'depth' => max(0, (int) ($source['depth'] ?? 0)),
                'path' => array_values(array_filter(
                    $path,
                    static fn (mixed $id): bool => is_string($id),
                )),
            ];

            if (! isset($sourcesById[$normalized['id']])
                || $this->compareRelatedSources($normalized, $sourcesById[$normalized['id']]) < 0) {
                $sourcesById[$normalized['id']] = $normalized;
            }
        }

        ksort($sourcesById);
        $matchingCount = 0;

        foreach ($sourcesById as $source) {
            foreach ($types as $type) {
                $matchingCount += count($this->rankedOutEdges[$source['id']][$type] ?? []);
            }
        }

        if ($matchingCount > $limit) {
            $truncated = true;
        }

        if ($limit === 0 || $matchingCount === 0) {
            return [];
        }

        $frontier = $this->newRankedMinQueue();

        foreach ($sourcesById as $source) {
            foreach ($types as $type) {
                $this->enqueueRelatedEdgeCursor($frontier, $source, $type, 0);
            }
        }

        $selected = [];

        while (count($selected) < $limit && ! $frontier->isEmpty()) {
            $cursor = $frontier->extract();
            $selected[] = ['source' => $cursor['source']['id'], 'edge' => $cursor['edge']];
            $this->enqueueRelatedEdgeCursor(
                $frontier,
                $cursor['source'],
                $cursor['type'],
                $cursor['position'] + 1,
            );
        }

        return $selected;
    }

    /**
     * Rank bounded paths by confidence rather than keeping the first shallow
     * visit. A lazy best-first frontier lets newly discovered strong descendants
     * compete immediately, while retaining the strongest path to each state at
     * each depth. Results use deterministic depth/path tie-breakers. Evidence
     * diversity is reported but is not a safe pruning criterion because later
     * edges can change the evidence set.
     *
     * @param array<int, string> $edgeTypes
     * @param int|null $maxTransitions Hard bound on matching edges examined; null is unbounded.
     * @return array<int, array{id: string, depth: int, confidence: float, via: string, edgeType: string, path: array<int, string>, evidenceCount: int}>
     */
    public function traverse(
        string $startId,
        array $edgeTypes,
        string $direction = 'out',
        int $maxDepth = 4,
        float $minConfidence = 0.0,
        int $limit = 50,
        bool &$truncated = false,
        ?int $maxTransitions = null,
    ): array {
        return $this->traverseSeedStates(
            [[
                'id' => $startId,
                'depth' => 0,
                'confidence' => 1.0,
                'via' => $startId,
                'edgeType' => '',
                'path' => [$startId],
                'evidenceCount' => 0,
            ]],
            $edgeTypes,
            $direction,
            $maxDepth,
            $minConfidence,
            $limit,
            null,
            [$startId => true],
            null,
            $maxTransitions,
            $truncated,
        );
    }

    /**
     * Traverse one causal graph from several entry points. Seed confidence is
     * retained and multiplied through every traversed edge, while all seeds
     * compete in the same deterministic strongest-path ranking.
     *
     * This is useful for framework entry points such as an HTTP action and its
     * middleware handlers: they share one bounded traversal without inventing a
     * synthetic node or allowing intermediate event/request nodes to consume a
     * method-result limit.
     *
     * @param array<int, array{id: string, confidence?: float, via?: string, edgeType?: string, path?: array<int, string>, statePartition?: scalar}> $seeds
     * @param array<int, string> $edgeTypes
     * @param array<int, string>|null $resultNodeTypes
     * @param callable(array<string, mixed>, array<string, mixed>, string, string):(array<string, mixed>|false)|null $transition
     * @param int|null $maxTransitions Hard bound on matching edges examined; null is unbounded.
     * @return array<int, array{id: string, depth: int, confidence: float, via: string, edgeType: string, path: array<int, string>, evidenceCount: int}>
     */
    public function traverseFromSeeds(
        array $seeds,
        array $edgeTypes,
        string $direction = 'out',
        int $maxDepth = 4,
        float $minConfidence = 0.0,
        int $limit = 50,
        ?array $resultNodeTypes = null,
        ?callable $transition = null,
        bool &$truncated = false,
        ?int $maxTransitions = null,
    ): array {
        $seedStates = [];

        foreach ($seeds as $seed) {
            if (! isset($seed['id']) || ! is_string($seed['id'])) {
                continue;
            }

            $state = [
                'id' => $seed['id'],
                'depth' => 0,
                'confidence' => round((float) ($seed['confidence'] ?? 1.0), 4),
                'via' => (string) ($seed['via'] ?? $seed['id']),
                'edgeType' => (string) ($seed['edgeType'] ?? ''),
                'path' => $seed['path'] ?? [$seed['id']],
                'evidenceKinds' => [],
                'evidenceCount' => 0,
            ];

            if (array_key_exists('statePartition', $seed) && is_scalar($seed['statePartition'])) {
                $state['statePartition'] = $seed['statePartition'];
            }

            if ($state['confidence'] < $minConfidence) {
                continue;
            }

            $stateKey = $this->traversalStateKey($state);

            if (! isset($seedStates[$stateKey]) || $this->pathRanksBefore($state, $seedStates[$stateKey])) {
                $seedStates[$stateKey] = $state;
            }
        }

        return $this->traverseSeedStates(
            array_values($seedStates),
            $edgeTypes,
            $direction,
            $maxDepth,
            $minConfidence,
            $limit,
            $resultNodeTypes,
            [],
            $transition,
            $maxTransitions,
            $truncated,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $seedStates
     * @param array<int, string> $edgeTypes
     * @param array<int, string>|null $resultNodeTypes
     * @param array<string, bool> $excludedResultIds
     * @param callable(array<string, mixed>, array<string, mixed>, string, string):(array<string, mixed>|false)|null $transition
     * @param int|null $maxTransitions Hard bound on matching edges examined; null is unbounded.
     * @return array<int, array{id: string, depth: int, confidence: float, via: string, edgeType: string, path: array<int, string>, evidenceCount: int}>
     */
    private function traverseSeedStates(
        array $seedStates,
        array $edgeTypes,
        string $direction,
        int $maxDepth,
        float $minConfidence,
        int $limit,
        ?array $resultNodeTypes,
        array $excludedResultIds,
        ?callable $transition,
        ?int $maxTransitions,
        bool &$truncated,
    ): array {
        $resultsById = [];
        $states = [[]];
        $edgeTypes = $this->normalizedEdgeTypes($edgeTypes);
        $depthLimit = max(0, $maxDepth);
        $transitionLimit = $maxTransitions === null ? null : max(0, $maxTransitions);
        $transitionsExamined = 0;
        $transitionBudgetExhausted = false;
        $stateVersion = 0;

        foreach ($seedStates as $state) {
            $stateKey = $this->traversalStateKey($state);
            $state['stateKeyPath'] = $this->initialStateKeyPath($state, $stateKey);

            if (! isset($states[0][$stateKey]) || $this->pathRanksBefore($state, $states[0][$stateKey])) {
                $state['_traversalVersion'] = ++$stateVersion;
                $states[0][$stateKey] = $state;
            }

            if (! isset($excludedResultIds[$state['id']])
                && $this->nodeMatchesTypes($state['id'], $resultNodeTypes)
                && (! isset($resultsById[$state['id']]) || $this->pathRanksBefore($state, $resultsById[$state['id']]))) {
                $resultsById[$state['id']] = $state;
            }
        }

        $frontier = $this->newRankedMinQueue();

        if ($depthLimit > 0) {
            foreach ($states[0] as $state) {
                $this->enqueueTraversalState($frontier, $state, $edgeTypes, $direction);
            }
        }

        while (($rankedTransition = $this->nextRankedTraversalTransition($frontier, $states)) !== null) {
            if ($transitionLimit !== null && $transitionsExamined >= $transitionLimit) {
                $truncated = true;
                $transitionBudgetExhausted = true;

                break;
            }

            $transitionsExamined++;
            $state = $rankedTransition['state'];
            $edge = $rankedTransition['edge'];
            $this->enqueueTraversalCursor(
                $frontier,
                $state,
                $rankedTransition['type'],
                $rankedTransition['position'] + 1,
                $direction,
            );
            $neighborId = $direction === 'out' ? $edge['to'] : $edge['from'];
            $candidate = $this->traversalCandidate(
                $state,
                $edge,
                $neighborId,
                $direction,
                $minConfidence,
                $transition,
            );

            if ($candidate === false) {
                continue;
            }

            $candidateDepth = (int) $candidate['depth'];
            $stateKey = $this->traversalStateKey($candidate);

            if (! isset($states[$candidateDepth][$stateKey])
                || $this->pathRanksBefore($candidate, $states[$candidateDepth][$stateKey])) {
                $storedCandidate = $candidate;
                $storedCandidate['_traversalVersion'] = ++$stateVersion;
                $states[$candidateDepth][$stateKey] = $storedCandidate;

                if ($candidateDepth < $depthLimit) {
                    $this->enqueueTraversalState($frontier, $storedCandidate, $edgeTypes, $direction);
                }
            }

            if (! isset($excludedResultIds[$neighborId])
                && $this->nodeMatchesTypes($neighborId, $resultNodeTypes)
                && (! isset($resultsById[$neighborId]) || $this->pathRanksBefore($candidate, $resultsById[$neighborId]))) {
                $resultsById[$neighborId] = $candidate;
            }
        }

        if (! $transitionBudgetExhausted) {
            $terminalFrontier = $this->newRankedMinQueue();

            foreach ($states[$depthLimit] ?? [] as $state) {
                $this->enqueueTraversalState($terminalFrontier, $state, $edgeTypes, $direction);
            }

            while (($rankedTransition = $this->nextRankedTraversalTransition(
                $terminalFrontier,
                $states,
            )) !== null) {
                if ($transitionLimit !== null && $transitionsExamined >= $transitionLimit) {
                    $truncated = true;

                    break;
                }

                $transitionsExamined++;
                $state = $rankedTransition['state'];
                $edge = $rankedTransition['edge'];
                $this->enqueueTraversalCursor(
                    $terminalFrontier,
                    $state,
                    $rankedTransition['type'],
                    $rankedTransition['position'] + 1,
                    $direction,
                );
                $neighborId = $direction === 'out' ? $edge['to'] : $edge['from'];

                if ($this->traversalCandidate(
                    $state,
                    $edge,
                    $neighborId,
                    $direction,
                    $minConfidence,
                    $transition,
                ) !== false) {
                    $truncated = true;

                    break;
                }
            }
        }

        $results = array_values($resultsById);
        usort($results, fn (array $a, array $b): int => $this->compareTraversalPaths($a, $b));

        foreach ($results as &$result) {
            unset($result['evidenceKinds'], $result['stateKeyPath'], $result['_traversalVersion']);
        }
        unset($result);

        if (count($results) > $limit) {
            $truncated = true;
            $results = array_slice($results, 0, $limit);
        }

        return $results;
    }

    /** @param array<string, mixed> $state */
    private function traversalStateKey(array $state): string
    {
        $partition = $state['statePartition'] ?? '';

        return (string) $state['id']."\0".(is_scalar($partition) ? (string) $partition : '');
    }

    /**
     * Create a min-priority queue for deterministic rank tuples. Lazy graph
     * merges keep one cursor per active pre-ranked adjacency stream.
     */
    private function newRankedMinQueue(): \SplPriorityQueue
    {
        $queue = new class extends \SplPriorityQueue
        {
            public function compare(mixed $priority1, mixed $priority2): int
            {
                return $priority2 <=> $priority1;
            }
        };
        $queue->setExtractFlags(\SplPriorityQueue::EXTR_DATA);

        return $queue;
    }

    /**
     * @param array{id: string, confidence: float, depth: int, path: array<int, string>} $source
     */
    private function enqueueRelatedEdgeCursor(
        \SplPriorityQueue $queue,
        array $source,
        string $type,
        int $position,
    ): void {
        $edge = $this->rankedOutEdges[$source['id']][$type][$position] ?? null;

        if ($edge === null) {
            return;
        }

        $queue->insert(
            [
                'source' => $source,
                'type' => $type,
                'position' => $position,
                'edge' => $edge,
            ],
            $this->relatedEdgePriority($source, $edge, $position),
        );
    }

    /**
     * @param array{id: string, confidence: float, depth: int, path: array<int, string>} $source
     * @param array<string, mixed> $edge
     * @return array{float, int, string, string, string, string, string, string, int}
     */
    private function relatedEdgePriority(array $source, array $edge, int $position): array
    {
        return [
            -round($source['confidence'] * (float) ($edge['confidence'] ?? 1.0), 4),
            $source['depth'],
            implode("\0", $source['path']),
            $source['id'],
            (string) $edge['to'],
            (string) $edge['type'],
            (string) $edge['from'],
            (string) $edge['to'],
            $position,
        ];
    }

    /**
     * @param array{id: string, confidence: float, depth: int, path: array<int, string>} $a
     * @param array{id: string, confidence: float, depth: int, path: array<int, string>} $b
     */
    private function compareRelatedSources(array $a, array $b): int
    {
        return [
            -$a['confidence'],
            $a['depth'],
            implode("\0", $a['path']),
            $a['id'],
        ] <=> [
            -$b['confidence'],
            $b['depth'],
            implode("\0", $b['path']),
            $b['id'],
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<int, string> $edgeTypes
     */
    private function enqueueTraversalState(
        \SplPriorityQueue $queue,
        array $state,
        array $edgeTypes,
        string $direction,
    ): void {
        foreach ($edgeTypes as $type) {
            $this->enqueueTraversalCursor($queue, $state, $type, 0, $direction);
        }
    }

    /** @param array<string, mixed> $state */
    private function enqueueTraversalCursor(
        \SplPriorityQueue $queue,
        array $state,
        string $type,
        int $position,
        string $direction,
    ): void {
        $edgesByType = $direction === 'out'
            ? $this->rankedOutEdges[$state['id']] ?? []
            : $this->rankedInEdges[$state['id']] ?? [];
        $edge = $edgesByType[$type][$position] ?? null;

        if ($edge === null) {
            return;
        }

        $queue->insert(
            [
                'depth' => (int) $state['depth'],
                'stateKey' => $this->traversalStateKey($state),
                'stateVersion' => (int) $state['_traversalVersion'],
                'type' => $type,
                'position' => $position,
                'edge' => $edge,
            ],
            $this->rankedTraversalTransitionPriority($state, $edge, $direction, $position),
        );
    }

    /**
     * Discard cursors belonging to a state superseded by a stronger path and
     * return the next live transition without copying an adjacency list.
     *
     * @param array<int, array<string, array<string, mixed>>> $states
     * @return array{state: array<string, mixed>, edge: array<string, mixed>, type: string, position: int}|null
     */
    private function nextRankedTraversalTransition(
        \SplPriorityQueue $queue,
        array $states,
    ): ?array {
        while (! $queue->isEmpty()) {
            $cursor = $queue->extract();
            $state = $states[$cursor['depth']][$cursor['stateKey']] ?? null;

            if (! is_array($state)
                || (int) ($state['_traversalVersion'] ?? -1) !== $cursor['stateVersion']) {
                continue;
            }

            return [
                'state' => $state,
                'edge' => $cursor['edge'],
                'type' => $cursor['type'],
                'position' => $cursor['position'],
            ];
        }

        return null;
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $edge */
    private function traversalCandidate(
        array $state,
        array $edge,
        string $neighborId,
        string $direction,
        float $minConfidence,
        ?callable $transition,
    ): array|false {
        $transitionState = [];

        if ($transition !== null) {
            $transitionResult = $transition($state, $edge, $neighborId, $direction);

            if ($transitionResult === false) {
                return false;
            }

            if (is_array($transitionResult)) {
                $transitionState = $transitionResult;
            }
        }

        $pathConfidence = round($state['confidence'] * (float) ($edge['confidence'] ?? 1.0), 4);

        if ($pathConfidence < $minConfidence) {
            return false;
        }

        $candidate = [
            'id' => $neighborId,
            'depth' => (int) $state['depth'] + 1,
            'confidence' => $pathConfidence,
            'via' => $state['id'],
            'edgeType' => $edge['type'],
            'path' => [...$state['path'], $neighborId],
            'evidenceKinds' => $this->mergeEvidenceKinds(
                $state['evidenceKinds'] ?? [],
                $this->edgeEvidenceKinds($edge),
            ),
        ] + $transitionState;
        $candidate['evidenceCount'] = count($candidate['evidenceKinds']);
        $stateKey = $this->traversalStateKey($candidate);
        $stateKeyPath = $state['stateKeyPath'] ?? $this->initialStateKeyPath(
            $state,
            $this->traversalStateKey($state),
        );

        if (in_array($stateKey, $stateKeyPath, true)) {
            return false;
        }

        $candidate['stateKeyPath'] = [...$stateKeyPath, $stateKey];

        return $candidate;
    }

    /** @param array<string, mixed> $state @return array<int, string> */
    private function initialStateKeyPath(array $state, string $currentStateKey): array
    {
        $nodePath = array_values(array_filter(
            $state['path'] ?? [],
            static fn (mixed $id): bool => is_string($id),
        ));
        $stateKeyPath = array_map(
            fn (string $id): string => $this->traversalStateKey(['id' => $id]),
            $nodePath,
        );

        if ($nodePath !== [] && end($nodePath) === $state['id']) {
            $stateKeyPath[array_key_last($stateKeyPath)] = $currentStateKey;
        } else {
            $stateKeyPath[] = $currentStateKey;
        }

        return $stateKeyPath;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $edge
     * @return array{float, int, string, string, string, string, int}
     */
    private function rankedTraversalTransitionPriority(
        array $state,
        array $edge,
        string $direction,
        int $position,
    ): array
    {
        $neighbor = (string) ($direction === 'out' ? $edge['to'] : $edge['from']);

        return [
            -round((float) $state['confidence'] * (float) ($edge['confidence'] ?? 1.0), 4),
            (int) $state['depth'] + 1,
            implode("\0", [...($state['path'] ?? []), $neighbor]),
            $neighbor,
            $this->traversalStateKey($state),
            (string) $edge['type'],
            $position,
        ];
    }

    /** @param array<int, string>|null $types */
    private function nodeMatchesTypes(string $id, ?array $types): bool
    {
        return $types === null || in_array($this->nodesById[$id]['type'] ?? null, $types, true);
    }

    /**
     * Return several complete, distinct, simple paths ordered by deterministic
     * confidence/evidence/depth ranking. Generated states are hard-bounded and
     * retain parent pointers so dense graphs cannot grow an unbounded frontier.
     *
     * @param array<int, string> $edgeTypes
     * @return array<int, array{nodes: array<int, string>, edges: array<int, array<string, mixed>>, depth: int, confidence: float, evidenceCount: int}>
     */
    public function topPaths(
        string $startId,
        string $destinationId,
        array $edgeTypes,
        string $direction = 'out',
        int $maxDepth = 4,
        float $minConfidence = 0.0,
        int $limit = 3,
        int $maxStates = 10000,
        bool &$truncated = false,
    ): array {
        if ($startId === $destinationId || $limit < 1 || $maxDepth < 1) {
            return [];
        }

        $frontier = new class extends \SplPriorityQueue
        {
            public function compare(mixed $priority1, mixed $priority2): int
            {
                foreach ([0, 1, 2, 3] as $position) {
                    $comparison = $priority1[$position] <=> $priority2[$position];

                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                return 0;
            }
        };
        $frontier->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        $states = [[
            'id' => $startId,
            'depth' => 0,
            'confidence' => 1.0,
            'parent' => null,
            'edge' => null,
            'evidenceKinds' => [],
            'evidenceCount' => 0,
        ]];
        $frontier->insert(0, [1.0, 0, 0, 0]);
        $candidateStateIds = [];
        $candidateKeys = [];
        $generatedStates = 1;
        $sequence = 0;
        $budgetExhausted = $maxStates < 1;

        if ($budgetExhausted) {
            $truncated = true;
        }

        while (! $frontier->isEmpty()) {
            $stateId = $frontier->extract();
            $state = $states[$stateId];

            if ($state['id'] === $destinationId) {
                $path = $this->reconstructStatePath($states, $stateId);
                $key = implode("\0", $path['nodes']);

                if (! isset($candidateKeys[$key])) {
                    $candidateKeys[$key] = true;
                    $candidateStateIds[] = $stateId;
                }

                continue;
            }

            if ($state['depth'] >= $maxDepth || $budgetExhausted) {
                continue;
            }

            $edges = $direction === 'out'
                ? $this->edgesFrom($state['id'], $edgeTypes)
                : $this->edgesTo($state['id'], $edgeTypes);
            usort($edges, function (array $a, array $b) use ($direction, $state): int {
                $aEvidence = $this->mergeEvidenceKinds($state['evidenceKinds'], $this->edgeEvidenceKinds($a));
                $bEvidence = $this->mergeEvidenceKinds($state['evidenceKinds'], $this->edgeEvidenceKinds($b));

                return [
                    -round($state['confidence'] * (float) ($a['confidence'] ?? 1.0), 4),
                    -count($aEvidence),
                    $direction === 'out' ? $a['to'] : $a['from'],
                    $a['type'],
                ] <=> [
                    -round($state['confidence'] * (float) ($b['confidence'] ?? 1.0), 4),
                    -count($bEvidence),
                    $direction === 'out' ? $b['to'] : $b['from'],
                    $b['type'],
                ];
            });

            foreach ($edges as $edge) {
                $neighborId = $direction === 'out' ? $edge['to'] : $edge['from'];

                if ($this->stateContainsNode($states, $stateId, $neighborId)) {
                    continue;
                }

                $confidence = round($state['confidence'] * (float) ($edge['confidence'] ?? 1.0), 4);

                if ($confidence < $minConfidence) {
                    continue;
                }

                if ($generatedStates >= $maxStates) {
                    $truncated = true;
                    $budgetExhausted = true;

                    break;
                }

                $evidenceKinds = $this->mergeEvidenceKinds(
                    $state['evidenceKinds'],
                    $this->edgeEvidenceKinds($edge),
                );
                $candidateStateId = count($states);
                $states[] = [
                    'id' => $neighborId,
                    'depth' => $state['depth'] + 1,
                    'confidence' => $confidence,
                    'parent' => $stateId,
                    'edge' => $edge,
                    'evidenceKinds' => $evidenceKinds,
                    'evidenceCount' => count($evidenceKinds),
                ];
                $sequence++;
                $frontier->insert($candidateStateId, [
                    $confidence,
                    count($evidenceKinds),
                    -($state['depth'] + 1),
                    -$sequence,
                ]);
                $generatedStates++;
            }
        }

        $candidatePaths = array_map(
            fn (int $stateId): array => $this->reconstructStatePath($states, $stateId),
            $candidateStateIds,
        );
        usort($candidatePaths, fn (array $a, array $b): int => $this->compareRankedPaths($a, $b));

        if (count($candidatePaths) > $limit) {
            $truncated = true;
            $candidatePaths = array_slice($candidatePaths, 0, $limit);
        }

        return $candidatePaths;
    }

    /** @param array<string, mixed> $candidate @param array<string, mixed> $current */
    private function pathRanksBefore(array $candidate, array $current): bool
    {
        return $this->compareTraversalPaths($candidate, $current) < 0;
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private function compareTraversalPaths(array $a, array $b): int
    {
        return [
            -(float) $a['confidence'],
            (int) $a['depth'],
            implode("\0", $a['path'] ?? []),
            (string) $a['id'],
        ] <=> [
            -(float) $b['confidence'],
            (int) $b['depth'],
            implode("\0", $b['path'] ?? []),
            (string) $b['id'],
        ];
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private function compareRankedPaths(array $a, array $b): int
    {
        $aPath = $a['path'] ?? $a['nodes'] ?? [];
        $bPath = $b['path'] ?? $b['nodes'] ?? [];

        return [
            -(float) $a['confidence'],
            -(int) ($a['evidenceCount'] ?? 0),
            (int) $a['depth'],
            implode("\0", $aPath),
            (string) ($a['id'] ?? end($aPath)),
        ] <=> [
            -(float) $b['confidence'],
            -(int) ($b['evidenceCount'] ?? 0),
            (int) $b['depth'],
            implode("\0", $bPath),
            (string) ($b['id'] ?? end($bPath)),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $states
     */
    private function stateContainsNode(array $states, int $stateId, string $nodeId): bool
    {
        $cursor = $stateId;

        while (isset($states[$cursor])) {
            if ($states[$cursor]['id'] === $nodeId) {
                return true;
            }

            $parent = $states[$cursor]['parent'];

            if (! is_int($parent)) {
                break;
            }

            $cursor = $parent;
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $states
     * @return array{nodes: array<int, string>, edges: array<int, array<string, mixed>>, depth: int, confidence: float, evidenceCount: int}
     */
    private function reconstructStatePath(array $states, int $stateId): array
    {
        $nodes = [];
        $edges = [];
        $cursor = $stateId;

        while (isset($states[$cursor])) {
            $state = $states[$cursor];
            $nodes[] = $state['id'];

            if (is_array($state['edge'])) {
                $edges[] = $state['edge'];
            }

            $parent = $state['parent'];

            if (! is_int($parent)) {
                break;
            }

            $cursor = $parent;
        }

        return [
            'nodes' => array_reverse($nodes),
            'edges' => array_reverse($edges),
            'depth' => $states[$stateId]['depth'],
            'confidence' => $states[$stateId]['confidence'],
            'evidenceCount' => $states[$stateId]['evidenceCount'],
        ];
    }

    /** @param array<string, mixed> $edge @return array<int, string> */
    private function edgeEvidenceKinds(array $edge): array
    {
        $kinds = [];

        foreach ($edge['metadata']['evidence'] ?? [] as $evidence) {
            if (! is_array($evidence)) {
                continue;
            }

            $kind = $evidence['rule']
                ?? $evidence['source']
                ?? $evidence['inference']
                ?? $evidence['syntax']
                ?? 'source_location';
            $kinds[(string) $kind] = (string) $kind;
        }

        sort($kinds);

        return array_values($kinds);
    }

    /** @param array<int, string> ...$sets @return array<int, string> */
    private function mergeEvidenceKinds(array ...$sets): array
    {
        $merged = [];

        foreach ($sets as $set) {
            foreach ($set as $kind) {
                $merged[$kind] = $kind;
            }
        }

        sort($merged);

        return array_values($merged);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $term, ?string $type = null, int $limit = 50, bool &$truncated = false): array
    {
        $results = [];

        foreach ($this->nodesById as $node) {
            if ($type !== null && $node['type'] !== $type) {
                continue;
            }

            if (stripos($node['id'], $term) === false && stripos($node['label'] ?? '', $term) === false) {
                continue;
            }

            $results[] = $node;
        }

        usort($results, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        if (count($results) > $limit) {
            $truncated = true;
            $results = array_slice($results, 0, $limit);
        }

        return $results;
    }

    /**
     * Resolve a user-supplied target to a node id. Accepts exact ids, Class@method,
     * bare table names, table.column shorthand, and class/method basenames (which
     * resolve only when unambiguous; otherwise candidates are returned).
     *
     * @return array{id: ?string, candidates: array<int, string>}
     */
    public function resolveId(string $target): array
    {
        $target = trim($target);

        if (isset($this->nodesById[$target])) {
            return ['id' => $target, 'candidates' => []];
        }

        if (str_contains($target, '@')) {
            $normalized = str_replace('@', '::', $target);

            if (isset($this->nodesById[$normalized])) {
                return ['id' => $normalized, 'candidates' => []];
            }

            $target = $normalized;
        }

        if (isset($this->nodesById['table:'.$target])) {
            return ['id' => 'table:'.$target, 'candidates' => []];
        }

        if (str_contains($target, '.') && isset($this->nodesById['column:'.$target])) {
            return ['id' => 'column:'.$target, 'candidates' => []];
        }

        $candidates = [];

        foreach ($this->nodesById as $id => $node) {
            $labelMatches = isset($node['label']) && strcasecmp((string) $node['label'], $target) === 0;
            $nameMatches = isset($node['metadata']['name']) && strcasecmp((string) $node['metadata']['name'], $target) === 0;

            if ($labelMatches || $nameMatches) {
                $candidates[] = $id;
            }
        }

        $candidates = array_values(array_unique($candidates));
        sort($candidates);

        if (count($candidates) === 1) {
            return ['id' => $candidates[0], 'candidates' => []];
        }

        if ($candidates !== []) {
            return ['id' => null, 'candidates' => array_slice($candidates, 0, 10)];
        }

        foreach ($this->nodesById as $id => $node) {
            if (str_ends_with($id, '\\'.$target) || str_ends_with($id, '\\'.ltrim($target, '\\'))) {
                $candidates[] = $id;
            }
        }

        $candidates = array_values(array_unique($candidates));
        sort($candidates);

        if (count($candidates) === 1) {
            return ['id' => $candidates[0], 'candidates' => []];
        }

        return ['id' => null, 'candidates' => array_slice($candidates, 0, 10)];
    }

    /**
     * @param array<int, array<string, mixed>> $edges
     * @param array<int, string>|null $types
     * @return array<int, array<string, mixed>>
     */
    private function filterEdges(array $edges, ?array $types): array
    {
        if ($types === null) {
            return $edges;
        }

        return array_values(array_filter(
            $edges,
            static fn (array $edge): bool => in_array($edge['type'], $types, true)
        ));
    }

    /**
     * Merge pre-ranked per-type edge lists without materializing their union.
     *
     * @param array<string, array<int, array<string, mixed>>> $edgesByType
     * @param array<int, string> $types
     * @return array<int, array<string, mixed>>
     */
    private function rankedEdges(
        array $edgesByType,
        array $types,
        int $limit,
        string $direction,
        bool &$truncated,
    ): array {
        $types = $this->normalizedEdgeTypes($types);
        $limit = max(0, $limit);
        $matchingCount = 0;

        foreach ($types as $type) {
            $matchingCount += count($edgesByType[$type] ?? []);
        }

        if ($matchingCount > $limit) {
            $truncated = true;
        }

        if ($limit === 0 || $matchingCount === 0) {
            return [];
        }

        $positions = [];
        $selected = [];

        while (count($selected) < $limit) {
            $bestType = null;
            $bestEdge = null;

            foreach ($types as $type) {
                $edge = $edgesByType[$type][$positions[$type] ?? 0] ?? null;

                if ($edge === null) {
                    continue;
                }

                if ($bestEdge === null || self::compareRankedEdges($edge, $bestEdge, $direction) < 0) {
                    $bestType = $type;
                    $bestEdge = $edge;
                }
            }

            if ($bestType === null || $bestEdge === null) {
                break;
            }

            $selected[] = $bestEdge;
            $positions[$bestType] = ($positions[$bestType] ?? 0) + 1;
        }

        return $selected;
    }

    /** @param array<int, string> $types @return array<int, string> */
    private function normalizedEdgeTypes(array $types): array
    {
        $normalized = [];

        foreach ($types as $type) {
            if (is_string($type)) {
                $normalized[$type] = $type;
            }
        }

        ksort($normalized);

        return array_values($normalized);
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private static function compareRankedEdges(array $a, array $b, string $direction): int
    {
        $confidence = (float) ($b['confidence'] ?? 1.0) <=> (float) ($a['confidence'] ?? 1.0);

        if ($confidence !== 0) {
            return $confidence;
        }

        foreach ([
            $direction === 'out' ? 'to' : 'from',
            'type',
            'from',
            'to',
        ] as $field) {
            $comparison = (string) ($a[$field] ?? '') <=> (string) ($b[$field] ?? '');

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        // Duplicate endpoint/type edges are malformed for a canonical graph,
        // but keep their order deterministic without walking metadata for the
        // normal (distinct endpoint) case.
        return self::stableEdgeKey($a) <=> self::stableEdgeKey($b);
    }

    /** @param array<string, mixed> $edge */
    private static function stableEdgeKey(array $edge): string
    {
        $encoded = json_encode(
            $edge,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );

        return is_string($encoded) ? $encoded : '';
    }
}
