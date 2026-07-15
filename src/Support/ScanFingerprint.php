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
    public const VERSION = 2;

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
     *     fileCount: int,
     *     files: array<string, string>,
     *     configuration: string,
     *     containerBindings?: string,
     *     laravelExecutionRegistry?: string,
     *     appgraphVersion: string,
     *     laravelVersion?: string,
     *     applicationEnvironment?: string
     * }
     */
    public function capture(bool $refreshRuntimeEvidence = true): array
    {
        $files = [];

        foreach ($this->inputFiles() as $file) {
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
        $identity = array_filter([
            'manifestVersion' => self::VERSION,
            'appgraphVersion' => AppGraph::VERSION,
            'laravelVersion' => $frameworkVersion,
            'applicationEnvironment' => $applicationEnvironment,
            'configuration' => $configuration,
            'containerBindings' => $containerBindings,
            'laravelExecutionRegistry' => $laravelExecutionRegistry,
            'files' => $files,
        ], static fn (mixed $value): bool => $value !== null);

        return array_filter([
            'version' => self::VERSION,
            'algorithm' => 'sha256',
            'fingerprint' => hash('sha256', $this->stableJson($identity)),
            'fileCount' => count($files),
            'files' => $files,
            'configuration' => $configuration,
            'containerBindings' => $containerBindings,
            'laravelExecutionRegistry' => $laravelExecutionRegistry,
            'appgraphVersion' => AppGraph::VERSION,
            'laravelVersion' => $frameworkVersion,
            'applicationEnvironment' => $applicationEnvironment,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<int, string> */
    private function inputFiles(): array
    {
        $paths = [
            ...$this->files->findPhpFiles(['app', 'routes', 'database/migrations', 'tests']),
            ...$this->files->findFiles(['resources', 'src'], ['js', 'jsx', 'ts', 'tsx', 'vue']),
            ...$this->files->findFiles('database/schema', ['sql']),
        ];

        foreach ($this->configuredSchemaPaths() as $path) {
            if (is_dir($path)) {
                $paths = [...$paths, ...$this->files->findFiles($path, ['sql'])];
            } elseif (is_file($path)) {
                $paths[] = $path;
            }
        }

        foreach (['composer.json', 'composer.lock', 'bootstrap/app.php', 'bootstrap/providers.php', 'config/appgraph.php'] as $path) {
            $absolute = $this->files->absolutePath($path);

            if (is_file($absolute)) {
                $paths[] = $absolute;
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
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
        $configuration = $this->configurationIsAvailable() ? config('appgraph', []) : [];

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
