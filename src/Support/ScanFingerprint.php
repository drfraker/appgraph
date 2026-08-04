<?php

namespace AppGraph\Support;

use AppGraph\AppGraph;

/**
 * Produces a deterministic, content-based description of every local input
 * that can affect an AppGraph scan. The manifest is persisted with the graph
 * so freshness checks do not depend on filesystem timestamp resolution.
 */
class ScanFingerprint
{
    public const VERSION = 5;

    private static ?string $runtimeEvidenceSession = null;

    public function __construct(
        private FileFinder $files,
        private ?ContainerBindingRegistry $containerBindings = null,
    ) {
    }

    /**
     * @return array{
     *     version: int,
     *     algorithm: string,
     *     fingerprint: string,
     *     staticFingerprint: string,
     *     fileCount: int,
     *     files: array<string, string>,
     *     additionalFiles?: array<int, string>,
     *     configuration: string,
     *     containerBindings?: string,
     *     laravelExecutionRegistry?: string,
     *     runtimeEvidenceSession?: string,
     *     appgraphVersion: string,
     *     laravelVersion?: string,
     *     applicationEnvironment?: string
     * }
     */
    public function capture(bool $refreshRuntimeEvidence = true, array $additionalFiles = []): array
    {
        $files = [];
        $additionalFileKeys = [];
        $normalizedAdditionalFiles = [];

        foreach ($additionalFiles as $path) {
            if (! is_string($path) || $path === '' || str_contains($path, "\0")) {
                continue;
            }

            $absolute = str_replace('\\', '/', $this->files->absolutePath($path));

            if (! is_file($absolute)) {
                continue;
            }

            $normalizedAdditionalFiles[] = $absolute;
            $additionalFileKeys[] = $this->files->relativePath($absolute) ?? $absolute;
        }

        $normalizedAdditionalFiles = array_values(array_unique($normalizedAdditionalFiles));
        $additionalFileKeys = array_values(array_unique($additionalFileKeys));
        sort($additionalFileKeys);

        foreach ($this->inputFiles($normalizedAdditionalFiles) as $file) {
            $hash = hash_file('sha256', $file);

            if (is_string($hash)) {
                $files[$this->files->relativePath($file) ?? $file] = $hash;
            }
        }

        ksort($files);

        $configuration = $this->configurationHash();
        $containerBindings = $this->containerBindings?->fingerprint($refreshRuntimeEvidence);
        $laravelExecutionRegistry = $this->containerBindings?->executionRegistryFingerprint();
        $frameworkVersion = $this->frameworkVersion();
        $applicationEnvironment = $this->applicationEnvironment();
        $staticIdentity = array_filter([
            'manifestVersion' => self::VERSION,
            'appgraphVersion' => AppGraph::VERSION,
            'laravelVersion' => $frameworkVersion,
            'files' => $files,
        ], static fn (mixed $value): bool => $value !== null);
        $identity = array_filter([
            ...$staticIdentity,
            'applicationEnvironment' => $applicationEnvironment,
            'configuration' => $configuration,
            'containerBindings' => $containerBindings,
            'laravelExecutionRegistry' => $laravelExecutionRegistry,
        ], static fn (mixed $value): bool => $value !== null);
        $runtimeEvidenceSession = $this->containerBindings !== null
            ? $this->runtimeEvidenceSession()
            : null;

        return array_filter([
            'version' => self::VERSION,
            'algorithm' => 'sha256',
            'fingerprint' => hash('sha256', $this->stableJson($identity)),
            'staticFingerprint' => hash('sha256', $this->stableJson($staticIdentity)),
            'fileCount' => count($files),
            'files' => $files,
            'additionalFiles' => $additionalFileKeys,
            'configuration' => $configuration,
            'containerBindings' => $containerBindings,
            'laravelExecutionRegistry' => $laravelExecutionRegistry,
            'runtimeEvidenceSession' => $runtimeEvidenceSession,
            'appgraphVersion' => AppGraph::VERSION,
            'laravelVersion' => $frameworkVersion,
            'applicationEnvironment' => $applicationEnvironment,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function runtimeEvidenceSession(): string
    {
        return self::$runtimeEvidenceSession ??= bin2hex(random_bytes(16));
    }

    /**
     * @param array<int, string> $additionalFiles Exact scanner-produced inputs
     *     that cannot be expanded from configuration alone (for example a
     *     templated per-connection schema dump path).
     * @return array<int, string>
     */
    private function inputFiles(array $additionalFiles = []): array
    {
        $paths = [
            ...$this->files->findPhpFiles(['app', 'routes', 'database/migrations', 'tests', 'config']),
            ...$this->files->findFiles(['resources', 'src'], ['js', 'jsx', 'ts', 'tsx', 'vue']),
            ...$this->files->findFiles('database/schema', ['sql']),
        ];

        foreach ($this->configuredViewPaths() as $path) {
            $paths = [...$paths, ...$this->files->findFiles($path, ['php'])];
        }

        foreach ($additionalFiles as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                $paths[] = str_replace('\\', '/', $this->files->absolutePath($path));
            }
        }

        foreach ($this->configuredSchemaPaths() as $path) {
            if (is_dir($path)) {
                $paths = [...$paths, ...$this->files->findFiles($path, ['sql'])];
            } elseif (is_file($path)) {
                $paths[] = $path;
            }
        }

        $environmentFile = $this->applicationEnvironment();

        foreach (array_filter([
            'composer.json',
            'composer.lock',
            'bootstrap/app.php',
            'bootstrap/providers.php',
            '.env',
            is_string($environmentFile) && $environmentFile !== '' ? '.env.'.$environmentFile : null,
        ]) as $path) {
            $absolute = $this->files->absolutePath($path);

            if (is_file($absolute)) {
                $paths[] = $absolute;
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

    /**
     * View templates are scan inputs for the views scanner, so editing one must
     * mark the graph stale and the manifest must cover every observed template.
     * Mirrors the ViewScanner's own discovery exactly: same config source, same
     * lexical canonicalization, and nothing when the scanner is disabled.
     *
     * @return array<int, string>
     */
    private function configuredViewPaths(): array
    {
        if (! $this->configurationIsAvailable()) {
            return [$this->files->canonicalPath('resources/views')];
        }

        if (! (bool) config('appgraph.scan.views', true)) {
            return [];
        }

        $paths = [];

        foreach ((array) config('view.paths', []) as $path) {
            if (is_string($path) && trim($path) !== '') {
                $paths[] = $path;
            }
        }

        if ($paths === []) {
            $paths = ['resources/views'];
        }

        return array_values(array_unique(array_map(
            fn (string $path): string => $this->files->canonicalPath($path),
            $paths,
        )));
    }

    /** @return array<int, string> */
    private function configuredSchemaPaths(): array
    {
        if (! $this->configurationIsAvailable()) {
            return [];
        }

        $paths = config('appgraph.database.dump.search_paths', []);
        $paths = is_array($paths) ? $paths : [];
        $configuredDump = config('appgraph.database.dump_path');

        if (is_string($configuredDump) && $configuredDump !== '' && ! str_contains($configuredDump, '{')) {
            $paths[] = $configuredDump;
        }

        return array_values(array_filter(array_map(
            fn (mixed $path): ?string => is_string($path) && $path !== ''
                ? $this->files->absolutePath($path)
                : null,
            $paths,
        )));
    }

    private function configurationHash(): string
    {
        $configuration = $this->configurationIsAvailable() ? config()->all() : [];

        // Laravel lazily creates and selects a deprecation log channel after
        // the first deprecation is emitted. That process-local mutation does
        // not affect AppGraph scanning and must not make an unchanged graph
        // appear stale on newer PHP versions that surface more deprecations.
        if (is_array($configuration['logging'] ?? null)) {
            unset($configuration['logging']['deprecations']);

            if (is_array($configuration['logging']['channels'] ?? null)) {
                unset($configuration['logging']['channels']['deprecations']);
            }
        }

        return hash('sha256', $this->stableJson($this->normalize($configuration)));
    }

    private function configurationIsAvailable(): bool
    {
        try {
            return function_exists('app') && app()->bound('config');
        } catch (\Throwable) {
            return false;
        }
    }

    private function frameworkVersion(): ?string
    {
        try {
            return function_exists('app') && app()->bound('app') ? app()->version() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function applicationEnvironment(): ?string
    {
        if ($this->containerBindings !== null) {
            return $this->containerBindings->environment();
        }

        try {
            return function_exists('app') && app()->bound('app') ? (string) app()->environment() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function stableJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            if ($value instanceof \Stringable) {
                return (string) $value;
            }

            if (is_object($value) || is_resource($value)) {
                return get_debug_type($value);
            }

            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
