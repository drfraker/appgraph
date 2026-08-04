<?php

namespace AppGraph\Storage;

use AppGraph\Support\AgentPayloadLimiter;
use PDO;
use PDOStatement;
use RuntimeException;

class GenerationDiffer
{
    /** @var array<int, string> */
    private const CATEGORIES = [
        'nodes',
        'edges',
        'routes',
        'writes',
        'authorization',
        'queues',
        'views',
        'tests',
    ];

    private const MAX_CHANGED_FIELD_PATHS = 64;

    private const MAX_CHANGED_FIELD_VISITS = 4096;

    private const MAX_DETAIL_SCALARS = 48;

    private const MAX_DETAIL_ARRAY_ITEMS = 16;

    private const MAX_DETAIL_DEPTH = 4;

    private const MAX_DETAIL_STRING_CHARACTERS = 512;

    private const MAX_DETAIL_STRING_BYTES = 2048;

    private const MAX_TOTAL_DETAIL_BYTES = 1000000;

    public function __construct(private GraphStore $store)
    {
    }

    /**
     * Compare two immutable generations. Counts always describe the complete
     * comparison; category selection only controls the bounded detail groups.
     *
     * @param array<int, string> $categories
     * @return array<string, mixed>
     */
    public function diff(
        string $from,
        string $to = 'current',
        array $categories = [],
        int $limit = 50,
    ): array {
        $categories = $this->validatedCategories($categories);

        if ($limit < 1 || $limit > 200) {
            throw new RuntimeException('AppGraph generation diff limit must be between 1 and 200.');
        }

        return $this->store->withSnapshot(
            fn (PDO $pdo): array => $this->diffSnapshot($pdo, $from, $to, $categories, $limit),
        );
    }

    /**
     * Compare generations within a caller-owned read transaction. This keeps
     * verification, its diff, and its scope evidence on one SQLite snapshot.
     *
     * @param array<int, string> $categories Validated canonical categories.
     * @return array<string, mixed>
     */
    public function diffSnapshot(
        PDO $pdo,
        string $from,
        string $to,
        array $categories,
        int $limit,
        bool $allowSame = false,
    ): array {
        $categories = $this->validatedCategories($categories);

        $this->assertNumericGeneration($from, 'baseline');
        $this->assertComparisonGeneration($to);

        if ($limit < 1 || $limit > 200) {
            throw new RuntimeException('AppGraph generation diff limit must be between 1 and 200.');
        }

        $fromRow = $this->store->resolveGenerationRow($pdo, $from);
        $toRow = $this->store->resolveGenerationRow($pdo, $to);
        $this->store->assertGenerationIntegrity($pdo, $fromRow);

        if ((int) $fromRow['id'] !== (int) $toRow['id']) {
            $this->store->assertGenerationIntegrity($pdo, $toRow);
        }

        $fromId = (int) $fromRow['id'];
        $toId = (int) $toRow['id'];

        if ($fromId === $toId && ! $allowSame) {
            throw new RuntimeException("AppGraph cannot diff generation [{$fromId}] against itself.");
        }

        if ($fromId > $toId) {
            throw new RuntimeException("AppGraph generation diffs must run forward in time; [{$fromId}] is newer than [{$toId}].");
        }

        $currentId = $pdo->query(
            'SELECT current_generation_id FROM store_state WHERE singleton = 1'
        )->fetchColumn();
        $counts = $this->counts($pdo, $fromId, $toId, $categories);
        $changes = [];
        $omitted = [];
        $remainingDetails = $limit;
        $returnedDetailBytes = 0;
        $byteBudgetExhausted = false;
        $nodePayload = $pdo->prepare('SELECT data_json, content_hash, semantic_hash, byte_count FROM node_objects WHERE id = :id');
        $edgePayload = $pdo->prepare('SELECT data_json, content_hash, semantic_hash, byte_count FROM edge_objects WHERE id = :id');

        foreach ($categories as $categoryIndex => $category) {
            $changes[$category] = [];
            $categoriesRemaining = count($categories) - $categoryIndex;
            $categoryLimit = $remainingDetails > 0
                ? max(1, (int) ceil($remainingDetails / $categoriesRemaining))
                : 0;

            if ($categoryLimit > 0 && ! $byteBudgetExhausted) {
                foreach ($this->detailRows($pdo, $fromId, $toId, $category, $categoryLimit) as $row) {
                    $detail = $this->detail($row, $nodePayload, $edgePayload);
                    $detailBytes = strlen(json_encode(
                        $detail,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                    ));

                    if ($returnedDetailBytes + $detailBytes > self::MAX_TOTAL_DETAIL_BYTES) {
                        $byteBudgetExhausted = true;
                        break;
                    }

                    $changes[$category][] = $detail;
                    $returnedDetailBytes += $detailBytes;
                    $remainingDetails--;
                }
            }

            $omitted[$category] = max(
                0,
                $counts['byCategory'][$category]['total'] - count($changes[$category]),
            );
        }

        $truncated = array_sum($omitted) > 0;
        $fromSummary = $this->store->generationSummary(
            $fromRow,
            $currentId !== false && $fromId === (int) $currentId,
        );
        $toSummary = $this->store->generationSummary(
            $toRow,
            $currentId !== false && $toId === (int) $currentId,
        );

        return [
            'query' => 'diff',
            'sameGeneration' => $fromId === $toId,
            'from' => $fromSummary,
            'to' => $toSummary,
            'comparison' => [
                'graphChanged' => (string) $fromRow['graph_fingerprint'] !== (string) $toRow['graph_fingerprint'],
                'semanticChanged' => (string) $fromRow['semantic_fingerprint'] !== (string) $toRow['semantic_fingerprint'],
                'sourceChanged' => ($fromRow['source_fingerprint'] ?? null) !== ($toRow['source_fingerprint'] ?? null),
            ],
            'detailCategories' => $categories,
            'counts' => $counts,
            'changes' => $changes,
            'omitted' => $omitted,
            'truncated' => $truncated,
            'detailBudget' => [
                'maxDetails' => $limit,
                'maxBytes' => self::MAX_TOTAL_DETAIL_BYTES,
                'returnedDetails' => $limit - $remainingDetails,
                'returnedBytes' => $returnedDetailBytes,
                'byteBudgetExhausted' => $byteBudgetExhausted,
            ],
        ];
    }

