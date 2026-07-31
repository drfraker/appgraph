<?php

namespace AppGraph\Tests\Unit\Runtime;

use AppGraph\Runtime\JsonRuntimeEvidenceStore;
use AppGraph\Runtime\PHPUnit\PhpUnitRuntimeSession;
use AppGraph\Runtime\PHPUnit\RuntimeCoverageRecorder;
use AppGraph\Runtime\RuntimeEvidenceCollector;
use AppGraph\Runtime\RuntimeEvidenceRegistry;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PhpUnitRuntimeSessionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/appgraph-phpunit-runtime-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/tests/Feature', 0755, true);
        mkdir($this->directory.'/app', 0755, true);
        file_put_contents($this->directory.'/tests/Feature/NoteTest.php', '<?php final class NoteTest {}');
        file_put_contents($this->directory.'/app/NoteService.php', "<?php\nfinal class NoteService {}\n");
    }

    protected function tearDown(): void
    {
        RuntimeEvidenceRegistry::deactivate();

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

    public function test_session_does_not_replace_evidence_without_an_active_coverage_session(): void
    {
        $collector = new RuntimeEvidenceCollector($this->directory, 'phpunit-session');
        $store = new JsonRuntimeEvidenceStore(
            $this->directory.'/storage/appgraph/runtime-evidence.json',
            $this->directory,
        );
        $session = new PhpUnitRuntimeSession($collector, $store);
        $test = new class($this->directory)
        {
            public function __construct(private readonly string $directory)
            {
            }

            public function id(): string
            {
                return 'NoteTest::test_show';
            }

            public function file(): string
            {
                return $this->directory.'/tests/Feature/NoteTest.php';
            }
        };
        $event = new class($test)
        {
            public function __construct(private readonly object $test)
            {
            }

            public function test(): object
            {
                return $this->test;
            }
        };

        RuntimeEvidenceRegistry::activate($collector);
        $session->preparationStarted($event);
        $collector->recordTable('notes');
        $session->finished($event);
        $session->flush();
        $session->flush();

        $this->assertNull($store->read());
        $this->assertNull(RuntimeEvidenceRegistry::collector());
    }

    public function test_session_persists_complete_direct_line_and_laravel_evidence(): void
    {
        $collector = new RuntimeEvidenceCollector($this->directory, 'direct-session');
        $store = new JsonRuntimeEvidenceStore(
            $this->directory.'/storage/appgraph/runtime-evidence.json',
            $this->directory,
        );
        $recorder = new RuntimeFakeCoverageRecorder([
            $this->directory.'/app/NoteService.php' => [2],
        ]);
        $session = new PhpUnitRuntimeSession($collector, $store, $recorder);
        $event = $this->event();

        RuntimeEvidenceRegistry::activate($collector);
        $session->preparationStarted($event);
        $collector->recordTable('notes');
        $session->finished($event);
        $session->flush();

        $snapshot = $store->read();

        $this->assertNotNull($snapshot);
        $this->assertCount(1, $snapshot->tests());
        $this->assertSame(['notes'], $snapshot->tests()[0]->tables());
        $this->assertSame('app/NoteService.php', $snapshot->tests()[0]->sources()[0]->file());
        $this->assertSame([[2, 2]], $snapshot->tests()[0]->sources()[0]->ranges());
        $this->assertSame(1, $recorder->startCalls);
        $this->assertSame(1, $recorder->stopCalls);
        $this->assertSame(0, $recorder->abortCalls);
    }

    public function test_session_discards_an_aborted_direct_segment(): void
    {
        $collector = new RuntimeEvidenceCollector($this->directory, 'aborted-session');
        $store = new JsonRuntimeEvidenceStore(
            $this->directory.'/storage/appgraph/runtime-evidence.json',
            $this->directory,
        );
        $recorder = new RuntimeFakeCoverageRecorder([
            $this->directory.'/app/NoteService.php' => [2],
        ]);
        $session = new PhpUnitRuntimeSession($collector, $store, $recorder);

        RuntimeEvidenceRegistry::activate($collector);
        $session->preparationStarted($this->event());
        $collector->recordTable('notes');
        $session->aborted();
        $session->flush();

        $this->assertNull($store->read());
        $this->assertSame(1, $recorder->startCalls);
        $this->assertSame(0, $recorder->stopCalls);
        $this->assertSame(1, $recorder->abortCalls);
    }

    public function test_aborted_retry_restores_the_prior_completed_observation(): void
    {
        $collector = new RuntimeEvidenceCollector($this->directory, 'aborted-retry-session');
        $store = new JsonRuntimeEvidenceStore(
            $this->directory.'/storage/appgraph/runtime-evidence.json',
            $this->directory,
        );
        $recorder = new RuntimeFakeCoverageRecorder([
            $this->directory.'/app/NoteService.php' => [2],
        ]);
        $session = new PhpUnitRuntimeSession($collector, $store, $recorder);
        $event = $this->event();

        RuntimeEvidenceRegistry::activate($collector);
        $session->preparationStarted($event);
        $collector->recordTable('notes');
        $session->finished($event);

        $session->preparationStarted($event);
        $collector->recordTable('drafts');
        $session->aborted();
        $session->flush();

        $snapshot = $store->read();

        $this->assertNotNull($snapshot);
        $this->assertCount(1, $snapshot->tests());
        $this->assertSame(['notes'], $snapshot->tests()[0]->tables());
        $this->assertSame('app/NoteService.php', $snapshot->tests()[0]->sources()[0]->file());
        $this->assertSame(2, $recorder->startCalls);
        $this->assertSame(1, $recorder->stopCalls);
        $this->assertSame(1, $recorder->abortCalls);
    }

    public function test_retry_that_cannot_start_direct_coverage_restores_the_prior_observation(): void
    {
        $collector = new RuntimeEvidenceCollector($this->directory, 'start-failed-retry-session');
        $store = new JsonRuntimeEvidenceStore(
            $this->directory.'/storage/appgraph/runtime-evidence.json',
            $this->directory,
        );
        $recorder = new RuntimeFakeCoverageRecorder(
            [$this->directory.'/app/NoteService.php' => [2]],
            [true, false],
        );
        $session = new PhpUnitRuntimeSession($collector, $store, $recorder);
        $event = $this->event();

        RuntimeEvidenceRegistry::activate($collector);
        $session->preparationStarted($event);
        $collector->recordTable('notes');
        $session->finished($event);

        $session->preparationStarted($event);
        $session->finished($event);
        $session->flush();

        $snapshot = $store->read();

        $this->assertNotNull($snapshot);
        $this->assertCount(1, $snapshot->tests());
        $this->assertSame(['notes'], $snapshot->tests()[0]->tables());
        $this->assertSame('app/NoteService.php', $snapshot->tests()[0]->sources()[0]->file());
        $this->assertSame(2, $recorder->startCalls);
        $this->assertSame(1, $recorder->stopCalls);
        $this->assertSame(0, $recorder->abortCalls);
    }

    public function test_retry_with_an_indeterminate_direct_stop_restores_the_prior_observation(): void
    {
        $coverage = [$this->directory.'/app/NoteService.php' => [2]];
        $collector = new RuntimeEvidenceCollector($this->directory, 'stop-failed-retry-session');
        $store = new JsonRuntimeEvidenceStore(
            $this->directory.'/storage/appgraph/runtime-evidence.json',
            $this->directory,
        );
        $recorder = new RuntimeFakeCoverageRecorder($coverage, stopResults: [$coverage, null]);
        $session = new PhpUnitRuntimeSession($collector, $store, $recorder);
        $event = $this->event();

        RuntimeEvidenceRegistry::activate($collector);
        $session->preparationStarted($event);
        $collector->recordTable('notes');
        $session->finished($event);

        $session->preparationStarted($event);
        $collector->recordTable('drafts');
        $session->finished($event);
        $session->flush();

        $snapshot = $store->read();

        $this->assertNotNull($snapshot);
        $this->assertCount(1, $snapshot->tests());
        $this->assertSame(['notes'], $snapshot->tests()[0]->tables());
        $this->assertSame('app/NoteService.php', $snapshot->tests()[0]->sources()[0]->file());
        $this->assertSame(2, $recorder->startCalls);
        $this->assertSame(2, $recorder->stopCalls);
        $this->assertSame(1, $recorder->abortCalls);
    }

    public function test_session_leaves_process_isolated_tests_for_phpunit_coverage_transport(): void
    {
        $collector = new RuntimeEvidenceCollector($this->directory, 'isolated-session');
        $store = new JsonRuntimeEvidenceStore(
            $this->directory.'/storage/appgraph/runtime-evidence.json',
            $this->directory,
        );
        $recorder = new RuntimeFakeCoverageRecorder([
            $this->directory.'/app/NoteService.php' => [2],
        ]);
        $session = new PhpUnitRuntimeSession($collector, $store, $recorder);

        RuntimeEvidenceRegistry::activate($collector);
        $event = $this->event(isolated: true);
        $session->preparationStarted($event);
        $session->finished($event);
        $session->flush();

        $this->assertNull($store->read());
        $this->assertSame(0, $recorder->startCalls);
        $this->assertSame(0, $recorder->stopCalls);
        $this->assertSame(0, $recorder->abortCalls);
    }

    private function event(bool $isolated = false): object
    {
        $test = new class($this->directory, $isolated)
        {
            public function __construct(
                private readonly string $directory,
                private readonly bool $isolated,
            ) {
            }

            public function id(): string
            {
                return 'NoteTest::test_show';
            }

            public function file(): string
            {
                return $this->directory.'/tests/Feature/NoteTest.php';
            }

            public function metadata(): object
            {
                return new class($this->isolated)
                {
                    public function __construct(private readonly bool $isolated)
                    {
                    }

                    public function isRunInSeparateProcess(): object
                    {
                        return new class($this->isolated)
                        {
                            public function __construct(private readonly bool $isolated)
                            {
                            }

                            public function isNotEmpty(): bool
                            {
                                return $this->isolated;
                            }
                        };
                    }
                };
            }
        };

        return new class($test)
        {
            public function __construct(private readonly object $test)
            {
            }

            public function test(): object
            {
                return $this->test;
            }
        };
    }
}

final class RuntimeFakeCoverageRecorder implements RuntimeCoverageRecorder
{
    public int $startCalls = 0;

    public int $stopCalls = 0;

    public int $abortCalls = 0;

    private bool $collecting = false;

    /**
     * @param array<string, list<int>> $coverage
     * @param list<bool> $startResults
     * @param list<array<string, list<int>>|null> $stopResults
     */
    public function __construct(
        private readonly array $coverage,
        private array $startResults = [],
        private array $stopResults = [],
    ) {
    }

    public function start(): bool
    {
        $this->startCalls++;
        $started = $this->startResults === [] ? true : array_shift($this->startResults);
        $this->collecting = $started;

        return $started;
    }

    public function stop(): ?array
    {
        $this->stopCalls++;
        $this->collecting = false;

        return $this->stopResults === [] ? $this->coverage : array_shift($this->stopResults);
    }

    public function abort(): void
    {
        $this->abortCalls++;
        $this->collecting = false;
    }

    public function isCollecting(): bool
    {
        return $this->collecting;
    }
}
