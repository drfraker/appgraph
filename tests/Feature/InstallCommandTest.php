<?php

namespace AppGraph\Tests\Feature;

use AppGraph\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    private string $configPath = '';

    private string $codexPath = '';

    private string $guidelinesPath = '';

    private string $gitignorePath = '';

    private string $phpunitPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = sys_get_temp_dir().'/appgraph-mcp-'.bin2hex(random_bytes(4)).'.json';
        $this->codexPath = sys_get_temp_dir().'/appgraph-codex-'.bin2hex(random_bytes(4)).'.toml';
        $this->guidelinesPath = sys_get_temp_dir().'/appgraph-agents-'.bin2hex(random_bytes(4)).'.md';
        $this->gitignorePath = sys_get_temp_dir().'/appgraph-ignore-'.bin2hex(random_bytes(4));
        $this->phpunitPath = sys_get_temp_dir().'/appgraph-phpunit-'.bin2hex(random_bytes(4)).'.xml';
    }

    protected function tearDown(): void
    {
        foreach ([$this->configPath, $this->codexPath, $this->guidelinesPath, $this->gitignorePath, $this->phpunitPath] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_it_writes_an_mcp_config_registering_the_appgraph_server(): void
    {
        $this->artisan('appgraph:install', $this->claudeOnlyOptions())
            ->expectsOutputToContain('Agents resolve with appgraph_find, plan source reads with appgraph_slice, and rebuild after edits with appgraph_refresh.')
            ->assertSuccessful();

        $this->assertFileExists($this->configPath);

        $config = json_decode((string) file_get_contents($this->configPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('php', $config['mcpServers']['appgraph']['command']);
        $this->assertSame(['artisan', 'mcp:start', 'appgraph'], $config['mcpServers']['appgraph']['args']);
        $this->assertSame(base_path(), $config['mcpServers']['appgraph']['cwd']);
    }

    public function test_it_merges_into_an_existing_config_without_clobbering_other_servers(): void
    {
        file_put_contents($this->configPath, json_encode([
            'mcpServers' => [
                'other' => ['command' => 'node', 'args' => ['server.js']],
            ],
        ]));

        $this->artisan('appgraph:install', $this->claudeOnlyOptions(['--force' => true]))
            ->assertSuccessful();

        $config = json_decode((string) file_get_contents($this->configPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('other', $config['mcpServers']);
        $this->assertArrayHasKey('appgraph', $config['mcpServers']);
        $this->assertSame('node', $config['mcpServers']['other']['command']);
    }

    public function test_it_fails_on_an_unparseable_existing_config(): void
    {
        file_put_contents($this->configPath, '{ not valid json');

        $this->artisan('appgraph:install', $this->claudeOnlyOptions())
            ->assertFailed();
    }

    public function test_it_merges_an_idempotent_managed_server_into_codex_config(): void
    {
        file_put_contents($this->codexPath, implode(PHP_EOL, [
            '[mcp_servers.other]',
            'command = "other"',
            '',
        ]));

        $options = [
            '--client' => ['codex'],
            '--codex-path' => $this->codexPath,
            '--no-guidelines' => true,
            '--no-gitignore' => true,
            '--no-scan' => true,
        ];

        $this->artisan('appgraph:install', $options)->assertSuccessful();
        $this->artisan('appgraph:install', $options)->assertSuccessful();

        $contents = (string) file_get_contents($this->codexPath);

        $this->assertStringContainsString('[mcp_servers.other]', $contents);
        $this->assertStringContainsString('[mcp_servers.appgraph]', $contents);
        $this->assertStringContainsString('args = ["artisan", "mcp:start", "appgraph"]', $contents);
        $this->assertStringContainsString('cwd = "'.str_replace('\\', '\\\\', base_path()).'"', $contents);
        $this->assertSame(1, substr_count($contents, '# >>> appgraph >>>'));
        $this->assertSame(1, substr_count($contents, '# <<< appgraph <<<'));
    }

    public function test_it_adds_idempotent_agent_workflow_without_clobbering_existing_guidance(): void
    {
        file_put_contents($this->guidelinesPath, "# Existing project instructions\n");

        $options = [
            '--client' => ['claude'],
            '--path' => $this->configPath,
            '--guidelines-path' => [$this->guidelinesPath],
            '--no-scan' => true,
            '--force' => true,
            '--no-gitignore' => true,
        ];

        $this->artisan('appgraph:install', $options)->assertSuccessful();
        $this->artisan('appgraph:install', $options)->assertSuccessful();

        $contents = (string) file_get_contents($this->guidelinesPath);
        $bundledGuidelines = trim((string) file_get_contents(__DIR__.'/../../resources/ai/appgraph-guidelines.md'));

        $this->assertStringStartsWith('# Existing project instructions', $contents);
        $this->assertStringNotContainsString('appgraph_context', $contents);
        $this->assertStringContainsString('appgraph_find', $contents);
        $this->assertStringContainsString('appgraph_slice', $contents);
        $this->assertStringContainsString('appgraph_refresh', $contents);
        $this->assertStringContainsString('not_observed', $contents);
        $this->assertStringContainsString('untrusted repository data', $contents);
        $this->assertStringNotContainsString('appgraph_search', $contents);
        $this->assertStringNotContainsString('appgraph_node', $contents);
        $this->assertStringNotContainsString('appgraph_query', $contents);
        $this->assertStringNotContainsString('appgraph_overview', $contents);
        $this->assertLessThanOrEqual(10, count(preg_split('/\R/', $bundledGuidelines) ?: []));
        $this->assertSame(1, substr_count($contents, '<appgraph-guidelines>'));
        $this->assertSame(1, substr_count($contents, '</appgraph-guidelines>'));
        $this->assertStringNotContainsString('<!--', $contents);
    }

    public function test_it_migrates_legacy_comment_guidelines_blocks(): void
    {
        $options = [
            '--client' => ['claude'],
            '--path' => $this->configPath,
            '--guidelines-path' => [$this->guidelinesPath],
            '--no-scan' => true,
            '--force' => true,
            '--no-gitignore' => true,
        ];

        foreach (['<!-- >>> appgraph >>>', '<!-- >>> appgraph >>> -->'] as $legacyStartMarker) {
            file_put_contents($this->guidelinesPath, implode(PHP_EOL, [
                '# Existing project instructions',
                '',
                $legacyStartMarker,
                '# Stale AppGraph workflow',
                '',
                '<!-- <<< appgraph <<< -->',
                '',
                '<laravel-boost-guidelines>',
                'Existing Boost guidance.',
                '</laravel-boost-guidelines>',
                '',
            ]));

            $this->artisan('appgraph:install', $options)->assertSuccessful();
            $this->artisan('appgraph:install', $options)->assertSuccessful();

            $contents = (string) file_get_contents($this->guidelinesPath);

            $this->assertStringStartsWith('# Existing project instructions', $contents);
            $this->assertStringContainsString('<laravel-boost-guidelines>', $contents);
            $this->assertStringContainsString('Existing Boost guidance.', $contents);
            $this->assertStringNotContainsString('# Stale AppGraph workflow', $contents);
            $this->assertStringNotContainsString('<!-- >>> appgraph >>>', $contents);
            $this->assertStringNotContainsString('<!-- <<< appgraph <<< -->', $contents);
            $this->assertSame(1, substr_count($contents, '<appgraph-guidelines>'));
            $this->assertSame(1, substr_count($contents, '</appgraph-guidelines>'));
        }
    }

    public function test_it_idempotently_ignores_the_generated_graph_directory(): void
    {
        file_put_contents($this->gitignorePath, "/vendor\n");

        $options = [
            '--client' => ['claude'],
            '--path' => $this->configPath,
            '--no-guidelines' => true,
            '--gitignore-path' => $this->gitignorePath,
            '--no-scan' => true,
            '--force' => true,
        ];

        $this->artisan('appgraph:install', $options)->assertSuccessful();
        $this->artisan('appgraph:install', $options)->assertSuccessful();

        $contents = (string) file_get_contents($this->gitignorePath);

        $this->assertStringStartsWith('/vendor', $contents);
        $this->assertStringContainsString('/storage/appgraph/', $contents);
        $this->assertSame(1, substr_count($contents, '# >>> appgraph generated files >>>'));
        $this->assertSame(1, substr_count($contents, '# <<< appgraph generated files <<<'));
    }

    public function test_it_ignores_distinct_json_and_sqlite_store_directories(): void
    {
        config()->set('appgraph.store.path', 'architecture-store/appgraph.sqlite');
        config()->set('appgraph.runtime_evidence.path', 'runtime-map/evidence.json');

        $this->artisan('appgraph:install', [
            '--client' => ['claude'],
            '--path' => $this->configPath,
            '--no-guidelines' => true,
            '--gitignore-path' => $this->gitignorePath,
            '--no-scan' => true,
            '--force' => true,
        ])->assertSuccessful();

        $contents = (string) file_get_contents($this->gitignorePath);

        $this->assertStringContainsString('/storage/appgraph/', $contents);
        $this->assertStringContainsString('/storage/architecture-store/', $contents);
        $this->assertStringContainsString('/storage/runtime-map/', $contents);
    }

    public function test_it_exactly_ignores_an_absolute_store_in_the_project_root(): void
    {
        config()->set('appgraph.store.path', base_path('root-appgraph.sqlite'));
        config()->set('appgraph.runtime_evidence.path', base_path('runtime-evidence.json'));

        $this->artisan('appgraph:install', [
            '--client' => ['claude'],
            '--path' => $this->configPath,
            '--no-guidelines' => true,
            '--gitignore-path' => $this->gitignorePath,
            '--no-scan' => true,
            '--force' => true,
        ])->assertSuccessful();

        $contents = (string) file_get_contents($this->gitignorePath);

        $this->assertStringContainsString('/root-appgraph.sqlite'.PHP_EOL, $contents);
        $this->assertStringContainsString('/root-appgraph.sqlite-wal'.PHP_EOL, $contents);
        $this->assertStringContainsString('/root-appgraph.sqlite-shm'.PHP_EOL, $contents);
        $this->assertStringContainsString('/root-appgraph.sqlite-journal'.PHP_EOL, $contents);
        $this->assertStringContainsString('/.scan.lock'.PHP_EOL, $contents);
        $this->assertStringContainsString('/runtime-evidence.json'.PHP_EOL, $contents);
        $this->assertStringContainsString('/runtime-evidence.json.lock'.PHP_EOL, $contents);
        $this->assertStringNotContainsString(PHP_EOL.'//'.PHP_EOL, $contents);
    }

    public function test_it_idempotently_registers_opt_in_runtime_evidence_in_phpunit_xml(): void
    {
        file_put_contents($this->phpunitPath, implode(PHP_EOL, [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<phpunit>',
            '    <extensions>',
            '        <bootstrap class="Existing\\Extension"/>',
            '    </extensions>',
            '</phpunit>',
            '',
        ]));

        $options = $this->claudeOnlyOptions([
            '--phpunit-path' => $this->phpunitPath,
            '--no-runtime' => false,
            '--force' => true,
        ]);

        $this->artisan('appgraph:install', $options)->assertSuccessful();
        $this->artisan('appgraph:install', $options)->assertSuccessful();

        $contents = (string) file_get_contents($this->phpunitPath);

        $this->assertStringContainsString('<bootstrap class="Existing\\Extension"/>', $contents);
        $this->assertStringContainsString('<bootstrap class="AppGraph\\Runtime\\PHPUnit\\AppGraphExtension">', $contents);
        $this->assertStringContainsString('<parameter name="output" value="storage/appgraph/runtime-evidence.json"/>', $contents);
        $this->assertSame(1, substr_count($contents, 'AppGraph\\Runtime\\PHPUnit\\AppGraphExtension'));
    }

    public function test_it_can_leave_phpunit_xml_untouched(): void
    {
        $original = "<?xml version=\"1.0\"?><phpunit/>\n";
        file_put_contents($this->phpunitPath, $original);

        $this->artisan('appgraph:install', $this->claudeOnlyOptions([
            '--phpunit-path' => $this->phpunitPath,
            '--no-runtime' => true,
        ]))->assertSuccessful();

        $this->assertSame($original, file_get_contents($this->phpunitPath));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function claudeOnlyOptions(array $overrides = []): array
    {
        return array_replace([
            '--client' => ['claude'],
            '--path' => $this->configPath,
            '--no-guidelines' => true,
            '--no-gitignore' => true,
            '--no-runtime' => true,
            '--no-scan' => true,
        ], $overrides);
    }
}
