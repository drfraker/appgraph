<?php

namespace AppGraph\Query;

use JsonException;
use RuntimeException;

class GraphIndex
{
    /**
     * @var array<string, array{mtime: int, index: self}>
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

        $mtime = (int) filemtime($path);

        if (isset(self::$cache[$path]) && self::$cache[$path]['mtime'] === $mtime) {
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

        self::$cache = [$path => ['mtime' => $mtime, 'index' => $index]];

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
     * Breadth-first traversal from a node, multiplying edge confidence along the
     * path. The first visit to a node wins (BFS guarantees it is the shallowest);
     * results are sorted by [depth, id] so output stays deterministic.
     *
     * @param array<int, string> $edgeTypes
     * @return array<int, array{id: string, depth: int, confidence: float, via: string, edgeType: string}>
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
        $results = [];
        $visited = [$startId => true];
        $queue = [[$startId, 0, 1.0]];

        while ($queue !== []) {
            [$currentId, $depth, $confidence] = array_shift($queue);

            if ($depth >= $maxDepth) {
                continue;
            }

            $edges = $direction === 'out'
                ? $this->edgesFrom($currentId, $edgeTypes)
                : $this->edgesTo($currentId, $edgeTypes);

            foreach ($edges as $edge) {
                $neighborId = $direction === 'out' ? $edge['to'] : $edge['from'];

                if (isset($visited[$neighborId])) {
                    continue;
                }

                $pathConfidence = round($confidence * (float) ($edge['confidence'] ?? 1.0), 4);

                if ($pathConfidence < $minConfidence) {
                    continue;
                }

                $visited[$neighborId] = true;
                $results[] = [
                    'id' => $neighborId,
                    'depth' => $depth + 1,
                    'confidence' => $pathConfidence,
                    'via' => $currentId,
                    'edgeType' => $edge['type'],
                ];
                $queue[] = [$neighborId, $depth + 1, $pathConfidence];
            }
        }

        usort($results, static fn (array $a, array $b): int => [$a['depth'], $a['id']] <=> [$b['depth'], $b['id']]);

        if (count($results) > $limit) {
            $truncated = true;
            $results = array_slice($results, 0, $limit);
        }

        return $results;
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
