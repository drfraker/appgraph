<?php

namespace AppGraph\Graph;

use AppGraph\Query\GraphIndex;
use AppGraph\Query\QueryEngine;

/**
 * Builds a small, always-loadable "overview" projection of a full graph.
 *
 * The full export is too large for an agent to read into context; this projection
 * is bounded by O(models + relationships + events + routes * route-item-limit) —
 * independent of the method/column/index node mass that dominates the full graph —
 * so an agent can load it on entry to orient itself before targeted graph/source reads.
 */
class OverviewBuilder
{
    private const MAX_ROUTE_DEPTH = 12;

    private const MAX_ROUTE_METHOD_LIMIT = 256;

    private const MAX_ROUTE_ITEM_LIMIT = 64;

    private const MAX_ROUTE_RELATED_LIMIT = 1024;

    private const MAX_ROUTE_TRANSITION_LIMIT = 50000;

    /**
     * Model-to-model relationship edge types (emitted by ModelScanner). Kept as a single
     * source of truth so new relationship kinds are added in one place.
     *
     * @var array<int, string>
     */
    public const RELATIONSHIP_EDGE_TYPES = [
        'belongs_to',
        'belongs_to_many',
        'has_many',
        'has_one',
    ];

    public function __construct(
        private int $routeDepth = 6,
        private int $routeMethodLimit = 32,
        private int $routeItemLimit = 12,
        private int $routeTransitionLimit = 5000,
    ) {
        $this->routeDepth = min(self::MAX_ROUTE_DEPTH, max(0, $this->routeDepth));
        $this->routeMethodLimit = min(self::MAX_ROUTE_METHOD_LIMIT, max(1, $this->routeMethodLimit));
        $this->routeItemLimit = min(self::MAX_ROUTE_ITEM_LIMIT, max(1, $this->routeItemLimit));
        $this->routeTransitionLimit = min(
            self::MAX_ROUTE_TRANSITION_LIMIT,
            max(1, $this->routeTransitionLimit),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Graph $graph): array
    {
        $nodes = $graph->nodes();
        $edges = $graph->edges();

        $byNodeType = [];
        foreach ($nodes as $node) {
            $byNodeType[$node->type] = ($byNodeType[$node->type] ?? 0) + 1;
        }
        ksort($byNodeType);

        $byEdgeType = [];
        foreach ($edges as $edge) {
            $byEdgeType[$edge->type] = ($byEdgeType[$edge->type] ?? 0) + 1;
        }
        ksort($byEdgeType);

        // model id -> table name (authoritative: the resolved uses_table edge)
        $models = [];
        // method id -> touched models / tables
        $methodModels = [];
        $methodTables = [];
        // Preserve the prior last-registration-wins behavior when malformed or
        // merged input contains several actions for one stable route id.
        $routeToAction = [];
        $routeActionCandidates = [];
        $relationships = [];
        // event/job id -> listener and dispatch-site counts (counts only, so the
        // projection stays bounded on event-heavy apps)
        $events = [];

        foreach ($edges as $edge) {
            switch ($edge->type) {
                case 'listens_to':
                    $events[$edge->to]['listeners'] = ($events[$edge->to]['listeners'] ?? 0) + 1;
                    break;
                case 'dispatches':
                    $events[$edge->to]['dispatchSites'] = ($events[$edge->to]['dispatchSites'] ?? 0) + 1;
                    break;
                case 'uses_table':
                    if (str_starts_with($edge->to, 'table:')) {
                        $models[$edge->from] = substr($edge->to, 6);
                    }
                    break;
                case 'uses_model':
                    $methodModels[$edge->from][] = $edge->to;
                    break;
                case 'reads':
                case 'writes':
                    if (str_starts_with($edge->to, 'table:')) {
                        $methodTables[$edge->from][] = substr($edge->to, 6);
                    }
                    break;
                case 'routes_to':
                    $routeToAction[$edge->from] = $edge->to;
                    $routeActionCandidates[$edge->from][$edge->to] = $edge->to;
                    break;
            }

            if (in_array($edge->type, self::RELATIONSHIP_EDGE_TYPES, true) && ! str_starts_with($edge->to, 'unknown:')) {
                $relationships[] = [
                    'from' => $edge->from,
                    'to' => $edge->to,
                    'type' => $edge->type,
                ];
            }
        }

        ksort($models);
        ksort($events);

        foreach ($events as &$eventCounts) {
            ksort($eventCounts);
        }
        unset($eventCounts);

        usort($relationships, static fn (array $a, array $b): int => [$a['from'], $a['type'], $a['to']] <=> [$b['from'], $b['type'], $b['to']]);

        // Reuse the same ranked, Laravel-aware causal traversal exposed to
        // agents by `flow-from`. The overview-specific index keeps only the
        // authoritative route action while still exposing alternatives below.
        $graphData = $graph->toArray();
        $graphData['edges'] = array_values(array_filter(
            $graphData['edges'],
            static fn (array $edge): bool => ($edge['type'] ?? null) !== 'routes_to'
                || ! isset($routeToAction[$edge['from'] ?? ''])
                || $routeToAction[$edge['from']] === ($edge['to'] ?? null),
        ));
        $index = GraphIndex::fromArray($graphData);
        $query = new QueryEngine($index);

        $routes = [];
        foreach ($nodes as $node) {
            if ($node->type !== 'route') {
                continue;
            }

            $action = $routeToAction[$node->id] ?? null;
            $routeModels = $action !== null ? array_values(array_unique($methodModels[$action] ?? [])) : [];
            $routeTables = $action !== null ? array_values(array_unique($methodTables[$action] ?? [])) : [];
            $routeDispatches = [];
            $truncated = false;
            $unresolvedAction = false;

            if ($action !== null && ($index->node($action)['type'] ?? null) === 'method') {
                $flow = $query->flowFrom(
                    $node->id,
                    depth: $this->routeDepth,
                    limit: $this->routeMethodLimit,
                    maxTransitions: $this->routeTransitionLimit,
                    maxRelatedFacts: self::MAX_ROUTE_RELATED_LIMIT,
                    relatedGroups: ['models', 'dataAccess', 'dispatches'],
                    includeDispatchDetails: false,
                    includeDataAccessDetails: false,
                    includeAnalysisWarnings: false,
                );
                $modelsTruncated = false;
                $tablesTruncated = false;
                $dispatchesTruncated = false;
                $causalDispatches = array_values(array_filter(
                    $flow['dispatches'] ?? [],
                    static fn (array $row): bool => ($row['causalExecutionProven'] ?? false) === true,
                ));

                $routeModels = $this->compactRankedGroup(
                    $flow['models'] ?? [],
                    static fn (array $row): mixed => $row['id'] ?? null,
                    $modelsTruncated,
                );
                $routeTables = $this->compactRankedGroup(
                    $flow['dataAccess'] ?? [],
                    static fn (array $row): mixed => $row['table'] ?? null,
                    $tablesTruncated,
                );
                $routeDispatches = $this->compactRankedGroup(
                    $causalDispatches,
                    static fn (array $row): mixed => $row['id'] ?? null,
                    $dispatchesTruncated,
                );
                $truncationGroups = $flow['truncation']['groups'] ?? [];
                $truncated = ($flow['truncation']['executionTraversal'] ?? false)
                    || isset($flow['truncation']['relatedFacts'])
                    || array_intersect(['models', 'dataAccess', 'dispatches'], $truncationGroups) !== []
                    || $modelsTruncated
                    || $tablesTruncated
                    || $dispatchesTruncated;
            } else {
                $unresolvedAction = $action !== null;
                $truncated = $unresolvedAction;
                sort($routeModels);
                sort($routeTables);

                if (count($routeModels) > $this->routeItemLimit) {
                    $truncated = true;
                    $routeModels = array_slice($routeModels, 0, $this->routeItemLimit);
                }

                if (count($routeTables) > $this->routeItemLimit) {
                    $truncated = true;
                    $routeTables = array_slice($routeTables, 0, $this->routeItemLimit);
                }
            }

            $actionsTruncated = false;
            $actionAlternatives = $this->routeActionAlternatives(
                $routeActionCandidates[$node->id] ?? [],
                $action,
                $actionsTruncated,
            );
            $truncated = $truncated || $actionsTruncated;

            $route = array_filter([
                '_sortId' => $node->id,
                'route' => $node->label,
                'name' => $node->metadata['name'] ?? null,
                'action' => $action,
                'actionAlternatives' => $actionAlternatives,
                'unresolvedAction' => $unresolvedAction ?: null,
                'models' => $routeModels,
                'tables' => $routeTables,
                'dispatches' => $routeDispatches,
            ], static fn ($value): bool => $value !== null && $value !== []);

            if ($truncated) {
                $route['truncated'] = true;
            }

            $routes[] = $route;
        }

        usort($routes, static fn (array $a, array $b): int => [
            $a['route'] ?? '',
            $a['name'] ?? '',
            $a['action'] ?? '',
            $a['_sortId'],
        ] <=> [
            $b['route'] ?? '',
            $b['name'] ?? '',
            $b['action'] ?? '',
            $b['_sortId'],
        ]);

        foreach ($routes as &$route) {
            unset($route['_sortId']);
        }
        unset($route);

        $meta = $graph->meta();

        return [
            'meta' => array_filter([
                'appName' => $meta['appName'] ?? null,
                'generatedAt' => $meta['generatedAt'] ?? null,
                'laravelVersion' => $meta['laravelVersion'] ?? null,
                'appgraphVersion' => $meta['appgraphVersion'] ?? null,
                'routeTraversal' => [
                    'maxDepth' => $this->routeDepth,
                    'maxMethods' => $this->routeMethodLimit,
                    'maxRelatedFacts' => self::MAX_ROUTE_RELATED_LIMIT,
                    'maxItemsPerGroup' => $this->routeItemLimit,
                    'maxTransitions' => $this->routeTransitionLimit,
                ],
            ], static fn ($value): bool => $value !== null),
            'counts' => [
                'nodes' => count($nodes),
                'edges' => count($edges),
                'byNodeType' => $byNodeType,
                'byEdgeType' => $byEdgeType,
            ],
            'models' => $models,
            'relationships' => $relationships,
            'routes' => $routes,
            'events' => $events,
        ];
    }

    /**
     * @param array<string, string> $candidates
     * @return array<int, string>
     */
    private function routeActionAlternatives(array $candidates, ?string $selected, bool &$truncated): array
    {
        $alternatives = array_values(array_filter(
            $candidates,
            static fn (string $candidate): bool => $candidate !== $selected,
        ));
        sort($alternatives);
        $truncated = count($alternatives) > $this->routeItemLimit;

        return array_slice($alternatives, 0, $this->routeItemLimit);
    }

    /**
     * Keep the strongest occurrence of each compact value, take a strict
     * confidence/depth/id-ranked prefix, then sort the retained strings for
     * stable and easy-to-diff overview output.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param callable(array<string, mixed>): mixed $value
     * @return array<int, string>
     */
    private function compactRankedGroup(array $rows, callable $value, bool &$truncated): array
    {
        $ranked = [];

        foreach ($rows as $row) {
            $id = $value($row);

            if (! is_string($id) || $id === '') {
                continue;
            }

            $candidate = [
                'id' => $id,
                'confidence' => round((float) ($row['confidence'] ?? 1.0), 4),
                'depth' => (int) ($row['depth'] ?? 0),
            ];

            if (! isset($ranked[$id]) || $this->compareCompactRows($candidate, $ranked[$id]) < 0) {
                $ranked[$id] = $candidate;
            }
        }

        $ranked = array_values($ranked);
        usort($ranked, $this->compareCompactRows(...));
        $truncated = count($ranked) > $this->routeItemLimit;
        $ranked = array_slice($ranked, 0, $this->routeItemLimit);
        $result = array_column($ranked, 'id');
        sort($result);

        return $result;
    }

    /** @param array{id: string, confidence: float, depth: int} $a @param array{id: string, confidence: float, depth: int} $b */
    private function compareCompactRows(array $a, array $b): int
    {
        return [
            -$a['confidence'],
            $a['depth'],
            $a['id'],
        ] <=> [
            -$b['confidence'],
            $b['depth'],
            $b['id'],
        ];
    }
}