    private function assertNumericGeneration(string $generation, string $label): void
    {
        $generation = trim($generation);

        if (strlen($generation) > 19 || preg_match('/^[1-9]\d*$/D', $generation) !== 1) {
            throw new RuntimeException(
                "AppGraph generation diff requires a captured numeric {$label} generation id."
            );
        }
    }

    private function assertComparisonGeneration(string $generation): void
    {
        if (trim($generation) === 'current') {
            return;
        }

        $this->assertNumericGeneration($generation, 'comparison');
    }

    /**
     * @param array<int, mixed> $categories
     * @return array<int, string>
     */
    private function validatedCategories(array $categories): array
    {
        if ($categories === []) {
            return self::CATEGORIES;
        }

        if (count($categories) > count(self::CATEGORIES)) {
            throw new RuntimeException('AppGraph generation diff accepts at most seven categories.');
        }

        $selected = [];

        foreach ($categories as $category) {
            if (! is_string($category) || ! in_array($category, self::CATEGORIES, true)) {
                $rendered = is_scalar($category) ? (string) $category : get_debug_type($category);

                throw new RuntimeException("Unknown AppGraph diff category [{$rendered}].");
            }

            if (isset($selected[$category])) {
                throw new RuntimeException("AppGraph diff category [{$category}] was provided more than once.");
            }

            $selected[$category] = true;
        }

        return array_values(array_filter(
            self::CATEGORIES,
            static fn (string $category): bool => isset($selected[$category]),
        ));
    }

