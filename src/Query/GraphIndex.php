<?php

namespace AppGraph\Query;

use AppGraph\Storage\GraphStore;
use AppGraph\Support\AgentPayloadLimiter;
use AppGraph\Support\BoundedText;
use JsonException;
use RuntimeException;

class GraphIndex
{
    private const MAX_INCLUDED_SEED_EDGES = 64;

    private const MAX_EXACT_REFERENCE_CHARACTERS = 4096;

    private const MAX_EXACT_REFERENCE_BYTES = 16384;

    private const MAX_SUFFIXES_PER_ID = 64;

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
     * @param array<string, array<int, string>> $nodeIdsByFile
     * @param array<string, array<int, string>> $nodeIdsByLabel
     * @param array<string, array<int, string>> $nodeIdsByName
     * @param array<string, array<int, string>> $nodeIdsBySuffix
     * @param array<string, array<int, array<string, mixed>>> $outEdges
     * @param array<string, array<int, array<string, mixed>>> $inEdges
     * @param array<string, array<string, array<int, array<string, mixed>>>> $rankedOutEdges
     * @param array<string, array<string, array<int, array<string, mixed>>>> $rankedInEdges
     */
    private function __construct(
        private array $meta,
        private array $nodesById,
        private array $nodeIdsByType,
        private array $nodeIdsByFile,
        private array $nodeIdsByLabel,
        private array $nodeIdsByName,
        private array $nodeIdsBySuffix,
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

        // JSON is a portable mirror, never an authoritative immutable
        // snapshot. A copied or stale mirror can carry a numeric id that now
        // refers to an unrelated SQLite store, so never expose it as a
        // verification baseline.
        if (is_array($graph['meta'] ?? null)) {
            unset($graph['meta']['generation']);
        }

        $index = self::fromArray($graph, $path);

        self::$cache = [$path => ['signature' => $signature, 'index' => $index]];

        return $index;
    }

    public static function loadStore(
        GraphStore $store,
        string $generationReference = 'current',
    ): self
    {
        $graph = $store->graph($generationReference);
        $generation = is_array($graph['meta']['generation'] ?? null)
            ? $graph['meta']['generation']
            : null;

        if ($generation === null || ! is_string($generation['id'] ?? null)) {
            throw new RuntimeException('AppGraph has no committed SQLite generation. Run `php artisan appgraph:scan` first.');
        }

        $path = $store->path();
        $signature = (string) $generation['id']
            .':'.(string) $generation['graphFingerprint']
            .':'.(($generation['current'] ?? false) ? 'current' : 'historical');

        if (isset(self::$cache[$path]) && self::$cache[$path]['signature'] === $signature) {
            return self::$cache[$path]['index'];
        }

        // Staleness is evaluated from persisted scan metadata. The SQLite file,
        // not an optional JSON mirror, is the existence anchor for that check.
        $index = self::fromArrayPayload($graph, $store->path(), true);
        self::$cache = [$path => ['signature' => $signature, 'index' => $index]];

        return $index;
    }

    /**
     * @param array<string, mixed> $graph
     */
    public static function fromArray(array $graph, ?string $sourcePath = null): self
    {
        return self::fromArrayPayload($graph, $sourcePath, false);
    }

