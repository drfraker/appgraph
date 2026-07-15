<?php

namespace AppGraph\Graph;

/**
 * Builds a small, always-loadable "overview" projection of a full graph.
 *
 * The full export is too large for an agent to read into context; this projection
 * is bounded by O(models + relationships + routes) — independent of the method/column/
 * index node mass that dominates the full graph — so an agent can load it on entry to
 * orient itself before doing targeted reads against the full graph or the source.
 */
class OverviewBuilder
{
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
        // route id -> controller method id
        $routeToAction = [];
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
        usort($relationships, static fn (array $a, array $b): int => [$a['from'], $a['type'], $a['to']] <=> [$b['from'], $b['type'], $b['to']]);

        $routes = [];
        foreach ($nodes as $node) {
            if ($node->type !== 'route') {
                continue;
            }

            $action = $routeToAction[$node->id] ?? null;
            $routeModels = $action !== null ? array_values(array_unique($methodModels[$action] ?? [])) : [];
            $routeTables = $action !== null ? array_values(array_unique($methodTables[$action] ?? [])) : [];
            sort($routeModels);
            sort($routeTables);

            $routes[] = array_filter([
                'route' => $node->label,
                'name' => $node->metadata['name'] ?? null,
                'action' => $action,
                'models' => $routeModels,
                'tables' => $routeTables,
            ], static fn ($value): bool => $value !== null && $value !== []);
        }

        usort($routes, static fn (array $a, array $b): int => ($a['route'] ?? '') <=> ($b['route'] ?? ''));

        $meta = $graph->meta();

        return [
            'meta' => array_filter([
                'appName' => $meta['appName'] ?? null,
                'generatedAt' => $meta['generatedAt'] ?? null,
                'laravelVersion' => $meta['laravelVersion'] ?? null,
                'appgraphVersion' => $meta['appgraphVersion'] ?? null,
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
}
