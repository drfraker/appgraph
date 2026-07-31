<?php

namespace AppGraph\Tests\Unit\Runtime;

use AppGraph\Runtime\RuntimeSourceScope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class RuntimeSourceScopeTest extends TestCase
{
    private string $directory;

    private string $outsideFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/appgraph-runtime-scope-'.bin2hex(random_bytes(6));
        $this->outsideFile = sys_get_temp_dir().'/appgraph-runtime-scope-outside-'.bin2hex(random_bytes(6)).'.php';

        foreach ([
            'app',
            'routes',
            'config',
            'database/migrations',
            'resources/views',
            'vendor/package',
            'node_modules/package',
            '.hidden',
            'app/.generated',
            'packages/Example/vendor/internal',
            'packages/Example/node_modules/generated',
            'storage/framework/views',
            'bootstrap/cache',
        ] as $directory) {
            mkdir($this->directory.'/'.$directory, 0755, true);
        }

        foreach ([
            'App.php',
            'app/Service.php',
            'routes/web.php',
            'config/app.php',
            'config/private.php',
            'database/migrations/CreateNotes.php',
            'resources/views/notes.blade.php',
            'vendor/package/Dependency.php',
            'node_modules/package/generated.php',
            '.hidden/Secret.php',
            'app/.generated/Proxy.php',
            'packages/Example/vendor/internal/Dependency.php',
            'packages/Example/node_modules/generated/module.php',
            'storage/framework/views/compiled.php',
            'bootstrap/cache/packages.php',
        ] as $file) {
            file_put_contents($this->directory.'/'.$file, '<?php return true;');
        }

        file_put_contents($this->directory.'/resources/readme.txt', 'not php');
        file_put_contents($this->outsideFile, '<?php return false;');
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

    public function test_it_covers_laravel_project_sources_and_excludes_noise_and_configured_paths(): void
    {
        $scope = new RuntimeSourceScope($this->directory, ['config/private.php']);

        foreach ([
            'App.php',
            'app/Service.php',
            'routes/web.php',
            'config/app.php',
            'database/migrations/CreateNotes.php',
            'resources/views/notes.blade.php',
        ] as $file) {
            $this->assertTrue($scope->contains($this->directory.'/'.$file), $file);
        }

        foreach ([
            'config/private.php',
            'vendor/package/Dependency.php',
            'node_modules/package/generated.php',
            '.hidden/Secret.php',
            'app/.generated/Proxy.php',
            'packages/Example/vendor/internal/Dependency.php',
            'packages/Example/node_modules/generated/module.php',
            'storage/framework/views/compiled.php',
            'bootstrap/cache/packages.php',
            'resources/readme.txt/../ignored.php',
        ] as $file) {
            $this->assertFalse($scope->contains($this->directory.'/'.$file), $file);
        }

        file_put_contents($this->directory.'/app/Escape.php', '<?php return true;');
        $this->assertTrue($scope->contains($this->directory.'/app/Escape.php'));
        unlink($this->directory.'/app/Escape.php');

        if (function_exists('symlink') && @symlink($this->outsideFile, $this->directory.'/app/Escape.php')) {
            $this->assertFalse($scope->contains($this->directory.'/app/Escape.php'));
        }

        $files = array_map(
            fn (string $file): string => substr($file, strlen(realpath($this->directory)) + 1),
            $scope->phpFiles(),
        );

        $this->assertSame([
            'App.php',
            'app/Service.php',
            'config/app.php',
            'database/migrations/CreateNotes.php',
            'resources/views/notes.blade.php',
            'routes/web.php',
        ], $files);
    }

    public function test_php_file_enumeration_is_bounded(): void
    {
        $scope = new RuntimeSourceScope($this->directory, [], 2);

        $files = array_map(
            fn (string $file): string => substr($file, strlen(realpath($this->directory)) + 1),
            $scope->phpFiles(),
        );

        $this->assertSame(['App.php', 'app/Service.php'], $files);
        $this->assertSame($files, array_map(
            fn (string $file): string => substr($file, strlen(realpath($this->directory)) + 1),
            $scope->phpFiles(),
        ));
    }

    public function test_it_rejects_unreasonable_enumeration_limits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RuntimeSourceScope($this->directory, [], 0);
    }
}
