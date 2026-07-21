<?php

namespace AppGraph\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;

class InstallCommand extends Command
{
    private const CODEX_BLOCK_START = '# >>> appgraph >>>';

    private const CODEX_BLOCK_END = '# <<< appgraph <<<';

    private const GUIDELINES_BLOCK_START = '<!-- >>> appgraph >>>';

    private const GUIDELINES_BLOCK_END = '<!-- <<< appgraph <<< -->';

    private const GITIGNORE_BLOCK_START = '# >>> appgraph generated files >>>';

    private const GITIGNORE_BLOCK_END = '# <<< appgraph generated files <<<';

    protected $signature = 'appgraph:install
        {--client=* : AI clients to configure: codex and/or claude. Defaults to both.}
        {--path= : Claude-compatible MCP JSON path. Defaults to .mcp.json.}
        {--codex-path= : Codex project config path. Defaults to .codex/config.toml.}
        {--guidelines-path=* : Agent instruction files to update. Defaults to existing AGENTS.md/CLAUDE.md, or AGENTS.md.}
        {--no-guidelines : Do not install the AppGraph agent workflow instructions.}
        {--gitignore-path= : Git ignore file to update. Defaults to .gitignore.}
        {--no-gitignore : Do not add the generated AppGraph directory to .gitignore.}
        {--no-scan : Do not build the initial graph after configuring clients.}
        {--force : Replace an existing AppGraph configuration without prompting.}';

    protected $description = 'Install AppGraph for local AI clients, add agent guidance, and build the initial application graph.';

    public function handle(Filesystem $files): int
    {
        $clients = $this->clients();

        if ($clients === null) {
            return self::FAILURE;
        }

        try {
            if (in_array('claude', $clients, true) && ! $this->installMcpJson($files)) {
                return self::FAILURE;
            }

            if (in_array('codex', $clients, true) && ! $this->installCodexConfig($files)) {
                return self::FAILURE;
            }

            if (! (bool) $this->option('no-guidelines')) {
                $this->installGuidelines($files);
            }

            if (! (bool) $this->option('no-gitignore')) {
                $this->installGitignore($files);
            }
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! (bool) $this->option('no-scan')) {
            $this->newLine();
            $this->components->info('Building the initial AppGraph');

            if ($this->call('appgraph:scan') !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->components->info('AppGraph is ready for AI-assisted feature work and refactoring.');
        $this->line('  <fg=gray>Restart the AI client so it loads the new MCP server and project instructions.</>');
        $this->line('  <fg=gray>Agents resolve targets with appgraph_search and traverse with appgraph_query as needed.</>');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>|null
     */
    private function clients(): ?array
    {
        $clients = array_values(array_unique(array_map(
            static fn (mixed $client): string => strtolower(trim((string) $client)),
            (array) $this->option('client')
        )));

        if ($clients === []) {
            return ['codex', 'claude'];
        }

        $invalid = array_values(array_diff($clients, ['codex', 'claude']));

        if ($invalid !== []) {
            $this->error('Unsupported AppGraph client(s): '.implode(', ', $invalid).'. Use codex and/or claude.');

            return null;
        }

        return $clients;
    }

    private function installMcpJson(Filesystem $files): bool
    {
        $path = $this->configuredPath('path', '.mcp.json');
        $config = $this->readExistingJson($files, $path);

        if ($config === null) {
            $this->error("The MCP config at [{$path}] is not valid JSON. Fix or remove it, then re-run.");

            return false;
        }

        $servers = is_array($config['mcpServers'] ?? null) ? $config['mcpServers'] : [];

        if (isset($servers['appgraph']) && ! $this->option('force')
            && ! $this->confirm("An 'appgraph' MCP server is already configured in {$this->relativePath($path)}. Overwrite it?", true)) {
            $this->info('Left the existing Claude-compatible MCP entry untouched.');

            return true;
        }

        $servers['appgraph'] = [
            'command' => 'php',
            'args' => ['artisan', 'mcp:start', 'appgraph'],
            'cwd' => base_path(),
        ];

        $config['mcpServers'] = $servers;

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $this->encode($config).PHP_EOL);

        $this->components->info("Claude-compatible MCP server registered in {$this->relativePath($path)}");

        return true;
    }

    private function installCodexConfig(Filesystem $files): bool
    {
        $path = $this->configuredPath('codex-path', '.codex/config.toml');
        $contents = $files->exists($path) ? (string) $files->get($path) : '';

        if (! str_contains($contents, self::CODEX_BLOCK_START)
            && preg_match('/^\[mcp_servers\.appgraph\]\s*$/m', $contents) === 1) {
            $this->error("Codex already has an unmanaged [mcp_servers.appgraph] entry in [{$path}]. Remove it or adopt the AppGraph managed block before re-running.");

            return false;
        }

        $block = implode(PHP_EOL, [
            self::CODEX_BLOCK_START,
            '[mcp_servers.appgraph]',
            'command = "php"',
            'args = ["artisan", "mcp:start", "appgraph"]',
            'cwd = '.$this->tomlString(base_path()),
            'startup_timeout_sec = 30',
            'tool_timeout_sec = 180',
            self::CODEX_BLOCK_END,
        ]);

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $this->upsertManagedBlock(
            $contents,
            $block,
            self::CODEX_BLOCK_START,
            self::CODEX_BLOCK_END,
            $path,
        ));

        $this->components->info("Codex MCP server registered in {$this->relativePath($path)}");

        return true;
    }

    private function installGuidelines(Filesystem $files): void
    {
        $source = __DIR__.'/../../resources/ai/appgraph-guidelines.md';

        if (! $files->exists($source)) {
            throw new RuntimeException("Bundled AppGraph guidelines were not found at [{$source}].");
        }

        $body = trim((string) $files->get($source));
        $block = self::GUIDELINES_BLOCK_START.PHP_EOL.$body.PHP_EOL.self::GUIDELINES_BLOCK_END;

        foreach ($this->guidelinePaths($files) as $path) {
            $contents = $files->exists($path) ? (string) $files->get($path) : '';

            $files->ensureDirectoryExists(dirname($path));
            $files->put($path, $this->upsertManagedBlock(
                $contents,
                $block,
                self::GUIDELINES_BLOCK_START,
                self::GUIDELINES_BLOCK_END,
                $path,
            ));

            $this->components->info("Agent workflow added to {$this->relativePath($path)}");
        }
    }

    private function installGitignore(Filesystem $files): void
    {
        $path = $this->configuredPath('gitignore-path', '.gitignore');
        $contents = $files->exists($path) ? (string) $files->get($path) : '';
        $storePath = config('appgraph.store.path', 'appgraph/appgraph.sqlite');
        $storePath = is_string($storePath) && $storePath !== ''
            ? ($this->isAbsolutePath($storePath) ? $storePath : storage_path($storePath))
            : storage_path('appgraph/appgraph.sqlite');
        $generatedPaths = [
            storage_path(config('appgraph.output_path', 'appgraph/appgraph.json')),
            $storePath,
        ];
        $patterns = [];

        foreach ($generatedPaths as $generatedPath) {
            $relative = $this->relativePath($generatedPath);

            if ($relative === $generatedPath) {
                continue;
            }

            $directory = trim(str_replace('\\', '/', dirname($relative)), './');

            if ($directory !== '') {
                $patterns['/'.$directory.'/'] = true;

                continue;
            }

            // dirname('appgraph.sqlite') is ".". Turning that into "//"
            // neither identifies the file nor ignores SQLite's adjacent
            // artifacts, so root-level generated files need exact patterns.
            $filename = basename(str_replace('\\', '/', $relative));
            $patterns['/'.$filename] = true;

            if ($generatedPath === $storePath) {
                foreach (['-wal', '-shm', '-journal'] as $suffix) {
                    $patterns['/'.$filename.$suffix] = true;
                }

                $patterns['/.scan.lock'] = true;
            }
        }

        if ($patterns === []) {
            $this->components->warn('AppGraph generated files are outside the project; .gitignore was left unchanged.');

            return;
        }

        $patterns = array_keys($patterns);
        sort($patterns);
        $block = implode(PHP_EOL, [
            self::GITIGNORE_BLOCK_START,
            ...$patterns,
            self::GITIGNORE_BLOCK_END,
        ]);

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $this->upsertManagedBlock(
            $contents,
            $block,
            self::GITIGNORE_BLOCK_START,
            self::GITIGNORE_BLOCK_END,
            $path,
        ));

        $this->components->info("Generated graph ignored in {$this->relativePath($path)}");
    }

