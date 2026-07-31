<?php

namespace AppGraph\Tests\Unit\Runtime;

use AppGraph\Runtime\RuntimeEvidenceCollector;
use AppGraph\Runtime\RuntimeTestMetadata;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class RuntimeEvidenceCollectorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/appgraph-runtime-collector-'.bin2hex(random_bytes(6));

        foreach (['app', 'tests/Feature', 'resources/views/notes'] as $directory) {
            mkdir($this->directory.'/'.$directory, 0755, true);
        }

        file_put_contents($this->directory.'/app/Signer.php', "<?php\nfinal class Signer\n{\n    public function sign(): void {}\n}\n");
        file_put_contents($this->directory.'/app/Verifier.php', "<?php\nfinal class Verifier {}\n");
        file_put_contents($this->directory.'/tests/Feature/SignNoteTest.php', '<?php final class SignNoteTest {}');
        file_put_contents($this->directory.'/tests/Feature/VerifyNoteTest.php', '<?php final class VerifyNoteTest {}');
        file_put_contents($this->directory.'/resources/views/notes/show.blade.php', '<p>{{ $note }}</p>');
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

    public function test_it_collects_hashed_line_ranges_and_optional_laravel_links(): void
    {
        $collector = new RuntimeEvidenceCollector(
            $this->directory,
            'session-a',
            static fn (): string => '2026-07-31T12:00:00Z',
        );
        $metadata = RuntimeTestMetadata::fromStrings(
            'Tests\\Feature\\SignNoteTest::test_signs_note',
            $this->directory.'/tests/Feature/SignNoteTest.php',
        );

        $this->assertNotNull($metadata);
        $this->assertTrue($collector->beginTest($metadata));

        $collector->recordSourceLines($this->directory.'/app/Signer.php', [9, 3, 4, 4, 7]);
        $collector->recordSourceLines('/outside/NotProject.php', [1]);
        $collector->recordTable(' Users ');
        $collector->recordBlade($this->directory.'/resources/views/notes/show.blade.php');
        $collector->recordInertiaComponent('Notes/Show');
        $collector->endTest();

        $snapshot = $collector->snapshot();
        $test = $snapshot->tests()[0];

        $this->assertSame(1, $snapshot->schemaVersion());
        $this->assertSame('session-a', $snapshot->sessionId());
        $this->assertSame('2026-07-31T12:00:00.000000Z', $snapshot->generatedAt());
        $this->assertSame('tests/Feature/SignNoteTest.php', $test->file());
        $this->assertSame(
            hash_file('sha256', $this->directory.'/tests/Feature/SignNoteTest.php'),
            $test->testFileSha256(),
        );
        $this->assertSame(['users'], $test->tables());
        $this->assertSame(['resources/views/notes/show.blade.php'], $test->blades());
        $this->assertSame(['Notes/Show'], $test->inertiaComponents());

        $sources = [];

        foreach ($test->sources() as $source) {
            $sources[$source->file()] = $source;
        }

        $this->assertSame([[3, 4], [7, 7], [9, 9]], $sources['app/Signer.php']->ranges());
        $this->assertSame(hash_file('sha256', $this->directory.'/app/Signer.php'), $sources['app/Signer.php']->sha256());
        $this->assertSame([], $sources['resources/views/notes/show.blade.php']->ranges());
        $this->assertSame(
            hash_file('sha256', $this->directory.'/resources/views/notes/show.blade.php'),
            $sources['resources/views/notes/show.blade.php']->sha256(),
        );
        $this->assertArrayNotHasKey('/outside/NotProject.php', $sources);
    }

    public function test_it_consumes_phpunit_line_coverage_using_generic_test_metadata(): void
    {
        $collector = new RuntimeEvidenceCollector(
            $this->directory,
            'session-coverage',
            static fn (): string => '2026-07-31T12:30:00Z',
        );
        $first = RuntimeTestMetadata::fromStrings(
            'Tests\\Feature\\SignNoteTest::test_signs_note',
            'tests/Feature/SignNoteTest.php',
        );

        $this->assertNotNull($first);
        $this->assertTrue($collector->registerTest($first));

        $collector->recordPhpUnitLineCoverage(
            [
                $this->directory.'/app/Signer.php' => [
                    3 => ['Tests\\Feature\\SignNoteTest::test_signs_note'],
                    4 => ['Tests\\Feature\\SignNoteTest::test_signs_note'],
                    8 => ['Tests\\Feature\\VerifyNoteTest::test_verifies_note'],
                ],
                $this->directory.'/app/Verifier.php' => [
                    2 => ['Tests\\Feature\\VerifyNoteTest::test_verifies_note'],
                ],
            ],
            [
                'Tests\\Feature\\VerifyNoteTest::test_verifies_note' => 'tests/Feature/VerifyNoteTest.php',
            ],
        );

        $tests = [];

        foreach ($collector->snapshot()->tests() as $test) {
            $tests[$test->id()] = $test;
        }

        $this->assertCount(2, $tests);
        $this->assertSame([[3, 4]], $tests['Tests\\Feature\\SignNoteTest::test_signs_note']->sources()[0]->ranges());

        $verifySources = [];

        foreach ($tests['Tests\\Feature\\VerifyNoteTest::test_verifies_note']->sources() as $source) {
            $verifySources[$source->file()] = $source->ranges();
        }

        $this->assertSame([[8, 8]], $verifySources['app/Signer.php']);
        $this->assertSame([[2, 2]], $verifySources['app/Verifier.php']);
    }

    public function test_it_pins_source_hashes_before_files_can_change_during_the_suite(): void
    {
        $source = $this->directory.'/app/Signer.php';
        $blade = $this->directory.'/resources/views/notes/show.blade.php';
        $sourceHash = hash_file('sha256', $source);
        $bladeHash = hash_file('sha256', $blade);
        $collector = new RuntimeEvidenceCollector($this->directory, 'session-pinned');
        $metadata = RuntimeTestMetadata::fromStrings(
            'Tests\\Feature\\SignNoteTest::test_signs_note',
            $this->directory.'/tests/Feature/SignNoteTest.php',
        );

        $this->assertNotNull($metadata);
        $collector->primeSourceHashes([$source, '/outside/NotProject.php']);
        $this->assertTrue($collector->beginTest($metadata));
        $collector->recordBlade($blade);

        file_put_contents($source, "<?php\nfinal class RewrittenSigner {}\n");
        file_put_contents($blade, '<p>rewritten</p>');
        $collector->recordSourceLines($source, [2]);
        $collector->endTest();

        $sources = [];

        foreach ($collector->snapshot()->tests()[0]->sources() as $evidence) {
            $sources[$evidence->file()] = $evidence->sha256();
        }

        $this->assertSame($sourceHash, $sources['app/Signer.php']);
        $this->assertSame($bladeHash, $sources['resources/views/notes/show.blade.php']);
        $this->assertNotSame(hash_file('sha256', $source), $sources['app/Signer.php']);
        $this->assertNotSame(hash_file('sha256', $blade), $sources['resources/views/notes/show.blade.php']);
    }

    public function test_active_test_evidence_is_restored_on_abort_and_replaced_on_commit(): void
    {
        $collector = new RuntimeEvidenceCollector($this->directory, 'session-retry');
        $sign = RuntimeTestMetadata::fromStrings(
            'Tests\\Feature\\SignNoteTest::test_signs_note',
            $this->directory.'/tests/Feature/SignNoteTest.php',
        );
        $verify = RuntimeTestMetadata::fromStrings(
            'Tests\\Feature\\VerifyNoteTest::test_verifies_note',
            $this->directory.'/tests/Feature/VerifyNoteTest.php',
        );

        $this->assertNotNull($sign);
        $this->assertNotNull($verify);

        $this->assertTrue($collector->beginTest($sign));
        $collector->recordSourceLines($this->directory.'/app/Signer.php', [3]);
        $collector->recordTable('notes');
        $collector->endTest();

        $this->assertTrue($collector->beginTest($sign));
        $collector->recordSourceLines($this->directory.'/app/Verifier.php', [2]);
        $collector->recordTable('drafts');
        $collector->abortTest();

        $this->assertTrue($collector->beginTest($verify));
        $collector->recordTable('users');
        $collector->abortTest();

        $snapshot = $collector->snapshot();

        $this->assertCount(1, $snapshot->tests());
        $this->assertSame(['notes'], $snapshot->tests()[0]->tables());
        $this->assertSame('app/Signer.php', $snapshot->tests()[0]->sources()[0]->file());

        $this->assertTrue($collector->beginTest($sign));
        $collector->recordSourceLines($this->directory.'/app/Verifier.php', [2]);
        $collector->recordTable('drafts');
        $collector->endTest();

        $snapshot = $collector->snapshot();

        $this->assertCount(1, $snapshot->tests());
        $this->assertSame(['drafts'], $snapshot->tests()[0]->tables());
        $this->assertSame('app/Verifier.php', $snapshot->tests()[0]->sources()[0]->file());
    }

    public function test_it_resolves_phpunit_metadata_without_requiring_pest_filename_state(): void
    {
        $test = new class($this->directory.'/tests/Feature/SignNoteTest.php')
        {
            public function __construct(private readonly string $path)
            {
            }

            public function id(): object
            {
                return new class
                {
                    public function asString(): string
                    {
                        return 'Tests\\Feature\\SignNoteTest::test_signs_note';
                    }
                };
            }

            public function file(): string
            {
                return $this->path;
            }
        };

        $metadata = RuntimeTestMetadata::fromPhpUnitTest($test);

        $this->assertNotNull($metadata);
        $this->assertSame('Tests\\Feature\\SignNoteTest::test_signs_note', $metadata->id());
        $this->assertSame($this->directory.'/tests/Feature/SignNoteTest.php', $metadata->file());
    }

    public function test_pest_authored_filename_wins_over_generated_phpunit_file_metadata(): void
    {
        $authoredFile = $this->directory.'/tests/Feature/SignNoteTest.php';
        $test = new class($authoredFile)
        {
            public static string $__filename;

            public function __construct(string $authoredFile)
            {
                self::$__filename = $authoredFile;
            }

            public function id(): string
            {
                return 'P\\Generated\\SignNoteTest::test_signs_note';
            }

            public function className(): string
            {
                return self::class;
            }

            public function file(): string
            {
                return '/tmp/pest-generated-code.php';
            }
        };

        $metadata = RuntimeTestMetadata::fromPhpUnitTest($test);

        $this->assertNotNull($metadata);
        $this->assertSame($authoredFile, $metadata->file());
    }

    public function test_it_detects_process_isolation_from_phpunit_event_metadata(): void
    {
        $selection = new class
        {
            public function isNotEmpty(): bool
            {
                return true;
            }
        };
        $empty = new class
        {
            public function isNotEmpty(): bool
            {
                return false;
            }
        };
        $metadata = new class($selection, $empty)
        {
            public function __construct(
                private readonly object $selection,
                private readonly object $empty,
            ) {
            }

            public function isRunInSeparateProcess(): object
            {
                return $this->selection;
            }

            public function isRunClassInSeparateProcess(): object
            {
                return $this->empty;
            }
        };
        $test = new class($metadata)
        {
            public function __construct(private readonly object $metadata)
            {
            }

            public function metadata(): object
            {
                return $this->metadata;
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

        $this->assertTrue(RuntimeTestMetadata::isProcessIsolatedEvent($event));
        $this->assertFalse(RuntimeTestMetadata::isProcessIsolatedEvent(new \stdClass));
    }
}
