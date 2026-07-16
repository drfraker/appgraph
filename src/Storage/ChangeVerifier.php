<?php

namespace AppGraph\Storage;

use AppGraph\Support\ProjectPathNormalizer;
use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;

class ChangeVerifier
{
    private const MAX_TARGETS = 10;

    private const MAX_CHANGED_FILES = 50;

    private const MAX_DEPTH = 6;

    private const MAX_NEIGHBORHOOD_NODES = 10000;

    private const NEIGHBORHOOD_QUERY_CHUNK = 200;

    private const MAX_WARNING_DETAILS = 50;

    private const MAX_WARNING_DETAIL_BYTES = 65536;

    /** @var array<int, string> */
    private const EXECUTION_BRIDGE_EDGE_TYPES = [
        'routes_to',
        'passes_through',
        'calls',
        'validates_with',
        'framework_invokes',
        'dispatches',
        'handled_by',
        'listens_to',
        'observes',
        'resolves_to',
    ];

    /** @var array<int, string> */
    private const RISK_AND_COVERAGE_CATEGORIES = [
        'routes',
        'writes',
        'authorization',
        'queues',
        'tests',
    ];

    public function __construct(
        private GraphStore $store,
        private GenerationDiffer $differ,
        ?string $basePath = null,
    ) {
        if ($basePath === null) {
            try {
                $application = \Illuminate\Container\Container::getInstance();
                $basePath = method_exists($application, 'basePath')
                    ? $application->basePath()
                    : null;
            } catch (\Throwable) {
                $basePath = null;
            }
        }

        $this->projectPaths = new ProjectPathNormalizer(
            is_string($basePath) && $basePath !== '' ? $basePath : (getcwd() ?: '.'),
        );
    }

    private ProjectPathNormalizer $projectPaths;

    /**
     * Assess graph-visible evidence between an explicit pre-edit baseline and a
     * later scan. This intentionally never returns a safe/unsafe verdict.
     *
     * @param array<int, string> $targets
     * @param array<int, string> $changedFiles
     * @return array<string, mixed>
     */
    public function verify(
        string $baseline,
        string $generation = 'current',
        array $targets = [],
        array $changedFiles = [],
        int $depth = 4,
        float $minConfidence = 0.0,
        int $limit = 50,
        bool $confirmedRefreshReuse = false,
    ): array {
        $baseline = trim($baseline);

        if (strlen($baseline) > 19 || preg_match('/^[1-9]\d*$/D', $baseline) !== 1) {
            throw new InvalidArgumentException(
                'AppGraph change verification requires a captured numeric baseline generation id.'
            );
        }

        $generation = trim($generation);

        if ($generation !== 'current'
            && (strlen($generation) > 19 || preg_match('/^[1-9]\d*$/D', $generation) !== 1)) {
            throw new InvalidArgumentException(
                'AppGraph change verification comparison must be current or a positive numeric generation id.'
            );
        }

        if ($depth < 1 || $depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('AppGraph change verification depth must be between 1 and 6.');
        }

        if (! is_finite($minConfidence) || $minConfidence < 0.0 || $minConfidence > 1.0) {
            throw new InvalidArgumentException('AppGraph change verification minimum confidence must be between 0 and 1.');
        }

        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('AppGraph change verification limit must be between 1 and 200.');
        }

        $targets = $this->validatedList($targets, 'targets', self::MAX_TARGETS, 4096, false);
        $changedFiles = $this->validatedList(
            $changedFiles,
            'changed files',
            self::MAX_CHANGED_FILES,
            1024,
            true,
        );

        $bundle = $this->store->withSnapshot(function (PDO $pdo) use (
            $baseline,
            $generation,
            $targets,
            $changedFiles,
            $depth,
            $minConfidence,
            $limit,
        ): array {
            // Deliberately unfiltered: expected scope must never hide collateral
            // graph changes. The entire assessment shares this one read snapshot.
            $diff = $this->differ->diffSnapshot(
                $pdo,
                $baseline,
                $generation,
                [],
                $limit,
                allowSame: true,
            );
            $fromId = (string) $diff['from']['id'];
            $toId = (string) $diff['to']['id'];
            $fromRow = $this->store->resolveGenerationRow($pdo, $fromId);
            $toRow = $this->store->resolveGenerationRow($pdo, $toId);
            $sourceCoverage = $this->sourceCoverage(
                $pdo,
                (int) $fromRow['id'],
                (int) $toRow['id'],
                $changedFiles,
                $limit,
            );
            $targetScope = $this->targetScope(
                $pdo,
                (int) $fromRow['id'],
                (int) $toRow['id'],
                $targets,
                $changedFiles,
                $depth,
                $minConfidence,
                $diff['counts']['overall'],
            );
            $warnings = $this->warningEvidence($fromRow, $toRow, $limit);

            return [
                'diff' => $diff,
                'sourceCoverage' => $sourceCoverage,
                'targetScope' => $targetScope,
                'scannerWarnings' => $warnings,
            ];
        });
        $diff = $bundle['diff'];
        $evidence = [
            'sourceCoverage' => $bundle['sourceCoverage'],
            'targetScope' => $bundle['targetScope'],
            'scannerWarnings' => $bundle['scannerWarnings'],
        ];

        $unmappedChangedFiles = array_values(array_map(
            static fn (array $file): string => (string) $file['path'],
            array_filter(
                $evidence['sourceCoverage']['requestedFiles'] ?? [],
                static fn (array $file): bool => in_array(
                    $file['manifestStatus'] ?? null,
                    ['added', 'removed', 'changed'],
                    true,
                )
                    && (int) ($file['nodeFacts']['before'] ?? 0) === 0
                    && (int) ($file['nodeFacts']['after'] ?? 0) === 0,
            ),
        ));

        if ($unmappedChangedFiles !== []) {
            // File-only/configuration surfaces can be real intent but have no
            // graph node from which to derive a neighborhood. Do not label all
            // remaining graph changes as exact collateral in that case.
            $evidence['targetScope']['unmappedChangedFiles'] = $unmappedChangedFiles;
            $evidence['targetScope']['changesInScopeExact'] = false;
            $evidence['targetScope']['collateralChanges'] = null;
        }

        $uncertainties = $this->uncertainties(
            $diff,
            $evidence,
            $targets,
            $changedFiles,
            $confirmedRefreshReuse,
        );
        $overallChanges = (int) $diff['counts']['overall']['total'];
        $inScope = (int) ($evidence['targetScope']['changesInScope']['total'] ?? 0);
        $collateral = $evidence['targetScope']['collateralChanges'] ?? null;
        $scopeSpecified = $targets !== [] || $changedFiles !== [];
        $status = match (true) {
            ($diff['sameGeneration'] ?? false) === true && $confirmedRefreshReuse => 'no_new_generation_published',
            ($diff['sameGeneration'] ?? false) === true => 'same_generation_selected',
            $overallChanges === 0 => 'no_graph_delta_observed',
            ! $scopeSpecified => 'graph_changes_observed',
            $inScope > 0 && is_int($collateral) && $collateral > 0 => 'expected_and_collateral_graph_changes_observed',
            $inScope > 0 => 'expected_graph_changes_observed',
            is_int($collateral) && $collateral > 0 => 'collateral_graph_changes_observed',
            default => 'graph_changes_observed',
        };

