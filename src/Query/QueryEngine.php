<?php

namespace AppGraph\Query;

use AppGraph\Graph\OverviewBuilder;

class QueryEngine
{
    public function __construct(
        private GraphIndex $index,
        private ?StalenessChecker $staleness = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $meta = $this->index->meta();

        $payload = [
            'meta' => array_filter([
                'appName' => $meta['appName'] ?? null,
                'laravelVersion' => $meta['laravelVersion'] ?? null,
                'appgraphVersion' => $meta['appgraphVersion'] ?? null,
            ], static fn ($value): bool => $value !== null),
            'counts' => [
                'nodes' => $this->index->nodeCount(),
                'edges' => $this->index->edgeCount(),
                'byNodeType' => $this->index->countsByNodeType(),
                'byEdgeType' => $this->index->countsByEdgeType(),
            ],
        ];

        $analysis = $meta['analysis'] ?? [];
        $analysisSummary = array_filter([
            'callResolution' => isset($analysis['callResolution']) ? array_filter([
                'unresolvedCount' => $analysis['callResolution']['unresolvedCount'] ?? null,
                'byReason' => $analysis['callResolution']['byReason'] ?? null,
                'boundaryCount' => $analysis['callResolution']['boundaryCount'] ?? null,
                'boundaryByReason' => $analysis['callResolution']['boundaryByReason'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== []) : null,
            'frontendRoutes' => isset($analysis['frontendRoutes']) ? array_filter([
                'unresolvedCount' => $analysis['frontendRoutes']['unresolvedCount'] ?? null,
            ], static fn ($value): bool => $value !== null) : null,
        ], static fn ($value): bool => $value !== null && $value !== []);

        if ($analysisSummary !== []) {
            $payload['analysis'] = $analysisSummary;
        }

        $sourcePath = $this->index->sourcePath();

        if ($this->staleness !== null && $sourcePath !== null) {
            $staleness = $this->staleness->check($sourcePath);

            if ($staleness !== []) {
                $payload['staleness'] = $staleness;
            }
        }

        return $this->envelope('overview', null, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function node(string $target, int $limit = 50, bool $full = false): array
    {
        $id = $this->resolve($target);
        $node = $this->index->node($id);

        if (! $full) {
            unset($node['metadata']);
        }

        $truncated = false;
        $out = $this->boundedEdgeList($this->index->edgesFrom($id), 'to', $limit, $truncated);
        $in = $this->boundedEdgeList($this->index->edgesTo($id), 'from', $limit, $truncated);

        return $this->envelope('node', $target, array_filter([
            'node' => $node,
            'out' => $out,
            'in' => $in,
        ], static fn ($value): bool => $value !== []), $truncated);
    }

    /**
     * @return array<string, mixed>
     */
    public function search(string $term, ?string $type = null, int $limit = 50): array
    {
        $truncated = false;
        $results = array_map(
            static fn (array $node): array => array_filter([
                'id' => $node['id'],
                'type' => $node['type'],
                'label' => $node['label'] ?? null,
                'file' => $node['file'] ?? null,
                'line' => $node['line'] ?? null,
            ], static fn ($value): bool => $value !== null),
            $this->index->search($term, $type, $limit, $truncated)
        );

        return $this->envelope('search', $term, ['results' => $results], $truncated);
    }

    /**
     * @return array<string, mixed>
     */
    public function writesTo(string $target, int $limit = 50): array
    {
        return $this->tableAccess('writes-to', 'writes', $target, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    public function readsFrom(string $target, int $limit = 50): array
    {
        return $this->tableAccess('reads-from', 'reads', $target, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    public function callersOf(string $target, int $depth = 4, int $limit = 50, float $minConfidence = 0.0): array
    {
        return $this->callTraversal('callers-of', 'in', $target, $depth, $limit, $minConfidence);
    }

    /**
     * @return array<string, mixed>
     */
    public function callsFrom(string $target, int $depth = 4, int $limit = 50, float $minConfidence = 0.0): array
    {
        return $this->callTraversal('calls-from', 'out', $target, $depth, $limit, $minConfidence);
    }

    /**
     * Build a compact, feature-shaped slice of the graph from an HTTP route or
     * method entry point through calls, validation, models, data access, and
     * dispatched events/jobs. This is intentionally a map for source discovery,
     * not a claim that every dynamic runtime path is represented.
     *
     * @return array<string, mixed>
     */
    public function flowFrom(string $target, int $depth = 4, int $limit = 50, float $minConfidence = 0.0): array
    {
        $resolvedId = $this->resolve($target);
        $resolvedNode = $this->index->node($resolvedId);
        $route = null;
        $entrypointId = $resolvedId;

        if (($resolvedNode['type'] ?? null) === 'route') {
            $routeEdge = $this->index->edgesFrom($resolvedId, ['routes_to'])[0] ?? null;

            if ($routeEdge === null) {
                throw new \RuntimeException("Route [{$target}] has no resolvable action in AppGraph.");
            }

            $entrypointId = $routeEdge['to'];
            $route = array_filter([
                'id' => $resolvedId,
                'label' => $resolvedNode['label'] ?? null,
                'name' => $resolvedNode['metadata']['name'] ?? null,
                'uri' => $resolvedNode['metadata']['uri'] ?? null,
                'methods' => $resolvedNode['metadata']['methods'] ?? null,
                'middleware' => $resolvedNode['metadata']['middleware'] ?? null,
                'action' => $entrypointId,
            ], static fn ($value): bool => $value !== null);
        } elseif (($resolvedNode['type'] ?? null) !== 'method') {
            throw new UnresolvedTargetException($target, [$resolvedId]);
        }

        $entrypoint = $this->index->node($entrypointId);

        if (($entrypoint['type'] ?? null) !== 'method') {
            throw new \RuntimeException("AppGraph resolved [{$target}] to an action that is not a method.");
        }

        $truncated = false;
        $methodHits = [[
            'id' => $entrypointId,
            'depth' => 0,
            'confidence' => 1.0,
        ]];
        $methodHits = array_merge($methodHits, $this->index->traverse(
            $entrypointId,
            ['calls'],
            'out',
            $depth,
            $minConfidence,
            $limit,
            $truncated,
        ));

        $methods = [];
        $models = [];
        $formRequests = [];
        $dataAccess = [];
        $dispatches = [];
        $sideEffects = [];
        $authorization = [];

        foreach ($methodHits as $hit) {
            $method = $this->index->node($hit['id']);
            $pathConfidence = (float) $hit['confidence'];

            $methods[$hit['id']] = array_filter([
                'id' => $hit['id'],
                'depth' => $hit['depth'],
                'confidence' => $pathConfidence,
                'via' => $hit['via'] ?? null,
                'file' => $method['file'] ?? null,
                'line' => $method['line'] ?? null,
            ], static fn ($value): bool => $value !== null);

            foreach ($this->index->edgesFrom($hit['id'], [
                'validates_with',
                'uses_model',
                'reads',
                'writes',
                'dispatches',
                'reads_cache',
                'writes_cache',
                'reads_filesystem',
                'writes_filesystem',
                'calls_external',
                'authorizes_via',
            ]) as $edge) {
                $edgeConfidence = round($pathConfidence * (float) ($edge['confidence'] ?? 1.0), 4);

                if ($edgeConfidence < $minConfidence) {
                    continue;
                }

                $related = $this->index->node($edge['to']);

                if ($edge['type'] === 'validates_with') {
                    $formRequests[$edge['to']] = $this->relatedNodeRow($edge['to'], $related, $hit, $edgeConfidence);
                    continue;
                }

                if ($edge['type'] === 'uses_model') {
                    $models[$edge['to']] = $this->relatedNodeRow($edge['to'], $related, $hit, $edgeConfidence);
                    continue;
                }

                if (in_array($edge['type'], ['reads', 'writes'], true)) {
                    $key = $hit['id'].'|'.$edge['type'].'|'.$edge['to'];
                    $dataAccess[$key] = array_filter([
                        'table' => str_starts_with($edge['to'], 'table:') ? substr($edge['to'], 6) : $edge['to'],
                        'access' => $edge['type'] === 'reads' ? 'read' : 'write',
                        'method' => $hit['id'],
                        'depth' => $hit['depth'],
                        'confidence' => $edgeConfidence,
                        'ops' => $this->edgeOperations($edge) ?: null,
                        'fields' => $this->edgeFields($edge) ?: null,
                    ], static fn ($value): bool => $value !== null);
                    continue;
                }

                if (in_array($edge['type'], ['reads_cache', 'writes_cache', 'reads_filesystem', 'writes_filesystem', 'calls_external'], true)) {
                    $sideEffects[$hit['id'].'|'.$edge['type'].'|'.$edge['to']] = array_filter([
                        'id' => $edge['to'],
                        'type' => $related['type'] ?? null,
                        'effect' => $edge['type'],
                        'operation' => $edge['metadata']['operation'] ?? null,
                        'from' => $hit['id'],
                        'depth' => $hit['depth'],
                        'confidence' => $edgeConfidence,
                        'file' => $related['file'] ?? null,
                        'line' => $edge['metadata']['line'] ?? null,
                    ], static fn ($value): bool => $value !== null);
                    continue;
                }

                if ($edge['type'] === 'authorizes_via') {
                    $authorization[$hit['id'].'|'.$edge['to']] = array_filter([
                        'id' => $edge['to'],
                        'policy' => $edge['to'],
                        'ability' => $edge['metadata']['ability'] ?? null,
                        'from' => $hit['id'],
                        'depth' => $hit['depth'],
                        'confidence' => $edgeConfidence,
                        'file' => $related['file'] ?? null,
                        'line' => $related['line'] ?? null,
                    ], static fn ($value): bool => $value !== null);
                    continue;
                }

                $listeners = [];

                foreach ($this->index->edgesTo($edge['to'], ['listens_to']) as $listenerEdge) {
                    $listener = $this->index->node($listenerEdge['from']);
                    $listeners[] = array_filter([
                        'id' => $listenerEdge['from'],
                        'confidence' => $listenerEdge['confidence'] ?? 1.0,
                        'queued' => $listenerEdge['metadata']['queued'] ?? null,
                        'afterCommit' => $listenerEdge['metadata']['afterCommit'] ?? null,
                        'file' => $listener['file'] ?? null,
                        'line' => $listener['line'] ?? null,
                    ], static fn ($value): bool => $value !== null);
                }

                usort($listeners, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

                $dispatches[$hit['id'].'|'.$edge['to']] = array_filter([
                    'id' => $edge['to'],
                    'type' => $related['type'] ?? ($edge['metadata']['kind'] ?? null),
                    'from' => $hit['id'],
                    'depth' => $hit['depth'],
                    'confidence' => $edgeConfidence,
                    'afterCommit' => $edge['metadata']['afterCommit'] ?? null,
                    'connection' => $edge['metadata']['connection'] ?? ($related['metadata']['connection'] ?? null),
                    'queue' => $edge['metadata']['queue'] ?? ($related['metadata']['queue'] ?? null),
                    'listeners' => $listeners ?: null,
                    'file' => $related['file'] ?? null,
                    'line' => $related['line'] ?? null,
                ], static fn ($value): bool => $value !== null);
            }
        }

        $frontendConsumers = [];
        $tests = [];
        $analysisWarnings = $this->flowAnalysisWarnings(array_keys($methods));

        if ($route !== null) {
            foreach ($this->index->edgesTo($route['id'], ['consumes_route', 'tests_route']) as $edge) {
                $node = $this->index->node($edge['from']);
                $row = array_filter([
                    'id' => $edge['from'],
                    'confidence' => $edge['confidence'] ?? 1.0,
                    'file' => $node['file'] ?? null,
                    'line' => $edge['metadata']['line'] ?? ($node['line'] ?? null),
                ], static fn ($value): bool => $value !== null);

                if ($edge['type'] === 'consumes_route') {
                    $frontendConsumers[$edge['from']] = $row;
                } else {
                    $tests[$edge['from']] = $row;
                }
            }

            if ($tests === []) {
                $analysisWarnings[] = [
                    'reason' => 'route_has_no_mapped_tests',
                    'route' => $route['id'],
                    'message' => 'No test method was statically mapped to this route; dynamic or indirect tests may still exist.',
                ];
            }
        }

        $groups = [
            'methods' => array_values($methods),
            'formRequests' => array_values($formRequests),
            'models' => array_values($models),
            'dataAccess' => array_values($dataAccess),
            'dispatches' => array_values($dispatches),
            'sideEffects' => array_values($sideEffects),
            'authorization' => array_values($authorization),
            'frontendConsumers' => array_values($frontendConsumers),
            'tests' => array_values($tests),
        ];

        foreach ($groups as $key => $group) {
            usort($group, static fn (array $a, array $b): int => [
                $a['depth'] ?? 0,
                $a['id'] ?? $a['method'].'|'.$a['access'].'|'.$a['table'],
            ] <=> [
                $b['depth'] ?? 0,
                $b['id'] ?? $b['method'].'|'.$b['access'].'|'.$b['table'],
            ]);

            if (count($group) > $limit) {
                $truncated = true;
                $group = array_slice($group, 0, $limit);
            }

            $groups[$key] = $group;
        }

        return $this->envelope('flow-from', $target, array_filter([
            'route' => $route,
            'entrypoint' => $methods[$entrypointId],
            ...$groups,
            'analysisWarnings' => $analysisWarnings ?: null,
        ], static fn ($value): bool => $value !== null && $value !== []), $truncated);
    }

    /**
     * Upstream impact closure: everything that can reach the target through the
     * data-access and call graph, grouped by node type. Confidence multiplies
     * along the path, so it is a ranking heuristic rather than a probability.
     *
     * @return array<string, mixed>
     */
    public function impactOf(string $target, int $depth = 4, int $limit = 50, float $minConfidence = 0.0): array
    {
        [$groups, $truncated] = $this->impactGroups($target, $depth, $limit, $minConfidence);

        return $this->envelope('impact-of', $target, array_filter($groups, static fn (array $group): bool => $group !== []), $truncated);
    }

    /**
     * @return array<string, mixed>
     */
    public function routesTouching(string $target, int $depth = 4, int $limit = 50, float $minConfidence = 0.0): array
    {
        [$groups, $truncated] = $this->impactGroups($target, $depth, $limit, $minConfidence);

        return $this->envelope('routes-touching', $target, ['results' => $groups['routes']], $truncated);
    }

    /**
     * @return array<string, mixed>
     */
    public function models(int $limit = 50): array
    {
        $truncated = false;
        $results = [];

        foreach ($this->index->nodesOfType('model') as $node) {
            $tableEdge = $this->index->edgesFrom($node['id'], ['uses_table'])[0] ?? null;
            $relationships = count($this->index->edgesFrom($node['id'], OverviewBuilder::RELATIONSHIP_EDGE_TYPES));

            $results[] = array_filter([
                'id' => $node['id'],
                'table' => $tableEdge !== null ? substr($tableEdge['to'], 6) : null,
                'relationships' => $relationships > 0 ? $relationships : null,
            ], static fn ($value): bool => $value !== null);
        }

        usort($results, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        if (count($results) > $limit) {
            $truncated = true;
            $results = array_slice($results, 0, $limit);
        }

        return $this->envelope('models', null, ['results' => $results], $truncated);
    }

    /**
     * @return array<string, mixed>
     */
    public function tables(int $limit = 50): array
    {
        $truncated = false;
        $results = [];

        foreach ($this->index->nodesOfType('table') as $node) {
            $columns = count($this->index->edgesFrom($node['id'], ['has_column']));
            $readers = count($this->index->edgesTo($node['id'], ['reads']));
            $writers = count($this->index->edgesTo($node['id'], ['writes']));

            $results[] = array_filter([
                'id' => $node['id'],
                'columns' => $columns > 0 ? $columns : null,
                'readers' => $readers > 0 ? $readers : null,
                'writers' => $writers > 0 ? $writers : null,
            ], static fn ($value): bool => $value !== null);
        }

        usort($results, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        if (count($results) > $limit) {
            $truncated = true;
            $results = array_slice($results, 0, $limit);
        }

        return $this->envelope('tables', null, ['results' => $results], $truncated);
    }

    /**
     * @return array<string, mixed>
     */
    private function tableAccess(string $query, string $edgeType, string $target, int $limit): array
    {
        $tableId = $this->resolveTableId($target);
        $truncated = false;
        $results = [];

        foreach ($this->index->edgesTo($tableId, [$edgeType]) as $edge) {
            $fromNode = $this->index->node($edge['from']);

            $ops = [];

            foreach ($edge['metadata']['operations'] ?? [] as $operation) {
                if (isset($operation['operation'])) {
                    $ops[$operation['operation']] = $operation['operation'];
                }
            }

            sort($ops);

            $results[] = array_filter([
                'id' => $edge['from'],
                'file' => $fromNode['file'] ?? null,
                'line' => $fromNode['line'] ?? null,
                'confidence' => $edge['confidence'] ?? null,
                'ops' => array_values($ops) ?: null,
                'fields' => $this->edgeFields($edge) ?: null,
            ], static fn ($value): bool => $value !== null);
        }

        usort($results, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        if (count($results) > $limit) {
            $truncated = true;
            $results = array_slice($results, 0, $limit);
        }

        return $this->envelope($query, $target, ['table' => $tableId, 'results' => $results], $truncated);
    }

    /**
     * @return array<string, mixed>
     */
    private function callTraversal(string $query, string $direction, string $target, int $depth, int $limit, float $minConfidence): array
    {
        $id = $this->resolve($target);
        $truncated = false;
        $results = [];

        foreach ($this->index->traverse($id, ['calls'], $direction, $depth, $minConfidence, $limit, $truncated) as $hit) {
            $node = $this->index->node($hit['id']);

            $results[] = array_filter([
                'id' => $hit['id'],
                'depth' => $hit['depth'],
                'confidence' => $hit['confidence'],
                'via' => $hit['via'] === $id ? null : $hit['via'],
                'file' => $node['file'] ?? null,
                'line' => $node['line'] ?? null,
            ], static fn ($value): bool => $value !== null);
        }

        return $this->envelope($query, $target, ['results' => $results], $truncated);
    }

    /**
     * @return array{0: array{methods: array<int, array<string, mixed>>, models: array<int, array<string, mixed>>, formRequests: array<int, array<string, mixed>>, routes: array<int, array<string, mixed>>}, 1: bool}
     */
    private function impactGroups(string $target, int $maxDepth, int $limit, float $minConfidence): array
    {
        $seed = $this->resolve($target);
        $groups = ['methods' => [], 'models' => [], 'formRequests' => [], 'routes' => []];
        $visited = [$seed => true];
        $queue = [[$seed, 0, 1.0]];

        while ($queue !== []) {
            [$currentId, $depth, $confidence] = array_shift($queue);

            if ($depth >= $maxDepth) {
                continue;
            }

            $currentNode = $this->index->node($currentId);

            // Per-type allowlist of incoming edges: each node type is only reachable
            // upstream through the relations that imply "changing the target affects
            // this dependent" — anything else would drag unrelated structure in.
            $edgeTypes = match ($currentNode['type'] ?? null) {
                'column' => ['has_column'],
                'table' => ['reads', 'writes', 'uses_table'],
                'model' => ['uses_model'],
                'form_request' => ['validates_with'],
                'method' => ['calls', 'routes_to'],
                default => [],
            };

            if ($edgeTypes === []) {
                continue;
            }

            foreach ($this->index->edgesTo($currentId, $edgeTypes) as $edge) {
                $fromId = $edge['from'];

                if (isset($visited[$fromId])) {
                    continue;
                }

                $pathConfidence = round($confidence * (float) ($edge['confidence'] ?? 1.0), 4);

                if ($pathConfidence < $minConfidence) {
                    continue;
                }

                $visited[$fromId] = true;
                $fromNode = $this->index->node($fromId);

                switch ($fromNode['type'] ?? null) {
                    case 'method':
                        $groups['methods'][] = array_filter([
                            'id' => $fromId,
                            'depth' => $depth + 1,
                            'confidence' => $pathConfidence,
                            'file' => $fromNode['file'] ?? null,
                            'line' => $fromNode['line'] ?? null,
                        ], static fn ($value): bool => $value !== null);
                        break;
                    case 'model':
                        $groups['models'][] = array_filter([
                            'id' => $fromId,
                            'depth' => $depth + 1,
                            'confidence' => $pathConfidence,
                            'file' => $fromNode['file'] ?? null,
                            'line' => $fromNode['line'] ?? null,
                        ], static fn ($value): bool => $value !== null);
                        break;
                    case 'form_request':
                        $groups['formRequests'][] = array_filter([
                            'id' => $fromId,
                            'depth' => $depth + 1,
                            'confidence' => $pathConfidence,
                            'file' => $fromNode['file'] ?? null,
                            'line' => $fromNode['line'] ?? null,
                        ], static fn ($value): bool => $value !== null);
                        break;
                    case 'route':
                        $action = $this->index->edgesFrom($fromId, ['routes_to'])[0]['to'] ?? null;
                        $groups['routes'][] = array_filter([
                            'id' => $fromId,
                            'label' => $fromNode['label'] ?? null,
                            'action' => $action,
                            'confidence' => $pathConfidence,
                            'depth' => $depth + 1,
                        ], static fn ($value): bool => $value !== null);
                        break;
                }

                $queue[] = [$fromId, $depth + 1, $pathConfidence];
            }
        }

        $truncated = false;

        foreach ($groups as $key => $group) {
            usort($group, static fn (array $a, array $b): int => [$a['depth'], $a['id']] <=> [$b['depth'], $b['id']]);

            if (count($group) > $limit) {
                $truncated = true;
                $group = array_slice($group, 0, $limit);
            }

            $groups[$key] = $group;
        }

        return [$groups, $truncated];
    }

    private function resolve(string $target): string
    {
        $result = $this->index->resolveId($target);

        if ($result['id'] === null) {
            throw new UnresolvedTargetException($target, $result['candidates']);
        }

        return $result['id'];
    }

    private function resolveTableId(string $target): string
    {
        $id = $this->resolve($target);
        $node = $this->index->node($id);

        // Convenience hops: a model resolves to its table, a column to its owner.
        if (($node['type'] ?? null) === 'model') {
            $tableEdge = $this->index->edgesFrom($id, ['uses_table'])[0] ?? null;

            if ($tableEdge !== null) {
                return $tableEdge['to'];
            }
        }

        if (($node['type'] ?? null) === 'column') {
            $tableEdge = $this->index->edgesTo($id, ['has_column'])[0] ?? null;

            if ($tableEdge !== null) {
                return $tableEdge['from'];
            }
        }

        if (($node['type'] ?? null) !== 'table') {
            throw new UnresolvedTargetException($target);
        }

        return $id;
    }

    /**
     * @param array<string, mixed>|null $node
     * @param array<string, mixed> $hit
     * @return array<string, mixed>
     */
    private function relatedNodeRow(string $id, ?array $node, array $hit, float $confidence): array
    {
        return array_filter([
            'id' => $id,
            'from' => $hit['id'],
            'depth' => $hit['depth'],
            'confidence' => $confidence,
            'file' => $node['file'] ?? null,
            'line' => $node['line'] ?? null,
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $edge
     * @return array<int, string>
     */
    private function edgeOperations(array $edge): array
    {
        $operations = [];

        foreach ($edge['metadata']['operations'] ?? [] as $operation) {
            if (is_array($operation) && isset($operation['operation'])) {
                $operations[(string) $operation['operation']] = (string) $operation['operation'];
            }
        }

        sort($operations);

        return array_values($operations);
    }

    /**
     * @param array<string, mixed> $edge
     * @return array<int, string>
     */
    private function edgeFields(array $edge): array
    {
        $fields = [];

        foreach ($edge['metadata']['operations'] ?? [] as $operation) {
            foreach ($operation['fields'] ?? [] as $field) {
                if (is_string($field)) {
                    $fields[$field] = $field;
                }
            }
        }

        sort($fields);

        return array_values($fields);
    }

    /**
     * @param array<int, string> $methodIds
     * @return array<int, array<string, mixed>>
     */
    private function flowAnalysisWarnings(array $methodIds): array
    {
        $methodIds = array_fill_keys($methodIds, true);
        $warnings = [];
        $resolution = $this->index->meta()['analysis']['callResolution'] ?? [];
        $byCaller = $resolution['byCaller'] ?? [];

        foreach (array_keys($methodIds) as $methodId) {
            if (! isset($byCaller[$methodId]) || ! is_array($byCaller[$methodId])) {
                continue;
            }

            $warnings[] = [
                'caller' => $methodId,
                'reason' => 'unresolved_calls',
                'message' => 'One or more call edges may be missing because static resolution was incomplete.',
            ] + $byCaller[$methodId];
        }

        if ($warnings !== []) {
            return $warnings;
        }

        $diagnostics = $resolution['samples'] ?? [];

        foreach ($diagnostics as $diagnostic) {
            if (! is_array($diagnostic) || ! isset($methodIds[$diagnostic['caller'] ?? ''])) {
                continue;
            }

            $warnings[] = ['message' => 'A call edge may be missing because static resolution was incomplete.'] + $diagnostic;
        }

        return $warnings;
    }

    /**
     * @param array<int, array<string, mixed>> $edges
     * @return array<int, array<string, mixed>>
     */
    private function boundedEdgeList(array $edges, string $endpoint, int $limit, bool &$truncated): array
    {
        $results = array_map(
            static fn (array $edge): array => [
                $endpoint => $edge[$endpoint],
                'type' => $edge['type'],
                'confidence' => $edge['confidence'] ?? 1.0,
            ],
            $edges
        );

        usort($results, static fn (array $a, array $b): int => [$a['type'], $a[$endpoint]] <=> [$b['type'], $b[$endpoint]]);

        if (count($results) > $limit) {
            $truncated = true;
            $results = array_slice($results, 0, $limit);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function envelope(string $query, ?string $target, array $payload, bool $truncated = false): array
    {
        $envelope = ['query' => $query];

        if ($target !== null) {
            $envelope['target'] = $target;
        }

        $generatedAt = $this->index->meta()['generatedAt'] ?? null;

        if (is_string($generatedAt)) {
            $envelope['generatedAt'] = $generatedAt;
            $timestamp = strtotime($generatedAt);

            if ($timestamp !== false) {
                $envelope['graphAgeSeconds'] = max(0, time() - $timestamp);
            }
        }

        $envelope += $payload;

        if ($truncated) {
            $envelope['truncated'] = true;
        }

        return $envelope;
    }
}