    /**
     * @return array<int, string>
     */
    private function guidelinePaths(Filesystem $files): array
    {
        $configured = array_values(array_filter(
            (array) $this->option('guidelines-path'),
            static fn (mixed $path): bool => is_string($path) && $path !== ''
        ));

        if ($configured !== []) {
            return array_map(fn (string $path): string => $this->absolutePath($path), $configured);
        }

        $paths = [];

        foreach (['AGENTS.md', 'CLAUDE.md'] as $candidate) {
            $path = base_path($candidate);

            if ($files->exists($path)) {
                $paths[] = $path;
            }
        }

        return $paths !== [] ? $paths : [base_path('AGENTS.md')];
    }

    /**
     * @return array<string, mixed>|null Null signals an unparseable non-empty file.
     */
    private function readExistingJson(Filesystem $files, string $path): ?array
    {
        if (! $files->exists($path)) {
            return [];
        }

        $contents = trim((string) $files->get($path));

        if ($contents === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function configuredPath(string $option, string $default): string
    {
        $configured = $this->option($option);

        return is_string($configured) && $configured !== ''
            ? $this->absolutePath($configured)
            : base_path($default);
    }

    private function upsertManagedBlock(
        string $contents,
        string $block,
        string $startMarker,
        string $endMarker,
        string $path,
    ): string {
        $start = strpos($contents, $startMarker);
        $end = strpos($contents, $endMarker);

        if (($start === false) !== ($end === false) || ($start !== false && $end < $start)) {
            throw new RuntimeException("AppGraph found a malformed managed block in [{$path}]. Fix or remove its AppGraph markers, then re-run.");
        }

        if ($start !== false && $end !== false) {
            $after = $end + strlen($endMarker);
            $contents = substr($contents, 0, $start).$block.substr($contents, $after);

            return rtrim($contents).PHP_EOL;
        }

        $contents = rtrim($contents);

        return ($contents === '' ? '' : $contents.PHP_EOL.PHP_EOL).$block.PHP_EOL;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function encode(array $config): string
    {
        return (string) json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function tomlString(string $value): string
    {
        return '"'.str_replace(
            ["\\", '"', "\n", "\r", "\t"],
            ["\\\\", '\\"', '\\n', '\\r', '\\t'],
            $value
        ).'"';
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $normalized = str_replace('\\', '/', $path);

        return str_starts_with($normalized, $base) ? substr($normalized, strlen($base)) : $path;
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Z]:[\/\\\\]/i', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Z]:[\/\\\\]/i', $path) === 1;
    }
}