    /**
     * @param array<int, string> $selectedCategories
     * @return array<string, mixed>
     */
    private function counts(PDO $pdo, int $fromId, int $toId, array $selectedCategories): array
    {
        $overall = $this->emptyCount();
        $selected = $this->emptyCount();
        $byEntity = [
            'nodes' => $this->emptyCount(),
            'edges' => $this->emptyCount(),
        ];
        $byCategory = [];

        foreach (self::CATEGORIES as $category) {
            $byCategory[$category] = $this->emptyCount();
        }

        $statement = $pdo->prepare($this->changesCte().<<<'SQL'

            SELECT category, entity, change_kind, COUNT(*) AS total
            FROM classified
            GROUP BY category, entity, change_kind
            ORDER BY category, entity, change_kind
            SQL);
        $statement->execute(['from_generation' => $fromId, 'to_generation' => $toId]);

        foreach ($statement->fetchAll() as $row) {
            $category = (string) $row['category'];
            $entity = (string) $row['entity'].'s';
            $kind = (string) $row['change_kind'];
            $total = (int) $row['total'];

            if (! isset($byCategory[$category], $byEntity[$entity])
                || ! in_array($kind, ['added', 'removed', 'changed'], true)) {
                throw new RuntimeException('AppGraph encountered an invalid persisted change classification.');
            }

            $overall[$kind] += $total;
            $overall['total'] += $total;
            $byEntity[$entity][$kind] += $total;
            $byEntity[$entity]['total'] += $total;
            $byCategory[$category][$kind] += $total;
            $byCategory[$category]['total'] += $total;

            if (in_array($category, $selectedCategories, true)) {
                $selected[$kind] += $total;
                $selected['total'] += $total;
            }
        }

        return [
            'overall' => $overall,
            'selected' => $selected,
            'byEntity' => $byEntity,
            'byCategory' => $byCategory,
        ];
    }

    /** @return array{added: int, removed: int, changed: int, total: int} */
    private function emptyCount(): array
    {
        return ['added' => 0, 'removed' => 0, 'changed' => 0, 'total' => 0];
    }

