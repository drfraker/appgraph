<?php

namespace AppGraph\Mcp\Tools;

use AppGraph\Query\GraphIndex;
use AppGraph\Query\QueryEngine;
use AppGraph\Query\SearchPayload;
use AppGraph\Query\StalenessChecker;
use AppGraph\Storage\GraphStore;
use AppGraph\Support\MemoryLimit;
use AppGraph\Support\ScanLock;
use AppGraph\Support\ScanRunner;

trait InteractsWithQueryEngine
{
    private function engine(): QueryEngine
    {
        MemoryLimit::ensure(config('appgraph.memory_limit', '256M'));

        $path = storage_path(config('appgraph.output_path', 'appgraph/appgraph.json'));

        $this->ensureGraph($path);

        $store = $this->store();

        if (! $store->hasCurrent() && ! is_file($path)) {
            throw new \RuntimeException(
                'AppGraph has no graph for this application yet. Call appgraph_refresh once to build it'
                .' (or run `php artisan appgraph:scan`); the initial scan can take a while on large applications.'
            );
        }

        return new QueryEngine(
            $store->hasCurrent() ? GraphIndex::loadStore($store) : GraphIndex::load($path),
            app(StalenessChecker::class),
        );
    }

    /** @return array{result: array<string, mixed>, lockOwner: string} */
    private function refreshGraph(
        ?string $baselineGeneration = null,
    ): array
    {
        MemoryLimit::ensure(config('appgraph.memory_limit', '256M'));
        $result = app(ScanRunner::class)->run($baselineGeneration);

        $path = storage_path(config('appgraph.output_path', 'appgraph/appgraph.json'));
        $store = $this->store();
        $lock = app(ScanLock::class);
        $lockOwner = $lock->acquire();

        // A refresh can replace the graph more than once within the filesystem's
        // one-second mtime resolution (common in tests and fast edit loops).
        try {
            GraphIndex::forget($path);
            GraphIndex::forget($store->path());
            $generation = $result['generation']['id'] ?? null;

            if (! is_string($generation) || preg_match('/^[1-9]\d*$/D', $generation) !== 1) {
                throw new \RuntimeException('AppGraph refresh did not return an immutable generation id.');
            }

            if ($baselineGeneration !== null) {
                $store->verifyGeneration($baselineGeneration);
            }

            $store->verifyGeneration($generation);
            $current = $store->current();

            if (! is_array($current) || ($current['id'] ?? null) !== $generation) {
                throw new \RuntimeException(
                    'A concurrent AppGraph scan superseded the refreshed generation before it could be returned.'
                );
            }

            return [
                'result' => $result,
                'lockOwner' => $lockOwner,
            ];
        } catch (\Throwable $throwable) {
            $lock->release($lockOwner);

            throw $throwable;
        }
    }

    /**
     * Honor the `appgraph.mcp.auto_scan` policy. Lookups are read-only by
     * default ('off'): a missing graph produces a structured error pointing at
     * appgraph_refresh instead of an implicit scan that bootstraps the host
     * application, blocks the tool call, and writes graph storage. 'missing'
     * and 'stale' opt back into automatic scanning.
     */
    private function ensureGraph(string $path): void
    {
        $mode = config('appgraph.mcp.auto_scan', 'off');

        if ($mode === 'off') {
            return;
        }

        $store = $this->store();

        if ($store->hasCurrent()) {
            if ($mode === 'stale' && $this->storedGraphIsStale($store)) {
                $this->autoScan();
            }

            return;
        }

        // For automatic modes, "missing" means no authoritative generation.
        // A legacy JSON mirror remains readable only when auto-scan is off.
        $this->autoScan();
    }

    private function storedGraphIsStale(GraphStore $store): bool
    {
        $graph = $store->graph('current');
        $scan = is_array($graph['meta']['scan'] ?? null) ? $graph['meta']['scan'] : null;
        $staleness = app(StalenessChecker::class)->check($store->path(), $scan);

        return (bool) ($staleness['stale'] ?? false) || ($staleness['newerSourceFiles'] ?? 0) > 0;
    }

    private function autoScan(): void
    {
        app(ScanRunner::class)->run();
    }

    private function store(): GraphStore
    {
        return app(GraphStore::class);
    }

    /** @return array<string, mixed> */
    private function searchGraph(string $term, ?string $type, int $limit): array
    {
        $path = storage_path(config('appgraph.output_path', 'appgraph/appgraph.json'));
        $this->ensureGraph($path);
        $store = $this->store();

        if (! $store->hasCurrent()) {
            return $this->engine()->search($term, $type, $limit);
        }

        return SearchPayload::fromStoreResult($term, $store->searchNodes($term, $type, $limit));
    }
}
