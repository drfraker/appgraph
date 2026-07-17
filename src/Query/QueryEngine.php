<?php

namespace AppGraph\Query;

use AppGraph\Graph\OverviewBuilder;
use AppGraph\Support\AgentPayloadLimiter;
use Illuminate\Container\Container;

class QueryEngine
{
    /** @var array<string, array<int, string>> */
    private const FLOW_RELATED_GROUP_EDGE_TYPES = [
        'formRequests' => ['validates_with'],
        'models' => ['uses_model'],
        'dataAccess' => ['reads', 'writes'],
        'dispatches' => ['dispatches'],
        'sideEffects' => [
            'reads_cache',
            'writes_cache',
            'reads_filesystem',
            'writes_filesystem',
            'calls_external',
        ],
        'authorization' => ['authorizes_via'],
        'frontendConsumers' => ['consumes_route'],
        'tests' => ['tests_route'],
    ];

    private const DEFAULT_MAX_RELATED_FACTS = 10000;

    private const MAX_DETAIL_OPERATIONS = 32;

    private const MAX_DETAIL_FIELDS = 64;

    private const MAX_DETAIL_SOURCE_OPERATIONS = 256;

    private const MAX_DETAIL_SOURCE_FIELDS = 1024;

    private const MAX_DETAIL_VALUE_BYTES = 1024;

    private LaravelExecutionSemantics $executionSemantics;

    private FieldAccessClassifier $fieldAccessClassifier;

    private string $basePath;

