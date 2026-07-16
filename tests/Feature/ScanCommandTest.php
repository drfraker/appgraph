<?php

namespace AppGraph\Tests\Feature;

use AppGraph\Graph\Graph;
use AppGraph\Query\StalenessChecker;
use AppGraph\Scanners\CallScanner;
use AppGraph\Scanners\DatabaseSchemaScanner;
use AppGraph\Scanners\FrontendRouteScanner;
use AppGraph\Support\ContainerBindingRegistry;
use AppGraph\Support\FileFinder;
use AppGraph\Support\PhpFileFacts;
use AppGraph\Support\SchemaDumpParser;
use AppGraph\Support\ScanFingerprint;
use AppGraph\Support\ScanResultRegistry;
use AppGraph\Support\SourceFileObservations;
use AppGraph\Storage\GraphStore;
use AppGraph\Tests\Fixtures\ProgressNoteController;
use AppGraph\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ScanCommandTest extends TestCase
{
    public function test_scan_writes_a_correlated_private_fresh_process_result(): void
    {
        $this->disableScanners();
        $store = app(GraphStore::class);
        $directory = dirname($store->path());
        (new Filesystem())->ensureDirectoryExists($directory);
        $token = bin2hex(random_bytes(16));
        $resultPath = $directory.'/.scan-result-'.$token.'.json';
        touch($resultPath);
        chmod($resultPath, 0600);

        try {
            $this->artisan('appgraph:scan', [
                '--no-overview' => true,
                '--result-token' => $token,
                '--result-file' => $resultPath,
            ])->assertSuccessful();

            $envelope = json_decode(
                (string) file_get_contents($resultPath),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            $this->assertSame($token, $envelope['token']);
            $this->assertSame($store->current()['id'], $envelope['result']['generation']['id']);
            $this->assertTrue($envelope['result']['created']);
        } finally {
            @unlink($resultPath);
        }
    }

    public function test_private_result_channel_cannot_alias_a_graph_artifact(): void
    {
        $this->disableScanners();
        $store = app(GraphStore::class);
        $directory = dirname($store->path());
        (new Filesystem())->ensureDirectoryExists($directory);
        $token = bin2hex(random_bytes(16));
        $resultPath = $directory.'/.scan-result-'.$token.'.json';
        touch($resultPath);
        chmod($resultPath, 0600);

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $resultPath,
                '--no-overview' => true,
                '--result-token' => $token,
                '--result-file' => $resultPath,
            ])
                ->expectsOutputToContain('must be distinct files')
                ->assertFailed();

            $this->assertNull($store->current());
            $this->assertSame('', file_get_contents($resultPath));
        } finally {
            @unlink($resultPath);
        }
    }

    public function test_duplicate_scan_reuses_byte_equivalent_authoritative_json_metadata(): void
    {
        $this->disableScanners();
        $outputPath = storage_path('appgraph-duplicate-test/appgraph.json');
        @unlink($outputPath);

        $this->artisan('appgraph:scan', [
            '--output' => $outputPath,
            '--no-overview' => true,
        ])->assertSuccessful();
        $first = (string) file_get_contents($outputPath);
        $firstGeneration = json_decode($first, true, flags: JSON_THROW_ON_ERROR)['meta']['generation']['id'];

        $this->artisan('appgraph:scan', [
            '--output' => $outputPath,
            '--no-overview' => true,
        ])->assertSuccessful();
        $second = (string) file_get_contents($outputPath);
        $firstPayload = json_decode($first, true, flags: JSON_THROW_ON_ERROR);
        $secondPayload = json_decode($second, true, flags: JSON_THROW_ON_ERROR);
        $secondGeneration = $secondPayload['meta']['generation']['id'];

        $this->assertSame($firstPayload['meta']['scan'], $secondPayload['meta']['scan']);
        $this->assertSame($firstGeneration, $secondGeneration);
        $this->assertSame($first, $second);
        $this->assertSame(
            json_decode($second, true, flags: JSON_THROW_ON_ERROR),
            app(GraphStore::class)->graph($secondGeneration),
        );
    }

    public function test_optional_mirror_failure_does_not_report_authoritative_publication_as_failed(): void
    {
        $this->disableScanners();
        $blockingPath = storage_path('appgraph-mirror-blocker');
        @unlink($blockingPath);
        file_put_contents($blockingPath, 'not a directory');

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $blockingPath.'/appgraph.json',
                '--no-overview' => true,
            ])
                ->expectsOutputToContain('remains committed')
                ->assertSuccessful();

            $this->assertNotNull(app(GraphStore::class)->current());
            $this->assertFileDoesNotExist($blockingPath.'/appgraph.json');
        } finally {
            @unlink($blockingPath);
        }
    }

    public function test_mirror_warnings_preserve_exact_paths_or_report_atomic_path_omission(): void
    {
        $this->disableScanners();
        $registry = app(ScanResultRegistry::class);
        $exactPath = storage_path(str_repeat('x', 1100).'.json');
        $exactToken = str_repeat('a', 32);

        $this->artisan('appgraph:scan', [
            '--output' => $exactPath,
            '--no-overview' => true,
            '--result-token' => $exactToken,
        ])->assertSuccessful();

        $exactWarning = $registry->take($exactToken)['mirrorWarnings'][0];
        $this->assertGreaterThan(1024, strlen($exactPath));
        $this->assertSame($exactPath, $exactWarning['path']);
        $this->assertArrayNotHasKey('pathOmitted', $exactWarning);

        $oversizedPath = storage_path(str_repeat('y', 4097).'.json');
        $oversizedToken = str_repeat('b', 32);

        $this->artisan('appgraph:scan', [
            '--output' => $oversizedPath,
            '--no-overview' => true,
            '--result-token' => $oversizedToken,
        ])->assertSuccessful();

        $oversizedWarning = $registry->take($oversizedToken)['mirrorWarnings'][0];
        $this->assertArrayNotHasKey('path', $oversizedWarning);
        $this->assertTrue($oversizedWarning['pathOmitted']);
        $this->assertSame(strlen($oversizedPath), $oversizedWarning['pathBytes']);
        $this->assertSame(mb_strlen($oversizedPath), $oversizedWarning['pathCharacters']);
    }

    public function test_scan_rejects_store_mirror_and_overview_path_collisions_before_publication(): void
    {
        $this->disableScanners();
        $store = app(GraphStore::class);

        $this->artisan('appgraph:scan', [
            '--output' => $store->path(),
            '--no-overview' => true,
        ])
            ->expectsOutputToContain('must be distinct files')
            ->assertFailed();

        $this->assertNull($store->current());
    }

    public function test_scan_rejects_sidecar_lock_lexical_and_dangling_symlink_collisions(): void
    {
        $this->disableScanners();
        $store = app(GraphStore::class);
        $directory = dirname($store->path());
        (new Filesystem())->ensureDirectoryExists($directory);
        $symlink = $directory.'/mirror-link.json';
        @unlink($symlink);
        symlink($store->path(), $symlink);

        try {
            foreach ([
                $store->path().'-wal',
                $directory.'/.scan.lock',
                $directory.'/missing/../'.basename($store->path()),
                $symlink,
            ] as $output) {
                $status = \Illuminate\Support\Facades\Artisan::call('appgraph:scan', [
                    '--output' => $output,
                    '--no-overview' => true,
                ]);
                $commandOutput = \Illuminate\Support\Facades\Artisan::output();
                $this->assertNotSame(0, $status, "Collision [{$output}] unexpectedly succeeded: {$commandOutput}");
                $this->assertStringContainsString('must be distinct files', $commandOutput);
                $this->assertNull($store->current());
            }
        } finally {
            @unlink($symlink);
        }
    }

    public function test_post_commit_identity_recheck_never_replaces_the_store_with_a_mirror(): void
    {
        $this->disableScanners();
        $configured = app(GraphStore::class);
        $output = dirname($configured->path()).'/late-hardlink.json';
        @unlink($output);
        $store = new class($configured->path(), $output) extends GraphStore
        {
            public function __construct(string $path, private string $mirror)
            {
                parent::__construct($path);
            }

            public function publish(Graph $graph, ?string $protectedGeneration = null): array
            {
                $result = parent::publish($graph, $protectedGeneration);
                @unlink($this->mirror);

                if (! link($this->path(), $this->mirror)) {
                    throw new \RuntimeException('Unable to create the late hard-link test fixture.');
                }

                return $result;
            }
        };
        app()->instance(GraphStore::class, $store);

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $output,
                '--no-overview' => true,
            ])
                ->expectsOutputToContain('Optional mirrors were skipped')
                ->assertSuccessful();

            $this->assertNotNull($store->current());
            $this->assertSame(
                fileinode($store->path()),
                fileinode($output),
            );
            $this->assertStringStartsWith('SQLite format 3', (string) file_get_contents($output));
        } finally {
            @unlink($output);
        }
    }

    public function test_live_database_schema_change_during_scan_prevents_publication(): void
    {
        $this->disableScanners();
        config()->set('appgraph.scan.database', true);
        config()->set('appgraph.database.source', 'live');
        $scanner = new class extends DatabaseSchemaScanner
        {
            private int $captures = 0;

            public function __construct()
            {
            }

            /** @return array<string, mixed> */
            public function captureLiveSchema(?string $connectionName = null): array
            {
                return ['connection' => 'testing', 'driver' => 'sqlite', 'database' => 'testing', 'tables' => [], 'capture' => ++$this->captures];
            }

            public function scanLiveSnapshot(Graph $graph, array $snapshot): Graph
            {
                return $graph;
            }
        };
        app()->instance(DatabaseSchemaScanner::class, $scanner);
        $outputPath = storage_path('appgraph-live-mutation/appgraph.json');
        @unlink($outputPath);

        $this->artisan('appgraph:scan', [
            '--output' => $outputPath,
            '--no-overview' => true,
        ])
            ->expectsOutputToContain('live database schema changed')
            ->assertFailed();

        $this->assertNull(app(GraphStore::class)->current());
        $this->assertFileDoesNotExist($outputPath);
    }

    public function test_stable_live_database_schema_records_consistency_evidence(): void
    {
        $this->disableScanners();
        config()->set('appgraph.scan.database', true);
        config()->set('appgraph.database.source', 'live');
        Schema::create('live_evidence', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $outputPath = storage_path('appgraph-live-evidence/appgraph.json');

        $this->artisan('appgraph:scan', [
            '--output' => $outputPath,
            '--no-overview' => true,
        ])->assertSuccessful();
        $graph = json_decode((string) file_get_contents($outputPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('matched_captured_before_after', $graph['meta']['scan']['databaseSchema']['consistency']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $graph['meta']['scan']['databaseSchema']['fingerprint']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $graph['meta']['scan']['generationFingerprint']);
        $freshness = app(StalenessChecker::class)->check($outputPath, $graph['meta']['scan']);
        $this->assertSame('unknown_after_scan', $freshness['databaseSchemaFreshness']);
        $this->assertTrue($freshness['databaseSchemaFreshnessUnknown']);
        $this->assertGraphHasNode($graph, 'table:live_evidence', 'table');
    }

    public function test_scan_does_not_publish_when_an_input_changes_during_analysis(): void
    {
        $sourcePath = base_path('app/AppGraphConsistencyFixture.php');
        $outputPath = storage_path('appgraph-consistency-test/appgraph.json');
        $original = is_file($sourcePath) ? (string) file_get_contents($sourcePath) : null;
        (new Filesystem())->ensureDirectoryExists(dirname($sourcePath));
        file_put_contents($sourcePath, "<?php\n\n// before\n");
        @unlink($outputPath);

        foreach (['routes', 'database', 'models', 'form_requests', 'events', 'data_flow', 'side_effects', 'frontend', 'tests', 'policies', 'container_bindings'] as $scanner) {
            config()->set("appgraph.scan.{$scanner}", false);
        }
        config()->set('appgraph.scan.calls', true);

        app()->instance(CallScanner::class, new class($sourcePath) extends CallScanner
        {
            public function __construct(private string $sourcePath)
            {
            }

            public function scan(Graph $graph): Graph
            {
                file_put_contents($this->sourcePath, "<?php\n\n// changed during scan\n");

                return $graph;
            }
        });

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $outputPath,
                '--no-overview' => true,
            ])->assertFailed();

            $this->assertFileDoesNotExist($outputPath);
            $this->assertNull(app(GraphStore::class)->current());
        } finally {
            if ($original !== null) {
                file_put_contents($sourcePath, $original);
            } else {
                @unlink($sourcePath);
            }
        }
    }

    public function test_scan_rejects_source_aba_when_php_parser_observed_intermediate_content(): void
    {
        $sourcePath = base_path('app/AppGraphAbaFixture.php');
        $outputPath = storage_path('appgraph-aba-test/appgraph.json');
        $original = is_file($sourcePath) ? (string) file_get_contents($sourcePath) : null;
        (new Filesystem())->ensureDirectoryExists(dirname($sourcePath));
        file_put_contents($sourcePath, "<?php\n\nreturn 'a';\n");
        $this->disableScanners();
        config()->set('appgraph.scan.calls', true);
        app()->instance(CallScanner::class, new class($sourcePath) extends CallScanner
        {
            public function __construct(private string $sourcePath)
            {
            }

            public function scan(Graph $graph): Graph
            {
                file_put_contents($this->sourcePath, "<?php\n\nreturn 'b';\n");
                app(\AppGraph\Support\PhpFileFacts::class)->statements($this->sourcePath);
                file_put_contents($this->sourcePath, "<?php\n\nreturn 'a';\n");

                return $graph;
            }
        });

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $outputPath,
                '--no-overview' => true,
            ])
                ->expectsOutputToContain('did not match the final scan manifest')
                ->assertFailed();

            $this->assertNull(app(GraphStore::class)->current());
            $this->assertFileDoesNotExist($outputPath);
        } finally {
            if ($original === null) {
                @unlink($sourcePath);
            } else {
                file_put_contents($sourcePath, $original);
            }
        }
    }

    public function test_scan_publishes_and_tracks_a_livewire_shaped_vendor_source_observed_by_route_introspection(): void
    {
        $scanRoot = $this->useIsolatedScanRoot();
        $applicationRoot = $scanRoot['root'];
        $files = $scanRoot['files'];
        $fixture = $this->createObservedDependencyControllerFixture(
            $files,
            'vendor/livewire/livewire/src/Features/SupportFileUploads',
            'FilePreviewController.php',
        );
        $unobservedPath = $files->absolutePath('vendor/livewire/livewire/src/Unobserved.php');
        (new Filesystem())->ensureDirectoryExists(dirname($unobservedPath));
        file_put_contents($unobservedPath, "<?php\n\n// never consumed by a scanner\n");
        $outputPath = storage_path('appgraph-observed-dependency-test/appgraph.json');
        $this->disableScanners();
        config()->set('appgraph.scan.routes', true);
        Route::get('/appgraph-observed-vendor-'.$fixture['token'], [
            $fixture['class'],
            '__invoke',
        ]);

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $outputPath,
                '--no-overview' => true,
            ])->assertSuccessful();

            $store = app(GraphStore::class);
            $firstGeneration = $store->current();
            $this->assertNotNull($firstGeneration);
            $graph = $store->graph($firstGeneration['id']);
            $scan = $graph['meta']['scan'];

            $this->assertSame(hash('sha256', $fixture['source']), $scan['files'][$fixture['relative']]);
            $this->assertSame([$fixture['relative']], $scan['additionalFiles']);
            $this->assertArrayNotHasKey('vendor/livewire/livewire/src/Unobserved.php', $scan['files']);
            $this->assertFalse(app(StalenessChecker::class)->check($store->path(), $scan)['stale']);

            $this->artisan('appgraph:scan', [
                '--output' => $outputPath,
                '--no-overview' => true,
            ])->assertSuccessful();

            $this->assertSame($firstGeneration['id'], $store->current()['id']);

            file_put_contents($fixture['path'], $fixture['changedSource']);
            $changed = app(StalenessChecker::class)->check($store->path(), $scan);
            $this->assertTrue($changed['stale']);
            $this->assertSame(1, $changed['changedFiles']);
            $this->assertContains($fixture['relative'], $changed['samplePaths']);

            unlink($fixture['path']);
            $removed = app(StalenessChecker::class)->check($store->path(), $scan);
            $this->assertTrue($removed['stale']);
            $this->assertSame(1, $removed['removedFiles']);
            $this->assertContains($fixture['relative'], $removed['samplePaths']);
        } finally {
            @unlink($fixture['path']);
            (new Filesystem())->deleteDirectory($applicationRoot);
            @unlink($outputPath);
        }
    }

    public function test_scan_rejects_a_dependency_php_source_mutated_after_route_introspection(): void
    {
        $scanRoot = $this->useIsolatedScanRoot();
        $fixture = $this->createObservedDependencyControllerFixture($scanRoot['files']);
        $outputPath = storage_path('appgraph-mutated-dependency-test/appgraph.json');
        $this->disableScanners();
        config()->set('appgraph.scan.routes', true);
        config()->set('appgraph.scan.calls', true);
        Route::get('/appgraph-mutated-vendor-'.$fixture['token'], [
            $fixture['class'],
            '__invoke',
        ]);
        app()->instance(CallScanner::class, new class($fixture['path'], $fixture['changedSource']) extends CallScanner
        {
            public function __construct(
                private string $sourcePath,
                private string $changedSource,
            ) {
            }

            public function scan(Graph $graph): Graph
            {
                file_put_contents($this->sourcePath, $this->changedSource);

                return $graph;
            }
        });

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $outputPath,
                '--no-overview' => true,
            ])
                ->expectsOutputToContain("AppGraph source [{$fixture['relative']}] did not match the final scan manifest")
                ->assertFailed();

            $this->assertNull(app(GraphStore::class)->current());
            $this->assertFileDoesNotExist($outputPath);
        } finally {
            (new Filesystem())->deleteDirectory($scanRoot['root']);
            @unlink($outputPath);
        }
    }

    public function test_scan_rejects_a_dependency_php_source_deleted_after_route_introspection(): void
    {
        $scanRoot = $this->useIsolatedScanRoot();
        $fixture = $this->createObservedDependencyControllerFixture($scanRoot['files']);
        $outputPath = storage_path('appgraph-deleted-dependency-test/appgraph.json');
        $this->disableScanners();
        config()->set('appgraph.scan.routes', true);
        config()->set('appgraph.scan.calls', true);
        Route::get('/appgraph-deleted-dependency-'.$fixture['token'], [
            $fixture['class'],
            '__invoke',
        ]);
        app()->instance(CallScanner::class, new class($fixture['path']) extends CallScanner
        {
            public function __construct(private string $sourcePath)
            {
            }

            public function scan(Graph $graph): Graph
            {
                unlink($this->sourcePath);

                return $graph;
            }
        });

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $outputPath,
                '--no-overview' => true,
            ])
                ->expectsOutputToContain('could not be safely canonicalized inside the project')
                ->assertFailed();

            $this->assertNull(app(GraphStore::class)->current());
            $this->assertFileDoesNotExist($outputPath);
        } finally {
            (new Filesystem())->deleteDirectory($scanRoot['root']);
            @unlink($outputPath);
        }
    }

    public function test_scan_does_not_reclassify_a_transient_declared_source_as_a_dependency(): void
    {
        $token = bin2hex(random_bytes(6));
        $sourcePath = app(FileFinder::class)->absolutePath('app/AppGraphTransient'.$token.'.php');
        $source = "<?php\n\nreturn true;\n";
        $outputPath = storage_path('appgraph-transient-declared-test/appgraph.json');
        @unlink($sourcePath);
        (new Filesystem())->ensureDirectoryExists(dirname($sourcePath));
        $this->disableScanners();
        config()->set('appgraph.scan.calls', true);
        app()->instance(CallScanner::class, new class(
            app(PhpFileFacts::class),
            $sourcePath,
            $source,
        ) extends CallScanner
        {
            public function __construct(
                private PhpFileFacts $facts,
                private string $sourcePath,
                private string $source,
            ) {
            }

            public function scan(Graph $graph): Graph
            {
                file_put_contents($this->sourcePath, $this->source);
                $this->facts->statements($this->sourcePath);
                unlink($this->sourcePath);

                return $graph;
            }
        });
        app()->instance(ScanFingerprint::class, new class(
            app(FileFinder::class),
            app(ContainerBindingRegistry::class),
            $sourcePath,
            $source,
        ) extends ScanFingerprint
        {
            private int $captures = 0;

            public function __construct(
                FileFinder $files,
                ContainerBindingRegistry $containerBindings,
                private string $sourcePath,
                private string $source,
            ) {
                parent::__construct($files, $containerBindings);
            }

            public function capture(bool $refreshRuntimeEvidence = true, array $additionalFiles = []): array
            {
                $this->captures++;
                $fingerprint = parent::capture($refreshRuntimeEvidence, $additionalFiles);

                if ($this->captures === 2) {
                    file_put_contents($this->sourcePath, $this->source);
                }

                return $fingerprint;
            }
        });

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $outputPath,
                '--no-overview' => true,
            ])
                ->expectsOutputToContain('observed a declared project source that was absent')
                ->assertFailed();

            $this->assertNull(app(GraphStore::class)->current());
            $this->assertFileDoesNotExist($outputPath);
        } finally {
            @unlink($sourcePath);
            @unlink($outputPath);
        }
    }

    public function test_scan_does_not_reclassify_a_transient_declared_symlink_as_its_vendor_target(): void
    {
        $scanRoot = $this->useIsolatedScanRoot();
        $files = $scanRoot['files'];
        $token = bin2hex(random_bytes(6));
        $targetPath = $files->absolutePath('vendor/appgraph-transient-target-'.$token.'.php');
        $aliasPath = $files->absolutePath('app/AppGraphTransientAlias'.$token.'.php');
        $outputPath = storage_path('appgraph-transient-declared-symlink-test/appgraph.json');
        (new Filesystem())->ensureDirectoryExists(dirname($targetPath));
        (new Filesystem())->ensureDirectoryExists(dirname($aliasPath));
        file_put_contents($targetPath, "<?php\n\nreturn true;\n");

        if (! @symlink($targetPath, $aliasPath)) {
            (new Filesystem())->deleteDirectory($scanRoot['root']);
            $this->markTestSkipped('Symbolic links are unavailable on this platform.');
        }

        unlink($aliasPath);
        $this->disableScanners();
        config()->set('appgraph.scan.calls', true);
        app()->instance(CallScanner::class, new class(
            app(PhpFileFacts::class),
            $aliasPath,
            $targetPath,
        ) extends CallScanner
        {
            public function __construct(
                private PhpFileFacts $facts,
                private string $aliasPath,
                private string $targetPath,
            ) {
            }

            public function scan(Graph $graph): Graph
            {
                symlink($this->targetPath, $this->aliasPath);
                $this->facts->statements($this->aliasPath);
                unlink($this->aliasPath);

                return $graph;
            }
        });
        app()->instance(ScanFingerprint::class, new class(
            $files,
            app(ContainerBindingRegistry::class),
            $aliasPath,
            $targetPath,
        ) extends ScanFingerprint
        {
            private int $captures = 0;

            public function __construct(
                FileFinder $files,
                ContainerBindingRegistry $containerBindings,
                private string $aliasPath,
                private string $targetPath,
            ) {
                parent::__construct($files, $containerBindings);
            }

            public function capture(bool $refreshRuntimeEvidence = true, array $additionalFiles = []): array
            {
                $this->captures++;
                $fingerprint = parent::capture($refreshRuntimeEvidence, $additionalFiles);

                if ($this->captures === 2) {
                    symlink($this->targetPath, $this->aliasPath);
                }

                return $fingerprint;
            }
        });

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $outputPath,
                '--no-overview' => true,
            ])
                ->expectsOutputToContain('observed a declared project source that was absent')
                ->assertFailed();

            $this->assertNull(app(GraphStore::class)->current());
            $this->assertFileDoesNotExist($outputPath);
        } finally {
            @unlink($aliasPath);
            (new Filesystem())->deleteDirectory($scanRoot['root']);
            @unlink($outputPath);
        }
    }

    public function test_scan_rejects_a_base_source_change_during_dependency_manifest_extension(): void
    {
        $scanRoot = $this->useIsolatedScanRoot();
        $files = $scanRoot['files'];
        $fixture = $this->createObservedDependencyControllerFixture($files);
        $projectSource = $files->absolutePath('app/AppGraphExtensionRaceFixture.php');
        $outputPath = storage_path('appgraph-extension-race-test/appgraph.json');
        (new Filesystem())->ensureDirectoryExists(dirname($projectSource));
        file_put_contents($projectSource, "<?php\n\n// stable\n");
        $this->disableScanners();
        config()->set('appgraph.scan.routes', true);
        Route::get('/appgraph-extension-race-'.$fixture['token'], [
            $fixture['class'],
            '__invoke',
        ]);
        app()->instance(ScanFingerprint::class, new class(
            $files,
            app(ContainerBindingRegistry::class),
            $fixture['path'],
            $projectSource,
        ) extends ScanFingerprint
        {
            private bool $mutated = false;

            public function __construct(
                FileFinder $files,
                ContainerBindingRegistry $containerBindings,
                private string $dependencySource,
                private string $projectSource,
            ) {
                parent::__construct($files, $containerBindings);
            }

            public function capture(bool $refreshRuntimeEvidence = true, array $additionalFiles = []): array
            {
                if (! $this->mutated && in_array($this->dependencySource, $additionalFiles, true)) {
                    file_put_contents($this->projectSource, "<?php\n\n// changed during extension\n");
                    $this->mutated = true;
                }

                return parent::capture($refreshRuntimeEvidence, $additionalFiles);
            }
        });

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $outputPath,
                '--no-overview' => true,
            ])
                ->expectsOutputToContain('source inputs changed while the scan was running')
                ->assertFailed();

            $this->assertNull(app(GraphStore::class)->current());
            $this->assertFileDoesNotExist($outputPath);
        } finally {
            (new Filesystem())->deleteDirectory($scanRoot['root']);
            @unlink($outputPath);
        }
    }

    public function test_scan_rejects_a_composer_path_repository_symlink_as_an_observed_dependency(): void
    {
        $scanRoot = $this->useIsolatedScanRoot();
        $files = $scanRoot['files'];
        $token = bin2hex(random_bytes(6));
        $targetDirectory = $files->absolutePath('packages/appgraph-path-repository-'.$token.'/src');
        $targetPath = $targetDirectory.'/PathRepositoryController.php';
        $aliasDirectory = $files->absolutePath('vendor/appgraph/path-repository-'.$token);
        $aliasPath = $aliasDirectory.'/src/PathRepositoryController.php';
        $outputPath = storage_path('appgraph-path-repository-test/appgraph.json');
        (new Filesystem())->ensureDirectoryExists($targetDirectory);
        (new Filesystem())->ensureDirectoryExists(dirname($aliasDirectory));
        file_put_contents($targetPath, "<?php\n\nreturn true;\n");

        if (! @symlink(dirname($targetDirectory), $aliasDirectory)) {
            (new Filesystem())->deleteDirectory($scanRoot['root']);
            $this->markTestSkipped('Symbolic links are unavailable on this platform.');
        }

        $this->assertSame(realpath($targetPath), realpath($aliasPath));
        $this->disableScanners();
        config()->set('appgraph.scan.calls', true);
        app()->instance(CallScanner::class, new class(app(PhpFileFacts::class), $aliasPath) extends CallScanner
        {
            public function __construct(
                private PhpFileFacts $facts,
                private string $sourcePath,
            ) {
            }

            public function scan(Graph $graph): Graph
            {
                $this->facts->statements($this->sourcePath);

                return $graph;
            }
        });

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $outputPath,
                '--no-overview' => true,
            ])
                ->expectsOutputToContain('Composer path-repository symlinks are not supported')
                ->assertFailed();

            $this->assertNull(app(GraphStore::class)->current());
            $this->assertFileDoesNotExist($outputPath);
        } finally {
            @unlink($aliasDirectory);
            (new Filesystem())->deleteDirectory($scanRoot['root']);
            @unlink($outputPath);
        }
    }

    public function test_vendor_bin_symlinks_do_not_block_an_observed_vendor_source(): void
    {
        $scanRoot = $this->useIsolatedScanRoot();
        $files = $scanRoot['files'];
        $sourcePath = $files->absolutePath('vendor/livewire/livewire/src/FilePreviewController.php');
        $binaryAlias = $files->absolutePath('vendor/bin/appgraph-fixture');
        $outputPath = storage_path('appgraph-vendor-bin-symlink-test/appgraph.json');
        (new Filesystem())->ensureDirectoryExists(dirname($sourcePath));
        (new Filesystem())->ensureDirectoryExists(dirname($binaryAlias));
        file_put_contents($sourcePath, "<?php\n\nreturn true;\n");

        if (! @symlink($sourcePath, $binaryAlias)) {
            (new Filesystem())->deleteDirectory($scanRoot['root']);
            $this->markTestSkipped('Symbolic links are unavailable on this platform.');
        }

        $this->disableScanners();
        config()->set('appgraph.scan.calls', true);
        app()->instance(CallScanner::class, new class(app(PhpFileFacts::class), $sourcePath) extends CallScanner
        {
            public function __construct(
                private PhpFileFacts $facts,
                private string $sourcePath,
            ) {
            }

            public function scan(Graph $graph): Graph
            {
                $this->facts->statements($this->sourcePath);

                return $graph;
            }
        });

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $outputPath,
                '--no-overview' => true,
            ])->assertSuccessful();

            $generation = app(GraphStore::class)->current();
            $this->assertNotNull($generation);
            $scan = app(GraphStore::class)->graph($generation['id'])['meta']['scan'];
            $this->assertSame(
                ['vendor/livewire/livewire/src/FilePreviewController.php'],
                $scan['additionalFiles'],
            );
        } finally {
            @unlink($binaryAlias);
            (new Filesystem())->deleteDirectory($scanRoot['root']);
            @unlink($outputPath);
        }
    }

    public function test_scan_rejects_an_already_canonical_source_reached_by_a_vendor_package_alias(): void
    {
        $scanRoot = $this->useIsolatedScanRoot();
        $files = $scanRoot['files'];
        $token = bin2hex(random_bytes(6));
        $targetDirectory = $files->absolutePath('vendor/appgraph/releases-'.$token.'/v1');
        $targetPath = $targetDirectory.'/CanonicalController.php';
        $aliasPath = $files->absolutePath('vendor/appgraph/current-'.$token);
        $outputPath = storage_path('appgraph-canonical-vendor-alias-test/appgraph.json');
        (new Filesystem())->ensureDirectoryExists($targetDirectory);
        file_put_contents($targetPath, "<?php\n\nreturn true;\n");

        if (! @symlink($targetDirectory, $aliasPath)) {
            (new Filesystem())->deleteDirectory($scanRoot['root']);
            $this->markTestSkipped('Symbolic links are unavailable on this platform.');
        }

        $this->disableScanners();
        config()->set('appgraph.scan.calls', true);
        app()->instance(CallScanner::class, new class(app(PhpFileFacts::class), $targetPath) extends CallScanner
        {
            public function __construct(
                private PhpFileFacts $facts,
                private string $sourcePath,
            ) {
            }

            public function scan(Graph $graph): Graph
            {
                $this->facts->statements($this->sourcePath);

                return $graph;
            }
        });

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $outputPath,
                '--no-overview' => true,
            ])
                ->expectsOutputToContain('symlinked vendor package path')
                ->assertFailed();

            $this->assertNull(app(GraphStore::class)->current());
            $this->assertFileDoesNotExist($outputPath);
        } finally {
            @unlink($aliasPath);
            (new Filesystem())->deleteDirectory($scanRoot['root']);
            @unlink($outputPath);
        }
    }

    public function test_scan_rejects_a_vendor_package_alias_retargeted_during_analysis(): void
    {
        $scanRoot = $this->useIsolatedScanRoot();
        $files = $scanRoot['files'];
        $token = bin2hex(random_bytes(6));
        $firstDirectory = $files->absolutePath('vendor/appgraph/releases-'.$token.'/v1');
        $secondDirectory = $files->absolutePath('vendor/appgraph/releases-'.$token.'/v2');
        $sourcePath = $firstDirectory.'/CanonicalController.php';
        $aliasPath = $files->absolutePath('vendor/appgraph/current-'.$token);
        $outputPath = storage_path('appgraph-vendor-alias-race-test/appgraph.json');
        (new Filesystem())->ensureDirectoryExists($firstDirectory);
        (new Filesystem())->ensureDirectoryExists($secondDirectory);
        file_put_contents($sourcePath, "<?php\n\nreturn true;\n");
        file_put_contents($secondDirectory.'/CanonicalController.php', "<?php\n\nreturn false;\n");

        if (! @symlink($firstDirectory, $aliasPath)) {
            (new Filesystem())->deleteDirectory($scanRoot['root']);
            $this->markTestSkipped('Symbolic links are unavailable on this platform.');
        }

        $this->disableScanners();
        config()->set('appgraph.scan.calls', true);
        app()->instance(CallScanner::class, new class(
            app(PhpFileFacts::class),
            $sourcePath,
            $aliasPath,
            $secondDirectory,
        ) extends CallScanner
        {
            public function __construct(
                private PhpFileFacts $facts,
                private string $sourcePath,
                private string $aliasPath,
                private string $secondDirectory,
            ) {
            }

            public function scan(Graph $graph): Graph
            {
                $this->facts->statements($this->sourcePath);
                unlink($this->aliasPath);
                symlink($this->secondDirectory, $this->aliasPath);

                return $graph;
            }
        });

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $outputPath,
                '--no-overview' => true,
            ])
                ->expectsOutputToContain('source inputs changed while the scan was running')
                ->assertFailed();

            $this->assertNull(app(GraphStore::class)->current());
            $this->assertFileDoesNotExist($outputPath);
        } finally {
            @unlink($aliasPath);
            (new Filesystem())->deleteDirectory($scanRoot['root']);
            @unlink($outputPath);
        }
    }

    public function test_scan_rejects_an_automatically_discovered_source_outside_the_project(): void
    {
        $sourcePath = sys_get_temp_dir().'/appgraph-external-source-'.bin2hex(random_bytes(6)).'.php';
        $outputPath = storage_path('appgraph-external-source-test/appgraph.json');
        file_put_contents($sourcePath, "<?php\n\nreturn true;\n");
        $this->disableScanners();
        config()->set('appgraph.scan.calls', true);
        app()->instance(CallScanner::class, new class(app(PhpFileFacts::class), $sourcePath) extends CallScanner
        {
            public function __construct(
                private PhpFileFacts $facts,
                private string $sourcePath,
            ) {
            }

            public function scan(Graph $graph): Graph
            {
                $this->facts->statements($this->sourcePath);

                return $graph;
            }
        });

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $outputPath,
                '--no-overview' => true,
            ])
                ->expectsOutputToContain('could not be safely canonicalized inside the project')
                ->assertFailed();

            $this->assertNull(app(GraphStore::class)->current());
            $this->assertFileDoesNotExist($outputPath);
        } finally {
            @unlink($sourcePath);
            @unlink($outputPath);
        }
    }

    public function test_scan_rejects_booted_router_mutation_during_analysis(): void
    {
        $outputPath = storage_path('appgraph-router-mutation-test/appgraph.json');
        $this->disableScanners();
        config()->set('appgraph.scan.calls', true);
        @unlink($outputPath);
        app()->instance(CallScanner::class, new class extends CallScanner
        {
            public function __construct()
            {
            }

            public function scan(Graph $graph): Graph
            {
                Route::get('/appgraph-runtime-mutation', static fn (): string => 'ok');

                return $graph;
            }
        });

        $this->artisan('appgraph:scan', [
            '--output' => $outputPath,
            '--no-overview' => true,
        ])
            ->expectsOutputToContain('source inputs changed while the scan was running')
            ->assertFailed();

        $this->assertNull(app(GraphStore::class)->current());
        $this->assertFileDoesNotExist($outputPath);
    }

    public function test_scan_rejects_frontend_source_aba_observed_between_equal_endpoint_fingerprints(): void
    {
        $sourcePath = base_path('resources/js/AppGraphFrontendAbaFixture.js');
        $outputPath = storage_path('appgraph-frontend-aba-test/appgraph.json');
        $original = is_file($sourcePath) ? (string) file_get_contents($sourcePath) : null;
        (new Filesystem())->ensureDirectoryExists(dirname($sourcePath));
        $stableSource = "const stable = true;\n";
        $intermediateSource = "route('appgraph.frontend-aba');\n";
        file_put_contents($sourcePath, $stableSource);
        @unlink($outputPath);
        $this->disableScanners();
        config()->set('appgraph.scan.routes', true);
        config()->set('appgraph.scan.frontend', true);
        Route::get('/appgraph-frontend-aba', static fn (): string => 'ok')
            ->name('appgraph.frontend-aba');
        $files = app(FileFinder::class);
        $observations = app(SourceFileObservations::class);
        app()->instance(FrontendRouteScanner::class, new class(
            $files,
            $observations,
            $sourcePath,
            $stableSource,
            $intermediateSource,
        ) extends FrontendRouteScanner
        {
            public function __construct(
                FileFinder $files,
                SourceFileObservations $observations,
                private string $sourcePath,
                private string $stableSource,
                private string $intermediateSource,
            ) {
                parent::__construct($files, $observations);
            }

            public function scan(Graph $graph): Graph
            {
                file_put_contents($this->sourcePath, $this->intermediateSource);

                try {
                    return parent::scan($graph);
                } finally {
                    file_put_contents($this->sourcePath, $this->stableSource);
                }
            }
        });

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $outputPath,
                '--no-overview' => true,
            ])
                ->expectsOutputToContain('did not match the final scan manifest')
                ->assertFailed();

            $this->assertNull(app(GraphStore::class)->current());
            $this->assertFileDoesNotExist($outputPath);
        } finally {
            if ($original === null) {
                @unlink($sourcePath);
            } else {
                file_put_contents($sourcePath, $original);
            }
        }
    }

    public function test_scan_rejects_schema_dump_aba_observed_between_equal_endpoint_fingerprints(): void
    {
        $directory = base_path('database/schema/appgraph-schema-aba');
        $sourcePath = $directory.'/schema.sql';
        $outputPath = storage_path('appgraph-schema-aba-test/appgraph.json');
        (new Filesystem())->ensureDirectoryExists($directory);
        $stableSource = "CREATE TABLE stable_schema (id INTEGER PRIMARY KEY);\n";
        $intermediateSource = "CREATE TABLE intermediate_schema (id INTEGER PRIMARY KEY);\n";
        file_put_contents($sourcePath, $stableSource);
        @unlink($outputPath);
        $this->disableScanners();
        config()->set('appgraph.scan.database', true);
        config()->set('appgraph.database.source', 'dump');
        config()->set('appgraph.database.dump.run', false);
        config()->set('appgraph.database.dump.search_paths', [$directory]);
        $observations = app(SourceFileObservations::class);
        app()->instance(DatabaseSchemaScanner::class, new class(
            new SchemaDumpParser(),
            new Filesystem(),
            $observations,
            $sourcePath,
            $stableSource,
            $intermediateSource,
        ) extends DatabaseSchemaScanner
        {
            public function __construct(
                SchemaDumpParser $parser,
                Filesystem $files,
                SourceFileObservations $observations,
                private string $sourcePath,
                private string $stableSource,
                private string $intermediateSource,
            ) {
                parent::__construct($parser, $files, $observations);
            }

            public function scan(Graph $graph, ?string $connectionName = null): Graph
            {
                file_put_contents($this->sourcePath, $this->intermediateSource);

                try {
                    return parent::scan($graph, $connectionName);
                } finally {
                    file_put_contents($this->sourcePath, $this->stableSource);
                }
            }
        });

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $outputPath,
                '--no-overview' => true,
            ])
                ->expectsOutputToContain('did not match the final scan manifest')
                ->assertFailed();

            $this->assertNull(app(GraphStore::class)->current());
            $this->assertFileDoesNotExist($outputPath);
        } finally {
            @unlink($sourcePath);
            @rmdir($directory);
        }
    }

    public function test_generated_schema_dump_read_before_initial_fingerprint_must_match_final_manifest(): void
    {
        $directory = base_path('database/schema/appgraph-generated-order');
        $sourcePath = $directory.'/schema.sql';
        $outputPath = storage_path('appgraph-generated-order-test/appgraph.json');
        (new Filesystem())->ensureDirectoryExists($directory);
        $parsedSource = "CREATE TABLE parsed_before_fingerprint (id INTEGER PRIMARY KEY);\n";
        $manifestSource = "CREATE TABLE changed_before_fingerprint (id INTEGER PRIMARY KEY);\n";
        file_put_contents($sourcePath, $parsedSource);
        @unlink($outputPath);
        $this->disableScanners();
        config()->set('appgraph.scan.database', true);
        config()->set('appgraph.database.source', 'dump');
        config()->set('appgraph.database.dump.run', true);
        config()->set('appgraph.database.dump.search_paths', [$directory]);
        $observations = app(SourceFileObservations::class);
        app()->instance(DatabaseSchemaScanner::class, new class(
            new SchemaDumpParser(),
            new Filesystem(),
            $observations,
            $sourcePath,
            $manifestSource,
        ) extends DatabaseSchemaScanner
        {
            public function __construct(
                SchemaDumpParser $parser,
                Filesystem $files,
                SourceFileObservations $observations,
                private string $sourcePath,
                private string $manifestSource,
            ) {
                parent::__construct($parser, $files, $observations);
            }

            public function scan(Graph $graph, ?string $connectionName = null): Graph
            {
                // The command selected its generated-dump-before-fingerprint phase.
                // Avoid creating a real dump in this fixture while exercising the
                // production parser and observation ledger on the existing SQL.
                config()->set('appgraph.database.dump.run', false);

                try {
                    parent::scan($graph, $connectionName);
                } finally {
                    config()->set('appgraph.database.dump.run', true);
                }

                file_put_contents($this->sourcePath, $this->manifestSource);

                return $graph;
            }
        });

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $outputPath,
                '--no-overview' => true,
            ])
                ->expectsOutputToContain('did not match the final scan manifest')
                ->assertFailed();

            $this->assertNull(app(GraphStore::class)->current());
            $this->assertFileDoesNotExist($outputPath);
        } finally {
            @unlink($sourcePath);
            @rmdir($directory);
        }
    }

    public function test_templated_generated_schema_dump_outside_search_paths_enters_the_manifest(): void
    {
        $directory = storage_path('appgraph-templated-schema');
        $sourcePath = $directory.'/testing-default.sql';
        $outputPath = storage_path('appgraph-templated-schema-test/appgraph.json');
        $source = "CREATE TABLE templated_schema (id INTEGER PRIMARY KEY);\n";
        (new Filesystem())->ensureDirectoryExists($directory);
        @unlink($sourcePath);
        @unlink($outputPath);
        $this->disableScanners();
        config()->set('appgraph.scan.database', true);
        config()->set('appgraph.database.source', 'dump');
        config()->set('appgraph.database.dump.run', true);
        config()->set('appgraph.database.dump_path', $directory.'/{connection}-{context}.sql');
        config()->set('appgraph.database.dump.search_paths', []);
        $staleness = app(StalenessChecker::class);
        $observations = app(SourceFileObservations::class);
        app()->instance(DatabaseSchemaScanner::class, new class(
            new SchemaDumpParser(),
            new Filesystem(),
            $observations,
            $sourcePath,
            $source,
        ) extends DatabaseSchemaScanner
        {
            public function __construct(
                SchemaDumpParser $parser,
                Filesystem $files,
                private SourceFileObservations $observations,
                private string $sourcePath,
                private string $source,
            ) {
                parent::__construct($parser, $files, $observations);
            }

            public function scan(Graph $graph, ?string $connectionName = null): Graph
            {
                (new Filesystem())->ensureDirectoryExists(dirname($this->sourcePath));
                file_put_contents($this->sourcePath, $this->source);
                $this->observations->record($this->sourcePath, $this->source);

                return $graph;
            }

            public function generatedDumpPaths(): array
            {
                return [$this->sourcePath];
            }
        });

        try {
            $this->artisan('appgraph:scan', [
                '--output' => $outputPath,
                '--no-overview' => true,
            ])->assertSuccessful();

            $generation = app(GraphStore::class)->current();
            $this->assertNotNull($generation);
            $graph = app(GraphStore::class)->graph($generation['id']);
            $relative = (new FileFinder())->relativePath($sourcePath);

            $this->assertIsString($relative);
            $this->assertSame(hash('sha256', $source), $graph['meta']['scan']['files'][$relative]);
            $this->assertSame([$relative], $graph['meta']['scan']['additionalFiles']);
            $freshness = $staleness->check(
                app(GraphStore::class)->path(),
                $graph['meta']['scan'],
            );
            $this->assertFalse($freshness['stale'], json_encode($freshness, JSON_THROW_ON_ERROR));
        } finally {
            @unlink($sourcePath);
            @rmdir($directory);
        }
    }

    public function test_scan_command_writes_valid_json_graph(): void
    {
        $this->useFileBackedSqliteDatabase();
        $this->createCommandModelFixture();
        $overviewController = $this->createFormRequestAndEventFixtures();
        $this->assertSame([
            'depth' => 6,
            'method_limit' => 32,
            'item_limit' => 12,
            'transition_limit' => 5000,
        ], config('appgraph.overview.route_summary'));
        $this->assertNull(config('appgraph.mcp.route_summary'));
        config()->set('appgraph.overview.route_summary.depth', 5);
        config()->set('appgraph.overview.route_summary.method_limit', 9);
        config()->set('appgraph.overview.route_summary.item_limit', 4);
        config()->set('appgraph.overview.route_summary.transition_limit', 77);
        Event::listen(
            'App\Events\CommandNoteSaved',
            ['App\Listeners\CommandNoteListener', 'handle'],
        );

        Route::put('/progress-notes/{note}', [ProgressNoteController::class, 'update'])
            ->name('progress-notes.update');
        Route::post('/command-notes', [$overviewController, 'store'])
            ->name('command-notes.store');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });

        Schema::create('progress_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('title');
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('message');
        });

        $outputPath = storage_path('appgraph-test/appgraph.json');
        $overviewPath = storage_path('appgraph-test/overview.json');
        @unlink($outputPath);
        @unlink($overviewPath);

        $this->artisan('appgraph:scan', [
            '--output' => $outputPath,
            '--pretty' => true,
        ])->assertSuccessful();

        $this->assertFileExists($outputPath);

        $graph = json_decode((string) file_get_contents($outputPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('0.4.1', $graph['meta']['appgraphVersion']);
        $this->assertSame($graph['meta']['generation']['id'], app(GraphStore::class)->current()['id']);
        $this->assertSame(count($graph['nodes']), app(GraphStore::class)->current()['counts']['nodes']);
        $this->assertSame(count($graph['edges']), app(GraphStore::class)->current()['counts']['edges']);
        $this->assertSame(ScanFingerprint::VERSION, $graph['meta']['scan']['version']);
        $this->assertSame('sha256', $graph['meta']['scan']['algorithm']);
        $this->assertSame('testing', $graph['meta']['scan']['applicationEnvironment']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $graph['meta']['scan']['containerBindings']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $graph['meta']['scan']['laravelExecutionRegistry']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $graph['meta']['scan']['fingerprint']);
        $this->assertNotEmpty($graph['meta']['scan']['files']);
        $this->assertGraphHasNode($graph, 'route:PUT:/progress-notes/{note}', 'route');
        $this->assertGraphHasNode($graph, ProgressNoteController::class.'::update', 'method');
        $this->assertGraphHasNode($graph, 'table:progress_notes', 'table');
        $this->assertGraphHasNode($graph, 'column:progress_notes.title', 'column');
        $this->assertGraphHasNode($graph, 'App\Models\CommandProgressNote', 'model');
        $this->assertGraphHasEdge($graph, 'route:PUT:/progress-notes/{note}', ProgressNoteController::class.'::update', 'routes_to');
        $this->assertGraphHasEdge($graph, 'App\Models\CommandProgressNote', 'table:progress_notes', 'uses_table');
        $this->assertGraphHasEdge($graph, 'table:progress_notes', 'column:progress_notes.title', 'has_column');

        // FormRequest rules extraction and event flow edges.
        $this->assertGraphHasNode($graph, 'App\Http\Requests\CommandNoteRequest', 'form_request');
        $request = $this->graphNode($graph, 'App\Http\Requests\CommandNoteRequest');
        $this->assertSame(['title' => 'required|string'], $request['metadata']['rules']);
        $this->assertGraphHasEdge(
            $graph,
            'App\Http\Requests\CommandNoteRequest',
            'App\Http\Requests\CommandNoteRequest::rules',
            'framework_invokes',
        );

        $this->assertGraphHasNode($graph, 'App\Events\CommandNoteSaved', 'event');
        $this->assertGraphHasEdge($graph, 'App\Services\CommandNotePublisher::publish', 'App\Events\CommandNoteSaved', 'dispatches');
        $this->assertGraphHasEdge($graph, 'App\Listeners\CommandNoteListener::handle', 'App\Events\CommandNoteSaved', 'listens_to');
        $this->assertGraphHasEdge($graph, 'App\Events\CommandNoteSaved', 'App\Listeners\CommandNoteListener::handle', 'handled_by');
        $this->assertSame('testing', $graph['meta']['analysis']['containerBindings']['environment']);
        $phpFacts = $graph['meta']['analysis']['phpFileFacts'];
        $this->assertFalse($phpFacts['persistent']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $phpFacts['identity']);
        $this->assertGreaterThan(0, $phpFacts['counters']['parses']);
        $this->assertGreaterThan(0, $phpFacts['counters']['memoryHits']);
        $this->assertGreaterThan(
            $phpFacts['counters']['parses'],
            $phpFacts['counters']['requests'],
        );

        // Token-efficient shape: null/empty fields are omitted, schema sources are interned.
        $routeNode = $this->graphNode($graph, 'route:PUT:/progress-notes/{note}');
        $this->assertArrayNotHasKey('summary', $routeNode, 'Null fields must be omitted from the export.');
        $this->assertArrayHasKey('sources', $graph['meta'], 'Schema sources must be interned into meta.');
        $tableNode = $this->graphNode($graph, 'table:progress_notes');
        $this->assertArrayNotHasKey('schemaSources', $tableNode['metadata'], 'Embedded schemaSources blob must be gone.');
        $this->assertContains($tableNode['metadata']['sources'][0], array_keys($graph['meta']['sources']));

        // Overview projection is emitted alongside the main graph and is well-formed.
        $this->assertFileExists($overviewPath);

        $overview = json_decode((string) file_get_contents($overviewPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('0.4.1', $overview['meta']['appgraphVersion']);
        $this->assertSame([
            'maxDepth' => 5,
            'maxMethods' => 9,
            'maxRelatedFacts' => 1024,
            'maxItemsPerGroup' => 4,
            'maxTransitions' => 77,
        ], $overview['meta']['routeTraversal']);
        $this->assertSame('progress_notes', $overview['models']['App\Models\CommandProgressNote']);
        $this->assertArrayHasKey('byNodeType', $overview['counts']);
        $this->assertSame(1, $overview['events']['App\Events\CommandNoteSaved']['listeners']);
        $this->assertSame(1, $overview['events']['App\Events\CommandNoteSaved']['dispatchSites']);

        $route = collect($overview['routes'])->firstWhere('route', 'PUT /progress-notes/{note}');
        $this->assertNotNull($route, 'Overview must list the scanned route.');
        $this->assertSame(ProgressNoteController::class.'::update', $route['action']);

        $transitive = collect($overview['routes'])->firstWhere('route', 'POST /command-notes');
        $this->assertNotNull($transitive, 'Overview must list the transitive fixture route.');
        $this->assertContains('audit_logs', $transitive['tables']);
        $this->assertContains('App\Events\CommandNoteSaved', $transitive['dispatches']);
    }

    /** @return class-string */
    private function createFormRequestAndEventFixtures(): string
    {
        $files = new Filesystem();

        foreach (['app/Http/Controllers', 'app/Http/Requests', 'app/Events', 'app/Listeners', 'app/Services'] as $directory) {
            $files->ensureDirectoryExists(base_path($directory));
        }

        $controller = base_path('app/Http/Controllers/CommandOverviewController.php');
        file_put_contents($controller, <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Services\CommandNotePublisher;

class CommandOverviewController
{
    public function store(CommandNotePublisher $publisher): array
    {
        $publisher->publish();

        return ['ok' => true];
    }
}
PHP);
        require_once $controller;

        file_put_contents(base_path('app/Http/Requests/CommandNoteRequest.php'), <<<'PHP'
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommandNoteRequest extends FormRequest
{
    public function rules(): array
    {
        return ['title' => 'required|string'];
    }
}
PHP);

        file_put_contents(base_path('app/Events/CommandNoteSaved.php'), <<<'PHP'
<?php

namespace App\Events;

class CommandNoteSaved
{
}
PHP);

        file_put_contents(base_path('app/Listeners/CommandNoteListener.php'), <<<'PHP'
<?php

namespace App\Listeners;

use App\Events\CommandNoteSaved;
use Illuminate\Support\Facades\DB;

class CommandNoteListener
{
    public function handle(CommandNoteSaved $event): void
    {
        DB::table('audit_logs')->insert(['message' => 'saved']);
    }
}
PHP);

        file_put_contents(base_path('app/Services/CommandNotePublisher.php'), <<<'PHP'
<?php

namespace App\Services;

use App\Events\CommandNoteSaved;

class CommandNotePublisher
{
    public function publish(): void
    {
        event(new CommandNoteSaved());
    }
}
PHP);

        return 'App\Http\Controllers\CommandOverviewController';
    }

    private function createCommandModelFixture(): void
    {
        $directory = base_path('app/Models');
        (new Filesystem())->ensureDirectoryExists($directory);

        file_put_contents($directory.'/CommandProgressNote.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommandProgressNote extends Model
{
    protected $table = 'progress_notes';
}
PHP);
    }

    /**
     * @return array{
     *     token: string,
     *     class: class-string,
     *     directory: string,
     *     path: string,
     *     relative: string,
     *     source: string,
     *     changedSource: string
     * }
     */
    private function createObservedDependencyControllerFixture(
        FileFinder $files,
        ?string $relativeDirectory = null,
        string $fileName = 'DependencyController.php',
    ): array
    {
        $token = bin2hex(random_bytes(6));
        $namespace = 'AppGraphVendorFixture\\F'.$token;
        $className = pathinfo($fileName, PATHINFO_FILENAME);
        $class = $namespace.'\\'.$className;
        $relativeDirectory ??= 'vendor/appgraph-scan-fixture-'.$token;
        $directory = $files->absolutePath($relativeDirectory);
        $path = $directory.'/'.$fileName;
        $source = <<<PHP
<?php

namespace {$namespace};

final class {$className} implements \\Illuminate\\Routing\\Controllers\\HasMiddleware
{
    public static array \$middleware = ['web'];

    public static function middleware()
    {
        return array_map(
            static fn (string \$middleware): string => \$middleware,
            static::\$middleware,
        );
    }

    public function __invoke(): string
    {
        return 'ok';
    }
}
PHP;
        $changedSource = $source."\n// changed after route introspection\n";
        (new Filesystem())->ensureDirectoryExists($directory);
        file_put_contents($path, $source);
        require_once $path;

        return [
            'token' => $token,
            'class' => $class,
            'directory' => $directory,
            'path' => $path,
            'relative' => $relativeDirectory.'/'.$fileName,
            'source' => $source,
            'changedSource' => $changedSource,
        ];
    }

    /** @return array{root: string, files: FileFinder, fingerprint: ScanFingerprint} */
    private function useIsolatedScanRoot(): array
    {
        $root = sys_get_temp_dir().'/appgraph-scan-root-'.bin2hex(random_bytes(6));
        (new Filesystem())->ensureDirectoryExists($root);
        $files = new FileFinder($root);
        $fingerprint = new ScanFingerprint($files, app(ContainerBindingRegistry::class));
        app()->instance(FileFinder::class, $files);
        app()->instance(ScanFingerprint::class, $fingerprint);
        app()->instance(StalenessChecker::class, new StalenessChecker($files, $fingerprint));

        return [
            'root' => $root,
            'files' => $files,
            'fingerprint' => $fingerprint,
        ];
    }

    private function disableScanners(): void
    {
        foreach (['routes', 'database', 'models', 'calls', 'data_flow', 'form_requests', 'events', 'side_effects', 'frontend', 'tests', 'policies', 'container_bindings'] as $scanner) {
            config()->set("appgraph.scan.{$scanner}", false);
        }
    }
}
