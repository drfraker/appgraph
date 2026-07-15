<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Query\StalenessChecker;
use AppGraph\Support\FileFinder;
use AppGraph\Support\ScanFingerprint;
use PHPUnit\Framework\TestCase;

class StalenessCheckerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/appgraph-staleness-'.bin2hex(random_bytes(4));
        mkdir($this->directory.'/app', 0775, true);
        mkdir($this->directory.'/tests', 0775, true);
        mkdir($this->directory.'/resources/js', 0775, true);
        file_put_contents($this->directory.'/app/Service.php', '<?php class Service {}');
        file_put_contents($this->directory.'/tests/ServiceTest.php', '<?php class ServiceTest {}');
        file_put_contents($this->directory.'/resources/js/app.ts', 'export const value = 1;');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($this->directory);
        parent::tearDown();
    }

    public function test_content_manifest_detects_changed_added_and_removed_inputs_without_mtime_reliance(): void
    {
        $files = new FileFinder($this->directory);
        $fingerprint = new ScanFingerprint($files);
        $recorded = $fingerprint->capture();
        $graphPath = $this->directory.'/appgraph.json';
        file_put_contents($graphPath, json_encode(['meta' => ['scan' => $recorded], 'nodes' => [], 'edges' => []]));

        $fresh = (new StalenessChecker($files, $fingerprint))->check($graphPath, $recorded);
        $this->assertFalse($fresh['stale']);
        $this->assertTrue($fresh['fingerprintMatches']);

        file_put_contents($this->directory.'/app/Service.php', '<?php class Service { public function changed() {} }');
        unlink($this->directory.'/tests/ServiceTest.php');
        file_put_contents($this->directory.'/resources/js/new.ts', 'export const added = true;');

        $stale = (new StalenessChecker($files, $fingerprint))->check($graphPath, $recorded);

        $this->assertTrue($stale['stale']);
        $this->assertFalse($stale['fingerprintMatches']);
        $this->assertSame(1, $stale['changedFiles']);
        $this->assertSame(1, $stale['addedFiles']);
        $this->assertSame(1, $stale['removedFiles']);
        $this->assertContains('app/Service.php', $stale['samplePaths']);
        $this->assertContains('resources/js/new.ts', $stale['samplePaths']);
        $this->assertContains('tests/ServiceTest.php', $stale['samplePaths']);
    }
}