        if ($uncertainties !== []) {
            $status .= '_with_uncertainties';
        }

        $resolvedTargets = [];

        foreach ($evidence['targetScope']['requestedTargets'] ?? [] as $target) {
            foreach (['beforeNode', 'afterNode'] as $field) {
                if (is_string($target[$field] ?? null)) {
                    $resolvedTargets[$target[$field]] = $target[$field];
                }
            }
        }

        $findings = $this->findings($diff, array_values($resolvedTargets), $changedFiles, $limit);

        return [
            'query' => 'verify-change',
            'assessment' => [
                'status' => $status,
                'basis' => 'static AppGraph generation evidence',
                'statement' => 'This assessment reports graph-visible structural evidence. It does not prove the change is safe, correct, complete, deployed, or covered by passing tests.',
            ],
            'baseline' => $diff['from'],
            'generation' => $diff['to'],
            'riskAndCoverageFacts' => $this->riskAndCoverageFacts($diff),
            'findings' => $findings['items'],
            'omittedFindings' => $findings['omitted'],
            'omittedFindingsIsLowerBound' => $findings['lowerBound'],
            'uninspectedRiskChanges' => $findings['uninspectedRiskChanges'],
            'findingsTruncated' => $findings['truncated'],
            'targetScope' => $evidence['targetScope'],
            'sourceCoverage' => $evidence['sourceCoverage'],
            'scannerWarnings' => $evidence['scannerWarnings'],
            'uncertainties' => $uncertainties,
            'diff' => $diff,
        ];
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, string>
     */
    private function validatedList(
        array $values,
        string $label,
        int $limit,
        int $maxCharacters,
        bool $normalizePath,
    ): array {
        if (count($values) > $limit) {
            throw new InvalidArgumentException("AppGraph change verification {$label} must contain at most {$limit} values.");
        }

        $normalized = [];

        foreach ($values as $value) {
            $value = is_string($value) ? trim($value) : null;
            $characters = is_string($value) ? preg_match_all('/./us', $value, $matches) : false;

            if (! is_string($value)
                || $value === ''
                || str_contains($value, "\0")
                || $characters === false
                || $characters > $maxCharacters
                || strlen($value) > $maxCharacters * 4) {
                throw new InvalidArgumentException("AppGraph change verification {$label} must contain nonblank strings no longer than {$maxCharacters} characters.");
            }

            if ($normalizePath) {
                $value = $this->projectPaths->normalize($value);

                if ($value === null) {
                    throw new InvalidArgumentException('AppGraph change verification changed files must resolve inside the project.');
                }
            }

            if (isset($normalized[$value])) {
                throw new InvalidArgumentException("AppGraph change verification {$label} must not contain duplicates.");
            }

            $normalized[$value] = $value;
        }

        ksort($normalized);

        return array_values($normalized);
    }

    /** @param array<string, mixed> $diff @return array<string, mixed> */
    private function riskAndCoverageFacts(array $diff): array
    {
        $facts = [];

        foreach (self::RISK_AND_COVERAGE_CATEGORIES as $category) {
            $counts = $diff['counts']['byCategory'][$category];
            $facts[$category] = [
                'observed' => $counts['total'] > 0,
                'counts' => $counts,
                'returnedDetails' => count($diff['changes'][$category]),
                'omittedDetails' => $diff['omitted'][$category],
            ];
        }

        return $facts;
    }

    /**
     * Turn bounded raw changes into cautious review prompts. Findings describe
     * static evidence only; they deliberately avoid claims such as "unsafe" or
     * "unauthorized" that the graph cannot establish.
     *
     * @param array<string, mixed> $diff
     * @param array<int, string> $targets
     * @param array<int, string> $changedFiles
     * @return array{items: array<int, array<string, mixed>>, omitted: int, lowerBound: bool, uninspectedRiskChanges: int, truncated: bool}
     */
    private function findings(array $diff, array $targets, array $changedFiles, int $limit): array
    {
        $items = [];

        foreach (self::RISK_AND_COVERAGE_CATEGORIES as $category) {
            foreach ($diff['changes'][$category] ?? [] as $change) {
                $finding = $this->findingForChange($change, $targets, $changedFiles);

                if ($finding !== null) {
                    $items[] = $finding;
                }
            }
        }

        $severityRank = ['high' => 0, 'medium' => 1, 'low' => 2, 'info' => 3];
        usort($items, static fn (array $left, array $right): int => [
            $severityRank[$left['severity']] ?? 4,
            $left['code'],
            $left['subject'],
        ] <=> [
            $severityRank[$right['severity']] ?? 4,
            $right['code'],
            $right['subject'],
        ]);
        $total = count($items);
        $uninspectedRiskChanges = array_sum(array_map(
            static fn (string $category): int => (int) ($diff['omitted'][$category] ?? 0),
            self::RISK_AND_COVERAGE_CATEGORIES,
        ));

        return [
            'items' => array_slice($items, 0, $limit),
            'omitted' => max(0, $total - $limit),
            'lowerBound' => $uninspectedRiskChanges > 0,
            'uninspectedRiskChanges' => $uninspectedRiskChanges,
            'truncated' => $total > $limit || $uninspectedRiskChanges > 0,
        ];
    }

