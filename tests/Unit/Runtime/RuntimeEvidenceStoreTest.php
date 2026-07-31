<?php

namespace AppGraph\Tests\Unit\Runtime;

use AppGraph\Runtime\JsonRuntimeEvidenceStore;
use AppGraph\Runtime\RuntimeEvidenceSnapshot;
use AppGraph\Runtime\RuntimeSourceEvidence;
use AppGraph\Runtime\RuntimeTestEvidence;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class RuntimeEvidenceStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/appgraph-runtime-store-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/app', 0755, true);
        mkdir($this->directory.'/tests', 0755, true);
        file_put_contents($this->directory.'/app/First.php', "<?php\nreturn 1;\n");
        file_put_contents($this->directory.'/app/Second.php', "<?php\nreturn 2;\n");
        file_put_contents($this->directory.'/tests/FirstTest.php', '<?php final class FirstTest {}');
        file_put_contents($this->directory.'/tests/SecondTest.php', '<?php final class SecondTest {}');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }

            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_json_round_trip_is_versioned_deterministic_and_bounded(): void
    {
        $snapshot = RuntimeEvidenceSnapshot::create(
            'session-a',
            '2026-07-31T13:00:00Z',
            [
                $this->evidence(
                    'SecondTest::test_second',
                    'tests/SecondTest.php',
                    'session-a',
                    '2026-07-31T12:59:00Z',
                    'app/Second.php',
                    [[8, 9]],
                ),
                $this->evidence(
                    'FirstTest::test_first',
                    'tests/FirstTest.php',
                    'session-a',
                    '2026-07-31T12:58:00Z',
                    'app/First.php',
                    [[2, 4]],
                ),
            ],
        );

        $json = $snapshot->toJson();
        $decoded = RuntimeEvidenceSnapshot::fromJson($json, $this->directory);

        $this->assertSame(1, $decoded->schemaVersion());
        $this->assertSame(['tests/FirstTest.php', 'tests/SecondTest.php'], $decoded->testFiles());
        $this->assertSame($json, $decoded->toJson());
        $this->assertStringNotContainsString('O:', $json);
        $this->assertLessThan(RuntimeEvidenceSnapshot::MAX_JSON_BYTES, strlen($json));
    }

    public function test_decoder_rejects_unknown_schemas_and_unsafe_paths(): void
    {
        $payload = [
            'schema' => 2,
            'session' => ['id' => 'session-a', 'generated_at' => '2026-07-31T13:00:00Z'],
            'tests' => [],
        ];

        try {
            RuntimeEvidenceSnapshot::fromJson(json_encode($payload, JSON_THROW_ON_ERROR), $this->directory);
            $this->fail('Unknown schema should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('schema', $exception->getMessage());
        }

        $payload['schema'] = 1;
        $payload['tests'] = [[
            'id' => 'EscapeTest::test_escape',
            'file' => '../EscapeTest.php',
            'session_id' => 'session-a',
            'captured_at' => '2026-07-31T13:00:00Z',
            'sources' => [],
            'tables' => [],
            'blades' => [],
            'inertia_components' => [],
        ]];

        $this->expectException(InvalidArgumentException::class);
        RuntimeEvidenceSnapshot::fromJson(json_encode($payload, JSON_THROW_ON_ERROR), $this->directory);
    }

    public function test_store_writes_atomically_and_decodes_the_exact_supplied_source(): void
    {
        $path = $this->directory.'/storage/runtime/evidence.json';
        $store = new JsonRuntimeEvidenceStore($path, $this->directory);
        $snapshot = RuntimeEvidenceSnapshot::create(
            'session-a',
            '2026-07-31T13:00:00Z',
            [$this->evidence(
                'FirstTest::test_first',
                'tests/FirstTest.php',
                'session-a',
                '2026-07-31T13:00:00Z',
                'app/First.php',
                [[2, 2]],
            )],
        );

        $this->assertNull($store->read());
        $store->write($snapshot);

        $this->assertFileExists($path);
        $this->assertNotNull($store->modifiedAt());
        $this->assertSame($snapshot->toArray(), $store->read()?->toArray());

        $source = (string) file_get_contents($path);
        $this->assertSame($snapshot->toArray(), $store->readSource($source)->toArray());
        $this->assertSame([], glob($path.'.tmp.*'));
    }

    public function test_lock_protected_merge_keeps_distinct_worker_sessions_and_replaces_stale_test_evidence(): void
    {
        $path = $this->directory.'/runtime-evidence.json';
        $firstStore = new JsonRuntimeEvidenceStore($path, $this->directory);
        $secondStore = new JsonRuntimeEvidenceStore($path, $this->directory);

        $firstStore->merge(RuntimeEvidenceSnapshot::create(
            'worker-a',
            '2026-07-31T13:00:00Z',
            [
                $this->evidence(
                    'FirstTest::test_first',
                    'tests/FirstTest.php',
                    'worker-a',
                    '2026-07-31T12:59:00Z',
                    'app/First.php',
                    [[2, 3]],
                ),
            ],
        ));

        $secondStore->merge(RuntimeEvidenceSnapshot::create(
            'worker-b',
            '2026-07-31T13:01:00Z',
            [
                $this->evidence(
                    'SecondTest::test_second',
                    'tests/SecondTest.php',
                    'worker-b',
                    '2026-07-31T13:00:30Z',
                    'app/Second.php',
                    [[6, 7]],
                ),
            ],
        ));

        $secondStore->merge(RuntimeEvidenceSnapshot::create(
            'worker-c',
            '2026-07-31T13:02:00Z',
            [
                $this->evidence(
                    'FirstTest::test_first',
                    'tests/FirstTest.php',
                    'worker-c',
                    '2026-07-31T13:01:30Z',
                    'app/First.php',
                    [[10, 10]],
                ),
            ],
        ));

        $merged = $firstStore->read();

        $this->assertNotNull($merged);
        $this->assertCount(2, $merged->tests());
        $this->assertSame('worker-c', $merged->sessionId());

        $tests = [];

        foreach ($merged->tests() as $test) {
            $tests[$test->id()] = $test;
        }

        $this->assertSame([[10, 10]], $tests['FirstTest::test_first']->sources()[0]->ranges());
        $this->assertSame('worker-c', $tests['FirstTest::test_first']->sessionId());
        $this->assertSame([[6, 7]], $tests['SecondTest::test_second']->sources()[0]->ranges());
    }

    /** @param list<array{0: int, 1: int}> $ranges */
    private function evidence(
        string $id,
        string $testFile,
        string $sessionId,
        string $capturedAt,
        string $sourceFile,
        array $ranges,
    ): RuntimeTestEvidence {
        return RuntimeTestEvidence::create(
            id: $id,
            file: $testFile,
            testFileSha256: hash_file('sha256', $this->directory.'/'.$testFile),
            sessionId: $sessionId,
            capturedAt: $capturedAt,
            sources: [RuntimeSourceEvidence::create(
                $sourceFile,
                hash_file('sha256', $this->directory.'/'.$sourceFile),
                $ranges,
            )],
        );
    }
}