    public function __construct(
        private GraphIndex $index,
        private ?StalenessChecker $staleness = null,
        ?string $basePath = null,
    ) {
        $this->executionSemantics = new LaravelExecutionSemantics($index);
        $this->fieldAccessClassifier = new FieldAccessClassifier;
        $application = Container::getInstance();
        $applicationBasePath = method_exists($application, 'basePath')
            ? $application->basePath()
            : null;
        $this->basePath = $basePath
            ?? (is_string($applicationBasePath) && $applicationBasePath !== ''
                ? $applicationBasePath
                : (getcwd() ?: '.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $meta = $this->index->meta();
        $scannerWarnings = ScannerWarningSummary::fromMeta($meta);

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
            'scannerWarnings' => $scannerWarnings,
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
            $staleness = $this->staleness->check($sourcePath, is_array($meta['scan'] ?? null) ? $meta['scan'] : null);

            if ($staleness !== []) {
                $payload['staleness'] = $staleness;
            }
        }

        return $this->envelope('overview', null, $payload, $scannerWarnings['truncated']);
    }

    /**
     * Compile a bounded source-reading plan around an agent's concrete task.
     * The token budget covers recommended source spans, not the JSON response,
     * and source contents are never returned by this query.
     *
     * @param array<int, string> $targets
     * @param array<int, string> $changedFiles
     * @return array<string, mixed>
     */
    public function contextForTask(
        string $task,
        array $targets = [],
        array $changedFiles = [],
        int $tokenBudget = 4000,
        int $depth = 4,
        float $minConfidence = 0.0,
    ): array {
        $payload = (new TaskContextPlanner($this->index, $this->basePath))->plan(
            $task,
            $targets,
            $changedFiles,
            $tokenBudget,
            $depth,
            $minConfidence,
        );
        $meta = $this->index->meta();
        $generation = $meta['generation'] ?? null;

        if (is_array($generation) && is_string($generation['id'] ?? null)) {
            $payload['verification']['baselineGeneration'] = $generation;
        }
        $truncated = (bool) $payload['truncated'];
        unset($payload['truncated']);
        $sourcePath = $this->index->sourcePath();

        if ($this->staleness !== null && $sourcePath !== null) {
            $staleness = $this->staleness->check(
                $sourcePath,
                is_array($meta['scan'] ?? null) ? $meta['scan'] : null,
            );

            if ($staleness !== []) {
                $payload['staleness'] = $staleness;
            }
        }

        return $this->envelope('context-for-task', null, $payload, $truncated);
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
        $out = $this->boundedEdgeList($this->index->edgesFrom($id), 'to', $limit, $truncated, $full);
        $in = $this->boundedEdgeList($this->index->edgesTo($id), 'from', $limit, $truncated, $full);

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
     * @param array<int, string>|null $relatedGroups Internal projection
     *        selectivity; null retains every public flow group.
     * @return array<string, mixed>
     */
    public function flowFrom(
        string $target,
        int $depth = 4,
        int $limit = 50,
        float $minConfidence = 0.0,
        ?int $relatedLimit = null,
        int $maxTransitions = 10000,
        int $maxRelatedFacts = self::DEFAULT_MAX_RELATED_FACTS,
        ?array $relatedGroups = null,
        bool $includeDispatchDetails = true,
        bool $includeDataAccessDetails = true,
        bool $includeAnalysisWarnings = true,
    ): array
    {
        $depth = max(0, $depth);
        $limit = max(1, $limit);
        $relatedLimit = max(1, $relatedLimit ?? $limit);
        $maxTransitions = max(0, $maxTransitions);
        $maxRelatedFacts = max(0, $maxRelatedFacts);
        $selectedRelatedGroups = $this->selectedFlowRelatedGroups($relatedGroups);
        $selectedRelatedEdgeTypes = $this->selectedFlowRelatedEdgeTypes($selectedRelatedGroups);
        $resolvedId = $this->resolve($target);
        $resolvedNode = $this->index->node($resolvedId);
        $route = null;
        $routeActionSelectionTruncated = false;
        $entrypointId = $resolvedId;
        $entrypointConfidence = 1.0;

        if (($resolvedNode['type'] ?? null) === 'route') {
            $routeEdge = $this->index->rankedEdgesFrom(
                $resolvedId,
                ['routes_to'],
                1,
                $routeActionSelectionTruncated,
            )[0] ?? null;

            if ($routeEdge === null) {
                throw new \RuntimeException("Route [{$target}] has no resolvable action in AppGraph.");
            }

            $entrypointId = $routeEdge['to'];
            $entrypointConfidence = round((float) ($routeEdge['confidence'] ?? 1.0), 4);
            $route = array_filter([
                'id' => $resolvedId,
                'label' => $resolvedNode['label'] ?? null,
                'name' => $resolvedNode['metadata']['name'] ?? null,
                'uri' => $resolvedNode['metadata']['uri'] ?? null,
                'methods' => $resolvedNode['metadata']['methods'] ?? null,
                'middleware' => $resolvedNode['metadata']['middleware'] ?? null,
                'action' => $entrypointId,
                'actionConfidence' => $entrypointConfidence,
            ], static fn ($value): bool => $value !== null);
        } elseif (($resolvedNode['type'] ?? null) !== 'method') {
            throw new UnresolvedTargetException($target, [$resolvedId]);
        }

        $entrypoint = $this->index->node($entrypointId);

        if (($entrypoint['type'] ?? null) !== 'method') {
            throw new \RuntimeException("AppGraph resolved [{$target}] to an action that is not a method.");
        }

        $truncated = $routeActionSelectionTruncated;
        $executionTraversalTruncated = false;
        $executionMetadataTruncated = false;
        $dataAccessDetailsTruncated = false;
        $actionReservationTruncated = false;
        $remainingTransitions = $maxTransitions;
        $methodSeeds = [[
            'id' => $entrypointId,
            'confidence' => $entrypointConfidence,
            'path' => [$entrypointId],
        ]];

        if ($route !== null) {
            $middlewareSeedTruncated = false;
            $middlewareEdges = $this->index->rankedEdgesFrom(
                $resolvedId,
                ['passes_through'],
                $remainingTransitions,
                $middlewareSeedTruncated,
            );
            $remainingTransitions -= count($middlewareEdges);
            $executionTraversalTruncated = $middlewareSeedTruncated;

            foreach ($middlewareEdges as $middlewareEdge) {
                $middleware = $this->index->node($middlewareEdge['to']);
                $middlewareConfidence = round((float) ($middlewareEdge['confidence'] ?? 1.0), 4);

                if (($middleware['type'] ?? null) !== 'method' || $middlewareConfidence < $minConfidence) {
                    continue;
                }

                $methodSeeds[] = [
                    'id' => $middlewareEdge['to'],
                    'confidence' => $middlewareConfidence,
                    'via' => $resolvedId,
                    'edgeType' => 'passes_through',
                    'path' => [$resolvedId, $middlewareEdge['to']],
                ];
            }
        }

        // Intermediate FormRequest, event, and job nodes are execution bridges,
        // not method results. Filter only the returned rows; traversal still
        // crosses those nodes to reach framework-invoked application methods.
        $methodHits = $this->index->traverseFromSeeds(
            $methodSeeds,
            $this->executionSemantics->executionEdgeTypes(),
            direction: 'out',
            maxDepth: $depth,
            minConfidence: $minConfidence,
            limit: max(1, $limit),
            resultNodeTypes: ['method'],
            transition: function (array $state, array $edge, string $neighborId, string $direction) use (&$executionMetadataTruncated, $minConfidence): array|false {
                $transition = $this->executionSemantics->transition($state, $edge, $neighborId, $direction);
                $pathConfidence = round(
                    (float) ($state['confidence'] ?? 1.0) * (float) ($edge['confidence'] ?? 1.0),
                    4,
                );

                if ($pathConfidence >= $minConfidence && ($edge['type'] ?? null) === 'dispatches') {
                    $analysis = $this->executionSemantics->dispatchAnalysis(
                        $edge,
                        $this->index->node($neighborId),
                    );
                    $executionMetadataTruncated = $executionMetadataTruncated || $analysis['truncated'];
                }

                if ($pathConfidence >= $minConfidence
                    && is_array($transition)
                    && ($transition['metadataTruncated'] ?? false)) {
                    $executionMetadataTruncated = true;
                }

                return $transition;
            },
            maxTransitions: $remainingTransitions,
            truncated: $executionTraversalTruncated,
        );

        $truncated = $truncated || $executionTraversalTruncated || $executionMetadataTruncated;

        if (! in_array($entrypointId, array_column($methodHits, 'id'), true)) {
            $truncated = true;
            $actionReservationTruncated = true;
            array_unshift($methodHits, [
                'id' => $entrypointId,
                'depth' => 0,
                'confidence' => $entrypointConfidence,
                'via' => $entrypointId,
                'edgeType' => '',
                'path' => [$entrypointId],
                'evidenceCount' => 0,
            ]);

            if (count($methodHits) > max(1, $limit)) {
                $methodHits = array_slice($methodHits, 0, max(1, $limit));
            }
        }

        $methods = [];
        $models = [];
        $formRequests = [];
        $dataAccess = [];
        $dispatches = [];
        $sideEffects = [];
        $authorization = [];
        $remainingRelatedFacts = $maxRelatedFacts;
        $relatedFactsConsumed = 0;
        $relatedFactsTruncated = false;
        $relatedFactTruncationStages = [];
        $methodHitsById = [];
        $relatedSources = [];

        foreach ($methodHits as $hit) {
            $method = $this->index->node($hit['id']);
            $pathConfidence = (float) $hit['confidence'];
            $methodHitsById[$hit['id']] = $hit;
            $relatedSources[] = [
                'id' => $hit['id'],
                'confidence' => $pathConfidence,
                'depth' => $hit['depth'],
                'path' => $hit['path'] ?? [$hit['id']],
            ];

            $methods[$hit['id']] = array_filter([
                'id' => $hit['id'],
                'depth' => $hit['depth'],
                'confidence' => $pathConfidence,
                'via' => $hit['via'] ?? null,
                'file' => $method['file'] ?? null,
                'line' => $method['line'] ?? null,
            ], static fn ($value): bool => $value !== null);
        }

        // Merge every method's pre-ranked adjacency before spending the shared
        // budget. This keeps a strong downstream fact from losing merely
        // because its method appeared later in the traversal result.
        $methodEdgesTruncated = false;
        $methodRelatedRows = $selectedRelatedEdgeTypes === []
            ? []
            : $this->index->rankedEdgesFromSources(
                $relatedSources,
                $selectedRelatedEdgeTypes,
                $remainingRelatedFacts,
                $methodEdgesTruncated,
            );
        $methodRelatedEdgeCount = count($methodRelatedRows);
        $remainingRelatedFacts -= $methodRelatedEdgeCount;
        $relatedFactsConsumed += $methodRelatedEdgeCount;

        if ($methodEdgesTruncated) {
            $relatedFactsTruncated = true;
            $relatedFactTruncationStages['methodEdges'] = true;
        }

        foreach ($methodRelatedRows as $methodRelatedRow) {
            $hit = $methodHitsById[$methodRelatedRow['source']];
            $edge = $methodRelatedRow['edge'];
            $pathConfidence = (float) $hit['confidence'];
            $edgeConfidence = round($pathConfidence * (float) ($edge['confidence'] ?? 1.0), 4);

            if ($edgeConfidence < $minConfidence) {
                continue;
            }

            $related = $this->index->node($edge['to']);

            if ($edge['type'] === 'validates_with') {
                $row = $this->relatedNodeRow($edge['to'], $related, $hit, $edgeConfidence);

                if (! isset($formRequests[$edge['to']])
                    || $this->compareFlowRows($row, $formRequests[$edge['to']]) < 0) {
                    $formRequests[$edge['to']] = $row;
                }

                continue;
            }

            if ($edge['type'] === 'uses_model') {
                $row = $this->relatedNodeRow($edge['to'], $related, $hit, $edgeConfidence);

                if (! isset($models[$edge['to']])
                    || $this->compareFlowRows($row, $models[$edge['to']]) < 0) {
                    $models[$edge['to']] = $row;
                }

                continue;
            }

            if (in_array($edge['type'], ['reads', 'writes'], true)) {
                $key = $hit['id'].'|'.$edge['type'].'|'.$edge['to'];
                $details = $includeDataAccessDetails
                    ? $this->boundedEdgeDetails($edge)
                    : ['ops' => [], 'fields' => [], 'truncated' => false];
                $dataAccessDetailsTruncated = $dataAccessDetailsTruncated || $details['truncated'];
                $dataAccess[$key] = array_filter([
                    'table' => str_starts_with($edge['to'], 'table:') ? substr($edge['to'], 6) : $edge['to'],
                    'access' => $edge['type'] === 'reads' ? 'read' : 'write',
                    'method' => $hit['id'],
                    'depth' => $hit['depth'],
                    'confidence' => $edgeConfidence,
                    'ops' => $includeDataAccessDetails ? ($details['ops'] ?: null) : null,
                    'fields' => $includeDataAccessDetails ? ($details['fields'] ?: null) : null,
                    'detailsTruncated' => $details['truncated'] ?: null,
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
            $dispatchAnalysis = $this->executionSemantics->dispatchAnalysis($edge, $related);
            $dispatchKinds = $dispatchAnalysis['kinds'];
            $executionMetadataTruncated = $executionMetadataTruncated || $dispatchAnalysis['truncated'];

            if ($includeDispatchDetails && in_array('event', $dispatchKinds, true)) {
                // A listens_to edge records registration, not execution. A
                // queued listener whose shouldQueue() returned false still
                // has that edge, while EventFlow deliberately omits its
                // handled_by edge. Build this compact execution summary from
                // the same active bridge used by flow traversal.
                $listenersTruncated = false;
                $listenerEdges = $this->index->rankedEdgesFrom(
                    $edge['to'],
                    ['handled_by'],
                    $remainingRelatedFacts,
                    $listenersTruncated,
                );
                $listenerEdgeCount = count($listenerEdges);
                $remainingRelatedFacts -= $listenerEdgeCount;
                $relatedFactsConsumed += $listenerEdgeCount;

                if ($listenersTruncated) {
                    $relatedFactsTruncated = true;
                    $relatedFactTruncationStages['dispatchListeners'] = true;
                }

                foreach ($listenerEdges as $listenerEdge) {
                    if (($listenerEdge['metadata']['kind'] ?? null) !== 'listener') {
                        continue;
                    }

                    if (($listenerEdge['metadata']['causalExecutionProven'] ?? null) === false) {
                        continue;
                    }

                    $listener = $this->index->node($listenerEdge['to']);
                    $listeners[] = array_filter([
                        'id' => $listenerEdge['to'],
                        'confidence' => $listenerEdge['confidence'] ?? 1.0,
                        'queued' => $listenerEdge['metadata']['queued'] ?? null,
                        'afterCommit' => $listenerEdge['metadata']['afterCommit'] ?? null,
                        'file' => $listener['file'] ?? null,
                        'line' => $listener['line'] ?? null,
                    ], static fn ($value): bool => $value !== null);
                }
            }

            usort($listeners, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
            $dispatchMetadata = $edge['metadata'] ?? [];
            $occurrences = is_array($dispatchMetadata['dispatchOccurrences'] ?? null)
                ? $dispatchMetadata['dispatchOccurrences']
                : [];
            $hasOccurrenceEvidence = $occurrences !== [];
            $dispatch = [
                'id' => $edge['to'],
                'type' => count($dispatchKinds) === 1 ? $dispatchKinds[0] : ($related['type'] ?? null),
                'kinds' => count($dispatchKinds) > 1 ? $dispatchKinds : null,
                'from' => $hit['id'],
                'depth' => $hit['depth'],
                'confidence' => $edgeConfidence,
                'causalExecutionProven' => $dispatchKinds !== []
                    && $dispatchAnalysis['causalExecutionProven'],
            ];

            if ($includeDispatchDetails) {
                $dispatch += [
                    'mode' => $dispatchMetadata['dispatchMode'] ?? null,
                    'modes' => $dispatchMetadata['dispatchModes'] ?? null,
                    'method' => $dispatchMetadata['dispatchMethod'] ?? null,
                    'methods' => $dispatchMetadata['dispatchMethods'] ?? null,
                    'afterCommit' => $dispatchMetadata['afterCommit'] ?? null,
                    'afterCommitValues' => $dispatchMetadata['afterCommitValues'] ?? null,
                    'connection' => $dispatchMetadata['connection']
                        ?? (! $hasOccurrenceEvidence ? ($related['metadata']['connection'] ?? null) : null),
                    'connections' => $dispatchMetadata['connections'] ?? null,
                    'queue' => $dispatchMetadata['queue']
                        ?? (! $hasOccurrenceEvidence ? ($related['metadata']['queue'] ?? null) : null),
                    'queues' => $dispatchMetadata['queues'] ?? null,
                    'occurrences' => $hasOccurrenceEvidence
                        ? array_slice(
                            array_values($occurrences),
                            0,
                            LaravelExecutionSemantics::MAX_DISPATCH_OCCURRENCES,
                        )
                        : null,
                    'metadataTruncated' => $dispatchAnalysis['truncated'] ?: null,
                    'listeners' => $listeners ?: null,
                    'file' => $related['file'] ?? null,
                    'line' => $related['line'] ?? null,
                ];
            }

            $dispatches[$hit['id'].'|'.$edge['to']] = array_filter(
                $dispatch,
                static fn ($value): bool => $value !== null,
            );
        }

        $frontendConsumers = [];
        $tests = [];
        $analysisWarnings = $includeAnalysisWarnings
            ? $this->flowAnalysisWarnings(array_keys($methods))
            : [];

        if ($includeAnalysisWarnings && $executionMetadataTruncated) {
            $analysisWarnings[] = [
                'reason' => 'dispatch_metadata_limit',
                'message' => 'Laravel dispatch role metadata was bounded; omitted occurrences may hide additional causal handlers.',
            ];
        }

        if ($route !== null) {
            $incomingEdgeTypes = [];

            if (isset($selectedRelatedGroups['frontendConsumers'])) {
                $incomingEdgeTypes[] = 'consumes_route';
            }

            if (isset($selectedRelatedGroups['tests'])) {
                $incomingEdgeTypes[] = 'tests_route';
            }

            $incomingEdgesTruncated = false;
            $incomingEdges = $incomingEdgeTypes === []
                ? []
                : $this->index->rankedEdgesTo(
                    $route['id'],
                    $incomingEdgeTypes,
                    $remainingRelatedFacts,
                    $incomingEdgesTruncated,
                );
            $incomingEdgeCount = count($incomingEdges);
            $remainingRelatedFacts -= $incomingEdgeCount;
            $relatedFactsConsumed += $incomingEdgeCount;

            if ($incomingEdgesTruncated) {
                $relatedFactsTruncated = true;
                $relatedFactTruncationStages['routeIncoming'] = true;
            }

            foreach ($incomingEdges as $edge) {
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

            if (isset($selectedRelatedGroups['tests']) && $tests === [] && ! $incomingEdgesTruncated) {
                $analysisWarnings[] = [
                    'reason' => 'route_has_no_mapped_tests',
                    'route' => $route['id'],
                    'message' => 'No test method was statically mapped to this route; dynamic or indirect tests may still exist.',
                ];
            }
        }

        $availableRelatedGroups = [
            'formRequests' => array_values($formRequests),
            'models' => array_values($models),
            'dataAccess' => array_values($dataAccess),
            'dispatches' => array_values($dispatches),
            'sideEffects' => array_values($sideEffects),
            'authorization' => array_values($authorization),
            'frontendConsumers' => array_values($frontendConsumers),
            'tests' => array_values($tests),
        ];
        $groups = ['methods' => array_values($methods)];

        foreach (array_keys($selectedRelatedGroups) as $group) {
            $groups[$group] = $availableRelatedGroups[$group];
        }

        $truncatedGroups = [];

        foreach ($groups as $key => $group) {
            usort($group, fn (array $a, array $b): int => $this->compareFlowRows($a, $b));

            $groupLimit = $key === 'methods' ? $limit : $relatedLimit;

            if (count($group) > $groupLimit) {
                $truncated = true;
                $truncatedGroups[] = $key;
                $group = array_slice($group, 0, $groupLimit);
            }

            $groups[$key] = $group;
        }

        if ($relatedFactsTruncated) {
            $truncated = true;
        }

        if ($executionMetadataTruncated) {
            $truncated = true;
        }

        if ($dataAccessDetailsTruncated) {
            $truncated = true;
        }

        $truncation = array_filter([
            'routeActionSelection' => $routeActionSelectionTruncated ?: null,
            'executionTraversal' => $executionTraversalTruncated ?: null,
            'executionMetadata' => $executionMetadataTruncated ?: null,
            'dataAccessDetails' => $dataAccessDetailsTruncated ?: null,
            'actionReservation' => $actionReservationTruncated ?: null,
            'relatedFacts' => $relatedFactsTruncated ? [
                'maxRelatedFacts' => $maxRelatedFacts,
                'consumed' => $relatedFactsConsumed,
                'stages' => array_keys($relatedFactTruncationStages),
            ] : null,
            'groups' => $truncatedGroups ?: null,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->envelope('flow-from', $target, array_filter([
            'route' => $route,
            'entrypoint' => $methods[$entrypointId],
            ...$groups,
            'analysisWarnings' => $analysisWarnings ?: null,
            'truncation' => $truncation ?: null,
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
        [$tableId, $columnId, $field] = $this->resolveAccessTarget($target);

        if ($columnId !== null && $field !== null) {
            return $this->columnAccess($query, $edgeType, $target, $tableId, $columnId, $field, $limit);
        }

        $truncated = false;
        $results = [];

        foreach ($this->index->edgesTo($tableId, [$edgeType]) as $edge) {
            $fromNode = $this->index->node($edge['from']);
            $details = $this->boundedEdgeDetails($edge);
            $truncated = $truncated || $details['truncated'];

            $results[] = array_filter([
                'id' => $edge['from'],
                'file' => $fromNode['file'] ?? null,
                'line' => $fromNode['line'] ?? null,
                'confidence' => $edge['confidence'] ?? null,
                'ops' => $details['ops'] ?: null,
                'fields' => $details['fields'] ?: null,
                'detailsTruncated' => $details['truncated'] ?: null,
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
    private function columnAccess(
        string $query,
        string $edgeType,
        string $target,
        string $tableId,
        string $columnId,
        string $field,
        int $limit,
    ): array {
        $matches = ['proven' => [], 'possible' => [], 'excluded' => []];
        $truncated = false;

        $tableName = str_starts_with($tableId, 'table:') ? substr($tableId, 6) : $tableId;

        foreach ($this->index->edgesTo($tableId, [$edgeType]) as $edge) {
            $classified = $this->boundedClassification(
                $this->fieldAccessClassifier->classifyForTask($edge, $field, $tableName),
            );
            $truncated = $truncated || $classified['detailsTruncated'];
            $fromNode = $this->index->node($edge['from']);
            $row = array_filter([
                'id' => $edge['from'],
                'file' => $fromNode['file'] ?? null,
                'line' => $fromNode['line'] ?? null,
                'confidence' => $edge['confidence'] ?? null,
                'match' => $classified['match'],
                'ops' => $classified['ops'] ?: null,
                'fields' => $classified['fields'] ?: null,
                'provenOps' => $classified['provenOps'] ?: null,
                'possibleOps' => $classified['possibleOps'] ?: null,
                'excludedOps' => $classified['excludedOps'] ?: null,
                'detailsTruncated' => $classified['detailsTruncated'] ?: null,
            ], static fn (mixed $value): bool => $value !== null);
            $matches[$classified['match']][] = $row;
        }

        foreach ($matches as &$group) {
            usort($group, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        }
        unset($group);

        $counts = array_map('count', $matches);

        foreach ($matches as &$group) {
            if (count($group) > $limit) {
                $truncated = true;
                $group = array_slice($group, 0, $limit);
            }
        }
        unset($group);

        $results = [...$matches['proven'], ...$matches['possible']];

        if (count($results) > $limit) {
            $truncated = true;
            $results = array_slice($results, 0, $limit);
        }

        return $this->envelope($query, $target, [
            'table' => $tableId,
            'column' => $columnId,
            'field' => $field,
            'results' => $results,
            'matches' => $matches,
            'counts' => $counts,
        ], $truncated);
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
        /** @var array<string, array<int, float>> $states */
        $states = [$seed => [0 => 1.0]];
        $queue = [[$seed, 0, 1.0]];
        $truncated = false;

        while ($queue !== []) {
            [$currentId, $depth, $confidence] = array_shift($queue);

            // A stronger/shallower state may have dominated this queued state
            // after it was enqueued. Do not expand stale work.
            if (($states[$currentId][$depth] ?? null) !== $confidence) {
                continue;
            }

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
                $pathConfidence = round($confidence * (float) ($edge['confidence'] ?? 1.0), 4);
                $candidateDepth = $depth + 1;

                if ($pathConfidence < $minConfidence) {
                    continue;
                }

                $dominated = false;

                foreach ($states[$fromId] ?? [] as $seenDepth => $seenConfidence) {
                    if ($seenDepth <= $candidateDepth && $seenConfidence >= $pathConfidence) {
                        $dominated = true;
                        break;
                    }
                }

                if ($dominated) {
                    continue;
                }

                // Keep the Pareto frontier of depth/confidence states. A
                // later stronger path must be re-expanded, while a shallower
                // weaker path may still matter because it has more hops left.
                foreach ($states[$fromId] ?? [] as $seenDepth => $seenConfidence) {
                    if ($seenDepth >= $candidateDepth && $seenConfidence <= $pathConfidence) {
                        unset($states[$fromId][$seenDepth]);
                    }
                }

                $states[$fromId][$candidateDepth] = $pathConfidence;
                ksort($states[$fromId]);
                $fromNode = $this->index->node($fromId);
                $group = match ($fromNode['type'] ?? null) {
                    'method' => 'methods',
                    'model' => 'models',
                    'form_request' => 'formRequests',
                    'route' => 'routes',
                    default => null,
                };
                $row = null;

                switch ($fromNode['type'] ?? null) {
                    case 'method':
                        $row = array_filter([
                            'id' => $fromId,
                            'depth' => $candidateDepth,
                            'confidence' => $pathConfidence,
                            'file' => $fromNode['file'] ?? null,
                            'line' => $fromNode['line'] ?? null,
                        ], static fn ($value): bool => $value !== null);
                        break;
                    case 'model':
                        $row = array_filter([
                            'id' => $fromId,
                            'depth' => $candidateDepth,
                            'confidence' => $pathConfidence,
                            'file' => $fromNode['file'] ?? null,
                            'line' => $fromNode['line'] ?? null,
                        ], static fn ($value): bool => $value !== null);
                        break;
                    case 'form_request':
                        $row = array_filter([
                            'id' => $fromId,
                            'depth' => $candidateDepth,
                            'confidence' => $pathConfidence,
                            'file' => $fromNode['file'] ?? null,
                            'line' => $fromNode['line'] ?? null,
                        ], static fn ($value): bool => $value !== null);
                        break;
                    case 'route':
                        $action = $this->index->edgesFrom($fromId, ['routes_to'])[0]['to'] ?? null;
                        $row = array_filter([
                            'id' => $fromId,
                            'label' => $fromNode['label'] ?? null,
                            'action' => $action,
                            'confidence' => $pathConfidence,
                            'depth' => $candidateDepth,
                        ], static fn ($value): bool => $value !== null);
                        break;
                }

                if ($group !== null && $row !== null) {
                    $existing = $groups[$group][$fromId] ?? null;

                    if ($existing === null
                        || $pathConfidence > (float) $existing['confidence']
                        || ($pathConfidence === (float) $existing['confidence']
                            && $candidateDepth < (int) $existing['depth'])) {
                        $groups[$group][$fromId] = $row;
                    }
                }

                $queue[] = [$fromId, $candidateDepth, $pathConfidence];
            }
        }

        foreach ($groups as $key => $group) {
            $group = array_values($group);
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

    /** @return array{0: string, 1: ?string, 2: ?string} */
    private function resolveAccessTarget(string $target): array
    {
        $id = $this->resolve($target);
        $node = $this->index->node($id);

        // Convenience hops: a model resolves to its table, a column to its owner.
        if (($node['type'] ?? null) === 'model') {
            $tableEdge = $this->index->edgesFrom($id, ['uses_table'])[0] ?? null;

            if ($tableEdge !== null) {
                return [$tableEdge['to'], null, null];
            }
        }

        if (($node['type'] ?? null) === 'column') {
            $tableEdge = $this->index->edgesTo($id, ['has_column'])[0] ?? null;

            if ($tableEdge !== null) {
                $table = $tableEdge['from'];
                $tableName = str_starts_with($table, 'table:') ? substr($table, 6) : $table;
                $prefix = $tableName.'.';
                $label = (string) ($node['label'] ?? '');
                $field = str_starts_with($label, $prefix)
                    ? substr($label, strlen($prefix))
                    : preg_replace('/^column:[^.]+\./', '', $id);

                return [$table, $id, is_string($field) && $field !== '' ? $field : null];
            }
        }

        if (($node['type'] ?? null) !== 'table') {
            throw new UnresolvedTargetException($target);
        }

        return [$id, null, null];
    }

    /**
     * @param array<int, string>|null $requestedGroups
     * @return array<string, true>
     */
    private function selectedFlowRelatedGroups(?array $requestedGroups): array
    {
        if ($requestedGroups === null) {
            return array_fill_keys(array_keys(self::FLOW_RELATED_GROUP_EDGE_TYPES), true);
        }

        $requested = [];

        foreach ($requestedGroups as $group) {
            if (! is_string($group) || ! array_key_exists($group, self::FLOW_RELATED_GROUP_EDGE_TYPES)) {
                $printable = is_scalar($group) ? (string) $group : get_debug_type($group);

                throw new \InvalidArgumentException("Unknown flow related group [{$printable}].");
            }

            $requested[$group] = true;
        }

        $selected = [];

        foreach (array_keys(self::FLOW_RELATED_GROUP_EDGE_TYPES) as $group) {
            if (isset($requested[$group])) {
                $selected[$group] = true;
            }
        }

        return $selected;
    }

    /**
     * @param array<string, true> $selectedGroups
     * @return array<int, string>
     */
    private function selectedFlowRelatedEdgeTypes(array $selectedGroups): array
    {
        $types = [];

        foreach ($selectedGroups as $group => $_selected) {
            if (in_array($group, ['frontendConsumers', 'tests'], true)) {
                continue;
            }

            foreach (self::FLOW_RELATED_GROUP_EDGE_TYPES[$group] as $type) {
                $types[$type] = $type;
            }
        }

        return array_values($types);
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
     * Rank compact flow rows before any group is sliced. Confidence is the
     * primary signal; depth and a complete stable identity break ties without
     * relying on insertion order or PHP's sort stability.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function compareFlowRows(array $a, array $b): int
    {
        $confidence = (float) ($b['confidence'] ?? 0.0) <=> (float) ($a['confidence'] ?? 0.0);

        if ($confidence !== 0) {
            return $confidence;
        }

        $depth = (int) ($a['depth'] ?? 0) <=> (int) ($b['depth'] ?? 0);

        if ($depth !== 0) {
            return $depth;
        }

        return $this->flowRowStableId($a) <=> $this->flowRowStableId($b);
    }

    /** @param array<string, mixed> $row */
    private function flowRowStableId(array $row): string
    {
        return implode('|', array_map(
            static fn (string $field): string => is_scalar($row[$field] ?? null)
                ? (string) $row[$field]
                : '',
            ['id', 'from', 'method', 'access', 'table', 'effect', 'policy'],
        ));
    }

    /**
     * @param array<string, mixed> $edge
     * @return array{ops: array<int, string>, fields: array<int, string>, truncated: bool}
     */
    private function boundedEdgeDetails(array $edge): array
    {
        $rawOperations = is_array($edge['metadata']['operations'] ?? null)
            ? $edge['metadata']['operations']
            : [];
        $operations = [];
        $fields = [];
        $truncated = count($rawOperations) > self::MAX_DETAIL_SOURCE_OPERATIONS;
        $examinedOperations = 0;
        $examinedFields = 0;

        foreach ($rawOperations as $operation) {
            if ($examinedOperations >= self::MAX_DETAIL_SOURCE_OPERATIONS) {
                break;
            }

            $examinedOperations++;

            if (! is_array($operation)) {
                $truncated = true;

                continue;
            }

            $name = $operation['operation'] ?? null;

            if (is_scalar($name) && $name !== null) {
                $name = (string) $name;

                if ($name !== '' && strlen($name) <= self::MAX_DETAIL_VALUE_BYTES) {
                    if (count($operations) < self::MAX_DETAIL_OPERATIONS || isset($operations[$name])) {
                        $operations[$name] = $name;
                    } else {
                        $truncated = true;
                    }
                } elseif ($name !== '') {
                    $truncated = true;
                }
            }

            $rawFields = is_array($operation['fields'] ?? null) ? $operation['fields'] : [];

            if (count($rawFields) > self::MAX_DETAIL_SOURCE_FIELDS - $examinedFields) {
                $truncated = true;
            }

            foreach ($rawFields as $field) {
                if ($examinedFields >= self::MAX_DETAIL_SOURCE_FIELDS) {
                    break 2;
                }

                $examinedFields++;

                if (! is_string($field) || $field === '') {
                    $truncated = true;

                    continue;
                }

                if (strlen($field) > self::MAX_DETAIL_VALUE_BYTES) {
                    $truncated = true;

                    continue;
                }

                if (count($fields) < self::MAX_DETAIL_FIELDS || isset($fields[$field])) {
                    $fields[$field] = $field;
                } else {
                    $truncated = true;
                }
            }
        }

        ksort($operations);
        ksort($fields);

        return [
            'ops' => array_values($operations),
            'fields' => array_values($fields),
            'truncated' => $truncated,
        ];
    }

    /**
     * @param array{match: string, ops: array<int, string>, fields: array<int, string>, provenOps: array<int, string>, possibleOps: array<int, string>, excludedOps: array<int, string>, truncated: bool} $classified
     * @return array{match: string, ops: array<int, string>, fields: array<int, string>, provenOps: array<int, string>, possibleOps: array<int, string>, excludedOps: array<int, string>, detailsTruncated: bool}
     */
    private function boundedClassification(array $classified): array
    {
        $truncated = $classified['truncated'];

        foreach (['ops', 'provenOps', 'possibleOps', 'excludedOps'] as $field) {
            $classified[$field] = $this->boundedDetailStrings(
                $classified[$field],
                self::MAX_DETAIL_OPERATIONS,
                $truncated,
            );
        }

        $classified['fields'] = $this->boundedDetailStrings(
            $classified['fields'],
            self::MAX_DETAIL_FIELDS,
            $truncated,
        );
        unset($classified['truncated']);
        $classified['detailsTruncated'] = $truncated;

        return $classified;
    }

    /** @param array<int, string> $values @return array<int, string> */
    private function boundedDetailStrings(array $values, int $limit, bool &$truncated): array
    {
        $bounded = [];

        foreach ($values as $value) {
            if (count($bounded) >= $limit) {
                $truncated = true;

                break;
            }

            if (strlen($value) > self::MAX_DETAIL_VALUE_BYTES) {
                $truncated = true;

                continue;
            }

            $bounded[] = $value;
        }

        return $bounded;
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
    private function boundedEdgeList(
        array $edges,
        string $endpoint,
        int $limit,
        bool &$truncated,
        bool $full = false,
    ): array
    {
        $results = array_map(
            static fn (array $edge): array => array_filter([
                $endpoint => $edge[$endpoint],
                'type' => $edge['type'],
                'confidence' => $edge['confidence'] ?? 1.0,
                'metadata' => $full && is_array($edge['metadata'] ?? null)
                    ? $edge['metadata']
                    : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
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

        $meta = $this->index->meta();
        $generation = $meta['generation'] ?? null;

        if (is_array($generation) && is_string($generation['id'] ?? null)) {
            $envelope['generation'] = $generation;
        } else {
            $envelope['generationUnavailable'] = [
                'reason' => 'legacy_json_without_immutable_generation',
                'message' => 'Run `php artisan appgraph:scan` before editing to capture a verifiable SQLite baseline.',
            ];
        }

        $generatedAt = $meta['generatedAt'] ?? null;

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

        return AgentPayloadLimiter::limit($envelope);
    }
}