    /**
     * Hydrate an index from decoded data. Only the integrity-checked SQLite
     * loader may retain immutable generation metadata; arbitrary arrays have
     * the same untrusted baseline semantics as portable JSON.
     *
     * @param array<string, mixed> $graph
     */
    private static function fromArrayPayload(
        array $graph,
        ?string $sourcePath,
        bool $trustedGeneration,
    ): self
    {
        if (! $trustedGeneration && is_array($graph['meta'] ?? null)) {
            unset($graph['meta']['generation']);
        }

        $nodesById = [];
        $nodeIdsByType = [];
        $nodeIdsByFile = [];
        $nodeIdsByLabel = [];
        $nodeIdsByName = [];
        $nodeIdsBySuffix = [];

        foreach ($graph['nodes'] ?? [] as $node) {
            if (! is_array($node)
                || ! is_string($node['id'] ?? null)
                || $node['id'] === ''
                || ! is_string($node['type'] ?? null)
                || $node['type'] === '') {
                continue;
            }

            $nodesById[$node['id']] = $node;
            $nodeIdsByType[$node['type']][] = $node['id'];

            if (is_string($node['file'] ?? null)
                && $node['file'] !== ''
                && strlen($node['file']) <= 4096) {
                $nodeIdsByFile[str_replace('\\', '/', $node['file'])][] = $node['id'];
            }
        }

        ksort($nodesById);

        foreach ($nodesById as $id => $node) {
            if (strlen($id) <= 2048
                && isset($node['label'])
                && is_scalar($node['label'])
                && strlen((string) $node['label']) <= 2048) {
                $nodeIdsByLabel[strtolower((string) $node['label'])][] = $id;
            }

            if (strlen($id) <= 2048
                && isset($node['metadata']['name'])
                && is_scalar($node['metadata']['name'])
                && strlen((string) $node['metadata']['name']) <= 2048) {
                $nodeIdsByName[strtolower((string) $node['metadata']['name'])][] = $id;
            }

            $offset = 0;
            $suffixes = 0;

            while (strlen($id) <= 2048
                && $suffixes < self::MAX_SUFFIXES_PER_ID
                && ($separator = strpos($id, '\\', $offset)) !== false) {
                $suffix = substr($id, $separator + 1);

                if ($suffix !== '') {
                    $nodeIdsBySuffix[$suffix][] = $id;
                    $suffixes++;
                }

                $offset = $separator + 1;
            }
        }

        foreach ($nodeIdsByFile as &$ids) {
            usort($ids, static fn (string $left, string $right): int => [
                is_int($nodesById[$left]['line'] ?? null) ? $nodesById[$left]['line'] : PHP_INT_MAX,
                $left,
            ] <=> [
                is_int($nodesById[$right]['line'] ?? null) ? $nodesById[$right]['line'] : PHP_INT_MAX,
                $right,
            ]);
        }
        unset($ids);
        ksort($nodeIdsByFile);
        ksort($nodeIdsByLabel);
        ksort($nodeIdsByName);
        ksort($nodeIdsBySuffix);

        $outEdges = [];
        $inEdges = [];
        $rankedOutEdges = [];
        $rankedInEdges = [];
        $edgeCount = 0;

        foreach ($graph['edges'] ?? [] as $edge) {
            if (! is_array($edge)
                || ! is_string($edge['from'] ?? null)
                || ! is_string($edge['to'] ?? null)
                || ! is_string($edge['type'] ?? null)) {
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
            nodeIdsByFile: $nodeIdsByFile,
            nodeIdsByLabel: $nodeIdsByLabel,
            nodeIdsByName: $nodeIdsByName,
            nodeIdsBySuffix: $nodeIdsBySuffix,
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
     * Iterate every indexed node without copying the graph into a second array.
     *
     * Task-oriented discovery intentionally performs one bounded scoring pass
     * over this generator. A future persistent GraphStore can satisfy the same
     * contract without changing query consumers.
     *
     * @return iterable<string, array<string, mixed>>
     */
    public function nodes(): iterable
    {
        foreach ($this->nodesById as $id => $node) {
            yield $id => $node;
        }
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

    /**
     * Return a deterministic bounded set of nodes declared in one graph file.
     *
     * @return array<int, array<string, mixed>>
     */
    public function nodesInFile(
        string $file,
        int $limit,
        bool &$truncated = false,
        ?int &$total = null,
    ): array {
        $file = str_replace('\\', '/', $file);
        $ids = $this->nodeIdsByFile[$file] ?? [];
        $total = count($ids);
        $limit = max(0, $limit);

        if ($total > $limit) {
            $truncated = true;
        }

        return array_map(
            fn (string $id): array => $this->nodesById[$id],
            array_slice($ids, 0, $limit),
        );
    }

    /**
     * Sample a bounded set across the full deterministic file index. This keeps
     * large classes representative without copying every node into query state.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sampledNodesInFile(
        string $file,
        int $limit,
        bool &$truncated = false,
        ?int &$total = null,
    ): array {
        $file = str_replace('\\', '/', $file);
        $ids = $this->nodeIdsByFile[$file] ?? [];
        $total = count($ids);
        $limit = max(0, $limit);

        if ($total > $limit) {
            $truncated = true;
        }

        if ($limit === 0 || $total === 0) {
            return [];
        }

        if ($total <= $limit || $limit === 1) {
            $selected = $limit === 1 ? [$ids[0]] : $ids;
        } else {
            $selected = [];

            for ($position = 0; $position < $limit; $position++) {
                $index = intdiv($position * ($total - 1), $limit - 1);
                $selected[] = $ids[$index];
            }
        }

        return array_map(fn (string $id): array => $this->nodesById[$id], $selected);
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
            false,
            0,
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
     * @param array<int, array{id: string, confidence?: float, graphConfidence?: float, via?: string, edgeType?: string, path?: array<int, string>, edges?: array<int, array<string, mixed>>, statePartition?: scalar}> $seeds
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
        bool $includeEdges = false,
        int $maxEvidencePerEdge = 3,
        ?int $maxEvidenceKindsPerEdge = null,
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

            if (isset($seed['graphConfidence']) && is_numeric($seed['graphConfidence'])) {
                $state['graphConfidence'] = round((float) $seed['graphConfidence'], 4);
            }

            if ($includeEdges) {
                $state['edges'] = [];
                $suppliedEdges = is_array($seed['edges'] ?? null) ? $seed['edges'] : [];
                $examined = 0;

                foreach ($suppliedEdges as $edge) {
                    $examined++;

                    if (is_array($edge)) {
                        $state['edges'][] = $this->compactTraversalEdge($edge, max(0, $maxEvidencePerEdge));
                    }

                    if (count($state['edges']) >= self::MAX_INCLUDED_SEED_EDGES
                        || $examined >= self::MAX_INCLUDED_SEED_EDGES * 4) {
                        break;
                    }
                }

                $edgesOmitted = max(0, count($suppliedEdges) - count($state['edges']));

                if ($edgesOmitted > 0) {
                    $state['edgesOmitted'] = $edgesOmitted;
                }
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
            $includeEdges,
            max(0, $maxEvidencePerEdge),
            $maxEvidenceKindsPerEdge === null ? null : max(0, $maxEvidenceKindsPerEdge),
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
        bool $includeEdges,
        int $maxEvidencePerEdge,
        ?int $maxEvidenceKindsPerEdge,
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
                $includeEdges,
                $maxEvidencePerEdge,
                $maxEvidenceKindsPerEdge,
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
                    $includeEdges,
                    $maxEvidencePerEdge,
                    $maxEvidenceKindsPerEdge,
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
        bool $includeEdges,
        int $maxEvidencePerEdge,
        ?int $maxEvidenceKindsPerEdge,
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
                $this->edgeEvidenceKinds($edge, $maxEvidenceKindsPerEdge),
            ),
        ] + $transitionState;

        if ($includeEdges) {
            $compactEdge = $this->compactTraversalEdge($edge, $maxEvidencePerEdge);
            $candidate['edges'] = [
                ...($state['edges'] ?? []),
                $compactEdge,
            ];
            $candidate['edgesOmitted'] = (int) ($state['edgesOmitted'] ?? 0);
        }

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

    /**
     * Keep enough edge provenance for an agent-facing path without copying
     * unbounded operation metadata into every traversal frontier state.
     *
     * @param array<string, mixed> $edge
     * @return array<string, mixed>
     */
    private function compactTraversalEdge(array $edge, int $maxEvidence): array
    {
        $evidence = [];
        $records = is_array($edge['metadata']['evidence'] ?? null)
            ? $edge['metadata']['evidence']
            : (is_array($edge['evidence'] ?? null) ? $edge['evidence'] : []);
        $evidenceTotal = count($records) + max(0, (int) ($edge['evidenceOmitted'] ?? 0));

        // Exported evidence maps are already key-sorted. Consume only the
        // bounded prefix so a pathological metadata payload cannot multiply
        // traversal work, and retain only the scalar provenance contract.
        if ($maxEvidence > 0) {
            $examined = 0;

            foreach ($records as $record) {
                $examined++;

                if (! is_array($record)) {
                    if ($examined >= $maxEvidence * 4) {
                        break;
                    }

                    continue;
                }

                $compact = $this->compactEvidenceRecord($record);

                if ($compact !== []) {
                    $evidence[] = $compact;
                }

                if (count($evidence) >= $maxEvidence) {
                    break;
                }

                if ($examined >= $maxEvidence * 4) {
                    break;
                }
            }
        }

        return array_filter([
            'from' => $edge['from'] ?? null,
            'to' => $edge['to'] ?? null,
            'type' => $edge['type'] ?? null,
            'confidence' => round((float) ($edge['confidence'] ?? 1.0), 4),
            'evidence' => $maxEvidence > 0 ? $evidence : null,
            'evidenceOmitted' => $evidenceTotal > count($evidence)
                ? $evidenceTotal - count($evidence)
                : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /** @param array<string, mixed> $record @return array<string, scalar> */
    private function compactEvidenceRecord(array $record): array
    {
        $compact = [];

        foreach (['file', 'line', 'rule', 'source', 'inference', 'syntax'] as $key) {
            if (! array_key_exists($key, $record)) {
                continue;
            }

            $value = $record[$key];

            if (AgentPayloadLimiter::isExactStringKey($key)) {
                if (! is_string($value) || ! $this->isBoundedExactReference($value)) {
                    // Evidence records are the atomic provenance unit. Keeping
                    // the other fields after dropping or shortening its source
                    // would make them appear to describe a different location.
                    return [];
                }

                $compact[$key] = $value;

                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $compact[$key] = is_string($value) ? BoundedText::utf8Bytes($value, 512) : $value;
        }

        return $compact;
    }

    private function isBoundedExactReference(string $value): bool
    {
        return $value !== ''
            && ! str_contains($value, "\0")
            && strlen($value) <= self::MAX_EXACT_REFERENCE_BYTES
            && mb_strlen($value) <= self::MAX_EXACT_REFERENCE_CHARACTERS
            && preg_match('//u', $value) === 1;
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
    private function edgeEvidenceKinds(array $edge, ?int $maxKinds = null): array
    {
        if ($maxKinds === 0) {
            return [];
        }

        $kinds = [];
        $examined = 0;

        foreach ($edge['metadata']['evidence'] ?? [] as $evidence) {
            $examined++;

            if (! is_array($evidence)) {
                if ($maxKinds !== null && $examined >= $maxKinds) {
                    break;
                }

                continue;
            }

            $kind = $evidence['rule']
                ?? $evidence['source']
                ?? $evidence['inference']
                ?? $evidence['syntax']
                ?? 'source_location';
            $kinds[(string) $kind] = (string) $kind;

            if ($maxKinds !== null && $examined >= $maxKinds) {
                break;
            }
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

            $metadata = is_array($node['metadata'] ?? null) ? $node['metadata'] : [];
            $name = (string) ($metadata['name'] ?? '');

            if (stripos($node['id'], $term) === false
                && stripos($node['label'] ?? '', $term) === false
                && stripos($name, $term) === false) {
                continue;
            }

            $results[] = $node;
        }

        // Mirror the store backend's relevance ranking (exact > prefix >
        // suffix > substring, shorter labels first, node id as the final
        // tiebreak) so ordering and truncation membership do not depend on
        // which backend serves the search.
        $lowerTerm = mb_strtolower($term);
        $ranked = array_map(
            static function (array $node) use ($lowerTerm): array {
                $label = (string) ($node['label'] ?? '');
                $lowerLabel = mb_strtolower($label);
                $metadata = is_array($node['metadata'] ?? null) ? $node['metadata'] : [];
                $lowerName = mb_strtolower((string) ($metadata['name'] ?? ''));
                $tier = 3;

                if (mb_strtolower($node['id']) === $lowerTerm
                    || $lowerLabel === $lowerTerm
                    || ($lowerName !== '' && $lowerName === $lowerTerm)) {
                    $tier = 0;
                } elseif (str_starts_with($lowerLabel, $lowerTerm)
                    || ($lowerName !== '' && str_starts_with($lowerName, $lowerTerm))) {
                    $tier = 1;
                } elseif (str_ends_with($lowerLabel, $lowerTerm)
                    || ($lowerName !== '' && str_ends_with($lowerName, $lowerTerm))) {
                    $tier = 2;
                }

                return [$tier, strlen($label), $node['id'], $node];
            },
            $results,
        );
        usort(
            $ranked,
            static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]],
        );
        $results = array_column($ranked, 3);

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
     * @return array{id: ?string, candidates: array<int, string>, candidatesTruncated?: bool}
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

        $lookup = strtolower($target);
        $resolvedCandidates = $this->boundedCandidateUnion([
            $this->nodeIdsByLabel[$lookup] ?? [],
            $this->nodeIdsByName[$lookup] ?? [],
        ], 10);
        $candidates = $resolvedCandidates['candidates'];

        if (count($candidates) === 1) {
            return ['id' => $candidates[0], 'candidates' => []];
        }

        if ($candidates !== []) {
            $result = [
                'id' => null,
                'candidates' => $candidates,
            ];

            if ($resolvedCandidates['truncated']) {
                $result['candidatesTruncated'] = true;
            }

            return $result;
        }

        $candidates = $this->nodeIdsBySuffix[ltrim($target, '\\')] ?? [];

        if (count($candidates) === 1) {
            return ['id' => $candidates[0], 'candidates' => []];
        }

        $result = [
            'id' => null,
            'candidates' => array_slice($candidates, 0, 10),
        ];

        if (count($candidates) > 10) {
            $result['candidatesTruncated'] = true;
        }

        return $result;
    }

    /**
     * Merge already-sorted candidate indexes while retaining only enough values
     * to answer uniqueness and return the public ambiguity hint.
     *
     * @param array<int, array<int, string>> $lists
     * @return array{candidates: array<int, string>, truncated: bool}
     */
    private function boundedCandidateUnion(array $lists, int $limit): array
    {
        $positions = array_fill(0, count($lists), 0);
        $candidates = [];

        while (true) {
            $next = null;

            foreach ($lists as $listIndex => $list) {
                $candidate = $list[$positions[$listIndex]] ?? null;

                if (is_string($candidate) && ($next === null || $candidate < $next)) {
                    $next = $candidate;
                }
            }

            if ($next === null) {
                return ['candidates' => $candidates, 'truncated' => false];
            }

            $candidates[] = $next;

            foreach ($lists as $listIndex => $list) {
                if (($list[$positions[$listIndex]] ?? null) === $next) {
                    $positions[$listIndex]++;
                }
            }

            if (count($candidates) > $limit) {
                return [
                    'candidates' => array_slice($candidates, 0, $limit),
                    'truncated' => true,
                ];
            }
        }
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
