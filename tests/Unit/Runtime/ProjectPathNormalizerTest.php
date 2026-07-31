<?php

namespace AppGraph\Tests\Unit\Runtime;

use AppGraph\Runtime\ProjectPathNormalizer;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ProjectPathNormalizerTest extends TestCase
{
    private string $directory;

    private string $outsideFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/appgraph-runtime-paths-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/app', 0755, true);
        file_put_contents($this->directory.'/app/Service.php', '<?php final class Service {}');

        $this->outsideFile = sys_get_temp_dir().'/appgraph-runtime-outside-'.bin2hex(random_bytes(6)).'.php';
        file_put_contents($this->outsideFile, '<?php return "outside";');
    }

    protected function tearDown(): void
    {
        if (is_link($this->directory.'/app/Escape.php')) {
            unlink($this->directory.'/app/Escape.php');
        }

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

    public function test_it_normalizes_relative_and_absolute_project_paths(): void
    {
        $paths = new ProjectPathNormalizer($this->directory);

        $this->assertSame('app/Service.php', $paths->relative('app\\Service.php'));
        $this->assertSame('app/Service.php', $paths->relative($this->directory.'/app/Service.php'));
        $this->assertSame('app/NewService.php', $paths->relative('./app/NewService.php'));
        $this->assertSame(
            realpath($this->directory.'/app/Service.php'),
            $paths->absolute('app/Service.php'),
        );
    }

    public function test_it_rejects_traversal_outside_paths_streams_and_symlink_escapes(): void
    {
        $paths = new ProjectPathNormalizer($this->directory);

        $this->assertNull($paths->relative('../secret.php'));
        $this->assertNull($paths->relative($this->outsideFile));
        $this->assertNull($paths->relative('php://filter/resource=app/Service.php'));
        $this->assertNull($paths->relative("app/Bad\0.php"));

        if (function_exists('symlink') && @symlink($this->outsideFile, $this->directory.'/app/Escape.php')) {
            $this->assertNull($paths->relative('app/Escape.php'));
        }
    }
}
