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
     */
    private function __construct(
        private array $meta,
        private array $nodesById,
        private array $nodeIdsByType,
        private array $outEdges,
        private array $inEdges,
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
        $edgeCount = 0;

        foreach ($graph['edges'] ?? [] as $edge) {
            if (! is_array($edge) || ! isset($edge['from'], $edge['to'], $edge['type'])) {
                continue;
            }

            $outEdges[$edge['from']][] = $edge;
            $inEdges[$edge['to']][] = $edge;
            $edgeCount++;
        }

        return new self(
            meta: $graph['meta'] ?? [],
            nodesById: $nodesById,
            nodeIdsByType: $nodeIdsByType,
            outEdges: $outEdges,
            inEdges: $inEdges,
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
     * Rank bounded paths by confidence rather than keeping the first shallow
     * visit. Dynamic programming retains the strongest path to each node at each
     * depth, then selects the strongest result across depths with deterministic
     * depth/path tie-breakers. Evidence diversity is reported, but it is not a
     * safe pruning criterion because later edges can change the evidence set.
     *
     * @param array<int, string> $edgeTypes
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
     * @param array<int, array{id: string, confidence?: float, via?: string, edgeType?: string, path?: array<int, string>}> $seeds
     * @param array<int, string> $edgeTypes
     * @param array<int, string>|null $resultNodeTypes
     * @param callable(array<string, mixed>, array<string, mixed>, string, string):(array<string, mixed>|false)|null $transition
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

            if ($state['confidence'] < $minConfidence) {
                continue;
            }

            if (! isset($seedStates[$state['id']]) || $this->pathRanksBefore($state, $seedStates[$state['id']])) {
                $seedStates[$state['id']] = $state;
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
            $truncated,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $seedStates
     * @param array<int, string> $edgeTypes
     * @param array<int, string>|null $resultNodeTypes
     * @param array<string, bool> $excludedResultIds
     * @param callable(array<string, mixed>, array<string, mixed>, string, string):(array<string, mixed>|false)|null $transition
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
        bool &$truncated,
    ): array {
        $resultsById = [];
        $states = [[]];

        foreach ($seedStates as $state) {
            $states[0][$this->traversalStateKey($state)] = $state;

            if (! isset($excludedResultIds[$state['id']])
                && $this->nodeMatchesTypes($state['id'], $resultNodeTypes)
                && (! isset($resultsById[$state['id']]) || $this->pathRanksBefore($state, $resultsById[$state['id']]))) {
                $resultsById[$state['id']] = $state;
            }
        }

        for ($depth = 0; $depth < $maxDepth; $depth++) {
            $nextStates = [];
            $currentStates = $states[$depth] ?? [];
            ksort($currentStates);

            foreach ($currentStates as $state) {
                $edges = $direction === 'out'
                    ? $this->edgesFrom($state['id'], $edgeTypes)
                    : $this->edgesTo($state['id'], $edgeTypes);
                usort($edges, static fn (array $a, array $b): int => [
                    $direction === 'out' ? $a['to'] : $a['from'],
                    $a['type'],
                ] <=> [
                    $direction === 'out' ? $b['to'] : $b['from'],
                    $b['type'],
                ]);

                foreach ($edges as $edge) {
                    $neighborId = $direction === 'out' ? $edge['to'] : $edge['from'];

                    $transitionState = [];

                    if ($transition !== null) {
                        $transitionResult = $transition($state, $edge, $neighborId, $direction);

                        if ($transitionResult === false) {
                            continue;
                        }

                        if (is_array($transitionResult)) {
                            $transitionState = $transitionResult;
                        }
                    }

                    $pathConfidence = round($state['confidence'] * (float) ($edge['confidence'] ?? 1.0), 4);

                    if ($pathConfidence < $minConfidence) {
                        continue;
                    }

                    $candidate = [
                        'id' => $neighborId,
                        'depth' => $depth + 1,
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

                    if (! isset($nextStates[$stateKey]) || $this->pathRanksBefore($candidate, $nextStates[$stateKey])) {
                        $nextStates[$stateKey] = $candidate;
                    }

                    if (! isset($excludedResultIds[$neighborId])
                        && $this->nodeMatchesTypes($neighborId, $resultNodeTypes)
                        && (! isset($resultsById[$neighborId]) || $this->pathRanksBefore($candidate, $resultsById[$neighborId]))) {
                        $resultsById[$neighborId] = $candidate;
                    }
                }
            }

            $states[$depth + 1] = $nextStates;
        }

        $results = array_values($resultsById);
        usort($results, fn (array $a, array $b): int => $this->compareTraversalPaths($a, $b));

        foreach ($results as &$result) {
            unset($result['evidenceKinds']);
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
}