    /**
     * @param array<string, mixed> $change
     * @param array<int, string> $targets
     * @param array<int, string> $changedFiles
     * @return array<string, mixed>|null
     */
    private function findingForChange(array $change, array $targets, array $changedFiles): ?array
    {
        $category = (string) ($change['category'] ?? '');
        $kind = (string) ($change['change'] ?? '');
        $factType = (string) ($change['factType'] ?? '');
        $identity = $change['identity'] ?? null;
        $subject = is_array($identity)
            ? sprintf('%s -%s-> %s', $identity['from'] ?? '?', $identity['type'] ?? '?', $identity['to'] ?? '?')
            : (string) $identity;
        $code = null;
        $severity = 'info';
        $message = null;

        if ($category === 'routes') {
            [$code, $severity, $message] = match (true) {
                $factType === 'route' && $kind === 'removed' => [
                    'route_removed', 'high',
                    'A route node disappeared from the static graph; confirm the HTTP surface changed intentionally.',
                ],
                $factType === 'routes_to' && $kind === 'removed' => [
                    'route_action_removed', 'high',
                    'A static route-to-action edge was removed; review the route action and replacement edge.',
                ],
                $factType === 'routes_to' && $kind === 'added' => [
                    'route_action_added', 'medium',
                    'A static route-to-action edge was added; review the new entry point and downstream flow.',
                ],
                $factType === 'passes_through' && $kind === 'removed' => [
                    'route_middleware_edge_removed', 'high',
                    'A statically observed route middleware edge was removed; verify the effective middleware pipeline.',
                ],
                $factType === 'passes_through' && $kind === 'added' => [
                    'route_middleware_edge_added', 'medium',
                    'A statically observed route middleware edge was added; verify ordering and the effective middleware pipeline.',
                ],
                $kind === 'changed' && ($change['semanticChanged'] ?? true) => [
                    'route_semantics_changed', 'medium',
                    'A route-related fact changed semantically; review its before/after details.',
                ],
                default => [null, 'info', null],
            };
        } elseif ($category === 'writes') {
            [$code, $severity, $message] = match (true) {
                $kind === 'added' => [
                    'write_surface_added', 'high',
                    'A statically observed write edge was added; review its target, operation, and field coverage.',
                ],
                $kind === 'changed' && ($change['semanticChanged'] ?? true) => [
                    'write_semantics_changed', 'high',
                    'A statically observed write changed semantically; review operation and field-level evidence.',
                ],
                $kind === 'removed' => [
                    'write_surface_removed', 'medium',
                    'A statically observed write edge was removed; confirm the behavior moved or was intentionally deleted.',
                ],
                default => [null, 'info', null],
            };
        } elseif ($category === 'authorization') {
            [$code, $severity, $message] = match (true) {
                $factType === 'authorizes_via' && $kind === 'removed' => [
                    'authorization_edge_removed', 'high',
                    'A statically observed authorization edge was removed; verify the intended authorization path in source.',
                ],
                $factType === 'authorizes_via' && $kind === 'changed' && ($change['semanticChanged'] ?? true) => [
                    'authorization_semantics_changed', 'high',
                    'A statically observed authorization edge changed semantically; review ability and policy evidence.',
                ],
                $factType === 'policy' && $kind === 'removed' => [
                    'policy_node_removed', 'high',
                    'A policy node disappeared from the static graph; verify affected authorization call sites.',
                ],
                $kind === 'added' => [
                    'authorization_fact_added', 'info',
                    'A static authorization fact was added; confirm it represents the intended policy path.',
                ],
                default => [null, 'info', null],
            };
        } elseif ($category === 'queues') {
            [$code, $severity, $message] = match (true) {
                in_array($factType, ['handled_by', 'listens_to', 'observes'], true) && $kind === 'removed' => [
                    'active_handler_edge_removed', 'high',
                    'A statically observed active handler edge was removed; review event/job/observer execution.',
                ],
                in_array($factType, ['event', 'job'], true) && $kind === 'removed' => [
                    'queue_or_event_node_removed', 'high',
                    'An event or job node disappeared from the static graph; review dispatch sites and active handlers.',
                ],
                $factType === 'dispatches' && $kind === 'changed' && ($change['semanticChanged'] ?? true) => [
                    'dispatch_semantics_changed', 'high',
                    'Dispatch semantics changed; review queue, connection, chain, and after-commit metadata.',
                ],
                $factType === 'dispatches' && $kind === 'removed' => [
                    'dispatch_edge_removed', 'medium',
                    'A static dispatch edge was removed; confirm the asynchronous or event flow changed intentionally.',
                ],
                $kind === 'added' => [
                    'queue_fact_added', 'medium',
                    'A queue/event/observer fact was added; review its execution and delivery semantics.',
                ],
                default => [null, 'info', null],
            };
        } elseif ($category === 'tests') {
            [$code, $severity, $message] = match (true) {
                $factType === 'tests_route' && $kind === 'removed' => [
                    'test_route_mapping_removed', 'high',
                    'A static test-to-route mapping was removed; verify relevant tests still exercise the route.',
                ],
                $factType === 'test' && $kind === 'removed' => [
                    'test_node_removed', 'medium',
                    'A mapped test node disappeared from the graph; review replacement coverage.',
                ],
                default => [null, 'info', null],
            };
        }

        if ($code === null || $message === null) {
            return null;
        }

        $before = is_array($change['before'] ?? null) ? $change['before'] : [];
        $after = is_array($change['after'] ?? null) ? $change['after'] : [];
        $files = array_values(array_filter([
            is_string($before['file'] ?? null) ? str_replace('\\', '/', $before['file']) : null,
            is_string($after['file'] ?? null) ? str_replace('\\', '/', $after['file']) : null,
        ]));
        $identityNodes = is_array($identity)
            ? [(string) ($identity['from'] ?? ''), (string) ($identity['to'] ?? '')]
            : [$subject];
        $scope = array_intersect($files, $changedFiles) !== []
            || array_intersect($identityNodes, $targets) !== []
                ? 'expected'
                : ($targets !== [] || $changedFiles !== [] ? 'outside_or_indirect' : 'unspecified');

        return [
            'code' => $code,
            'severity' => $severity,
            'category' => $category,
            'subject' => $subject,
            'scope' => $scope,
            'message' => $message,
            'evidence' => array_filter([
                'change' => $kind,
                'factType' => $factType,
                'semanticChanged' => $change['semanticChanged'] ?? null,
                'changedFields' => $change['changedFields'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
        ];
    }

    /**
     * @param array<int, string> $changedFiles
     * @return array<string, mixed>
     */
    private function sourceCoverage(
        PDO $pdo,
        int $fromId,
        int $toId,
        array $changedFiles,
        int $limit,
    ): array {
        $counts = $this->emptyCount();
        $countStatement = $pdo->prepare($this->fileChangesCte().<<<'SQL'

            SELECT change_kind, COUNT(*) AS total
            FROM file_changes
            GROUP BY change_kind
            ORDER BY change_kind
            SQL);
        $countStatement->execute(['from_generation' => $fromId, 'to_generation' => $toId]);

        foreach ($countStatement->fetchAll() as $row) {
            $kind = (string) $row['change_kind'];
            $total = (int) $row['total'];
            $counts[$kind] += $total;
            $counts['total'] += $total;
        }

        $detailStatement = $pdo->prepare($this->fileChangesCte().<<<'SQL'

            SELECT path, change_kind
            FROM file_changes
            ORDER BY path, change_kind
            LIMIT :detail_limit
            SQL);
        $detailStatement->bindValue('from_generation', $fromId, PDO::PARAM_INT);
        $detailStatement->bindValue('to_generation', $toId, PDO::PARAM_INT);
        $detailStatement->bindValue('detail_limit', $limit, PDO::PARAM_INT);
        $detailStatement->execute();
        $files = array_map(
            static fn (array $row): array => [
                'path' => (string) $row['path'],
                'change' => (string) $row['change_kind'],
            ],
            $detailStatement->fetchAll(),
        );
        $fileHashes = $pdo->prepare(<<<'SQL'
            SELECT
                (SELECT content_hash FROM generation_files WHERE generation_id = :before AND path = :path) AS before_hash,
                (SELECT content_hash FROM generation_files WHERE generation_id = :after AND path = :path) AS after_hash
            SQL);
        $nodeCounts = $pdo->prepare(<<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM generation_nodes WHERE generation_id = :before AND file = :path) AS before_nodes,
                (SELECT COUNT(*) FROM generation_nodes WHERE generation_id = :after AND file = :path) AS after_nodes
            SQL);
        $nodeChanges = $pdo->prepare($this->fileNodeChangesSql());
        $requested = [];
        $recordedRequestedChanges = 0;

        foreach ($changedFiles as $path) {
            $fileHashes->execute(['before' => $fromId, 'after' => $toId, 'path' => $path]);
            $hashes = $fileHashes->fetch();
            $beforeHash = is_array($hashes) ? $hashes['before_hash'] : null;
            $afterHash = is_array($hashes) ? $hashes['after_hash'] : null;
            $status = $this->fileStatus($beforeHash, $afterHash);
            $nodeCounts->execute(['before' => $fromId, 'after' => $toId, 'path' => $path]);
            $nodes = $nodeCounts->fetch();
            $nodeChanges->execute([
                'before_generation' => $fromId,
                'after_generation' => $toId,
                'path' => $path,
            ]);
            $directNodeChanges = (int) $nodeChanges->fetchColumn();

            if (in_array($status, ['added', 'removed', 'changed'], true)) {
                $recordedRequestedChanges++;
            }

            $requested[] = [
                'path' => $path,
                'manifestStatus' => $status,
                'nodeFacts' => [
                    'before' => (int) ($nodes['before_nodes'] ?? 0),
                    'after' => (int) ($nodes['after_nodes'] ?? 0),
                    'changed' => $directNodeChanges,
                ],
            ];
        }

        return [
            'counts' => $counts,
            'files' => $files,
            'omittedFiles' => max(0, $counts['total'] - count($files)),
            'filesTruncated' => $counts['total'] > count($files),
            'requestedFiles' => $requested,
            'recordedRequestedChanges' => $recordedRequestedChanges,
            'unrequestedSourceChanges' => max(0, $counts['total'] - $recordedRequestedChanges),
        ];
    }

    private function fileStatus(mixed $beforeHash, mixed $afterHash): string
    {
        if ($beforeHash === null && $afterHash === null) {
            return 'not_recorded';
        }

        if ($beforeHash === null) {
            return 'added';
        }

        if ($afterHash === null) {
            return 'removed';
        }

        return $beforeHash === $afterHash ? 'unchanged' : 'changed';
    }

    /**
     * @param array<int, string> $targets
     * @param array{added: int, removed: int, changed: int, total: int} $overallChanges
     * @return array<string, mixed>
     */
    private function targetScope(
        PDO $pdo,
        int $fromId,
        int $toId,
        array $targets,
        array $changedFiles,
        int $depth,
        float $minConfidence,
        array $overallChanges,
    ): array {
        if ($targets === [] && $changedFiles === []) {
            return [
                'requestedTargets' => [],
                'mode' => 'all_graph_changes',
                'depth' => $depth,
                'minConfidence' => $minConfidence,
                'changesInScope' => $overallChanges,
                'changesInScopeExact' => true,
                'collateralChanges' => 0,
            ];
        }

        $targetRows = [];
        $beforeSeeds = [];
        $afterSeeds = [];

        foreach ($targets as $target) {
            $beforeResolution = $this->resolveTarget($pdo, $fromId, $target);
            $afterResolution = $this->resolveTarget($pdo, $toId, $target);
            $beforeId = $beforeResolution['id'];
            $afterId = $afterResolution['id'];
            $beforeExists = $beforeId !== null;
            $afterExists = $afterId !== null;

            if ($beforeExists) {
                $beforeSeeds[$beforeId] = $beforeId;
            }

            if ($afterExists) {
                $afterSeeds[$afterId] = $afterId;
            }

            $targetRows[] = [
                'target' => $target,
                'before' => $beforeExists,
                'after' => $afterExists,
                'beforeResolution' => $beforeResolution['status'],
                'afterResolution' => $afterResolution['status'],
                'beforeNode' => $beforeId,
                'afterNode' => $afterId,
                'candidates' => array_slice(array_values(array_unique([
                    ...$beforeResolution['candidates'],
                    ...$afterResolution['candidates'],
                ])), 0, 5),
                'candidatesTruncated' => $beforeResolution['candidatesTruncated']
                    || $afterResolution['candidatesTruncated']
                    || count(array_unique([
                        ...$beforeResolution['candidates'],
                        ...$afterResolution['candidates'],
                    ])) > 5,
                'directChange' => match (true) {
                    ! $beforeExists && $afterExists => 'added',
                    $beforeExists && ! $afterExists => 'removed',
                    $beforeExists && $afterExists && $beforeId !== $afterId => 'resolved_to_different_nodes',
                    $beforeExists && $afterExists
                        && (int) $beforeResolution['objectId'] !== (int) $afterResolution['objectId'] => 'changed',
                    $beforeExists && $afterExists => 'unchanged',
                    $beforeResolution['status'] === 'ambiguous'
                        || $afterResolution['status'] === 'ambiguous' => 'ambiguous',
                    default => 'unresolved',
                },
            ];
        }

        $beforeFileSeeds = $this->nodesForFiles($pdo, $fromId, $changedFiles);
        $afterFileSeeds = $this->nodesForFiles($pdo, $toId, $changedFiles);
        $beforeSeeds += $beforeFileSeeds['nodes'];
        $afterSeeds += $afterFileSeeds['nodes'];
        $before = $this->neighborhood($pdo, $fromId, array_values($beforeSeeds), $depth, $minConfidence);
        $after = $this->neighborhood($pdo, $toId, array_values($afterSeeds), $depth, $minConfidence);
        $nodes = $before['nodes'] + $after['nodes'];
        $explicitSeeds = array_fill_keys(array_values($beforeSeeds + $afterSeeds), true);
        $scopeCounts = $this->changesTouching(
            $pdo,
            $fromId,
            $toId,
            $nodes,
            $explicitSeeds,
        );
        $targetResolutionIncomplete = (bool) array_filter(
            $targetRows,
            static fn (array $target): bool => in_array(
                $target['beforeResolution'],
                ['unresolved', 'ambiguous'],
                true,
            ) || in_array(
                $target['afterResolution'],
                ['unresolved', 'ambiguous'],
                true,
            ),
        );
        $neighborhoodTruncated = $before['truncated']
            || $after['truncated']
            || $beforeFileSeeds['truncated']
            || $afterFileSeeds['truncated'];
        $exact = ! $targetResolutionIncomplete
            && ! $before['truncated']
            && ! $after['truncated']
            && ! $beforeFileSeeds['truncated']
            && ! $afterFileSeeds['truncated'];

        return [
            'requestedTargets' => $targetRows,
            'mode' => match (true) {
                $targets !== [] && $changedFiles !== [] => 'targets_and_changed_files',
                $targets !== [] => 'target_neighborhood',
                default => 'changed_file_neighborhood',
            },
            'depth' => $depth,
            'minConfidence' => $minConfidence,
            'fileSeedNodes' => [
                'before' => count($beforeFileSeeds['nodes']),
                'after' => count($afterFileSeeds['nodes']),
                'truncated' => $beforeFileSeeds['truncated'] || $afterFileSeeds['truncated'],
            ],
            'neighborhoodNodes' => [
                'before' => count($before['nodes']),
                'after' => count($after['nodes']),
                'union' => count($nodes),
            ],
            'targetResolutionIncomplete' => $targetResolutionIncomplete,
            'neighborhoodTruncated' => $neighborhoodTruncated,
            'changesInScope' => $scopeCounts,
            'changesInScopeExact' => $exact,
            'collateralChanges' => $exact
                ? max(0, (int) $overallChanges['total'] - $scopeCounts['total'])
                : null,
        ];
    }

    /**
     * Resolve an exact id first, then a unique exact label/name or familiar id
     * suffix. Ambiguous matches remain unresolved and are surfaced to agents.
     *
     * @return array{id: string|null, objectId: int|null, status: string, candidates: array<int, string>, candidatesTruncated: bool}
     */
    private function resolveTarget(PDO $pdo, int $generation, string $target): array
    {
        $literal = $this->resolveLiteralTarget($pdo, $generation, $target);

        if ($literal['status'] !== 'unresolved'
            || substr_count($target, '@') !== 1
            || str_contains($target, '::')) {
            return $literal;
        }

        // Laravel route names and URIs may legitimately contain "@". Only
        // interpret Controller@method after the caller's literal target has no
        // id, name, label, or familiar-suffix match of its own.
        return $this->resolveLiteralTarget(
            $pdo,
            $generation,
            str_replace('@', '::', $target),
        );
    }

    /**
     * @return array{id: string|null, objectId: int|null, status: string, candidates: array<int, string>, candidatesTruncated: bool}
     */
    private function resolveLiteralTarget(PDO $pdo, int $generation, string $target): array
    {

        $exact = $pdo->prepare(<<<'SQL'
            SELECT node_id, object_id
            FROM generation_nodes
            WHERE generation_id = :generation AND node_id = :target
            LIMIT 1
            SQL);
        $exact->execute(['generation' => $generation, 'target' => $target]);
        $row = $exact->fetch();

        if (is_array($row)) {
            return [
                'id' => (string) $row['node_id'],
                'objectId' => (int) $row['object_id'],
                'status' => 'exact',
                'candidates' => [],
                'candidatesTruncated' => false,
            ];
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $target);
        $candidates = $pdo->prepare(<<<'SQL'
            SELECT node_id, object_id,
                CASE
                    WHEN lower(name) = lower(:target) THEN 0
                    WHEN lower(label) = lower(:target) THEN 1
                    WHEN lower(node_id) = lower(:target) THEN 2
                    WHEN lower(node_id) LIKE lower(:class_suffix) ESCAPE '\' THEN 3
                    WHEN lower(node_id) LIKE lower(:method_suffix) ESCAPE '\' THEN 4
                    ELSE 5
                END AS rank
            FROM generation_nodes
            WHERE generation_id = :generation
              AND (
                lower(name) = lower(:target)
                OR lower(label) = lower(:target)
                OR lower(node_id) = lower(:target)
                OR lower(node_id) LIKE lower(:class_suffix) ESCAPE '\'
                OR lower(node_id) LIKE lower(:method_suffix) ESCAPE '\'
              )
            ORDER BY rank, node_id
            LIMIT 6
            SQL);
        $candidates->execute([
            'generation' => $generation,
            'target' => $target,
            'class_suffix' => '%\\'.$escaped,
            'method_suffix' => '%::'.$escaped,
        ]);
        $rows = $candidates->fetchAll();

        $bestRank = $rows !== [] ? (int) $rows[0]['rank'] : null;
        $best = $bestRank !== null
            ? array_values(array_filter($rows, static fn (array $row): bool => (int) $row['rank'] === $bestRank))
            : [];

        if (count($best) === 1) {
            return [
                'id' => (string) $best[0]['node_id'],
                'objectId' => (int) $best[0]['object_id'],
                'status' => 'resolved',
                'candidates' => [],
                'candidatesTruncated' => false,
            ];
        }

        return [
            'id' => null,
            'objectId' => null,
            'status' => $rows === [] ? 'unresolved' : 'ambiguous',
            'candidates' => array_slice(array_column($best !== [] ? $best : $rows, 'node_id'), 0, 5),
            'candidatesTruncated' => count($best !== [] ? $best : $rows) > 5,
        ];
    }

    /** @param array<int, string> $files @return array{nodes: array<string, string>, truncated: bool} */
    private function nodesForFiles(PDO $pdo, int $generation, array $files): array
    {
        if ($files === []) {
            return ['nodes' => [], 'truncated' => false];
        }

        $statement = $pdo->prepare(<<<'SQL'
            SELECT node_id
            FROM generation_nodes
            WHERE generation_id = :generation AND file = :file
            ORDER BY node_id
            LIMIT :limit
            SQL);
        $nodes = [];
        $truncated = false;

        foreach ($files as $file) {
            $remaining = self::MAX_NEIGHBORHOOD_NODES - count($nodes);

            if ($remaining <= 0) {
                $truncated = true;
                break;
            }

            $statement->bindValue('generation', $generation, PDO::PARAM_INT);
            $statement->bindValue('file', $file);
            $statement->bindValue('limit', $remaining + 1, PDO::PARAM_INT);
            $statement->execute();
            $rows = $statement->fetchAll(PDO::FETCH_COLUMN);

            if (count($rows) > $remaining) {
                $rows = array_slice($rows, 0, $remaining);
                $truncated = true;
            }

            foreach ($rows as $node) {
                $nodes[(string) $node] = (string) $node;
            }
        }

        ksort($nodes);

        return ['nodes' => $nodes, 'truncated' => $truncated];
    }

    /**
     * @param array<int, string> $seeds
     * @return array{nodes: array<string, true>, truncated: bool}
     */
    private function neighborhood(
        PDO $pdo,
        int $generation,
        array $seeds,
        int $depth,
        float $minConfidence,
    ): array {
        $reachable = array_fill_keys($seeds, true);
        $seedSet = $reachable;
        $frontier = array_fill_keys($seeds, 1.0);
        $bestExpandableConfidence = $frontier;
        $truncated = false;

        for ($level = 0; $level < $depth && $frontier !== []; $level++) {
            $expandable = [];
            $discovered = [];

            foreach (array_chunk(array_keys($frontier), self::NEIGHBORHOOD_QUERY_CHUNK) as $chunk) {
                $remaining = self::MAX_NEIGHBORHOOD_NODES - count($reachable);

                if ($remaining <= 0) {
                    $truncated = true;

                    break 2;
                }

                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $statement = $pdo->prepare(<<<SQL
                    SELECT edge_from, edge_to, type, confidence
                    FROM generation_edges
                    WHERE generation_id = ?
                      AND confidence >= ?
                      AND (edge_from IN ({$placeholders}) OR edge_to IN ({$placeholders}))
                    ORDER BY edge_from, type, edge_to
                    LIMIT ?
                    SQL);
                $statement->execute([
                    $generation,
                    $minConfidence,
                    ...$chunk,
                    ...$chunk,
                    self::MAX_NEIGHBORHOOD_NODES + 1,
                ]);
                $rows = $statement->fetchAll();

                if (count($rows) > self::MAX_NEIGHBORHOOD_NODES) {
                    $rows = array_slice($rows, 0, self::MAX_NEIGHBORHOOD_NODES);
                    $truncated = true;
                }

                foreach ($rows as $row) {
                    $confidence = (float) $row['confidence'];
                    $from = (string) $row['edge_from'];
                    $to = (string) $row['edge_to'];
                    $bridge = in_array((string) $row['type'], self::EXECUTION_BRIDGE_EDGE_TYPES, true);

                    if (isset($frontier[$from])) {
                        $candidate = $frontier[$from] * $confidence;

                        if ($candidate >= $minConfidence) {
                            $discovered[$to] = max((float) ($discovered[$to] ?? 0.0), $candidate);

                            if ($bridge) {
                                $expandable[$to] = max((float) ($expandable[$to] ?? 0.0), $candidate);
                            }
                        }
                    }

                    // Reverse traversal is reserved for causal execution edges,
                    // except when the related/resource node was an explicit seed
                    // (for example, verifying a table target). This prevents a
                    // shared table from connecting unrelated route workflows.
                    if (isset($frontier[$to]) && ($bridge || isset($seedSet[$to]))) {
                        $candidate = $frontier[$to] * $confidence;

                        if ($candidate >= $minConfidence) {
                            $discovered[$from] = max((float) ($discovered[$from] ?? 0.0), $candidate);
                            $expandable[$from] = max((float) ($expandable[$from] ?? 0.0), $candidate);
                        }
                    }
                }

                if ($truncated) {
                    break;
                }
            }

            ksort($discovered);

            foreach ($discovered as $node => $_confidence) {
                if (isset($reachable[$node])) {
                    continue;
                }

                if (count($reachable) >= self::MAX_NEIGHBORHOOD_NODES) {
                    $truncated = true;

                    break;
                }

                $reachable[$node] = true;
            }

            if ($truncated) {
                break;
            }

            // A first visit can arrive through a weak path that is insufficient
            // to cross the next edge. Re-expand a node when a later path gives
            // it a strictly stronger expandable confidence, while preserving
            // non-bridge forward edges as terminal facts.
            ksort($expandable);
            $frontier = [];

            foreach ($expandable as $node => $confidence) {
                if (! isset($reachable[$node])
                    || $confidence <= (float) ($bestExpandableConfidence[$node] ?? -1.0)) {
                    continue;
                }

                $bestExpandableConfidence[$node] = $confidence;
                $frontier[$node] = $confidence;
            }
        }

        return ['nodes' => $reachable, 'truncated' => $truncated];
    }

    /**
     * @param array<string, true> $nodes
     * @param array<string, true> $explicitSeeds
     * @return array{added: int, removed: int, changed: int, total: int}
     */
    private function changesTouching(
        PDO $pdo,
        int $fromId,
        int $toId,
        array $nodes,
        array $explicitSeeds,
    ): array
    {
        $counts = $this->emptyCount();

        if ($nodes === []) {
            return $counts;
        }

        $statement = $pdo->prepare($this->touchingChangesSql());
        $statement->execute(['from_generation' => $fromId, 'to_generation' => $toId]);
        $sharedResources = $this->sharedResourceNodes($pdo, $fromId, $toId, array_keys($nodes));

        while (($row = $statement->fetch()) !== false) {
            if ($row['entity'] === 'node') {
                $touches = isset($nodes[$row['node_id']]);
            } else {
                $from = (string) $row['edge_from'];
                $to = (string) $row['edge_to'];
                $fromInScope = isset($nodes[$from]);
                $toInScope = isset($nodes[$to]);
                $touches = $fromInScope && $toInScope;

                if (! $touches && ($fromInScope xor $toInScope)) {
                    $inside = $fromInScope ? $from : $to;
                    // Tables, columns, and models are shared terminal resources.
                    // An unrelated writer incident to one must not enter scope
                    // unless that resource itself was an explicit target/file seed.
                    $touches = isset($explicitSeeds[$inside]) || ! isset($sharedResources[$inside]);
                }
            }

            if (! $touches) {
                continue;
            }

            $kind = (string) $row['change_kind'];
            $counts[$kind]++;
            $counts['total']++;
        }

        return $counts;
    }

    /** @param array<int, string> $nodes @return array<string, true> */
    private function sharedResourceNodes(PDO $pdo, int $fromId, int $toId, array $nodes): array
    {
        $resources = [];

        foreach ($nodes as $node) {
            if (str_starts_with($node, 'table:') || str_starts_with($node, 'column:')) {
                $resources[$node] = true;
            }
        }

        foreach (array_chunk($nodes, self::NEIGHBORHOOD_QUERY_CHUNK) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $pdo->prepare(<<<SQL
                SELECT DISTINCT node_id
                FROM generation_nodes
                WHERE generation_id IN (?, ?)
                  AND node_id IN ({$placeholders})
                  AND type IN ('table', 'column', 'model')
                SQL);
            $statement->execute([$fromId, $toId, ...$chunk]);

            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $node) {
                $resources[(string) $node] = true;
            }
        }

        return $resources;
    }

    /**
     * @param array<string, mixed> $fromRow
     * @param array<string, mixed> $toRow
     * @return array<string, mixed>
     */
    private function warningEvidence(array $fromRow, array $toRow, int $limit): array
    {
        $before = $this->warnings($fromRow);
        $after = $this->warnings($toRow);
        $beforeFingerprints = $this->fingerprintMultiset($before);
        $afterFingerprints = $this->fingerprintMultiset($after);
        $new = 0;
        $resolved = 0;

        foreach (array_unique([...array_keys($beforeFingerprints), ...array_keys($afterFingerprints)]) as $fingerprint) {
            $beforeCount = $beforeFingerprints[$fingerprint] ?? 0;
            $afterCount = $afterFingerprints[$fingerprint] ?? 0;
            $new += max(0, $afterCount - $beforeCount);
            $resolved += max(0, $beforeCount - $afterCount);
        }

        $details = [];
        $detailBytes = 0;

        foreach (array_slice($after, 0, min($limit, self::MAX_WARNING_DETAILS)) as $warning) {
            $detail = $this->compactWarning($warning);
            $bytes = strlen(json_encode(
                $detail,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            if ($detailBytes + $bytes > self::MAX_WARNING_DETAIL_BYTES) {
                break;
            }

            $details[] = $detail;
            $detailBytes += $bytes;
        }

        return [
            'before' => count($before),
            'after' => count($after),
            'new' => $new,
            'resolved' => $resolved,
            'current' => $details,
            'omittedCurrent' => max(0, count($after) - count($details)),
            'currentTruncated' => count($after) > count($details),
            'detailBudget' => [
                'maxDetails' => min($limit, self::MAX_WARNING_DETAILS),
                'maxBytes' => self::MAX_WARNING_DETAIL_BYTES,
                'returnedBytes' => $detailBytes,
            ],
        ];
    }

    /** @param array<string, mixed> $row @return array<int, array<string, mixed>> */
    private function warnings(array $row): array
    {
        $meta = $this->store->verifiedGenerationMeta($row);

        $warnings = is_array($meta['warnings'] ?? null)
            ? $meta['warnings']
            : [];

        return array_values(array_filter($warnings, 'is_array'));
    }

    /** @param array<int, array<string, mixed>> $warnings @return array<string, int> */
    private function fingerprintMultiset(array $warnings): array
    {
        $fingerprints = [];

        foreach ($warnings as $warning) {
            $normalized = $this->canonicalize($warning);

            try {
                $json = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (JsonException $exception) {
                throw new RuntimeException('AppGraph could not compare scanner warnings.', 0, $exception);
            }

            $fingerprint = hash('sha256', $json);
            $fingerprints[$fingerprint] = ($fingerprints[$fingerprint] ?? 0) + 1;
        }

        return $fingerprints;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $nested) {
            $value[$key] = $this->canonicalize($nested);
        }

        return $value;
    }

    /** @param array<string, mixed> $warning @return array<string, mixed> */
    private function compactWarning(array $warning): array
    {
        // Warning fields are agent-reusable evidence: file paths, classes,
        // methods, routes, targets, names, namespaces, and scanner-specific
        // references must remain exact. The enclosing byte budget atomically
        // omits a detail that is too large; it must never turn a reference into
        // a different-looking value. Only human-facing diagnostic prose is
        // shortened here.
        if (is_string($warning['message'] ?? null)) {
            $warning['message'] = mb_strcut(
                mb_substr($warning['message'], 0, 256),
                0,
                1024,
                'UTF-8',
            );
        }

        return $warning;
    }

    /**
     * @param array<string, mixed> $diff
     * @param array<string, mixed> $evidence
     * @param array<int, string> $targets
     * @param array<int, string> $changedFiles
     * @return array<int, array<string, mixed>>
     */
    private function uncertainties(
        array $diff,
        array $evidence,
        array $targets,
        array $changedFiles,
        bool $confirmedRefreshReuse,
    ): array
    {
        $uncertainties = [];
        $from = $diff['from'];
        $to = $diff['to'];

        if (($from['applicationEnvironment'] ?? null) !== ($to['applicationEnvironment'] ?? null)) {
            $uncertainties[] = [
                'code' => 'application_environment_changed',
                'message' => 'The generations were scanned under different application environments; runtime registrations and configuration may not be comparable.',
                'before' => $from['applicationEnvironment'] ?? null,
                'after' => $to['applicationEnvironment'] ?? null,
            ];
        }

        if (($from['configurationFingerprint'] ?? null) !== ($to['configurationFingerprint'] ?? null)) {
            $uncertainties[] = [
                'code' => 'scan_configuration_changed',
                'message' => 'The AppGraph scan configuration changed between generations, so graph deltas may reflect scanner scope as well as source edits.',
            ];
        }

        if (($from['laravelVersion'] ?? null) !== ($to['laravelVersion'] ?? null)
            || ($from['appgraphVersion'] ?? null) !== ($to['appgraphVersion'] ?? null)) {
            $uncertainties[] = [
                'code' => 'scanner_version_changed',
                'message' => 'Laravel or AppGraph versions changed between generations, so deltas may reflect different framework or scanner semantics.',
                'before' => [
                    'laravel' => $from['laravelVersion'] ?? null,
                    'appgraph' => $from['appgraphVersion'] ?? null,
                ],
                'after' => [
                    'laravel' => $to['laravelVersion'] ?? null,
                    'appgraph' => $to['appgraphVersion'] ?? null,
                ],
            ];
        }

        if (($from['containerBindingsFingerprint'] ?? null) !== ($to['containerBindingsFingerprint'] ?? null)) {
            $uncertainties[] = [
                'code' => 'container_bindings_changed',
                'message' => 'The booted container binding registry changed between generations and may account for runtime-resolution deltas.',
            ];
        }

        if (($from['executionRegistryFingerprint'] ?? null) !== ($to['executionRegistryFingerprint'] ?? null)) {
            $uncertainties[] = [
                'code' => 'laravel_execution_registry_changed',
                'message' => 'Laravel event, queue, middleware, or observer registrations changed between generations.',
            ];
        }

        if (($diff['sameGeneration'] ?? false) !== true
            && ($to['parentGeneration'] ?? null) !== ($from['id'] ?? null)) {
            $uncertainties[] = [
                'code' => 'non_adjacent_generations',
                'message' => 'The comparison spans intervening generations; the observed delta may include more than one edit batch.',
            ];
        }

        if ($evidence['scannerWarnings']['after'] > 0) {
            $uncertainties[] = [
                'code' => 'scanner_warnings_present',
                'message' => 'The comparison generation contains unresolved scanner warnings; affected runtime relationships may be incomplete.',
                'count' => $evidence['scannerWarnings']['after'],
                'new' => $evidence['scannerWarnings']['new'],
            ];
        }

        if (($diff['comparison']['sourceChanged'] ?? false) === true
            && $diff['counts']['overall']['total'] === 0) {
            $uncertainties[] = [
                'code' => 'source_changed_without_graph_delta',
                'message' => 'Source fingerprints changed, but no node or edge delta was observed. The edits may be graph-neutral, outside modeled semantics, or unresolved by scanners.',
                'sourceFileChanges' => $evidence['sourceCoverage']['counts']['total'],
            ];
        }

        if (($diff['sameGeneration'] ?? false) === true) {
            $uncertainties[] = [
                'code' => $confirmedRefreshReuse
                    ? 'no_new_generation_published'
                    : 'same_generation_selected',
                'message' => $confirmedRefreshReuse
                    ? 'The refresh reused the captured baseline because source, graph, and evidence fingerprints were identical; there is no later immutable snapshot to compare.'
                    : 'The same immutable generation was selected on both sides; this comparison alone does not prove that a refresh occurred or that no newer generation exists.',
            ];
        }

        if ($changedFiles !== []) {
            $unrecorded = array_values(array_filter(
                $evidence['sourceCoverage']['requestedFiles'],
                static fn (array $file): bool => in_array($file['manifestStatus'], ['not_recorded', 'unchanged'], true),
            ));

            if ($unrecorded !== []) {
                $uncertainties[] = [
                    'code' => 'provided_files_not_in_source_delta',
                    'message' => 'One or more files identified as changed were absent or unchanged in the selected generation manifests; verify the baseline and refresh timing.',
                    'files' => array_column($unrecorded, 'path'),
                ];
            }

            if ($evidence['sourceCoverage']['unrequestedSourceChanges'] > 0) {
                $uncertainties[] = [
                    'code' => 'unrequested_source_changes',
                    'message' => 'The generation manifest contains changed files outside the caller-provided changed_files scope.',
                    'count' => $evidence['sourceCoverage']['unrequestedSourceChanges'],
                ];
            }

            if (($evidence['targetScope']['unmappedChangedFiles'] ?? []) !== []) {
                $uncertainties[] = [
                    'code' => 'changed_files_without_graph_seeds',
                    'message' => 'One or more recorded changed files have no before/after node facts, so expected scope and collateral classification are incomplete.',
                    'files' => $evidence['targetScope']['unmappedChangedFiles'],
                ];
            }
        }

        if ($targets !== []) {
            $unresolved = array_values(array_filter(
                $evidence['targetScope']['requestedTargets'],
                static fn (array $target): bool => ! $target['before'] && ! $target['after'],
            ));

            if ($unresolved !== []) {
                $uncertainties[] = [
                    'code' => 'targets_unresolved',
                    'message' => 'One or more expected targets could not be resolved uniquely by id, label, route name, or familiar suffix in either generation.',
                    'targets' => array_column($unresolved, 'target'),
                ];
            }

            $ambiguous = array_values(array_filter(
                $evidence['targetScope']['requestedTargets'],
                static fn (array $target): bool => $target['beforeResolution'] === 'ambiguous'
                    || $target['afterResolution'] === 'ambiguous',
            ));

            if ($ambiguous !== []) {
                $uncertainties[] = [
                    'code' => 'targets_ambiguous',
                    'message' => 'One or more expected targets were ambiguous in at least one selected generation.',
                    'targets' => array_column($ambiguous, 'target'),
                ];
            }

            $candidateTruncation = array_values(array_filter(
                $evidence['targetScope']['requestedTargets'],
                static fn (array $target): bool => (bool) ($target['candidatesTruncated'] ?? false),
            ));

            if ($candidateTruncation !== []) {
                $uncertainties[] = [
                    'code' => 'target_candidates_truncated',
                    'message' => 'Candidate lists for one or more ambiguous targets were truncated to five deterministic entries.',
                    'targets' => array_column($candidateTruncation, 'target'),
                ];
            }

            if (($evidence['targetScope']['targetResolutionIncomplete'] ?? false) === true) {
                $uncertainties[] = [
                    'code' => 'expected_scope_resolution_incomplete',
                    'message' => 'At least one expected target was unresolved or ambiguous in a selected generation, so in-scope counts are a lower bound and collateral changes cannot be counted exactly.',
                    'targets' => array_column(array_values(array_filter(
                        $evidence['targetScope']['requestedTargets'],
                        static fn (array $target): bool => in_array(
                            $target['beforeResolution'],
                            ['unresolved', 'ambiguous'],
                            true,
                        ) || in_array(
                            $target['afterResolution'],
                            ['unresolved', 'ambiguous'],
                            true,
                        ),
                    )), 'target'),
                ];
            }
        }

        if (($targets !== [] || $changedFiles !== [])
            && $evidence['targetScope']['neighborhoodTruncated']) {
            $uncertainties[] = [
                'code' => 'expected_scope_truncated',
                'message' => 'The bounded target/file neighborhood was truncated; in-scope counts are a lower bound and collateral changes cannot be counted exactly.',
            ];
        }

        if ($diff['truncated']) {
            $uncertainties[] = [
                'code' => 'diff_details_truncated',
                'message' => 'Exact counts include changes omitted from bounded detail groups. Increase the limit or request focused diff categories for more detail.',
                'omitted' => array_sum($diff['omitted']),
            ];
        }

        return $uncertainties;
    }

    /** @return array{added: int, removed: int, changed: int, total: int} */
    private function emptyCount(): array
    {
        return ['added' => 0, 'removed' => 0, 'changed' => 0, 'total' => 0];
    }

    private function fileChangesCte(): string
    {
        return <<<'SQL'
            WITH selected AS (
                SELECT
                    CAST(:from_generation AS INTEGER) AS before_id,
                    CAST(:to_generation AS INTEGER) AS after_id
            ),
            file_changes AS (
                SELECT after.path, 'added' AS change_kind
                FROM selected
                JOIN generation_files after ON after.generation_id = selected.after_id
                LEFT JOIN generation_files before
                    ON before.generation_id = selected.before_id
                   AND before.path = after.path
                WHERE before.path IS NULL

                UNION ALL

                SELECT before.path, 'removed'
                FROM selected
                JOIN generation_files before ON before.generation_id = selected.before_id
                LEFT JOIN generation_files after
                    ON after.generation_id = selected.after_id
                   AND after.path = before.path
                WHERE after.path IS NULL

                UNION ALL

                SELECT after.path, 'changed'
                FROM selected
                JOIN generation_files before ON before.generation_id = selected.before_id
                JOIN generation_files after
                    ON after.generation_id = selected.after_id
                   AND after.path = before.path
                WHERE before.content_hash <> after.content_hash
            )
            SQL;
    }

    private function fileNodeChangesSql(): string
    {
        return <<<'SQL'
            SELECT COUNT(*)
            FROM (
                SELECT after.node_id
                FROM generation_nodes after
                LEFT JOIN generation_nodes before
                    ON before.generation_id = :before_generation
                   AND before.node_id = after.node_id
                WHERE after.generation_id = :after_generation
                  AND after.file = :path
                  AND before.node_id IS NULL

                UNION ALL

                SELECT before.node_id
                FROM generation_nodes before
                LEFT JOIN generation_nodes after
                    ON after.generation_id = :after_generation
                   AND after.node_id = before.node_id
                WHERE before.generation_id = :before_generation
                  AND before.file = :path
                  AND after.node_id IS NULL

                UNION ALL

                SELECT after.node_id
                FROM generation_nodes before
                JOIN generation_nodes after
                    ON after.generation_id = :after_generation
                   AND after.node_id = before.node_id
                WHERE before.generation_id = :before_generation
                  AND before.object_id <> after.object_id
                  AND (before.file = :path OR after.file = :path)
            )
            SQL;
    }

    private function touchingChangesSql(): string
    {
        return <<<'SQL'
            WITH selected AS (
                SELECT
                    CAST(:from_generation AS INTEGER) AS before_id,
                    CAST(:to_generation AS INTEGER) AS after_id
            )
            SELECT 'node' AS entity, 'added' AS change_kind, after.node_id, NULL AS edge_from, NULL AS edge_to
            FROM selected
            JOIN generation_nodes after ON after.generation_id = selected.after_id
            LEFT JOIN generation_nodes before
                ON before.generation_id = selected.before_id AND before.node_id = after.node_id
            WHERE before.node_id IS NULL

            UNION ALL

            SELECT 'node', 'removed', before.node_id, NULL, NULL
            FROM selected
            JOIN generation_nodes before ON before.generation_id = selected.before_id
            LEFT JOIN generation_nodes after
                ON after.generation_id = selected.after_id AND after.node_id = before.node_id
            WHERE after.node_id IS NULL

            UNION ALL

            SELECT 'node', 'changed', after.node_id, NULL, NULL
            FROM selected
            JOIN generation_nodes before ON before.generation_id = selected.before_id
            JOIN generation_nodes after
                ON after.generation_id = selected.after_id AND after.node_id = before.node_id
            WHERE before.object_id <> after.object_id

            UNION ALL

            SELECT 'edge', 'added', NULL, after.edge_from, after.edge_to
            FROM selected
            JOIN generation_edges after ON after.generation_id = selected.after_id
            LEFT JOIN generation_edges before
                ON before.generation_id = selected.before_id
               AND before.edge_from = after.edge_from
               AND before.type = after.type
               AND before.edge_to = after.edge_to
            WHERE before.edge_from IS NULL

            UNION ALL

            SELECT 'edge', 'removed', NULL, before.edge_from, before.edge_to
            FROM selected
            JOIN generation_edges before ON before.generation_id = selected.before_id
            LEFT JOIN generation_edges after
                ON after.generation_id = selected.after_id
               AND after.edge_from = before.edge_from
               AND after.type = before.type
               AND after.edge_to = before.edge_to
            WHERE after.edge_from IS NULL

            UNION ALL

            SELECT 'edge', 'changed', NULL, after.edge_from, after.edge_to
            FROM selected
            JOIN generation_edges before ON before.generation_id = selected.before_id
            JOIN generation_edges after
                ON after.generation_id = selected.after_id
               AND after.edge_from = before.edge_from
               AND after.type = before.type
               AND after.edge_to = before.edge_to
            WHERE before.object_id <> after.object_id
            SQL;
    }
}
