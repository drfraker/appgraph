<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Query\GraphIndex;
use AppGraph\Storage\GraphStore;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class GraphStoreTest extends TestCase
{
    /** @var array<int, string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            foreach (glob($directory.'/*') ?: [] as $path) {
                if (is_file($path) || is_link($path)) {
                    @unlink($path);
                }
            }

            @rmdir($directory);
        }

        parent::tearDown();
    }

    public function test_it_round_trips_an_exact_graph_and_reuses_an_exact_duplicate(): void
    {
        $store = $this->store();
        $graph = $this->graph(
            source: 'source-a',
            nodes: [
                Node::make('controller:Orders', 'controller', 'OrdersController', [
                    'file' => 'app/Http/Controllers/OrdersController.php',
                    'line' => 12,
                    'endLine' => 48,
                    'metadata' => ['middleware' => ['auth']],
                ]),
                Node::make('model:Order', 'model', 'Order'),
            ],
            edges: [
                new Edge('controller:Orders', 'model:Order', 'reads', 0.95, [
                    'column' => 'status',
                ]),
            ],
        );

        $first = $store->publish($graph);
        $expected = $graph->toArray();
        $duplicate = $store->publish($this->graph(
            source: 'source-a',
            nodes: [
                Node::make('controller:Orders', 'controller', 'OrdersController', [
                    'file' => 'app/Http/Controllers/OrdersController.php',
                    'line' => 12,
                    'endLine' => 48,
                    'metadata' => ['middleware' => ['auth']],
                ]),
                Node::make('model:Order', 'model', 'Order'),
            ],
            edges: [
                new Edge('controller:Orders', 'model:Order', 'reads', 0.95, [
                    'column' => 'status',
                ]),
            ],
        ));

        $this->assertTrue($first['created']);
        $this->assertFalse($duplicate['created']);
        $this->assertSame($first['generation']['id'], $duplicate['generation']['id']);
        $this->assertSame($this->canonicalize($expected), $this->canonicalize($store->graph()));
        $this->assertCount(1, $store->generations()['generations']);
        $this->assertTrue($store->hasCurrent());
    }

    public function test_same_graph_with_a_changed_source_fingerprint_creates_a_generation(): void
    {
        $store = $this->store();
        $first = $store->publish($this->graph(
            source: 'source-a',
            files: ['app/Order.php' => hash('sha256', 'before')],
        ));
        $second = $store->publish($this->graph(
            source: 'source-b',
            files: ['app/Order.php' => hash('sha256', 'after')],
        ));

        $this->assertTrue($second['created']);
        $this->assertNotSame($first['generation']['id'], $second['generation']['id']);
        $this->assertSame(
            $first['generation']['graphFingerprint'],
            $second['generation']['graphFingerprint'],
        );
        $this->assertSame('source-b', $second['generation']['sourceFingerprint']);
        $this->assertSame(
            ['added' => 0, 'changed' => 1, 'removed' => 0],
            $second['generation']['sourceChanges'],
        );
    }

    public function test_runtime_evidence_session_nonce_does_not_defeat_duplicate_reuse(): void
    {
        $store = $this->store();
        $firstGraph = $this->graph(source: 'same-source');
        $firstGraph->addMeta(['scan' => ['runtimeEvidenceSession' => str_repeat('a', 32)]]);
        $first = $store->publish($firstGraph);
        $secondGraph = $this->graph(source: 'same-source');
        $secondGraph->addMeta(['scan' => ['runtimeEvidenceSession' => str_repeat('b', 32)]]);
        $second = $store->publish($secondGraph);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['generation']['id'], $second['generation']['id']);
        $this->assertSame(
            str_repeat('a', 32),
            $store->graph()['meta']['scan']['runtimeEvidenceSession'],
        );
    }

    public function test_retention_never_drops_below_two_and_keeps_the_current_generation(): void
    {
        $store = $this->store(retainedGenerations: 1);

        $first = $store->publish($this->graph(source: 'source-1', label: 'One'));
        $second = $store->publish($this->graph(source: 'source-2', label: 'Two'));
        $third = $store->publish($this->graph(source: 'source-3', label: 'Three'));
        $listed = $store->generations(limit: 100);

        $this->assertSame($third['generation']['id'], $listed['currentGeneration']);
        $this->assertSame(
            [$third['generation']['id'], $second['generation']['id']],
            array_column($listed['generations'], 'id'),
        );
        $this->assertTrue($listed['generations'][0]['current']);
        $this->assertFalse($listed['generations'][1]['current']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was not found or is no longer retained');
        $store->graph($first['generation']['id']);
    }

    public function test_fts_search_is_substring_based_and_treats_query_operators_as_literal_text(): void
    {
        $store = $this->store(enableFts: true);
        $store->publish($this->graph(source: 'search', nodes: [
            Node::make('node:alpha', 'action', 'Alpha'),
            Node::make('node:beta-a', 'action', 'AlphaBeta first'),
            Node::make('node:beta-b', 'action', 'AlphaBeta second'),
            Node::make('node:literal', 'action', 'alpha OR zulu'),
            Node::make('node:zulu', 'action', 'Zulu'),
        ]));

        $substring = $store->searchNodes('phaBe');
        $literal = $store->searchNodes('alpha OR zulu');

        if ($substring['fts'] === 'trigram') {
            $this->assertSame(['node:beta-a', 'node:beta-b'], array_column($substring['results'], 'id'));
            $this->assertSame(['node:literal'], array_column($literal['results'], 'id'));
            $this->assertSame('trigram', $literal['fts']);

            return;
        }

        // Builds without FTS5 trigram support must retain the same literal,
        // deterministic behavior through the indexed LIKE fallback.
        $this->assertSame(['node:beta-a', 'node:beta-b'], array_column($substring['results'], 'id'));
        $this->assertSame(['node:literal'], array_column($literal['results'], 'id'));
        $this->assertSame('fallback', $literal['fts']);
    }

    public function test_fallback_search_escapes_wildcards_and_orders_by_node_id(): void
    {
        $store = $this->store(enableFts: false);
        $store->publish($this->graph(source: 'fallback', nodes: [
            Node::make('node:z', 'action', 'Common value'),
            Node::make('node:percent', 'action', 'Literal % marker'),
            Node::make('node:a', 'action', 'Common value'),
            Node::make('node:underscore', 'action', 'Literal _ marker'),
            Node::make('node:slash', 'action', 'Literal \\ marker'),
        ]));

        $ordered = $store->searchNodes('Common');

        $this->assertSame('fallback', $ordered['fts']);
        $this->assertSame(['node:a', 'node:z'], array_column($ordered['results'], 'id'));
        $this->assertSame(['node:percent'], array_column($store->searchNodes('%')['results'], 'id'));
        $this->assertSame(['node:underscore'], array_column($store->searchNodes('_')['results'], 'id'));
        $this->assertSame(['node:slash'], array_column($store->searchNodes('\\')['results'], 'id'));
    }

    public function test_fallback_search_matches_metadata_names(): void
    {
        $store = $this->store(enableFts: false);
        $store->publish($this->graph(source: 'fallback-name', nodes: [
            Node::make('route:GET:/dashboard', 'route', 'GET /dashboard', [
                'metadata' => ['name' => 'admin.home'],
            ]),
        ]));

        $result = $store->searchNodes('admin.home');

        $this->assertSame('fallback', $result['fts']);
        $this->assertSame(['route:GET:/dashboard'], array_column($result['results'], 'id'));
    }

    public function test_generation_references_and_search_inputs_are_strictly_bounded(): void
    {
        $store = $this->store(enableFts: false);
        $store->publish($this->graph(source: 'bounds'));

        foreach (['', '0', '-1', '1 OR 1=1', str_repeat('9', 129)] as $reference) {
            try {
                $store->graph($reference);
                $this->fail("Generation reference [{$reference}] should have been rejected.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Invalid AppGraph generation', $exception->getMessage());
            }
        }

        foreach (['', " \t\n ", "nul\0byte", str_repeat('x', 513), str_repeat("\u{1F600}", 513)] as $term) {
            try {
                $store->searchNodes($term);
                $this->fail('An invalid search term should have been rejected.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('AppGraph search', $exception->getMessage());
            }
        }

        foreach (['', str_repeat('t', 129)] as $type) {
            try {
                $store->searchNodes('node', type: $type);
                $this->fail('An invalid node type should have been rejected.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('AppGraph node types', $exception->getMessage());
            }
        }
    }

    public function test_publish_rolls_back_generation_objects_and_current_pointer_on_failure(): void
    {
        $store = $this->store();
        $baseline = $store->publish($this->graph(source: 'baseline'));
        $pdo = $this->pdo($store);
        $pdo->exec(<<<'SQL'
            CREATE TRIGGER reject_exploding_node
            BEFORE INSERT ON generation_nodes
            WHEN NEW.node_id = 'node:explode'
            BEGIN
                SELECT RAISE(ABORT, 'deliberate test failure');
            END
            SQL);

        try {
            $store->publish($this->graph(
                source: 'failing',
                nodes: [Node::make('node:explode', 'action', 'Explode')],
            ));
            $this->fail('The deliberately rejected generation should not publish.');
        } catch (Throwable $throwable) {
            $this->assertStringContainsString('deliberate test failure', $throwable->getMessage());
        }

        $this->assertSame($baseline['generation']['id'], $store->current()['id']);
        $this->assertCount(1, $store->generations()['generations']);
        $this->assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM node_objects WHERE data_json LIKE '%node:explode%'"
        )->fetchColumn());
    }

    public function test_wal_readers_keep_their_old_snapshot_while_a_new_generation_commits(): void
    {
        $writer = $this->store();
        $reader = new GraphStore($writer->path());
        $first = $writer->publish($this->graph(source: 'before', label: 'Before'));

        $snapshot = $reader->withSnapshot(function (PDO $pdo) use ($reader, $writer): array {
            $before = $reader->resolveGenerationRow($pdo, 'current');
            $published = $writer->publish($this->graph(source: 'after', label: 'After'));
            $during = $reader->resolveGenerationRow($pdo, 'current');

            return [
                'before' => (string) $before['id'],
                'during' => (string) $during['id'],
                'published' => $published['generation']['id'],
            ];
        });

        $this->assertSame($first['generation']['id'], $snapshot['before']);
        $this->assertSame($snapshot['before'], $snapshot['during']);
        $this->assertNotSame($snapshot['during'], $snapshot['published']);
        $this->assertSame($snapshot['published'], $reader->current()['id']);
    }

    public function test_changed_warning_evidence_publishes_a_new_generation(): void
    {
        $store = $this->store();
        $first = $this->graph(source: 'same');
        $first->addMeta([
            'generatedAt' => '2026-01-01T00:00:00.000000Z',
            'warnings' => [['scanner' => 'first']],
        ]);
        $published = $store->publish($first);
        $second = $this->graph(source: 'same');
        $second->addMeta([
            'generatedAt' => '2026-02-02T00:00:00.000000Z',
            'warnings' => [['scanner' => 'second']],
        ]);
        $secondPublication = $store->publish($second);

        $this->assertTrue($secondPublication['created']);
        $this->assertNotSame($published['generation']['id'], $secondPublication['generation']['id']);
        $this->assertSame('2026-02-02T00:00:00.000000Z', $second->meta()['generatedAt']);
        $this->assertSame([['scanner' => 'second']], $second->meta()['warnings']);
        $this->assertSame(
            $this->canonicalize($store->graph()['meta']),
            $this->canonicalize($second->meta()),
        );
    }

    public function test_identical_warning_evidence_reuses_the_authoritative_generation(): void
    {
        $store = $this->store();
        $first = $this->graph(source: 'same');
        $first->addMeta(['warnings' => [['scanner' => 'same', 'message' => 'same']]]);
        $published = $store->publish($first);
        $second = $this->graph(source: 'same');
        $second->addMeta(['warnings' => [['scanner' => 'same', 'message' => 'same']]]);
        $reused = $store->publish($second);

        $this->assertFalse($reused['created']);
        $this->assertSame($published['generation']['id'], $reused['generation']['id']);
        $this->assertSame(
            $this->canonicalize($store->graph()['meta']),
            $this->canonicalize($second->meta()),
        );
    }

    public function test_stable_php_parser_evidence_affects_duplicate_identity_but_counters_do_not(): void
    {
        $store = $this->store();
        $first = $this->graph(source: 'php-facts');
        $first->addMeta(['analysis' => ['phpFileFacts' => [
            'identity' => 'parser-a',
            'format' => 'facts-v2',
            'phpVersionId' => 80400,
            'parser' => ['class' => 'Parser', 'identity' => 'a'],
            'resolver' => ['replaceNodes' => false],
            'persistent' => true,
            'memoryEntries' => 1,
            'counters' => ['parses' => 1],
        ]]]);
        $firstPublication = $store->publish($first);
        $volatileOnly = $this->graph(source: 'php-facts');
        $volatileOnly->addMeta(['analysis' => ['phpFileFacts' => [
            'identity' => 'parser-a',
            'format' => 'facts-v2',
            'phpVersionId' => 80400,
            'parser' => ['class' => 'Parser', 'identity' => 'a'],
            'resolver' => ['replaceNodes' => false],
            'persistent' => false,
            'memoryEntries' => 999,
            'counters' => ['parses' => 999],
        ]]]);
        $reused = $store->publish($volatileOnly);

        $this->assertFalse($reused['created']);
        $this->assertSame($firstPublication['generation']['id'], $reused['generation']['id']);

        $parserChanged = $this->graph(source: 'php-facts');
        $parserChanged->addMeta(['analysis' => ['phpFileFacts' => [
            'identity' => 'parser-b',
            'format' => 'facts-v2',
            'phpVersionId' => 80400,
            'parser' => ['class' => 'Parser', 'identity' => 'b'],
            'resolver' => ['replaceNodes' => false],
            'counters' => ['parses' => 1],
        ]]]);
        $published = $store->publish($parserChanged);

        $this->assertTrue($published['created']);
        $this->assertNotSame($reused['generation']['id'], $published['generation']['id']);
    }

    public function test_it_refuses_to_claim_an_unrelated_unbranded_sqlite_database(): void
    {
        $store = $this->store();
        mkdir(dirname($store->path()), 0775, true);
        $pdo = $this->pdo($store);
        $pdo->exec('CREATE TABLE customer_records (id INTEGER PRIMARY KEY, name TEXT)');

        try {
            $store->publish($this->graph(source: 'must-not-publish'));
            $this->fail('An unrelated SQLite database should never be claimed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing to claim non-empty SQLite database', $exception->getMessage());
        }

        $this->assertSame(0, (int) $pdo->query('PRAGMA application_id')->fetchColumn());
        $this->assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_schema WHERE type = 'table' AND name = 'customer_records'"
        )->fetchColumn());
        $this->assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_schema WHERE type = 'table' AND name = 'generations'"
        )->fetchColumn());
    }

    public function test_authoritative_store_path_must_not_be_a_symbolic_link(): void
    {
        $directory = sys_get_temp_dir().'/appgraph-graph-store-'.bin2hex(random_bytes(8));
        $this->temporaryDirectories[] = $directory;
        mkdir($directory, 0775, true);
        $target = $directory.'/target.sqlite';
        $link = $directory.'/appgraph.sqlite';
        symlink($target, $link);
        $store = new GraphStore($link);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to use symbolic link');
        $store->publish($this->graph(source: 'symlink'));
    }

    public function test_trigram_index_remains_complete_across_enabled_disabled_enabled_processes(): void
    {
        $enabled = $this->store(enableFts: true);
        $enabled->publish($this->graph(source: 'first', nodes: [
            Node::make('node:first', 'action', 'First searchable node'),
        ]));

        if ($enabled->searchNodes('searchable')['fts'] !== 'trigram') {
            $this->assertSame('fallback', $enabled->searchNodes('searchable')['fts']);

            return;
        }

        $disabled = new GraphStore($enabled->path(), enableFts: false);
        $disabled->publish($this->graph(source: 'second', nodes: [
            Node::make('node:needle', 'action', 'UniqueNeedleValue'),
        ]));
        $reenabled = new GraphStore($enabled->path(), enableFts: true);
        $result = $reenabled->searchNodes('NeedleValue');

        $this->assertSame('trigram', $result['fts']);
        $this->assertSame(['node:needle'], array_column($result['results'], 'id'));
    }

    public function test_read_snapshot_rejects_writes_and_preserves_current(): void
    {
        $store = $this->store();
        $generation = $store->publish($this->graph(source: 'read-only'))['generation']['id'];

        try {
            $store->withSnapshot(static function (PDO $pdo): int {
                // query_only is defense in depth, not the read boundary.
                $pdo->exec('PRAGMA query_only = OFF');

                return $pdo->exec(
                    'UPDATE store_state SET current_generation_id = NULL WHERE singleton = 1'
                );
            });
            $this->fail('A read snapshot should reject writes.');
        } catch (\PDOException $exception) {
            $this->assertStringContainsString('readonly', strtolower($exception->getMessage()));
        }

        $this->assertSame($generation, $store->current()['id']);
    }

    public function test_precreated_empty_database_initializes_exactly_one_store_state_row(): void
    {
        $store = $this->store(enableFts: false);
        mkdir(dirname($store->path()), 0775, true);
        file_put_contents($store->path(), '');

        $published = $store->publish($this->graph(source: 'fresh-empty'));
        $state = $this->pdo($store)->query(
            'SELECT singleton, current_generation_id, fts_mode FROM store_state'
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->assertTrue($published['created']);
        $this->assertSame([[
            'singleton' => 1,
            'current_generation_id' => (int) $published['generation']['id'],
            'fts_mode' => 'none',
        ]], $state);
    }

    public function test_missing_store_state_fails_closed_on_reads_and_before_publication(): void
    {
        $store = $this->store(enableFts: false);
        $store->publish($this->graph(source: 'state-one', label: 'One'));
        $store->publish($this->graph(source: 'state-two', label: 'Two'));
        $pdo = $this->pdo($store);
        $pdo->exec('DELETE FROM store_state');

        foreach ([
            static fn (): bool => $store->hasCurrent(),
            static fn (): ?array => $store->current(),
            fn (): array => $store->publish($this->graph(source: 'state-three', label: 'Three')),
        ] as $operation) {
            try {
                $operation();
                $this->fail('A missing authoritative store state row must fail closed.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('store state', $exception->getMessage());
            }
        }

        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM generations')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM store_state')->fetchColumn());
    }

    public function test_regressed_current_pointer_fails_closed_without_forking_or_pruning_history(): void
    {
        $store = $this->store(retainedGenerations: 2, enableFts: false);
        $first = $store->publish($this->graph(source: 'pointer-one', label: 'One'))['generation']['id'];
        $second = $store->publish($this->graph(source: 'pointer-two', label: 'Two'))['generation']['id'];
        $pdo = $this->pdo($store);
        $pdo->prepare('UPDATE store_state SET current_generation_id = :generation WHERE singleton = 1')
            ->execute(['generation' => $first]);

        try {
            $store->generation($second);
            $this->fail('A read must reject a current pointer that no longer names the latest retained generation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'current generation is not the latest retained generation',
                $exception->getMessage(),
            );
        }

        try {
            $store->publish($this->graph(source: 'pointer-three', label: 'Three'));
            $this->fail('Publication must not fork from a regressed current pointer.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'current generation is not the latest retained generation',
                $exception->getMessage(),
            );
        }

        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM generations')->fetchColumn());
        $this->assertSame((int) $first, (int) $pdo->query(
            'SELECT current_generation_id FROM store_state WHERE singleton = 1'
        )->fetchColumn());
    }

    public function test_valid_json_tampering_fails_closed_against_the_content_hash(): void
    {
        $store = $this->store();
        $store->publish($this->graph(source: 'integrity'));
        $tampered = json_encode([
            'id' => 'node:tampered',
            'type' => 'action',
            'label' => 'Tampered',
        ], JSON_THROW_ON_ERROR);
        $this->pdo($store)->prepare(
            'UPDATE node_objects SET data_json = :json, byte_count = :bytes'
        )->execute(['json' => $tampered, 'bytes' => strlen($tampered)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('integrity check failed');
        $store->graph();
    }

    public function test_explicit_generation_verification_checks_facts_not_only_metadata(): void
    {
        $store = $this->store();
        $generation = $store->publish($this->graph(source: 'integrity'))['generation']['id'];
        $this->pdo($store)->exec('DELETE FROM generation_nodes');

        // Lightweight generation discovery remains available for summaries.
        $this->assertSame($generation, $store->generation($generation)['id']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('integrity check failed');
        $store->verifyGeneration($generation);
    }

    public function test_deleted_membership_prevents_duplicate_reuse_and_successor_publication(): void
    {
        $store = $this->store();
        $store->publish($this->graph(source: 'integrity'));
        $this->pdo($store)->exec('DELETE FROM generation_nodes');

        try {
            $store->publish($this->graph(source: 'integrity'));
            $this->fail('A generation with missing memberships must not be reused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('integrity check failed', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('integrity check failed');
        $store->publish($this->graph(source: 'successor', label: 'Successor'));
    }

    public function test_search_rejects_same_cardinality_membership_object_swaps(): void
    {
        $store = $this->store(enableFts: false);
        $first = $store->publish($this->graph(source: 'before', label: 'Before'))['generation']['id'];
        $second = $store->publish($this->graph(source: 'after', label: 'After'))['generation']['id'];
        $pdo = $this->pdo($store);
        $beforeObject = $pdo->prepare(
            'SELECT object_id FROM generation_nodes WHERE generation_id = :generation AND node_id = :node'
        );
        $beforeObject->execute(['generation' => $first, 'node' => 'node:example']);
        $pdo->prepare(<<<'SQL'
            UPDATE generation_nodes
            SET object_id = :object, label = 'Before'
            WHERE generation_id = :generation AND node_id = 'node:example'
            SQL)->execute([
                'object' => (int) $beforeObject->fetchColumn(),
                'generation' => $second,
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('generation aggregate fingerprint mismatch');
        $store->searchNodes('Before');
    }

    public function test_full_integrity_rejects_coerced_membership_scalars(): void
    {
        $lineStore = $this->store(enableFts: false);
        $lineGeneration = $lineStore->publish($this->graph(
            source: 'line-membership',
            nodes: [Node::make('node:line', 'action', 'Line', ['line' => 12])],
        ))['generation']['id'];
        $this->pdo($lineStore)->exec(
            "UPDATE generation_nodes SET line = '12garbage' WHERE node_id = 'node:line'"
        );

        try {
            $lineStore->verifyGeneration($lineGeneration);
            $this->fail('A text membership line must not compare equal after an integer cast.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('node membership field [line] mismatch', $exception->getMessage());
        }

        $confidenceStore = $this->store(enableFts: false);
        $confidenceGeneration = $confidenceStore->publish($this->graph(
            source: 'confidence-membership',
            nodes: [
                Node::make('node:from', 'action', 'From'),
                Node::make('node:to', 'action', 'To'),
            ],
            edges: [new Edge('node:from', 'node:to', 'calls', 0.5)],
        ))['generation']['id'];
        $this->pdo($confidenceStore)->exec(
            'UPDATE generation_edges SET confidence = 0.5000000000005'
        );

        try {
            $confidenceStore->verifyGeneration($confidenceGeneration);
            $this->fail('A changed membership confidence must not compare equal within an epsilon.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('edge membership field [confidence] mismatch', $exception->getMessage());
        }
    }

    public function test_hashed_metadata_summary_tampering_fails_closed(): void
    {
        $store = $this->store();
        $store->publish($this->graph(source: 'metadata'));
        $pdo = $this->pdo($store);
        $meta = json_decode((string) $pdo->query('SELECT meta_json FROM generations')->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        $meta['generation']['counts']['nodes'] = 999;
        $json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $pdo->prepare('UPDATE generations SET meta_json = :json, meta_hash = :hash')->execute([
            'json' => $json,
            'hash' => hash('sha256', "meta\0".$json),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('generation metadata summary mismatch');
        $store->graph();
    }

    public function test_source_manifest_and_type_count_tampering_fail_closed(): void
    {
        $manifestStore = $this->store();
        $manifestStore->publish($this->graph(
            source: 'manifest',
            files: ['app/Order.php' => hash('sha256', 'order')],
        ));
        $this->pdo($manifestStore)->exec('DELETE FROM generation_files');

        try {
            $manifestStore->graph();
            $this->fail('A missing source manifest row must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('source manifest mismatch', $exception->getMessage());
        }

        $countStore = $this->store();
        $countStore->publish($this->graph(source: 'counts'));
        $this->pdo($countStore)->exec('DELETE FROM generation_counts');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('generation type count mismatch');
        $countStore->publish($this->graph(source: 'counts'));
    }

    public function test_corrupt_protected_baseline_aborts_before_publication(): void
    {
        $store = $this->store(retainedGenerations: 2);
        $first = $store->publish($this->graph(
            source: 'one',
            files: ['app/One.php' => hash('sha256', 'one')],
        ))['generation']['id'];
        $second = $store->publish($this->graph(source: 'two', label: 'Two'))['generation']['id'];
        $pdo = $this->pdo($store);
        $pdo->prepare('DELETE FROM generation_files WHERE generation_id = :generation')
            ->execute(['generation' => $first]);

        try {
            $store->publish(
                $this->graph(source: 'three', label: 'Three'),
                protectedGeneration: $first,
            );
            $this->fail('A corrupt protected baseline must abort publication.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('integrity check failed', $exception->getMessage());
        }

        $this->assertSame($second, $store->current()['id']);
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM generations')->fetchColumn());
    }

    public function test_tampered_fts_rows_fall_back_and_are_healed_on_publication(): void
    {
        $store = $this->store(enableFts: true);
        $graph = $this->graph(source: 'fts-integrity', nodes: [
            Node::make('node:needle', 'action', 'UniqueNeedleValue'),
        ]);
        $store->publish($graph);

        if ($store->searchNodes('NeedleValue')['fts'] !== 'trigram') {
            $this->markTestSkipped('This SQLite build does not provide FTS5 trigram support.');
        }

        $this->pdo($store)->exec("UPDATE node_search SET label = 'tampered'");
        $fallback = $store->searchNodes('NeedleValue');

        $this->assertSame('fallback', $fallback['fts']);
        $this->assertSame(['node:needle'], array_column($fallback['results'], 'id'));

        $reused = $store->publish($this->graph(source: 'fts-integrity', nodes: [
            Node::make('node:needle', 'action', 'UniqueNeedleValue'),
        ]));

        $this->assertFalse($reused['created']);
        $this->assertSame('trigram', $store->searchNodes('NeedleValue')['fts']);
    }

    public function test_historical_membership_cannot_poison_fts_for_a_shared_current_object(): void
    {
        $store = $this->store(enableFts: true);
        $node = Node::make('node:needle', 'action', 'UniqueNeedleValue');
        $first = $store->publish($this->graph(source: 'fts-shared-one', nodes: [$node]));
        $store->publish($this->graph(source: 'fts-shared-two', nodes: [$node]));

        if ($store->searchNodes('NeedleValue')['fts'] !== 'trigram') {
            $this->markTestSkipped('This SQLite build does not provide FTS5 trigram support.');
        }

        $pdo = $this->pdo($store);
        $pdo->prepare(<<<'SQL'
            UPDATE generation_nodes
            SET node_id = 'node:aaa-corrupt', label = 'AAA corrupt'
            WHERE generation_id = :generation
              AND node_id = 'node:needle'
            SQL)->execute(['generation' => $first['generation']['id']]);

        // Enabled publication rebuilds the global disposable index. The
        // corrupted historical membership wins its MIN() projection for the
        // content-addressed object that the valid successor also reuses.
        $published = $store->publish($this->graph(source: 'fts-shared-three', nodes: [$node]));
        $search = $store->searchNodes('NeedleValue');

        $this->assertTrue($published['created']);
        $this->assertSame('fallback', $search['fts']);
        $this->assertSame(['node:needle'], array_column($search['results'], 'id'));
    }

    public function test_damaged_fts_shadow_index_falls_back_to_authoritative_memberships(): void
    {
        $store = $this->store(enableFts: true);
        $store->publish($this->graph(source: 'fts-shadow-integrity', nodes: [
            Node::make('node:needle', 'action', 'UniqueNeedleValue'),
        ]));

        if ($store->searchNodes('NeedleValue')['fts'] !== 'trigram') {
            $this->markTestSkipped('This SQLite build does not provide FTS5 trigram support.');
        }

        // Preserve the logical FTS content row (which the cheap completeness
        // check can validate) while removing its term-index segment.
        $this->pdo($store)->exec(<<<'SQL'
            DELETE FROM node_search_data
            WHERE id = (SELECT MAX(id) FROM node_search_data WHERE id > 10)
            SQL);
        $fallback = $store->searchNodes('NeedleValue');

        $this->assertSame('fallback', $fallback['fts']);
        $this->assertSame(['node:needle'], array_column($fallback['results'], 'id'));
    }

    public function test_fts_disabled_publication_downgrades_missing_or_unusable_derived_indexes(): void
    {
        foreach (['missing', 'unusable'] as $condition) {
            $store = $this->store(enableFts: false);
            $store->publish($this->graph(source: "fts-{$condition}-before", label: 'Before'));
            $pdo = $this->pdo($store);

            if ($condition === 'unusable') {
                $pdo->exec('CREATE TABLE node_search (unexpected_column TEXT)');
            }

            $pdo->exec("UPDATE store_state SET fts_mode = 'trigram' WHERE singleton = 1");
            $writer = new GraphStore($store->path(), enableFts: false);
            $published = $writer->publish($this->graph(
                source: "fts-{$condition}-after",
                label: "After {$condition}",
            ));

            $this->assertTrue($published['created']);
            $this->assertSame($published['generation']['id'], $writer->current()['id']);
            $this->assertSame('none', $pdo->query(
                'SELECT fts_mode FROM store_state WHERE singleton = 1'
            )->fetchColumn());

            $search = $writer->searchNodes("After {$condition}");
            $this->assertSame('fallback', $search['fts']);
            $this->assertSame(['node:example'], array_column($search['results'], 'id'));
        }
    }

    public function test_store_file_permissions_are_owner_only(): void
    {
        $store = $this->store();
        $store->publish($this->graph(source: 'permissions'));

        $this->assertSame(0600, fileperms($store->path()) & 0777);
    }

    public function test_store_sidecar_symlinks_are_rejected_without_touching_their_targets(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symbolic-link semantics differ on Windows.');
        }

        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            $store = $this->store(enableFts: false);
            $directory = dirname($store->path());
            mkdir($directory, 0775, true);
            $target = $directory.'/target'.str_replace('-', '.', $suffix);
            file_put_contents($target, 'unchanged');
            chmod($target, 0644);
            symlink($target, $store->path().$suffix);

            try {
                $store->publish($this->graph(source: 'sidecar-symlink'));
                $this->fail("The {$suffix} sidecar symlink must be rejected.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('symbolic link', $exception->getMessage());
            }

            clearstatcache(true, $target);
            $this->assertSame('unchanged', file_get_contents($target));
            $this->assertSame(0644, fileperms($target) & 0777);
        }
    }

    public function test_historical_search_marks_only_the_actual_current_generation(): void
    {
        $store = $this->store(enableFts: false);
        $first = $store->publish($this->graph(source: 'first', label: 'Common First'))['generation']['id'];
        $second = $store->publish($this->graph(source: 'second', label: 'Common Second'))['generation']['id'];

        $this->assertFalse($store->searchNodes('Common', generation: $first)['generation']['current']);
        $this->assertTrue($store->searchNodes('Common', generation: $second)['generation']['current']);
        $this->assertFalse($store->graph($first)['meta']['generation']['current']);
        $this->assertTrue($store->graph($second)['meta']['generation']['current']);
    }

    public function test_graph_index_cache_refreshes_when_current_becomes_historical(): void
    {
        $store = $this->store();
        $first = $store->publish($this->graph(source: 'first'))['generation']['id'];
        GraphIndex::forget($store->path());
        $currentIndex = GraphIndex::loadStore($store, $first);

        $this->assertTrue($currentIndex->meta()['generation']['current']);

        $store->publish($this->graph(source: 'second', label: 'Second'));
        $historicalIndex = GraphIndex::loadStore($store, $first);

        $this->assertFalse($historicalIndex->meta()['generation']['current']);
    }

    public function test_late_prune_failure_rolls_back_generation_pointer_memberships_and_objects(): void
    {
        $store = $this->store(retainedGenerations: 2);
        $store->publish($this->graph(source: 'one', label: 'One'));
        $second = $store->publish($this->graph(source: 'two', label: 'Two'));
        $pdo = $this->pdo($store);
        $pdo->exec(<<<'SQL'
            CREATE TRIGGER reject_generation_prune
            BEFORE DELETE ON generations
            BEGIN
                SELECT RAISE(ABORT, 'deliberate prune failure');
            END
            SQL);
        $third = $this->graph(source: 'three', nodes: [
            Node::make('node:three', 'action', 'Three'),
        ]);
        $metaBefore = $third->meta();

        try {
            $store->publish($third);
            $this->fail('The prune trigger should abort the whole publication.');
        } catch (Throwable $throwable) {
            $this->assertStringContainsString('deliberate prune failure', $throwable->getMessage());
        }

        $this->assertSame($second['generation']['id'], $store->current()['id']);
        $this->assertCount(2, $store->generations(limit: 100)['generations']);
        $this->assertSame($metaBefore, $third->meta());
        $this->assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM node_objects WHERE data_json LIKE '%node:three%'"
        )->fetchColumn());
    }

    public function test_wal_reader_retains_a_generation_while_concurrent_gc_prunes_it(): void
    {
        $writer = $this->store(retainedGenerations: 2);
        $reader = new GraphStore($writer->path(), retainedGenerations: 2);
        $first = $writer->publish($this->graph(source: 'one', label: 'One'))['generation']['id'];
        $writer->publish($this->graph(source: 'two', label: 'Two'));

        $observed = $reader->withSnapshot(function (PDO $pdo) use ($writer, $first): array {
            $statement = $pdo->prepare(<<<'SQL'
                SELECT object.data_json
                FROM generation_nodes membership
                JOIN node_objects object ON object.id = membership.object_id
                WHERE membership.generation_id = :generation
                SQL);
            $statement->execute(['generation' => $first]);
            $before = $statement->fetchColumn();
            $writer->publish($this->graph(source: 'three', label: 'Three'));
            $statement->execute(['generation' => $first]);

            return ['before' => $before, 'after' => $statement->fetchColumn()];
        });

        $this->assertSame($observed['before'], $observed['after']);
        $this->assertCount(2, $writer->generations(limit: 100)['generations']);
        $this->expectException(RuntimeException::class);
        $writer->graph($first);
    }

    public function test_generation_pagination_cursor_remains_valid_after_its_row_is_pruned(): void
    {
        $store = $this->store(retainedGenerations: 2);
        $first = $store->publish($this->graph(source: 'one'))['generation']['id'];
        $store->publish($this->graph(source: 'two', label: 'Two'));
        $store->publish($this->graph(source: 'three', label: 'Three'));
        $page = $store->generations(limit: 10, beforeGeneration: $first);

        $this->assertSame([], $page['generations']);
        $this->assertFalse($page['truncated']);
    }

    public function test_publication_protects_a_verification_baseline_from_retention_gc(): void
    {
        $store = $this->store(retainedGenerations: 2);
        $first = $store->publish($this->graph(source: 'one'))['generation']['id'];
        $second = $store->publish($this->graph(source: 'two', label: 'Two'))['generation']['id'];
        $third = $store->publish(
            $this->graph(source: 'three', label: 'Three'),
            protectedGeneration: $first,
        )['generation']['id'];

        $this->assertSame(
            [$third, $second, $first],
            array_column($store->generations(limit: 10)['generations'], 'id'),
        );
        $this->assertSame($first, $store->generation($first)['id']);

        $fourth = $store->publish($this->graph(source: 'four', label: 'Four'))['generation']['id'];

        $this->assertSame(
            [$fourth, $third],
            array_column($store->generations(limit: 10)['generations'], 'id'),
        );
    }

    private function store(int $retainedGenerations = 10, bool $enableFts = true): GraphStore
    {
        $directory = sys_get_temp_dir().'/appgraph-graph-store-'.bin2hex(random_bytes(8));
        $this->temporaryDirectories[] = $directory;

        return new GraphStore(
            path: $directory.'/appgraph.sqlite',
            retainedGenerations: $retainedGenerations,
            busyTimeoutMs: 1000,
            enableFts: $enableFts,
        );
    }

    /**
     * @param array<int, Node>|null $nodes
     * @param array<int, Edge> $edges
     * @param array<string, string> $files
     */
    private function graph(
        string $source,
        string $label = 'Example',
        ?array $nodes = null,
        array $edges = [],
        array $files = [],
    ): Graph {
        $graph = new Graph([
            'generatedAt' => '2026-07-15T12:00:00.000000Z',
            'appName' => 'GraphStore Test',
            'laravelVersion' => '12.0.0',
            'appgraphVersion' => 'test',
            'scan' => [
                'fingerprint' => $source,
                'files' => $files,
            ],
        ]);

        foreach ($nodes ?? [Node::make('node:example', 'action', $label)] as $node) {
            $graph->addNode($node);
        }

        foreach ($edges as $edge) {
            $graph->addEdge($edge);
        }

        return $graph;
    }

    private function pdo(GraphStore $store): PDO
    {
        return new PDO('sqlite:'.$store->path(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
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
}
