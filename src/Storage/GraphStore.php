<?php

namespace AppGraph\Storage;

use AppGraph\Graph\Graph;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

class GraphStore
{
    public const SCHEMA_VERSION = 1;

    private const APPLICATION_ID = 0x41504752; // APGR

    private const MAX_SEARCH_CHARACTERS = 512;

    private const MAX_SEARCH_BYTES = 2048;

    private const MAX_FACT_BYTES = 1048576;

    private const MAX_GENERATION_META_BYTES = 16777216;

    private const MAX_SOURCE_CHANGES_BYTES = 4096;

    public function __construct(
        private string $path,
        private int $retainedGenerations = 10,
        private int $busyTimeoutMs = 5000,
        private bool $enableFts = false,
    ) {
        $this->retainedGenerations = max(2, min(50, $this->retainedGenerations));
        $this->busyTimeoutMs = max(0, min(60000, $this->busyTimeoutMs));
    }

    public function path(): string
    {
        return $this->path;
    }

    public function hasCurrent(): bool
    {
        if (! is_file($this->path)) {
            return false;
        }

        return $this->withSnapshot(
            static fn (PDO $pdo): bool => $pdo->query(
                'SELECT current_generation_id IS NOT NULL FROM store_state WHERE singleton = 1'
            )->fetchColumn() === 1,
        );
    }

    /**
     * Publish one immutable generation and atomically move the current pointer.
     * An exact repeat (same source and full graph fingerprints) reuses the
     * current generation rather than filling retention with refresh noise.
     *
     * @return array{created: bool, generation: array<string, mixed>, previousGeneration: array<string, mixed>|null}
     */
    public function publish(Graph $graph): array
    {
        $this->assertRuntimeAvailable();
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create AppGraph store directory [{$directory}].");
        }

        $pdo = $this->connect(create: true);
        $this->bootstrap($pdo);
        $this->secureStoreFiles();
        $graphData = $graph->toArray();
        $nodeFacts = $this->nodeFacts($graphData['nodes'] ?? []);
        $edgeFacts = $this->edgeFacts($graphData['edges'] ?? []);
        $graphFingerprint = $this->aggregateFingerprint('graph', $nodeFacts, $edgeFacts, 'contentHash');
        $semanticFingerprint = $this->aggregateFingerprint('semantic', $nodeFacts, $edgeFacts, 'semanticHash');
        $sourceFingerprint = $this->nullableString(
            $graphData['meta']['scan']['generationFingerprint']
                ?? $graphData['meta']['scan']['fingerprint']
                ?? null,
        );
        $evidenceFingerprint = $this->generationEvidenceFingerprint(
            is_array($graphData['meta'] ?? null) ? $graphData['meta'] : [],
        );

        $pdo->exec('BEGIN IMMEDIATE');

