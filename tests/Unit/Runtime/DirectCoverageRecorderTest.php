<?php

namespace AppGraph\Tests\Unit\Runtime;

use AppGraph\Runtime\PHPUnit\CoverageDriver;
use AppGraph\Runtime\PHPUnit\DirectCoverageRecorder;
use AppGraph\Runtime\RuntimeSourceScope;
use AppGraph\Tests\Fixtures\Runtime\FakeXdebugState;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class DirectCoverageRecorderTest extends TestCase
{
    private string $directory;

    private string $outsideFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/appgraph-direct-coverage-'.bin2hex(random_bytes(6));
        $this->outsideFile = sys_get_temp_dir().'/appgraph-direct-coverage-outside-'.bin2hex(random_bytes(6)).'.php';
        mkdir($this->directory.'/app', 0755, true);
        mkdir($this->directory.'/vendor/package', 0755, true);
        file_put_contents($this->directory.'/app/Service.php', '<?php return true;');
        file_put_contents($this->directory.'/app/Other.php', '<?php return true;');
        file_put_contents($this->directory.'/vendor/package/Dependency.php', '<?php return true;');
        file_put_contents($this->outsideFile, '<?php return false;');
    }

    protected function tearDown(): void
    {
        if (is_file($this->outsideFile)) {
            unlink($this->outsideFile);
        }

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

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_pcov_records_only_executed_project_lines_and_can_abort_owned_coverage(): void
    {
        if (function_exists('pcov\\start')) {
            $this->markTestSkipped('The fake PCOV driver requires PCOV not to be loaded.');
        }

        require dirname(__DIR__, 2).'/Fixtures/Runtime/FakePcov.php';

        $recorder = new DirectCoverageRecorder(
            new RuntimeSourceScope($this->directory),
            CoverageDriver::PCOV,
        );

        $this->assertTrue($recorder->start());
        $this->assertTrue($recorder->isCollecting());
        $this->assertFalse($recorder->start());
        \pcov\FakeState::$coverage = [
            realpath($this->directory.'/app/Service.php') => [7 => 1, 3 => -1, 4 => 2, '9' => 1],
            realpath($this->directory.'/vendor/package/Dependency.php') => [1 => 1],
            realpath($this->outsideFile) => [1 => 1],
        ];
        $this->assertSame([
            realpath($this->directory.'/app/Service.php') => [4, 7, 9],
        ], $recorder->stop());
        $this->assertFalse($recorder->isCollecting());
        $this->assertNull($recorder->stop());
        $this->assertSame(1, \pcov\FakeState::$stopCalls);
        $this->assertSame(2, \pcov\FakeState::$clearCalls);

        $this->assertTrue($recorder->start());
        $recorder->abort();

        $this->assertFalse($recorder->isCollecting());
        $this->assertSame(2, \pcov\FakeState::$stopCalls);
        $this->assertSame(4, \pcov\FakeState::$clearCalls);

        \pcov\FakeState::$coverage = [];

        $this->assertTrue($recorder->start());
        $this->assertNull($recorder->stop());
        $this->assertFalse($recorder->isCollecting());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_pcov_refuses_to_disturb_an_existing_coverage_owner(): void
    {
        if (function_exists('pcov\\start')) {
            $this->markTestSkipped('The fake PCOV driver requires PCOV not to be loaded.');
        }

        require dirname(__DIR__, 2).'/Fixtures/Runtime/FakePcov.php';

        \pcov\FakeState::$coverage = [
            realpath($this->directory.'/app/Service.php') => [1 => 1],
        ];
        $guard = new DirectCoverageRecorder(
            new RuntimeSourceScope($this->directory),
            CoverageDriver::PCOV,
        );

        $this->assertFalse($guard->start());
        $this->assertSame(0, \pcov\FakeState::$clearCalls);
        $this->assertSame(0, \pcov\FakeState::$stopCalls);
        $this->assertSame(0, \pcov\FakeState::$collectCalls);
        $this->assertNotSame([], \pcov\FakeState::$coverage);

        \pcov\FakeState::$coverage = [];
        $owner = new DirectCoverageRecorder(
            new RuntimeSourceScope($this->directory),
            CoverageDriver::PCOV,
        );
        $contender = new DirectCoverageRecorder(
            new RuntimeSourceScope($this->directory),
            CoverageDriver::PCOV,
        );

        $this->assertTrue($owner->start());
        $this->assertFalse($contender->start());
        $contender->abort();

        $this->assertTrue(\pcov\FakeState::$collecting);
        $this->assertSame(0, \pcov\FakeState::$stopCalls);
        $this->assertSame(1, \pcov\FakeState::$clearCalls);

        $owner->abort();

        $this->assertFalse(\pcov\FakeState::$collecting);
        $this->assertSame(1, \pcov\FakeState::$stopCalls);
        $this->assertSame(2, \pcov\FakeState::$clearCalls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_pcov_fails_closed_on_file_overflow_and_releases_ownership(): void
    {
        if (function_exists('pcov\\start')) {
            $this->markTestSkipped('The fake PCOV driver requires PCOV not to be loaded.');
        }

        require dirname(__DIR__, 2).'/Fixtures/Runtime/FakePcov.php';

        $recorder = new DirectCoverageRecorder(
            new RuntimeSourceScope($this->directory),
            CoverageDriver::PCOV,
            maxCoveredFiles: 1,
        );

        $this->assertTrue($recorder->start());
        \pcov\FakeState::$coverage = [
            realpath($this->directory.'/app/Service.php') => [1 => 1],
            realpath($this->directory.'/app/Other.php') => [1 => 1],
        ];

        $this->assertNull($recorder->stop());
        $this->assertSame(0, \pcov\FakeState::$collectCalls);
        $this->assertFalse(\pcov\FakeState::$collecting);

        $next = new DirectCoverageRecorder(
            new RuntimeSourceScope($this->directory),
            CoverageDriver::PCOV,
        );

        $this->assertTrue($next->start());
        $next->abort();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_pcov_releases_ownership_when_start_or_collection_cleanup_throws(): void
    {
        if (function_exists('pcov\\start')) {
            $this->markTestSkipped('The fake PCOV driver requires PCOV not to be loaded.');
        }

        require dirname(__DIR__, 2).'/Fixtures/Runtime/FakePcov.php';

        \pcov\FakeState::$throwOnClear = true;
        $failedStart = new DirectCoverageRecorder(
            new RuntimeSourceScope($this->directory),
            CoverageDriver::PCOV,
        );

        $this->assertFalse($failedStart->start());
        $this->assertSame(0, \pcov\FakeState::$stopCalls);

        \pcov\FakeState::$throwOnClear = false;
        \pcov\FakeState::$throwOnStartAfterActivation = true;
        $activatedStart = new DirectCoverageRecorder(
            new RuntimeSourceScope($this->directory),
            CoverageDriver::PCOV,
        );

        $this->assertFalse($activatedStart->start());
        $this->assertFalse(\pcov\FakeState::$collecting);

        \pcov\FakeState::$throwOnStartAfterActivation = false;
        $failedCleanup = new DirectCoverageRecorder(
            new RuntimeSourceScope($this->directory),
            CoverageDriver::PCOV,
        );

        $this->assertTrue($failedCleanup->start());
        \pcov\FakeState::$throwOnClear = true;
        $failedCleanup->abort();
        \pcov\FakeState::$throwOnClear = false;

        $failedCollect = new DirectCoverageRecorder(
            new RuntimeSourceScope($this->directory),
            CoverageDriver::PCOV,
        );

        $this->assertTrue($failedCollect->start());
        \pcov\FakeState::$coverage = [
            realpath($this->directory.'/app/Service.php') => [1 => 1],
        ];
        \pcov\FakeState::$throwOnCollect = true;

        $this->assertNull($failedCollect->stop());
        $this->assertFalse(\pcov\FakeState::$collecting);

        \pcov\FakeState::$throwOnCollect = false;
        $next = new DirectCoverageRecorder(
            new RuntimeSourceScope($this->directory),
            CoverageDriver::PCOV,
        );

        $this->assertTrue($next->start());
        $next->abort();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_xdebug_records_lines_and_refuses_a_foreign_coverage_session(): void
    {
        if (function_exists('xdebug_start_code_coverage')) {
            $this->markTestSkipped('The fake Xdebug driver requires Xdebug not to be loaded.');
        }

        require dirname(__DIR__, 2).'/Fixtures/Runtime/FakeXdebug.php';

        FakeXdebugState::$coverage = [
            $this->directory.'/app/Service.php' => [2 => 1, 3 => 0, 5 => -2],
            $this->directory.'/vendor/package/Dependency.php' => [1 => 1],
        ];

        $recorder = new DirectCoverageRecorder(
            new RuntimeSourceScope($this->directory),
            CoverageDriver::XDEBUG,
        );

        $this->assertTrue($recorder->start());
        $this->assertSame([
            realpath($this->directory.'/app/Service.php') => [2],
        ], $recorder->stop());
        $this->assertSame(1, FakeXdebugState::$stopCalls);

        FakeXdebugState::$collecting = true;

        $this->assertFalse($recorder->start());
        $recorder->abort();
        $this->assertTrue(FakeXdebugState::$collecting);
        $this->assertSame(1, FakeXdebugState::$stopCalls);

        FakeXdebugState::$collecting = false;
        $recorder = new DirectCoverageRecorder(
            new RuntimeSourceScope($this->directory),
            CoverageDriver::XDEBUG,
        );
        $this->assertTrue($recorder->start());
        FakeXdebugState::$collecting = false;

        $this->assertNull($recorder->stop());
        $this->assertFalse($recorder->isCollecting());
        $this->assertSame(1, FakeXdebugState::$stopCalls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_xdebug_fails_closed_on_file_total_and_per_file_line_overflow(): void
    {
        if (function_exists('xdebug_start_code_coverage')) {
            $this->markTestSkipped('The fake Xdebug driver requires Xdebug not to be loaded.');
        }

        require dirname(__DIR__, 2).'/Fixtures/Runtime/FakeXdebug.php';

        $fileOverflow = new DirectCoverageRecorder(
            new RuntimeSourceScope($this->directory),
            CoverageDriver::XDEBUG,
            maxCoveredFiles: 1,
        );
        $this->assertTrue($fileOverflow->start());
        FakeXdebugState::$coverage = [
            realpath($this->directory.'/app/Service.php') => [1 => 1],
            realpath($this->directory.'/app/Other.php') => [1 => 1],
        ];
        $this->assertNull($fileOverflow->stop());

        $totalOverflow = new DirectCoverageRecorder(
            new RuntimeSourceScope($this->directory),
            CoverageDriver::XDEBUG,
            maxTotalExecutedLines: 2,
        );
        $this->assertTrue($totalOverflow->start());
        FakeXdebugState::$coverage = [
            realpath($this->directory.'/app/Service.php') => [1 => 1, 2 => 1],
            realpath($this->directory.'/app/Other.php') => [1 => 1],
        ];
        $this->assertNull($totalOverflow->stop());

        $perFileOverflow = new DirectCoverageRecorder(
            new RuntimeSourceScope($this->directory),
            CoverageDriver::XDEBUG,
            maxExecutedLinesPerFile: 2,
        );
        $this->assertTrue($perFileOverflow->start());
        FakeXdebugState::$coverage = [
            realpath($this->directory.'/app/Service.php') => [1 => 1, 2 => 1, 3 => 1],
        ];
        $this->assertNull($perFileOverflow->stop());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_xdebug_start_exception_cleans_up_activated_coverage(): void
    {
        if (function_exists('xdebug_start_code_coverage')) {
            $this->markTestSkipped('The fake Xdebug driver requires Xdebug not to be loaded.');
        }

        require dirname(__DIR__, 2).'/Fixtures/Runtime/FakeXdebug.php';

        FakeXdebugState::$throwOnStartAfterActivation = true;
        $recorder = new DirectCoverageRecorder(
            new RuntimeSourceScope($this->directory),
            CoverageDriver::XDEBUG,
        );

        $this->assertFalse($recorder->start());
        $this->assertFalse(FakeXdebugState::$collecting);
        $this->assertSame(1, FakeXdebugState::$stopCalls);

        FakeXdebugState::$throwOnStartAfterActivation = false;

        $this->assertTrue($recorder->start());
        $recorder->abort();
        $this->assertSame(2, FakeXdebugState::$stopCalls);
    }
}