    /** @return array<int, array<string, mixed>> */
    private function detailRows(
        PDO $pdo,
        int $fromId,
        int $toId,
        string $category,
        int $limit,
    ): array {
        $statement = $pdo->prepare($this->changesCte().<<<'SQL'

            SELECT *
            FROM classified
            WHERE category = :category
            ORDER BY
                CASE entity WHEN 'node' THEN 0 ELSE 1 END,
                identity,
                CASE change_kind WHEN 'removed' THEN 0 WHEN 'changed' THEN 1 ELSE 2 END
            LIMIT :detail_limit
            SQL);
        $statement->bindValue('from_generation', $fromId, PDO::PARAM_INT);
        $statement->bindValue('to_generation', $toId, PDO::PARAM_INT);
        $statement->bindValue('category', $category);
        $statement->bindValue('detail_limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function detail(array $row, PDOStatement $nodePayload, PDOStatement $edgePayload): array
    {
        $entity = (string) $row['entity'];
        $loader = $entity === 'node' ? $nodePayload : $edgePayload;
        $baseIdentity = $entity === 'node'
            ? ['id' => (string) $row['node_id']]
            : [
                'from' => (string) $row['edge_from'],
                'to' => (string) $row['edge_to'],
            ];
        $beforeRecord = $this->payload(
            $loader,
            $row['before_object_id'] ?? null,
            $entity,
            $baseIdentity + ['type' => (string) ($row['before_type'] ?? $row['fact_type'])],
        );
        $afterRecord = $this->payload(
            $loader,
            $row['after_object_id'] ?? null,
            $entity,
            $baseIdentity + ['type' => (string) ($row['after_type'] ?? $row['fact_type'])],
        );
        $before = $beforeRecord['payload'] ?? null;
        $after = $afterRecord['payload'] ?? null;
        $beforeCompact = $before !== null ? $this->compactFact($before, $entity) : null;
        $afterCompact = $after !== null ? $this->compactFact($after, $entity) : null;
        $factType = (string) $row['fact_type'];
        $identity = $entity === 'node'
            ? (string) $row['node_id']
            : [
                'from' => (string) $row['edge_from'],
                'type' => $factType,
                'to' => (string) $row['edge_to'],
            ];
        $detail = [
            'entity' => $entity,
            'identity' => $identity,
            'change' => (string) $row['change_kind'],
            'category' => (string) $row['category'],
            'factType' => $factType,
        ];

        if ($beforeCompact !== null) {
            $detail['before'] = $beforeCompact['fact'];
        }

        if ($afterCompact !== null) {
            $detail['after'] = $afterCompact['fact'];
        }

        if (($beforeCompact['truncated'] ?? false) || ($afterCompact['truncated'] ?? false)) {
            $detail['payloadDetailsTruncated'] = true;
        }

        if ($before !== null && $after !== null) {
            $detail['semanticChanged'] = ! hash_equals(
                (string) $beforeRecord['semanticHash'],
                (string) $afterRecord['semanticHash'],
            );
            $fieldChanges = $this->changedFieldPaths($before, $after);
            $detail['changedFields'] = $fieldChanges['paths'];
            $detail['changedFieldCount'] = $fieldChanges['count'];

            if ($fieldChanges['truncated']) {
                $detail['changedFieldsTruncated'] = true;
            }

            if ($fieldChanges['countIsLowerBound']) {
                $detail['changedFieldCountIsLowerBound'] = true;
            }
        }

        return $detail;
    }

    /** @return array{payload: array<string, mixed>, semanticHash: string}|null */
    private function payload(
        PDOStatement $statement,
        mixed $objectId,
        string $entity,
        array $expectedIdentity,
    ): ?array
    {
        if ($objectId === null) {
            return null;
        }

        $statement->execute(['id' => (int) $objectId]);
        $row = $statement->fetch();
        if (! is_array($row)) {
            throw new RuntimeException("AppGraph could not load a persisted {$entity} payload.");
        }

        return $this->store->verifiedFactPayload($entity, $row, $expectedIdentity);
    }

    /**
     * Return the stable, useful part of a raw fact while enforcing hard output
     * bounds independently of scanner metadata size.
     *
     * @param array<string, mixed> $payload
     * @return array{fact: array<string, mixed>, truncated: bool}
     */
    private function compactFact(array $payload, string $entity): array
    {
        $fields = $entity === 'node'
            ? ['id', 'type', 'label', 'namespace', 'class', 'method', 'file', 'line', 'endLine', 'signature', 'summary']
            : ['from', 'to', 'type', 'confidence'];
        $fact = [];
        $budget = self::MAX_DETAIL_SCALARS;
        $truncated = false;

        foreach ($fields as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $fact[$field] = $this->compactValue(
                $payload[$field],
                0,
                $budget,
                $truncated,
                $field,
            );
        }

        foreach (['inputs', 'outputs', 'metadata'] as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            if ($budget <= 0) {
                $truncated = true;

                break;
            }

            $fact[$field] = $this->compactValue(
                $payload[$field],
                0,
                $budget,
                $truncated,
                $field,
            );
        }

        return ['fact' => $fact, 'truncated' => $truncated];
    }

    private function compactValue(
        mixed $value,
        int $depth,
        int &$budget,
        bool &$truncated,
        ?string $key = null,
        bool $exactStringContext = false,
    ): mixed {
        if (! is_array($value)) {
            if ($budget <= 0) {
                $truncated = true;

                return null;
            }

            $budget--;

            if (is_string($value)) {
                if ($exactStringContext || AgentPayloadLimiter::isExactStringKey($key)) {
                    return $value;
                }

                return $this->boundedString($value, $truncated);
            }

            return $value;
        }

        if ($depth >= self::MAX_DETAIL_DEPTH || $budget <= 0) {
            $truncated = true;

            return [];
        }

        $isList = array_is_list($value);
        $keys = array_keys($value);
        $exactStringContext = $exactStringContext
            || AgentPayloadLimiter::isExactStringCollectionKey($key);

        if (! $isList) {
            usort($keys, static fn (int|string $left, int|string $right): int => (string) $left <=> (string) $right);
        }

        if (count($keys) > self::MAX_DETAIL_ARRAY_ITEMS) {
            $keys = array_slice($keys, 0, self::MAX_DETAIL_ARRAY_ITEMS);
            $truncated = true;
        }

        $result = [];

        foreach ($keys as $key) {
            if ($budget <= 0) {
                $truncated = true;

                break;
            }

            $compact = $this->compactValue(
                $value[$key],
                $depth + 1,
                $budget,
                $truncated,
                is_string($key) ? $key : null,
                $exactStringContext,
            );

            if ($isList) {
                $result[] = $compact;
            } else {
                // Object keys are field identities. Shortening one would make
                // the detail point at a different field (and can collide with
                // another key), so byte budgeting must omit the whole detail
                // rather than rewrite it.
                $result[(string) $key] = $compact;
            }
        }

        return $result;
    }

    private function boundedString(string $value, bool &$truncated): string
    {
        if (strlen($value) <= self::MAX_DETAIL_STRING_BYTES
            && mb_strlen($value) <= self::MAX_DETAIL_STRING_CHARACTERS) {
            return $value;
        }

        $truncated = true;
        $value = mb_substr($value, 0, self::MAX_DETAIL_STRING_CHARACTERS);

        if (strlen($value) > self::MAX_DETAIL_STRING_BYTES) {
            $value = mb_strcut($value, 0, self::MAX_DETAIL_STRING_BYTES, 'UTF-8');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array{paths: array<int, string>, count: int, truncated: bool, countIsLowerBound: bool}
     */
    private function changedFieldPaths(array $before, array $after): array
    {
        $paths = [];
        $count = 0;
        $visits = 0;
        $workTruncated = false;
        $this->collectChangedFieldPaths(
            $before,
            $after,
            '',
            0,
            $paths,
            $count,
            $visits,
            $workTruncated,
        );

        return [
            'paths' => $paths,
            'count' => $count,
            'truncated' => $count > count($paths) || $workTruncated,
            'countIsLowerBound' => $workTruncated,
        ];
    }

    /** @param array<int, string> $paths */
    private function collectChangedFieldPaths(
        mixed $before,
        mixed $after,
        string $path,
        int $depth,
        array &$paths,
        int &$count,
        int &$visits,
        bool &$workTruncated,
    ): void {
        if ($visits >= self::MAX_CHANGED_FIELD_VISITS) {
            $workTruncated = true;

            return;
        }

        $visits++;

        if ($before === $after) {
            return;
        }

        if (! is_array($before)
            || ! is_array($after)
            || array_is_list($before) !== array_is_list($after)
            || $depth >= 32) {
            $this->recordChangedPath($path, $paths, $count);

            return;
        }

        $keys = [];
        $seen = [];

        foreach ([$before, $after] as $values) {
            foreach ($values as $key => $_value) {
                $identity = is_int($key) ? 'i:'.$key : 's:'.$key;

                if (isset($seen[$identity])) {
                    continue;
                }

                if (count($keys) >= self::MAX_CHANGED_FIELD_VISITS - $visits) {
                    $workTruncated = true;

                    break 2;
                }

                $seen[$identity] = true;
                $keys[] = $key;
            }
        }

        usort($keys, static function (int|string $left, int|string $right): int {
            if (is_int($left) && is_int($right)) {
                return $left <=> $right;
            }

            return (string) $left <=> (string) $right;
        });

        foreach ($keys as $key) {
            $nextPath = $path.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key);

            if (! array_key_exists($key, $before) || ! array_key_exists($key, $after)) {
                $this->recordChangedPath($nextPath, $paths, $count);

                continue;
            }

            $this->collectChangedFieldPaths(
                $before[$key],
                $after[$key],
                $nextPath,
                $depth + 1,
                $paths,
                $count,
                $visits,
                $workTruncated,
            );

            if ($visits >= self::MAX_CHANGED_FIELD_VISITS) {
                $workTruncated = true;

                break;
            }
        }
    }

    /** @param array<int, string> $paths */
    private function recordChangedPath(
        string $path,
        array &$paths,
        int &$count,
    ): void
    {
        $count++;

        if (count($paths) >= self::MAX_CHANGED_FIELD_PATHS) {
            return;
        }

        $paths[] = $path === '' ? '/' : $path;
    }

    private function changesCte(): string
    {
        return <<<'SQL'
            WITH selected AS (
                SELECT
                    CAST(:from_generation AS INTEGER) AS before_id,
                    CAST(:to_generation AS INTEGER) AS after_id
            ),
            changes AS (
                SELECT
                    'node' AS entity,
                    'added' AS change_kind,
                    after.node_id AS identity,
                    after.node_id AS node_id,
                    NULL AS edge_from,
                    NULL AS edge_to,
                    after.type AS fact_type,
                    NULL AS before_type,
                    after.type AS after_type,
                    NULL AS before_object_id,
                    after.object_id AS after_object_id
                FROM selected
                JOIN generation_nodes after ON after.generation_id = selected.after_id
                LEFT JOIN generation_nodes before
                    ON before.generation_id = selected.before_id
                   AND before.node_id = after.node_id
                WHERE before.node_id IS NULL

                UNION ALL

                SELECT
                    'node',
                    'removed',
                    before.node_id,
                    before.node_id,
                    NULL,
                    NULL,
                    before.type,
                    before.type,
                    NULL,
                    before.object_id,
                    NULL
                FROM selected
                JOIN generation_nodes before ON before.generation_id = selected.before_id
                LEFT JOIN generation_nodes after
                    ON after.generation_id = selected.after_id
                   AND after.node_id = before.node_id
                WHERE after.node_id IS NULL

                UNION ALL

                SELECT
                    'node',
                    'changed',
                    after.node_id,
                    after.node_id,
                    NULL,
                    NULL,
                    after.type,
                    before.type,
                    after.type,
                    before.object_id,
                    after.object_id
                FROM selected
                JOIN generation_nodes before ON before.generation_id = selected.before_id
                JOIN generation_nodes after
                    ON after.generation_id = selected.after_id
                   AND after.node_id = before.node_id
                WHERE before.object_id <> after.object_id

                UNION ALL

                SELECT
                    'edge',
                    'added',
                    after.edge_from || char(0) || after.type || char(0) || after.edge_to,
                    NULL,
                    after.edge_from,
                    after.edge_to,
                    after.type,
                    NULL,
                    after.type,
                    NULL,
                    after.object_id
                FROM selected
                JOIN generation_edges after ON after.generation_id = selected.after_id
                LEFT JOIN generation_edges before
                    ON before.generation_id = selected.before_id
                   AND before.edge_from = after.edge_from
                   AND before.type = after.type
                   AND before.edge_to = after.edge_to
                WHERE before.edge_from IS NULL

                UNION ALL

                SELECT
                    'edge',
                    'removed',
                    before.edge_from || char(0) || before.type || char(0) || before.edge_to,
                    NULL,
                    before.edge_from,
                    before.edge_to,
                    before.type,
                    before.type,
                    NULL,
                    before.object_id,
                    NULL
                FROM selected
                JOIN generation_edges before ON before.generation_id = selected.before_id
                LEFT JOIN generation_edges after
                    ON after.generation_id = selected.after_id
                   AND after.edge_from = before.edge_from
                   AND after.type = before.type
                   AND after.edge_to = before.edge_to
                WHERE after.edge_from IS NULL

                UNION ALL

                SELECT
                    'edge',
                    'changed',
                    after.edge_from || char(0) || after.type || char(0) || after.edge_to,
                    NULL,
                    after.edge_from,
                    after.edge_to,
                    after.type,
                    before.type,
                    after.type,
                    before.object_id,
                    after.object_id
                FROM selected
                JOIN generation_edges before ON before.generation_id = selected.before_id
                JOIN generation_edges after
                    ON after.generation_id = selected.after_id
                   AND after.edge_from = before.edge_from
                   AND after.type = before.type
                   AND after.edge_to = before.edge_to
                WHERE before.object_id <> after.object_id
            ),
            classified AS (
                SELECT
                    changes.*,
                    CASE
                        WHEN (entity = 'node' AND (
                                before_type IN ('test', 'test_file')
                                OR after_type IN ('test', 'test_file')
                             ))
                          OR (entity = 'edge' AND fact_type IN (
                                'tests_route',
                                'runtime_covers',
                                'runtime_uses_table',
                                'runtime_renders_blade',
                                'runtime_renders_inertia'
                             ))
                            THEN 'tests'
                        WHEN (entity = 'node' AND (before_type = 'policy' OR after_type = 'policy'))
                          OR (entity = 'edge' AND fact_type = 'authorizes_via')
                            THEN 'authorization'
                        WHEN entity = 'edge'
                         AND fact_type IN ('writes', 'writes_cache', 'writes_filesystem')
                            THEN 'writes'
                        WHEN (entity = 'node' AND (
                                before_type IN ('event', 'job') OR after_type IN ('event', 'job')
                             ))
                          OR (entity = 'edge' AND fact_type IN ('dispatches', 'handled_by', 'listens_to', 'observes'))
                            THEN 'queues'
                        WHEN (entity = 'node' AND (before_type = 'route' OR after_type = 'route'))
                          OR (entity = 'edge' AND fact_type IN ('routes_to', 'passes_through', 'consumes_route'))
                            THEN 'routes'
                        WHEN (entity = 'node' AND (before_type = 'view' OR after_type = 'view'))
                          OR (entity = 'edge' AND fact_type IN ('renders', 'includes', 'extends', 'uses_component'))
                            THEN 'views'
                        WHEN entity = 'node' THEN 'nodes'
                        ELSE 'edges'
                    END AS category
                FROM changes
            )
            SQL;
    }
}