        try {
            $this->assertStoreStateIntegrity($pdo);

            $currentRow = $this->currentRow($pdo);
            if ($currentRow !== null) {
                // Parent facts and source manifests affect the new generation's
                // lineage and source-change summary, so corruption must fail
                // before a successor can be published on top of it.
                $this->assertGenerationIntegrity($pdo, $currentRow);
            }
            $currentMeta = $currentRow !== null
                ? $this->verifiedGenerationMeta($currentRow)
                : null;
            $previousGeneration = $currentRow !== null
                ? $this->generationSummary($currentRow, false)
                : null;

            if ($currentRow !== null
                && hash_equals((string) $currentRow['graph_fingerprint'], $graphFingerprint)
                && $this->nullableString($currentRow['source_fingerprint']) === $sourceFingerprint
                && is_array($currentMeta)
                && hash_equals(
                    $this->generationEvidenceFingerprint($currentMeta),
                    $evidenceFingerprint,
                )) {
                $generation = $this->generationSummary($currentRow, true);
                $persistedMeta = $currentMeta;
                $pdo->commit();
                $graph->replaceMeta($persistedMeta);

                return [
                    'created' => false,
                    'generation' => $generation,
                    // "Previous" means the generation that was current when
                    // publication began. On an exact no-op refresh that is the
                    // same immutable generation being returned.
                    'previousGeneration' => $generation,
                ];
            }

            $parentId = $currentRow !== null ? (int) $currentRow['id'] : null;
            $generatedAt = $this->nullableString($graphData['meta']['generatedAt'] ?? null)
                ?? $this->timestamp();
            $sourceChanges = $this->sourceChanges(
                $pdo,
                $parentId,
                $this->sourceFiles($graphData['meta'] ?? []),
            );
            $insertGeneration = $pdo->prepare(<<<'SQL'
                INSERT INTO generations (
                    parent_generation_id, generated_at, committed_at,
                    graph_fingerprint, semantic_fingerprint, source_fingerprint,
                    app_name, laravel_version, appgraph_version,
                    application_environment, configuration_fingerprint,
                    container_bindings_fingerprint, execution_registry_fingerprint,
                    source_changes_json, meta_json, meta_hash, node_count, edge_count
                ) VALUES (
                    :parent, :generated, :committed,
                    :graph, :semantic, :source,
                    :app, :laravel, :appgraph,
                    :environment, :configuration,
                    :container_bindings, :execution_registry,
                    :source_changes, '{}', :meta_hash, :nodes, :edges
                )
            SQL);
            $scan = is_array($graphData['meta']['scan'] ?? null) ? $graphData['meta']['scan'] : [];
            $insertGeneration->execute([
                'parent' => $parentId,
                'generated' => $generatedAt,
                'committed' => $this->timestamp(),
                'graph' => $graphFingerprint,
                'semantic' => $semanticFingerprint,
                'source' => $sourceFingerprint,
                'app' => $this->nullableString($graphData['meta']['appName'] ?? null),
                'laravel' => $this->nullableString($graphData['meta']['laravelVersion'] ?? null),
                'appgraph' => $this->nullableString($graphData['meta']['appgraphVersion'] ?? null),
                'environment' => $this->nullableString($scan['applicationEnvironment'] ?? null),
                'configuration' => $this->nullableString($scan['configuration'] ?? null),
                'container_bindings' => $this->nullableString($scan['containerBindings'] ?? null),
                'execution_registry' => $this->nullableString($scan['laravelExecutionRegistry'] ?? null),
                'source_changes' => $this->encode($sourceChanges),
                'meta_hash' => hash('sha256', "meta\0{}"),
                'nodes' => count($nodeFacts),
                'edges' => count($edgeFacts),
            ]);
            $generationId = (int) $pdo->lastInsertId();
            $generationRow = $this->generationRow($pdo, (string) $generationId);
            $generation = $this->generationSummary($generationRow, true);
            $canonicalMeta = is_array($graphData['meta'] ?? null) ? $graphData['meta'] : [];
            $canonicalMeta['generation'] = $generation;
            $metaJson = $this->encodeCanonical($canonicalMeta);

            if (strlen($metaJson) > self::MAX_GENERATION_META_BYTES) {
                throw new RuntimeException('AppGraph generation metadata exceeds the 16 MiB storage limit.');
            }

            $canonicalMeta = $this->decode($metaJson, 'generation metadata');
            $pdo->prepare('UPDATE generations SET meta_json = :meta, meta_hash = :hash WHERE id = :id')->execute([
                'meta' => $metaJson,
                'hash' => hash('sha256', "meta\0".$metaJson),
                'id' => $generationId,
            ]);

            $this->insertNodes($pdo, $generationId, $nodeFacts);
            $this->insertEdges($pdo, $generationId, $edgeFacts);
            $this->insertSourceFiles($pdo, $generationId, $this->sourceFiles($graphData['meta'] ?? []));
            $this->insertCounts($pdo, $generationId, $nodeFacts, $edgeFacts);
            $this->assertGenerationIntegrity(
                $pdo,
                $this->generationRow($pdo, (string) $generationId),
            );
            $pdo->prepare('UPDATE store_state SET current_generation_id = :id WHERE singleton = 1')
                ->execute(['id' => $generationId]);
            $this->prune($pdo);
            $this->assertStoreStateIntegrity($pdo);
            $pdo->commit();
            $graph->replaceMeta($canonicalMeta);
            $this->maintenance($pdo);

            return [
                'created' => true,
                'generation' => $generation,
                'previousGeneration' => $previousGeneration,
            ];
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }
    }

    /** @return array<string, mixed>|null */
    public function current(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        return $this->withSnapshot(function (PDO $pdo): ?array {
            $row = $this->currentRow($pdo);

            if ($row !== null) {
                $this->assertGenerationMetadataIntegrity($pdo, $row);
            }

            return $row !== null ? $this->generationSummary($row, true) : null;
        });
    }

    /**
     * Resolve a generation and verify every persisted fact, membership,
     * manifest entry, count, and aggregate fingerprint in one read snapshot.
     * Use this before expensive work that promises to preserve a baseline.
     *
     * @return array<string, mixed>
     */
    public function verifyGeneration(string $generation = 'current'): array
    {
        return $this->withSnapshot(function (PDO $pdo) use ($generation): array {
            $row = $this->resolveGenerationRow($pdo, $generation);
            $current = $this->currentRow($pdo);
            $this->assertGenerationIntegrity($pdo, $row);

            return $this->generationSummary(
                $row,
                $current !== null && (int) $current['id'] === (int) $row['id'],
            );
        });
    }

    /**
     * @return array{currentGeneration: string|null, generations: array<int, array<string, mixed>>, truncated: bool}
     */
    public function generations(int $limit = 20, ?string $beforeGeneration = null): array
    {
        $limit = max(1, min(100, $limit));

        return $this->withSnapshot(function (PDO $pdo) use ($limit, $beforeGeneration): array {
            $current = $this->currentRow($pdo);
            $before = null;

            if ($beforeGeneration !== null) {
                $before = $this->numericGenerationId($beforeGeneration, 'pagination cursor');
            }

            $sql = 'SELECT * FROM generations'.($before !== null ? ' WHERE id < :before' : '')
                .' ORDER BY id DESC LIMIT :limit';
            $statement = $pdo->prepare($sql);

            if ($before !== null) {
                $statement->bindValue('before', $before, PDO::PARAM_INT);
            }

            $statement->bindValue('limit', $limit + 1, PDO::PARAM_INT);
            $statement->execute();
            $summaries = [];
            $truncated = false;

            while (($row = $statement->fetch()) !== false) {
                if (count($summaries) >= $limit) {
                    $truncated = true;

                    break;
                }

                $this->assertGenerationMetadataIntegrity($pdo, $row);
                $summaries[] = $this->generationSummary(
                    $row,
                    $current !== null && (int) $row['id'] === (int) $current['id'],
                );
            }

            return [
                'currentGeneration' => $current !== null ? (string) $current['id'] : null,
                'generations' => $summaries,
                'truncated' => $truncated,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function graph(string $generation = 'current'): array
    {
        return $this->withSnapshot(function (PDO $pdo) use ($generation): array {
            $row = $this->resolveGenerationRow($pdo, $generation);
            $meta = $this->assertGenerationMetadataIntegrity($pdo, $row);
            $current = $this->currentRow($pdo);
            $meta['generation'] = $this->canonicalize($this->generationSummary(
                $row,
                $current !== null && (int) $current['id'] === (int) $row['id'],
            ));
            $nodes = [];
            $nodeFacts = [];
            $actualCounts = ['node' => [], 'edge' => []];
            $nodeStatement = $pdo->prepare(<<<'SQL'
                SELECT
                    membership.node_id,
                    membership.type,
                    membership.label,
                    membership.name,
                    membership.file,
                    membership.line,
                    membership.end_line,
                    object.data_json,
                    object.content_hash,
                    object.semantic_hash,
                    object.byte_count
                FROM generation_nodes membership
                JOIN node_objects object ON object.id = membership.object_id
                WHERE membership.generation_id = :generation
                ORDER BY membership.type, membership.node_id
                SQL);
            $nodeStatement->execute(['generation' => $row['id']]);

            while (($object = $nodeStatement->fetch()) !== false) {
                $verified = $this->verifiedFactPayload('node', $object, [
                    'id' => (string) $object['node_id'],
                    'type' => (string) $object['type'],
                    'label' => (string) $object['label'],
                    'name' => $object['name'],
                    'file' => $object['file'],
                    'line' => $object['line'],
                    'endLine' => $object['end_line'],
                ]);
                $nodes[] = $verified['payload'];
                $nodeFacts[] = [
                    'identity' => (string) $object['node_id'],
                    'contentHash' => (string) $object['content_hash'],
                    'semanticHash' => $verified['semanticHash'],
                ];
                $actualCounts['node'][(string) $object['type']] =
                    ($actualCounts['node'][(string) $object['type']] ?? 0) + 1;
            }

            $edges = [];
            $edgeFacts = [];
            $edgeStatement = $pdo->prepare(<<<'SQL'
                SELECT
                    membership.edge_from,
                    membership.edge_to,
                    membership.type,
                    membership.confidence,
                    object.data_json,
                    object.content_hash,
                    object.semantic_hash,
                    object.byte_count
                FROM generation_edges membership
                JOIN edge_objects object ON object.id = membership.object_id
                WHERE membership.generation_id = :generation
                ORDER BY membership.edge_from, membership.type, membership.edge_to
                SQL);
            $edgeStatement->execute(['generation' => $row['id']]);

            while (($object = $edgeStatement->fetch()) !== false) {
                $verified = $this->verifiedFactPayload('edge', $object, [
                    'from' => (string) $object['edge_from'],
                    'to' => (string) $object['edge_to'],
                    'type' => (string) $object['type'],
                    'confidence' => $object['confidence'],
                ]);
                $edges[] = $verified['payload'];
                $edgeFacts[] = [
                    'identity' => (string) $object['edge_from']."\0".(string) $object['type']."\0".(string) $object['edge_to'],
                    'contentHash' => (string) $object['content_hash'],
                    'semanticHash' => $verified['semanticHash'],
                ];
                $actualCounts['edge'][(string) $object['type']] =
                    ($actualCounts['edge'][(string) $object['type']] ?? 0) + 1;
            }

            $this->assertGenerationAggregate($row, $nodeFacts, $edgeFacts);
            $this->assertGenerationTypeCounts($pdo, $row, $actualCounts);

            return ['meta' => $meta, 'nodes' => $nodes, 'edges' => $edges];
        });
    }

    /**
     * Run a callback inside one SQLite read transaction. Resolving current and
     * every subsequent statement therefore observe the same immutable snapshot.
     */
    public function withSnapshot(callable $callback): mixed
    {
        $pdo = $this->connect(create: false);
        $pdo->exec('PRAGMA query_only = ON');
        $pdo->beginTransaction();

        try {
            $this->verifySchema($pdo);
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }
    }

    /**
     * @return array{generation: array<string, mixed>, results: array<int, array<string, mixed>>, truncated: bool, fts: string}
     */
    public function searchNodes(
        string $term,
        ?string $type = null,
        int $limit = 50,
    ): array {
        $term = trim($term);

        if ($term === '' || str_contains($term, "\0")) {
            throw new RuntimeException('AppGraph search requires a nonblank term without NUL bytes.');
        }

        if (mb_strlen($term) > self::MAX_SEARCH_CHARACTERS || strlen($term) > self::MAX_SEARCH_BYTES) {
            throw new RuntimeException('AppGraph search terms must not exceed 512 characters or 2048 bytes.');
        }

        if ($type !== null && (
            trim($type) === ''
            || str_contains($type, "\0")
            || mb_strlen($type) > 128
            || strlen($type) > 512
        )) {
            throw new RuntimeException('AppGraph node types must be nonblank, omit NUL bytes, and stay within 128 characters or 512 bytes.');
        }

        $limit = max(1, min(200, $limit));

        return $this->withSnapshot(function (PDO $pdo) use ($term, $type, $limit): array {
            $generationRow = $this->resolveGenerationRow($pdo, 'current');
            $this->assertGenerationIntegrity($pdo, $generationRow);
            $ftsMode = (string) $pdo->query('SELECT fts_mode FROM store_state WHERE singleton = 1')->fetchColumn();
            $useFts = $this->enableFts
                && $ftsMode === 'trigram'
                && mb_strlen($term) >= 3
                && $this->ftsIndexMatchesFacts($pdo, (int) $generationRow['id']);
            $typeClause = $type !== null ? ' AND membership.type = :type' : '';

            if ($useFts) {
                try {
                    $results = $this->searchNodeResults(
                        $pdo,
                        (int) $generationRow['id'],
                        $term,
                        $type,
                        $limit,
                        $typeClause,
                        true,
                    );
                } catch (PDOException) {
                    // FTS is a disposable accelerator. Its content rows can
                    // still look complete while a damaged shadow index makes
                    // MATCH fail, so retry against authoritative memberships.
                    $useFts = false;
                    $results = $this->searchNodeResults(
                        $pdo,
                        (int) $generationRow['id'],
                        $term,
                        $type,
                        $limit,
                        $typeClause,
                        false,
                    );
                }
            } else {
                $results = $this->searchNodeResults(
                    $pdo,
                    (int) $generationRow['id'],
                    $term,
                    $type,
                    $limit,
                    $typeClause,
                    false,
                );
            }

            $truncated = count($results) > $limit;

            return [
                'generation' => $this->generationSummary(
                    $generationRow,
                    (int) $generationRow['id'] === (int) $pdo->query(
                        'SELECT current_generation_id FROM store_state WHERE singleton = 1'
                    )->fetchColumn(),
                ),
                'results' => array_slice($results, 0, $limit),
                'truncated' => $truncated,
                'fts' => $useFts ? 'trigram' : 'fallback',
            ];
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchNodeResults(
        PDO $pdo,
        int $generationId,
        string $term,
        ?string $type,
        int $limit,
        string $typeClause,
        bool $useFts,
    ): array {
        // Results are relevance-ranked: exact id/label/name match, then label or
        // name prefix, then suffix (class basenames), then bare substring, with
        // shorter labels first inside a tier. Node-id order is only the final
        // deterministic tiebreak, not the ranking.
        $rankExpression = <<<SQL
            CASE
                WHEN lower(membership.node_id) = lower(:rankExact)
                    OR lower(membership.label) = lower(:rankExact)
                    OR lower(membership.name) = lower(:rankExact) THEN 0
                WHEN lower(membership.label) LIKE lower(:rankPrefix) ESCAPE '\\'
                    OR lower(membership.name) LIKE lower(:rankPrefix) ESCAPE '\\' THEN 1
                WHEN lower(membership.label) LIKE lower(:rankSuffix) ESCAPE '\\'
                    OR lower(membership.name) LIKE lower(:rankSuffix) ESCAPE '\\' THEN 2
                ELSE 3
            END
            SQL;
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        if ($useFts) {
            $statement = $pdo->prepare(<<<SQL
                SELECT
                    membership.node_id,
                    membership.type,
                    membership.label,
                    membership.name,
                    membership.file,
                    membership.line,
                    membership.end_line,
                    object.data_json,
                    object.content_hash,
                    object.semantic_hash,
                    object.byte_count
                FROM node_search
                JOIN node_objects object ON object.id = node_search.rowid
                JOIN generation_nodes membership ON membership.object_id = object.id
                WHERE node_search MATCH :term
                  AND membership.generation_id = :generation
                  {$typeClause}
                ORDER BY {$rankExpression}, length(membership.label), membership.node_id
                LIMIT :limit
                SQL);
            $statement->bindValue('term', '"'.str_replace('"', '""', $term).'"');
        } else {
            $statement = $pdo->prepare(<<<SQL
                SELECT
                    membership.node_id,
                    membership.type,
                    membership.label,
                    membership.name,
                    membership.file,
                    membership.line,
                    membership.end_line,
                    object.data_json,
                    object.content_hash,
                    object.semantic_hash,
                    object.byte_count
                FROM generation_nodes membership
                JOIN node_objects object ON object.id = membership.object_id
                WHERE membership.generation_id = :generation
                  {$typeClause}
                  AND (
                    lower(membership.node_id) LIKE lower(:term) ESCAPE '\\'
                    OR lower(membership.label) LIKE lower(:term) ESCAPE '\\'
                    OR lower(membership.name) LIKE lower(:term) ESCAPE '\\'
                  )
                ORDER BY {$rankExpression}, length(membership.label), membership.node_id
                LIMIT :limit
                SQL);
            $statement->bindValue('term', '%'.$escaped.'%');
        }

        $statement->bindValue('rankExact', $term);
        $statement->bindValue('rankPrefix', $escaped.'%');
        $statement->bindValue('rankSuffix', '%'.$escaped);
        $statement->bindValue('generation', $generationId, PDO::PARAM_INT);

        if ($type !== null) {
            $statement->bindValue('type', $type);
        }

        $statement->bindValue('limit', $limit + 1, PDO::PARAM_INT);
        $statement->execute();
        $results = [];

        while (($object = $statement->fetch()) !== false) {
            $payload = $this->verifiedFactPayload('node', $object, [
                'id' => (string) $object['node_id'],
                'type' => (string) $object['type'],
                'label' => (string) $object['label'],
                'name' => $object['name'],
                'file' => $object['file'],
                'line' => $object['line'],
                'endLine' => $object['end_line'],
            ])['payload'];
            // Search is an agent-facing locator, not a full-fact endpoint.
            // Retain only the stable source projection so 201 valid 1 MiB
            // node facts cannot all remain resident in the result array.
            $results[] = array_filter([
                'id' => $payload['id'] ?? null,
                'type' => $payload['type'] ?? null,
                'label' => $payload['label'] ?? null,
                'file' => $payload['file'] ?? null,
                'line' => $payload['line'] ?? null,
                'endLine' => $payload['endLine'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $results;
    }

    /** @return array<string, mixed> */
    public function resolveGenerationRow(PDO $pdo, string $reference): array
    {
        $reference = trim($reference);

        if ($reference === 'current') {
            $row = $this->currentRow($pdo);

            if ($row === null) {
                throw new RuntimeException('AppGraph has no committed SQLite generation. Run `php artisan appgraph:scan` first.');
            }

            return $row;
        }

        try {
            $id = $this->numericGenerationId($reference, 'generation');
        } catch (RuntimeException) {
            throw new RuntimeException("Invalid AppGraph generation [{$reference}]. Use a numeric id or current.");
        }

        return $this->generationRow($pdo, (string) $id);
    }

    /** @return array<string, mixed> */
    public function generationSummary(array $row, bool $current): array
    {
        $id = $this->storedPositiveInteger($row['id'] ?? null, 'generation id');
        $parent = $row['parent_generation_id'] !== null
            ? $this->storedPositiveInteger($row['parent_generation_id'], 'parent generation id')
            : null;
        $nodeCount = $this->storedNonNegativeInteger($row['node_count'] ?? null, 'node count');
        $edgeCount = $this->storedNonNegativeInteger($row['edge_count'] ?? null, 'edge count');
        $sourceChangesJson = $row['source_changes_json'] ?? null;

        if (! is_string($sourceChangesJson)
            || strlen($sourceChangesJson) > self::MAX_SOURCE_CHANGES_BYTES) {
            throw $this->integrityException('invalid or unbounded source change summary');
        }

        $sourceChanges = $this->decode($sourceChangesJson, 'source change summary');

        foreach (['added', 'changed', 'removed'] as $kind) {
            if (! is_int($sourceChanges[$kind] ?? null) || $sourceChanges[$kind] < 0) {
                throw $this->integrityException('invalid source change summary');
            }
        }

        if (count($sourceChanges) !== 3
            || array_diff(array_keys($sourceChanges), ['added', 'changed', 'removed']) !== []) {
            throw $this->integrityException('invalid source change summary');
        }

        $generatedAt = $this->requiredBoundedStoredString(
            $row['generated_at'] ?? null,
            128,
            512,
            'generated timestamp',
        );
        $committedAt = $this->requiredBoundedStoredString(
            $row['committed_at'] ?? null,
            128,
            512,
            'committed timestamp',
        );
        $graphFingerprint = $this->requiredBoundedStoredString(
            $row['graph_fingerprint'] ?? null,
            128,
            512,
            'graph fingerprint',
        );
        $semanticFingerprint = $this->requiredBoundedStoredString(
            $row['semantic_fingerprint'] ?? null,
            128,
            512,
            'semantic fingerprint',
        );

        if (preg_match('/^[a-f0-9]{64}$/D', $graphFingerprint) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $semanticFingerprint) !== 1) {
            throw $this->integrityException('invalid generation aggregate fingerprint');
        }

        return array_filter([
            'id' => (string) $id,
            'sequence' => $id,
            'parentGeneration' => $parent !== null ? (string) $parent : null,
            'generatedAt' => $generatedAt,
            'committedAt' => $committedAt,
            'sourceFingerprint' => $this->boundedStoredString($row['source_fingerprint'] ?? null, 128, 512, 'source fingerprint'),
            'graphFingerprint' => $graphFingerprint,
            'semanticFingerprint' => $semanticFingerprint,
            'appName' => $this->boundedStoredString($row['app_name'] ?? null, 512, 2048, 'application name'),
            'laravelVersion' => $this->boundedStoredString($row['laravel_version'] ?? null, 128, 512, 'Laravel version'),
            'appgraphVersion' => $this->boundedStoredString($row['appgraph_version'] ?? null, 128, 512, 'AppGraph version'),
            'applicationEnvironment' => $this->boundedStoredString($row['application_environment'] ?? null, 128, 512, 'application environment'),
            'configurationFingerprint' => $this->boundedStoredString($row['configuration_fingerprint'] ?? null, 128, 512, 'configuration fingerprint'),
            'containerBindingsFingerprint' => $this->boundedStoredString($row['container_bindings_fingerprint'] ?? null, 128, 512, 'container bindings fingerprint'),
            'executionRegistryFingerprint' => $this->boundedStoredString($row['execution_registry_fingerprint'] ?? null, 128, 512, 'execution registry fingerprint'),
            'counts' => [
                'nodes' => $nodeCount,
                'edges' => $edgeCount,
            ],
            'sourceChanges' => $sourceChanges,
            'current' => $current,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function connect(bool $create): PDO
    {
        $this->assertRuntimeAvailable();
        $this->assertStoreArtifactsAreNotSymlinks();

        if (! $create && ! is_file($this->path)) {
            throw new RuntimeException("AppGraph SQLite store not found at [{$this->path}]. Run `php artisan appgraph:scan` first.");
        }

        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ];

            if (! $create) {
                // query_only can be disabled from SQL. The SQLite open flag is
                // the actual read-only boundary for query and diff callbacks.
                $options[\Pdo\Sqlite::ATTR_OPEN_FLAGS] = \Pdo\Sqlite::OPEN_READONLY;
            }

            $pdo = new PDO('sqlite:'.$this->path, null, null, $options);

            if ($create) {
                $this->secureStoreFiles();
            }

            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = '.$this->busyTimeoutMs);

            return $pdo;
        } catch (PDOException $exception) {
            throw new RuntimeException("Unable to open AppGraph SQLite store [{$this->path}]: {$exception->getMessage()}", 0, $exception);
        }
    }

    private function bootstrap(PDO $pdo): void
    {
        $this->assertBootstrapIdentity($pdo);
        $pdo->query('PRAGMA journal_mode = WAL')->fetchColumn();
        $pdo->exec('PRAGMA synchronous = FULL');
        $pdo->exec('BEGIN IMMEDIATE');

        try {
            // Recheck after taking the write lock so two first-use publishers
            // cannot race while claiming an empty database.
            $this->assertBootstrapIdentity($pdo);
            $applicationId = (int) $pdo->query('PRAGMA application_id')->fetchColumn();
            $freshStore = $applicationId === 0;

            if ($freshStore) {
                $pdo->exec('PRAGMA auto_vacuum = INCREMENTAL');
                $pdo->exec('PRAGMA application_id = '.self::APPLICATION_ID);
            }

            $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS generations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_generation_id INTEGER,
                generated_at TEXT NOT NULL,
                committed_at TEXT NOT NULL,
                graph_fingerprint TEXT NOT NULL,
                semantic_fingerprint TEXT NOT NULL,
                source_fingerprint TEXT,
                app_name TEXT,
                laravel_version TEXT,
                appgraph_version TEXT,
                application_environment TEXT,
                configuration_fingerprint TEXT,
                container_bindings_fingerprint TEXT,
                execution_registry_fingerprint TEXT,
                source_changes_json TEXT NOT NULL,
                meta_json TEXT NOT NULL,
                meta_hash TEXT NOT NULL,
                node_count INTEGER NOT NULL,
                edge_count INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS store_state (
                singleton INTEGER PRIMARY KEY CHECK (singleton = 1),
                current_generation_id INTEGER REFERENCES generations(id),
                fts_mode TEXT NOT NULL DEFAULT 'none'
            );

            CREATE TABLE IF NOT EXISTS node_objects (
                id INTEGER PRIMARY KEY,
                content_hash TEXT NOT NULL UNIQUE,
                semantic_hash TEXT NOT NULL,
                data_json TEXT NOT NULL,
                byte_count INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS edge_objects (
                id INTEGER PRIMARY KEY,
                content_hash TEXT NOT NULL UNIQUE,
                semantic_hash TEXT NOT NULL,
                data_json TEXT NOT NULL,
                byte_count INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS generation_nodes (
                generation_id INTEGER NOT NULL REFERENCES generations(id) ON DELETE CASCADE,
                node_id TEXT NOT NULL,
                object_id INTEGER NOT NULL REFERENCES node_objects(id),
                type TEXT NOT NULL,
                label TEXT NOT NULL,
                name TEXT,
                file TEXT,
                line INTEGER,
                end_line INTEGER,
                PRIMARY KEY (generation_id, node_id)
            ) WITHOUT ROWID;

            CREATE TABLE IF NOT EXISTS generation_edges (
                generation_id INTEGER NOT NULL REFERENCES generations(id) ON DELETE CASCADE,
                edge_from TEXT NOT NULL,
                edge_to TEXT NOT NULL,
                type TEXT NOT NULL,
                confidence REAL NOT NULL,
                object_id INTEGER NOT NULL REFERENCES edge_objects(id),
                PRIMARY KEY (generation_id, edge_from, type, edge_to)
            ) WITHOUT ROWID;

            CREATE TABLE IF NOT EXISTS generation_files (
                generation_id INTEGER NOT NULL REFERENCES generations(id) ON DELETE CASCADE,
                path TEXT NOT NULL,
                content_hash TEXT NOT NULL,
                PRIMARY KEY (generation_id, path)
            ) WITHOUT ROWID;

            CREATE TABLE IF NOT EXISTS generation_counts (
                generation_id INTEGER NOT NULL REFERENCES generations(id) ON DELETE CASCADE,
                entity TEXT NOT NULL,
                type TEXT NOT NULL,
                total INTEGER NOT NULL,
                PRIMARY KEY (generation_id, entity, type)
            ) WITHOUT ROWID;

            CREATE INDEX IF NOT EXISTS generation_nodes_type
                ON generation_nodes(generation_id, type, node_id);
            CREATE INDEX IF NOT EXISTS generation_nodes_file_v2
                ON generation_nodes(generation_id, file, node_id);

            -- Retired indexes, dropped here so pre-0.9 stores migrate on their
            -- next publish. The name index could never match because all name
            -- predicates wrap lower(name); the edge indexes served no default
            -- query plan (AppGraph never runs ANALYZE, so even the CLI
            -- verify-change neighborhood scan never selected them — dropping
            -- them trades that latent plan for publish speed and store size).
            -- generation_nodes_file is superseded by the trimmed _v2 shape.
            DROP INDEX IF EXISTS generation_nodes_name;
            DROP INDEX IF EXISTS generation_nodes_file;
            DROP INDEX IF EXISTS generation_edges_out;
            DROP INDEX IF EXISTS generation_edges_in;
            DROP INDEX IF EXISTS generation_edges_type;
            SQL);

            if ($freshStore) {
                $pdo->exec("INSERT INTO store_state (singleton, current_generation_id, fts_mode) VALUES (1, NULL, 'none')");
            }

            // Never guess a lost or regressed current pointer. Every successful
            // publisher leaves current on the greatest retained generation id,
            // so any other state is evidence of a damaged authoritative store.
            $this->assertStoreStateIntegrity($pdo);

            if (! $this->enableFts) {
                // A store that published under an fts-enabled configuration
                // would otherwise keep paying per-insert node_search
                // maintenance forever. Retire the accelerator entirely; a later
                // fts-enabled publish rebuilds it from verified facts.
                $pdo->exec("UPDATE store_state SET fts_mode = 'none' WHERE singleton = 1");
                $pdo->exec('DROP TABLE IF EXISTS node_search');
            }

            if ($this->enableFts) {
                try {
                    // Running this on every enabled bootstrap both creates the
                    // optional index and heals rows omitted while query-time FTS
                    // was disabled in a prior process.
                    $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS node_search USING fts5(node_id, label, tokenize='trigram')");
                    // FTS is a disposable accelerator, never authority. Rebuild
                    // it from verified membership facts on every enabled
                    // publication so altered or orphaned derived rows heal.
                    $pdo->exec('DELETE FROM node_search');
                    $pdo->exec(<<<'SQL'
                        INSERT INTO node_search (rowid, node_id, label)
                        SELECT object.id, MIN(membership.node_id), MIN(membership.label)
                        FROM node_objects object
                        JOIN generation_nodes membership ON membership.object_id = object.id
                        GROUP BY object.id
                        SQL);
                    $pdo->exec("UPDATE store_state SET fts_mode = 'trigram' WHERE singleton = 1");
                } catch (PDOException) {
                    $pdo->exec("UPDATE store_state SET fts_mode = 'none' WHERE singleton = 1");
                }
            }

            $pdo->exec('PRAGMA user_version = '.self::SCHEMA_VERSION);
            $pdo->commit();
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }
    }

    private function assertBootstrapIdentity(PDO $pdo): void
    {
        $applicationId = (int) $pdo->query('PRAGMA application_id')->fetchColumn();
        $userVersion = (int) $pdo->query('PRAGMA user_version')->fetchColumn();

        if ($applicationId !== 0 && $applicationId !== self::APPLICATION_ID) {
            throw new RuntimeException("Refusing to use non-AppGraph SQLite database [{$this->path}].");
        }

        if ($applicationId === 0) {
            $foreignObject = $pdo->query(<<<'SQL'
                SELECT name
                FROM sqlite_schema
                WHERE name NOT LIKE 'sqlite_%'
                ORDER BY name
                LIMIT 1
                SQL)->fetchColumn();

            if ($userVersion !== 0 || $foreignObject !== false) {
                throw new RuntimeException(
                    "Refusing to claim non-empty SQLite database [{$this->path}] as an AppGraph store. Configure appgraph.store.path to a dedicated file."
                );
            }

            return;
        }

        if ($userVersion > self::SCHEMA_VERSION) {
            throw new RuntimeException("AppGraph SQLite schema {$userVersion} is newer than supported schema ".self::SCHEMA_VERSION.'.');
        }

        if ($userVersion !== 0 && $userVersion < self::SCHEMA_VERSION) {
            throw new RuntimeException("AppGraph SQLite schema {$userVersion} requires a migration that this package does not provide.");
        }
    }

    private function ftsIndexMatchesFacts(PDO $pdo, int $generationId): bool
    {
        try {
            // The accelerator is global across retained generations, but a
            // query only needs its resolved generation to be represented
            // exactly. An altered historical membership must not make a
            // self-consistent global MIN() projection poison a valid current
            // snapshot that shares the same content-addressed object.
            $statement = $pdo->prepare(<<<'SQL'
                SELECT 1
                FROM generation_nodes expected
                LEFT JOIN node_search search ON search.rowid = expected.object_id
                WHERE expected.generation_id = :generation
                  AND (
                       search.rowid IS NULL
                    OR search.node_id IS NOT expected.node_id
                    OR search.label IS NOT expected.label
                  )
                LIMIT 1
                SQL);
            $statement->execute(['generation' => $generationId]);
            $mismatch = $statement->fetchColumn();

            return $mismatch === false;
        } catch (PDOException) {
            return false;
        }
    }

    private function verifySchema(PDO $pdo): void
    {
        $applicationId = (int) $pdo->query('PRAGMA application_id')->fetchColumn();
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();

        if ($applicationId !== self::APPLICATION_ID || $version !== self::SCHEMA_VERSION) {
            throw new RuntimeException("AppGraph SQLite store [{$this->path}] is missing or has an unsupported schema. Run `php artisan appgraph:scan` to rebuild it.");
        }

        $this->assertStoreStateIntegrity($pdo);
    }

    private function assertStoreStateIntegrity(PDO $pdo): void
    {
        try {
            $states = $pdo->query(
                'SELECT singleton, current_generation_id, fts_mode FROM store_state ORDER BY singleton'
            )->fetchAll();
            $generations = $pdo->query(
                'SELECT COUNT(*) AS total, MAX(id) AS latest_generation_id FROM generations'
            )->fetch();
        } catch (PDOException) {
            throw $this->integrityException('store state is missing or unreadable');
        }

        if (count($states) !== 1
            || ! is_array($states[0] ?? null)
            || $this->storedPositiveInteger($states[0]['singleton'] ?? null, 'store state singleton') !== 1
            || ! is_array($generations)) {
            throw $this->integrityException('store state row mismatch');
        }

        $ftsMode = $this->requiredBoundedStoredString(
            $states[0]['fts_mode'] ?? null,
            16,
            16,
            'FTS mode',
        );

        if (! in_array($ftsMode, ['none', 'trigram'], true)) {
            throw $this->integrityException('invalid FTS mode');
        }

        $total = $this->storedNonNegativeInteger(
            $generations['total'] ?? null,
            'generation total',
        );
        $current = $states[0]['current_generation_id'] ?? null;
        $latest = $generations['latest_generation_id'] ?? null;

        if ($total === 0) {
            if ($current !== null || $latest !== null) {
                throw $this->integrityException('empty store current-generation mismatch');
            }

            return;
        }

        $currentId = $this->storedPositiveInteger($current, 'current generation id');
        $latestId = $this->storedPositiveInteger($latest, 'latest generation id');

        if ($currentId !== $latestId) {
            throw $this->integrityException('current generation is not the latest retained generation');
        }
    }

    /** @param array<int, array<string, mixed>> $nodes @return array<int, array<string, mixed>> */
    private function nodeFacts(array $nodes): array
    {
        $facts = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || ! is_string($node['id'] ?? null) || ! is_string($node['type'] ?? null)) {
                throw new RuntimeException('AppGraph cannot store a node without string id and type fields.');
            }

            $id = $this->factString($node['id'], 4096, 16384, 'node id');
            $type = $this->factString($node['type'], 256, 1024, 'node type');
            $label = is_scalar($node['label'] ?? null)
                ? $this->factString((string) $node['label'], 4096, 16384, 'node label')
                : $id;
            $name = is_scalar($node['metadata']['name'] ?? null)
                ? $this->factString((string) $node['metadata']['name'], 4096, 16384, 'node name', true)
                : null;
            $file = $this->nullableString($node['file'] ?? null);

            if ($file !== null) {
                $file = $this->factString($file, 4096, 16384, 'node file');
            }

            $json = $this->encodeCanonical($node);

            if (strlen($json) > self::MAX_FACT_BYTES) {
                throw new RuntimeException("AppGraph node [{$id}] exceeds the 1 MiB fact limit.");
            }

            $semantic = $this->semanticPayload($node, true);
            $facts[] = [
                'identity' => $id,
                'type' => $type,
                'label' => $label,
                'name' => $name,
                'file' => $file,
                'line' => is_int($node['line'] ?? null) ? $node['line'] : null,
                'endLine' => is_int($node['endLine'] ?? null) ? $node['endLine'] : null,
                'json' => $json,
                'contentHash' => hash('sha256', "node\0".$json),
                'semanticHash' => hash('sha256', "node-semantic\0".$this->encodeCanonical($semantic)),
            ];
        }

        return $facts;
    }

    /** @param array<int, array<string, mixed>> $edges @return array<int, array<string, mixed>> */
    private function edgeFacts(array $edges): array
    {
        $facts = [];

        foreach ($edges as $edge) {
            if (! is_array($edge)
                || ! is_string($edge['from'] ?? null)
                || ! is_string($edge['to'] ?? null)
                || ! is_string($edge['type'] ?? null)) {
                throw new RuntimeException('AppGraph cannot store an edge without string from, to, and type fields.');
            }

            $from = $this->factString($edge['from'], 4096, 16384, 'edge source id');
            $to = $this->factString($edge['to'], 4096, 16384, 'edge target id');
            $type = $this->factString($edge['type'], 256, 1024, 'edge type');
            $confidence = (float) ($edge['confidence'] ?? 1.0);

            if (! is_finite($confidence) || $confidence < 0.0 || $confidence > 1.0) {
                throw new RuntimeException("AppGraph edge [{$from}] -{$type}-> [{$to}] has invalid confidence.");
            }

            $json = $this->encodeCanonical($edge);

            if (strlen($json) > self::MAX_FACT_BYTES) {
                throw new RuntimeException(
                    "AppGraph edge [{$from}] -{$type}-> [{$to}] exceeds the 1 MiB fact limit."
                );
            }

            $facts[] = [
                'identity' => $from."\0".$type."\0".$to,
                'from' => $from,
                'to' => $to,
                'type' => $type,
                'confidence' => $confidence,
                'json' => $json,
                'contentHash' => hash('sha256', "edge\0".$json),
                'semanticHash' => hash('sha256', "edge-semantic\0".$this->encodeCanonical($this->semanticPayload($edge, false))),
            ];
        }

        return $facts;
    }

    /** @param array<int, array<string, mixed>> $nodeFacts @param array<int, array<string, mixed>> $edgeFacts */
    private function aggregateFingerprint(
        string $prefix,
        array $nodeFacts,
        array $edgeFacts,
        string $hashKey,
    ): string {
        $context = hash_init('sha256');
        hash_update($context, $prefix."\0");

        foreach ($nodeFacts as $fact) {
            hash_update($context, "N\0{$fact['identity']}\0{$fact[$hashKey]}\n");
        }

        foreach ($edgeFacts as $fact) {
            hash_update($context, "E\0{$fact['identity']}\0{$fact[$hashKey]}\n");
        }

        return hash_final($context);
    }

    /** @param array<int, array<string, mixed>> $facts */
    private function insertNodes(PDO $pdo, int $generationId, array $facts): void
    {
        $insertObject = $pdo->prepare(
            'INSERT OR IGNORE INTO node_objects (content_hash, semantic_hash, data_json, byte_count) VALUES (:hash, :semantic, :json, :bytes)'
        );
        $selectObject = $pdo->prepare('SELECT id FROM node_objects WHERE content_hash = :hash');
        $insertMembership = $pdo->prepare(<<<'SQL'
            INSERT INTO generation_nodes (
                generation_id, node_id, object_id, type, label, name, file, line, end_line
            ) VALUES (:generation, :node, :object, :type, :label, :name, :file, :line, :end_line)
            SQL);
        $ftsMode = (string) $pdo->query('SELECT fts_mode FROM store_state WHERE singleton = 1')->fetchColumn();
        // Once a store has a trigram index, maintain it even when this process
        // has query-time FTS disabled. Re-enabling must never expose holes. A
        // missing or unusable derived index is downgraded instead of blocking
        // publication of authoritative generation facts.
        $insertSearch = null;

        if ($ftsMode === 'trigram') {
            try {
                $insertSearch = $pdo->prepare(
                    'INSERT OR IGNORE INTO node_search (rowid, node_id, label) VALUES (:rowid, :node, :label)'
                );
            } catch (PDOException) {
                $pdo->exec("UPDATE store_state SET fts_mode = 'none' WHERE singleton = 1");
            }
        }

        foreach ($facts as $fact) {
            $insertObject->execute([
                'hash' => $fact['contentHash'],
                'semantic' => $fact['semanticHash'],
                'json' => $fact['json'],
                'bytes' => strlen($fact['json']),
            ]);
            $selectObject->execute(['hash' => $fact['contentHash']]);
            $objectId = $selectObject->fetchColumn();

            if (! is_int($objectId)) {
                $objectId = (int) $objectId;
            }

            $insertMembership->execute([
                'generation' => $generationId,
                'node' => $fact['identity'],
                'object' => $objectId,
                'type' => $fact['type'],
                'label' => $fact['label'],
                'name' => $fact['name'],
                'file' => $fact['file'],
                'line' => $fact['line'],
                'end_line' => $fact['endLine'],
            ]);
            if ($insertSearch !== null) {
                try {
                    $insertSearch->execute([
                        'rowid' => $objectId,
                        'node' => $fact['identity'],
                        'label' => $fact['label'],
                    ]);
                } catch (PDOException) {
                    $insertSearch = null;
                    $pdo->exec("UPDATE store_state SET fts_mode = 'none' WHERE singleton = 1");
                }
            }
        }
    }

    /** @param array<int, array<string, mixed>> $facts */
    private function insertEdges(PDO $pdo, int $generationId, array $facts): void
    {
        $insertObject = $pdo->prepare(
            'INSERT OR IGNORE INTO edge_objects (content_hash, semantic_hash, data_json, byte_count) VALUES (:hash, :semantic, :json, :bytes)'
        );
        $selectObject = $pdo->prepare('SELECT id FROM edge_objects WHERE content_hash = :hash');
        $insertMembership = $pdo->prepare(<<<'SQL'
            INSERT INTO generation_edges (
                generation_id, edge_from, edge_to, type, confidence, object_id
            ) VALUES (:generation, :from, :to, :type, :confidence, :object)
            SQL);

        foreach ($facts as $fact) {
            $insertObject->execute([
                'hash' => $fact['contentHash'],
                'semantic' => $fact['semanticHash'],
                'json' => $fact['json'],
                'bytes' => strlen($fact['json']),
            ]);
            $selectObject->execute(['hash' => $fact['contentHash']]);
            $objectId = (int) $selectObject->fetchColumn();
            $insertMembership->execute([
                'generation' => $generationId,
                'from' => $fact['from'],
                'to' => $fact['to'],
                'type' => $fact['type'],
                'confidence' => $fact['confidence'],
                'object' => $objectId,
            ]);
        }
    }

    /** @param array<string, string> $files */
    private function insertSourceFiles(PDO $pdo, int $generationId, array $files): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO generation_files (generation_id, path, content_hash) VALUES (:generation, :path, :hash)'
        );

        foreach ($files as $path => $hash) {
            $statement->execute(['generation' => $generationId, 'path' => $path, 'hash' => $hash]);
        }
    }

    /** @param array<int, array<string, mixed>> $nodes @param array<int, array<string, mixed>> $edges */
    private function insertCounts(PDO $pdo, int $generationId, array $nodes, array $edges): void
    {
        $counts = ['node' => [], 'edge' => []];

        foreach ($nodes as $node) {
            $counts['node'][$node['type']] = ($counts['node'][$node['type']] ?? 0) + 1;
        }

        foreach ($edges as $edge) {
            $counts['edge'][$edge['type']] = ($counts['edge'][$edge['type']] ?? 0) + 1;
        }

        $statement = $pdo->prepare(
            'INSERT INTO generation_counts (generation_id, entity, type, total) VALUES (:generation, :entity, :type, :total)'
        );

        foreach ($counts as $entity => $byType) {
            ksort($byType);

            foreach ($byType as $type => $total) {
                $statement->execute([
                    'generation' => $generationId,
                    'entity' => $entity,
                    'type' => $type,
                    'total' => $total,
                ]);
            }
        }
    }

    private function prune(PDO $pdo): void
    {
        $statement = $pdo->prepare('SELECT id FROM generations ORDER BY id DESC LIMIT -1 OFFSET :retain');
        $statement->bindValue('retain', $this->retainedGenerations, PDO::PARAM_INT);
        $statement->execute();
        $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));

        if ($ids === []) {
            return;
        }

        $delete = $pdo->prepare('DELETE FROM generations WHERE id = :id');

        foreach ($ids as $id) {
            $delete->execute(['id' => $id]);
        }

        if ((string) $pdo->query('SELECT fts_mode FROM store_state WHERE singleton = 1')->fetchColumn() === 'trigram') {
            try {
                $pdo->exec('DELETE FROM node_search WHERE rowid IN (SELECT id FROM node_objects WHERE NOT EXISTS (SELECT 1 FROM generation_nodes WHERE object_id = node_objects.id))');
            } catch (PDOException) {
                $pdo->exec("UPDATE store_state SET fts_mode = 'none' WHERE singleton = 1");
            }
        }

        $pdo->exec('DELETE FROM node_objects WHERE NOT EXISTS (SELECT 1 FROM generation_nodes WHERE object_id = node_objects.id)');
        $pdo->exec('DELETE FROM edge_objects WHERE NOT EXISTS (SELECT 1 FROM generation_edges WHERE object_id = edge_objects.id)');
    }

    private function maintenance(PDO $pdo): void
    {
        try {
            $pdo->exec('PRAGMA wal_checkpoint(PASSIVE)');
            $pdo->exec('PRAGMA incremental_vacuum(64)');
        } catch (PDOException) {
            // Maintenance is opportunistic; the committed generation is valid.
        }
    }

    /** @return array<string, mixed>|null */
    private function currentRow(PDO $pdo): ?array
    {
        $row = $pdo->query(<<<'SQL'
            SELECT generation.*
            FROM store_state state
            JOIN generations generation ON generation.id = state.current_generation_id
            WHERE state.singleton = 1
            SQL)->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    private function generationRow(PDO $pdo, string $id): array
    {
        $statement = $pdo->prepare('SELECT * FROM generations WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if (! is_array($row)) {
            throw new RuntimeException("AppGraph generation [{$id}] was not found or is no longer retained.");
        }

        return $row;
    }

    /** @param array<string, mixed> $meta @return array<string, string> */
    private function sourceFiles(array $meta): array
    {
        $files = is_array($meta['scan']['files'] ?? null) ? $meta['scan']['files'] : [];
        $normalized = [];

        foreach ($files as $path => $hash) {
            if (is_string($path) && $path !== '' && is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1) {
                $normalized[str_replace('\\', '/', $path)] = $hash;
            }
        }

        ksort($normalized);

        return $normalized;
    }

    /** @param array<string, string> $after @return array{added: int, changed: int, removed: int} */
    private function sourceChanges(PDO $pdo, ?int $beforeGeneration, array $after): array
    {
        if ($beforeGeneration === null) {
            return ['added' => count($after), 'changed' => 0, 'removed' => 0];
        }

        $statement = $pdo->prepare('SELECT path, content_hash FROM generation_files WHERE generation_id = :generation');
        $statement->execute(['generation' => $beforeGeneration]);
        $before = [];

        foreach ($statement->fetchAll() as $row) {
            $before[$row['path']] = $row['content_hash'];
        }

        return [
            'added' => count(array_diff_key($after, $before)),
            'changed' => count(array_filter(
                array_intersect_key($after, $before),
                static fn (string $hash, string $path): bool => ! hash_equals($before[$path], $hash),
                ARRAY_FILTER_USE_BOTH,
            )),
            'removed' => count(array_diff_key($before, $after)),
        ];
    }

    /**
     * Decode and authenticate one content-addressed fact before it influences a
     * query. This catches valid-JSON corruption as well as membership rows that
     * no longer agree with their immutable object.
     *
     * @param array<string, mixed> $object
     * @param array<string, mixed> $expectedIdentity
     * @return array{payload: array<string, mixed>, semanticHash: string}
     */
    public function verifiedFactPayload(
        string $entity,
        array $object,
        array $expectedIdentity = [],
    ): array {
        if (! in_array($entity, ['node', 'edge'], true)
            || ! is_string($object['data_json'] ?? null)
            || ! is_string($object['content_hash'] ?? null)
            || ! is_string($object['semantic_hash'] ?? null)) {
            throw $this->integrityException("invalid {$entity} object record");
        }

        $byteCount = $this->storedNonNegativeInteger(
            $object['byte_count'] ?? null,
            "{$entity} object byte count",
        );

        if ($byteCount !== strlen($object['data_json']) || $byteCount > self::MAX_FACT_BYTES) {
            throw $this->integrityException("invalid or unbounded {$entity} object payload");
        }

        $payload = $this->decode($object['data_json'], "{$entity} payload");
        $canonical = $this->encodeCanonical($payload);
        $contentHash = hash('sha256', $entity."\0".$canonical);
        $semanticHash = hash(
            'sha256',
            $entity.'-semantic'."\0".$this->encodeCanonical(
                $this->semanticPayload($payload, $entity === 'node'),
            ),
        );

        if (! hash_equals($object['content_hash'], $contentHash)
            || ! hash_equals($object['semantic_hash'], $semanticHash)) {
            throw $this->integrityException("{$entity} content hash mismatch");
        }

        foreach ($expectedIdentity as $field => $expected) {
            $actual = $field === 'name'
                ? ($payload['metadata']['name'] ?? null)
                : ($payload[$field] ?? null);

            if ($field === 'confidence') {
                $matches = (is_int($actual) || is_float($actual))
                    && (is_int($expected) || is_float($expected))
                    && is_finite((float) $actual)
                    && is_finite((float) $expected)
                    && (float) $actual >= 0.0
                    && (float) $actual <= 1.0
                    && (float) $expected >= 0.0
                    && (float) $expected <= 1.0
                    && (float) $actual === (float) $expected;
            } elseif (in_array($field, ['line', 'endLine'], true)) {
                $matches = ($actual === null && $expected === null)
                    || (is_int($actual) && is_int($expected) && $expected === $actual);
            } else {
                $matches = $actual === $expected;
            }

            if (! $matches) {
                throw $this->integrityException("{$entity} membership field [{$field}] mismatch");
            }
        }

        return ['payload' => $payload, 'semanticHash' => $semanticHash];
    }

    /**
     * Verify a complete immutable generation before a diff or duplicate reuse
     * trusts its aggregate fingerprints. Missing memberships, swapped objects,
     * and valid-JSON payload corruption all fail closed.
     *
     * @param array<string, mixed> $row
     */
    public function assertGenerationIntegrity(PDO $pdo, array $row): void
    {
        $this->assertGenerationMetadataIntegrity($pdo, $row);
        $nodeFacts = [];
        $actualCounts = ['node' => [], 'edge' => []];
        $nodes = $pdo->prepare(<<<'SQL'
            SELECT
                membership.node_id,
                membership.type,
                membership.label,
                membership.name,
                membership.file,
                membership.line,
                membership.end_line,
                object.data_json,
                object.content_hash,
                object.semantic_hash,
                object.byte_count
            FROM generation_nodes membership
            JOIN node_objects object ON object.id = membership.object_id
            WHERE membership.generation_id = :generation
            ORDER BY membership.type, membership.node_id
            SQL);
        $nodes->execute(['generation' => $row['id']]);

        while (($object = $nodes->fetch()) !== false) {
            $verified = $this->verifiedFactPayload('node', $object, [
                'id' => (string) $object['node_id'],
                'type' => (string) $object['type'],
                'label' => (string) $object['label'],
                'name' => $object['name'],
                'file' => $object['file'],
                'line' => $object['line'],
                'endLine' => $object['end_line'],
            ]);
            $nodeFacts[] = [
                'identity' => (string) $object['node_id'],
                'contentHash' => (string) $object['content_hash'],
                'semanticHash' => $verified['semanticHash'],
            ];
            $actualCounts['node'][(string) $object['type']] =
                ($actualCounts['node'][(string) $object['type']] ?? 0) + 1;
        }

        $edgeFacts = [];
        $edges = $pdo->prepare(<<<'SQL'
            SELECT
                membership.edge_from,
                membership.edge_to,
                membership.type,
                membership.confidence,
                object.data_json,
                object.content_hash,
                object.semantic_hash,
                object.byte_count
            FROM generation_edges membership
            JOIN edge_objects object ON object.id = membership.object_id
            WHERE membership.generation_id = :generation
            ORDER BY membership.edge_from, membership.type, membership.edge_to
            SQL);
        $edges->execute(['generation' => $row['id']]);

        while (($object = $edges->fetch()) !== false) {
            $verified = $this->verifiedFactPayload('edge', $object, [
                'from' => (string) $object['edge_from'],
                'to' => (string) $object['edge_to'],
                'type' => (string) $object['type'],
                'confidence' => $object['confidence'],
            ]);
            $edgeFacts[] = [
                'identity' => (string) $object['edge_from']."\0".(string) $object['type']."\0".(string) $object['edge_to'],
                'contentHash' => (string) $object['content_hash'],
                'semanticHash' => $verified['semanticHash'],
            ];
            $actualCounts['edge'][(string) $object['type']] =
                ($actualCounts['edge'][(string) $object['type']] ?? 0) + 1;
        }

        $this->assertGenerationAggregate($row, $nodeFacts, $edgeFacts);
        $this->assertGenerationTypeCounts($pdo, $row, $actualCounts);
    }

    /**
     * @param array<string, mixed> $row
     * @param array{node: array<string, int>, edge: array<string, int>} $actualCounts
     */
    private function assertGenerationTypeCounts(PDO $pdo, array $row, array $actualCounts): void
    {
        foreach ($actualCounts as &$countsByType) {
            ksort($countsByType);
        }
        unset($countsByType);

        $storedCounts = ['node' => [], 'edge' => []];
        $counts = $pdo->prepare(
            'SELECT entity, type, total FROM generation_counts WHERE generation_id = :generation ORDER BY entity, type'
        );
        $counts->execute(['generation' => $row['id']]);

        foreach ($counts->fetchAll() as $count) {
            $entity = $this->requiredBoundedStoredString($count['entity'] ?? null, 8, 8, 'count entity');
            $type = $this->requiredBoundedStoredString($count['type'] ?? null, 512, 2048, 'count type');

            if (! array_key_exists($entity, $storedCounts)) {
                throw $this->integrityException('invalid count entity');
            }

            $storedCounts[$entity][$type] = $this->storedNonNegativeInteger(
                $count['total'] ?? null,
                'type count',
            );
        }

        if ($storedCounts !== $actualCounts) {
            throw $this->integrityException('generation type count mismatch');
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function assertGenerationMetadataIntegrity(PDO $pdo, array $row): array
    {
        $summary = $this->generationSummary($row, false);
        $meta = $this->verifiedGenerationMeta($row);
        $persistedSummary = is_array($meta['generation'] ?? null)
            ? $meta['generation']
            : null;

        if ($persistedSummary === null) {
            throw $this->integrityException('generation metadata summary is missing');
        }

        // `current` is a live view, not immutable generation content.
        $persistedSummary['current'] = false;

        if ($this->encodeCanonical($persistedSummary) !== $this->encodeCanonical($summary)) {
            throw $this->integrityException('generation metadata summary mismatch');
        }

        $scan = is_array($meta['scan'] ?? null) ? $meta['scan'] : [];
        $metaSourceFingerprint = $this->nullableString(
            $scan['generationFingerprint'] ?? $scan['fingerprint'] ?? null,
        );

        if ($metaSourceFingerprint !== ($summary['sourceFingerprint'] ?? null)) {
            throw $this->integrityException('generation source fingerprint mismatch');
        }

        $metaFiles = $this->verifiedSourceFiles($meta);
        $storedFiles = [];
        $files = $pdo->prepare(
            'SELECT path, content_hash FROM generation_files WHERE generation_id = :generation ORDER BY path'
        );
        $files->execute(['generation' => $row['id']]);

        foreach ($files->fetchAll() as $file) {
            $path = $this->requiredBoundedStoredString($file['path'] ?? null, 4096, 16384, 'source file path');
            $hash = $this->requiredBoundedStoredString($file['content_hash'] ?? null, 64, 64, 'source file hash');

            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw $this->integrityException('invalid source file hash');
            }

            $storedFiles[$path] = $hash;
        }

        if ($storedFiles !== $metaFiles) {
            throw $this->integrityException('generation source manifest mismatch');
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, string>> $nodeFacts
     * @param array<int, array<string, string>> $edgeFacts
     */
    private function assertGenerationAggregate(array $row, array $nodeFacts, array $edgeFacts): void
    {
        $summary = $this->generationSummary($row, false);

        if (count($nodeFacts) !== $summary['counts']['nodes']
            || count($edgeFacts) !== $summary['counts']['edges']) {
            throw $this->integrityException('generation membership count mismatch');
        }

        $graphFingerprint = $this->aggregateFingerprint(
            'graph',
            $nodeFacts,
            $edgeFacts,
            'contentHash',
        );
        $semanticFingerprint = $this->aggregateFingerprint(
            'semantic',
            $nodeFacts,
            $edgeFacts,
            'semanticHash',
        );

        if (! hash_equals($summary['graphFingerprint'], $graphFingerprint)
            || ! hash_equals($summary['semanticFingerprint'], $semanticFingerprint)) {
            throw $this->integrityException('generation aggregate fingerprint mismatch');
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function verifiedGenerationMeta(array $row): array
    {
        $json = $row['meta_json'] ?? null;
        $hash = $row['meta_hash'] ?? null;

        if (! is_string($json)
            || ! is_string($hash)
            || strlen($json) > self::MAX_GENERATION_META_BYTES
            || ! hash_equals($hash, hash('sha256', "meta\0".$json))) {
            throw $this->integrityException('generation metadata hash mismatch');
        }

        return $this->decode($json, 'generation metadata');
    }

    private function integrityException(string $reason): RuntimeException
    {
        return new RuntimeException(
            "AppGraph SQLite integrity check failed ({$reason}). Stop AppGraph writers, remove the dedicated store file [{$this->path}] and its -wal/-shm/-journal sidecars, then run `php artisan appgraph:scan`."
        );
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function semanticPayload(array $payload, bool $node): array
    {
        if ($node) {
            unset($payload['file'], $payload['line'], $payload['endLine']);
        }

        if (is_array($payload['metadata'] ?? null)) {
            $payload['metadata'] = $this->stripProvenance($payload['metadata']);

            if ($payload['metadata'] === []) {
                unset($payload['metadata']);
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $meta */
    private function generationEvidenceFingerprint(array $meta): string
    {
        // Publication timestamps and generated generation summaries are
        // intentionally volatile. PHP-fact cache counters describe scan cost,
        // not architectural completeness. Warnings and all other analysis
        // metadata remain part of duplicate identity so uncertainty cannot be
        // discarded by reusing an older generation.
        unset($meta['generatedAt'], $meta['generation']);

        // Correlates runtime evidence only within one PHP process so a
        // long-lived MCP parent never compares its stale registries to a fresh
        // child scan. It is intentionally not immutable generation evidence.
        unset($meta['scan']['runtimeEvidenceSession']);

        if (is_array($meta['analysis']['phpFileFacts'] ?? null)) {
            unset(
                $meta['analysis']['phpFileFacts']['persistent'],
                $meta['analysis']['phpFileFacts']['memoryEntries'],
                $meta['analysis']['phpFileFacts']['counters'],
            );

            if ($meta['analysis']['phpFileFacts'] === []) {
                unset($meta['analysis']['phpFileFacts']);
            }
        }

        if (($meta['analysis'] ?? null) === []) {
            unset($meta['analysis']);
        }

        return hash('sha256', "generation-evidence\0".$this->encodeCanonical($meta));
    }

    private function stripProvenance(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            if (in_array((string) $key, ['evidence', 'sources', 'file', 'line', 'endLine', 'source', 'rule', 'syntax', 'inference'], true)) {
                unset($value[$key]);
                continue;
            }

            $value[$key] = $this->stripProvenance($item);
        }

        return $value;
    }

    private function encodeCanonical(mixed $value): string
    {
        return $this->encode($this->canonicalize($value));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Unable to encode AppGraph SQLite payload: {$exception->getMessage()}", 0, $exception);
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $json, string $label): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("AppGraph SQLite {$label} is invalid JSON: {$exception->getMessage()}", 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("AppGraph SQLite {$label} must decode to an object.");
        }

        return $decoded;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function factString(
        string $value,
        int $maximumCharacters,
        int $maximumBytes,
        string $label,
        bool $allowEmpty = false,
    ): string {
        if ((! $allowEmpty && $value === '')
            || str_contains($value, "\0")
            || preg_match('//u', $value) !== 1
            || mb_strlen($value) > $maximumCharacters
            || strlen($value) > $maximumBytes) {
            $shape = $allowEmpty
                ? 'UTF-8 without NUL bytes'
                : 'nonblank UTF-8 without NUL bytes';
            throw new RuntimeException(
                "AppGraph {$label} must be {$shape} and within {$maximumCharacters} characters."
            );
        }

        return $value;
    }

    private function boundedStoredString(
        mixed $value,
        int $maximumCharacters,
        int $maximumBytes,
        string $label,
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)
            || str_contains($value, "\0")
            || preg_match('//u', $value) !== 1
            || mb_strlen($value) > $maximumCharacters
            || strlen($value) > $maximumBytes) {
            throw $this->integrityException("invalid or unbounded {$label}");
        }

        return $value;
    }

    private function requiredBoundedStoredString(
        mixed $value,
        int $maximumCharacters,
        int $maximumBytes,
        string $label,
    ): string {
        $value = $this->boundedStoredString($value, $maximumCharacters, $maximumBytes, $label);

        if ($value === null) {
            throw $this->integrityException("missing {$label}");
        }

        return $value;
    }

    private function storedPositiveInteger(mixed $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if (! is_int($integer)) {
            throw $this->integrityException("invalid {$label}");
        }

        return $integer;
    }

    private function storedNonNegativeInteger(mixed $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);

        if (! is_int($integer)) {
            throw $this->integrityException("invalid {$label}");
        }

        return $integer;
    }

    private function numericGenerationId(string $reference, string $label): int
    {
        $reference = trim($reference);

        if (strlen($reference) > 19 || preg_match('/^[1-9]\d*$/D', $reference) !== 1) {
            throw new RuntimeException("Invalid AppGraph {$label} [{$reference}]; use a positive numeric generation id.");
        }

        $value = filter_var($reference, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if (! is_int($value)) {
            throw new RuntimeException("Invalid AppGraph {$label} [{$reference}]; use a positive numeric generation id.");
        }

        return $value;
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function assertRuntimeAvailable(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('AppGraph SQLite storage requires the pdo_sqlite PHP extension.');
        }
    }

    /** @param array<string, mixed> $meta @return array<string, string> */
    private function verifiedSourceFiles(array $meta): array
    {
        $raw = $meta['scan']['files'] ?? [];

        if (! is_array($raw)) {
            throw $this->integrityException('invalid source manifest');
        }

        $files = [];

        foreach ($raw as $path => $hash) {
            if (! is_string($path)
                || $path === ''
                || str_contains($path, "\0")
                || strlen($path) > 16384
                || ! is_string($hash)
                || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw $this->integrityException('invalid source manifest entry');
            }

            $normalized = str_replace('\\', '/', $path);

            if (isset($files[$normalized])) {
                throw $this->integrityException('duplicate normalized source manifest path');
            }

            $files[$normalized] = $hash;
        }

        ksort($files);

        return $files;
    }

    private function secureStoreFiles(): void
    {
        $this->assertStoreArtifactsAreNotSymlinks();

        foreach ([$this->path, $this->path.'-wal', $this->path.'-shm', $this->path.'-journal'] as $path) {
            if (is_file($path) && ! @chmod($path, 0600)) {
                throw new RuntimeException("Unable to restrict AppGraph store permissions for [{$path}].");
            }
        }
    }

    private function assertStoreArtifactsAreNotSymlinks(): void
    {
        foreach ([$this->path, $this->path.'-wal', $this->path.'-shm', $this->path.'-journal'] as $path) {
            if (is_link($path)) {
                throw new RuntimeException(
                    "Refusing to use symbolic link [{$path}] as an authoritative AppGraph store artifact. Configure a dedicated regular file."
                );
            }
        }
    }
}
