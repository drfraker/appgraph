<?php

namespace AppGraph\Mcp\Tools;

use AppGraph\Query\GraphIndex;
use AppGraph\Query\QueryEngine;
use AppGraph\Query\StalenessChecker;
use AppGraph\Support\FileFinder;
use AppGraph\Support\MemoryLimit;
use Illuminate\Support\Facades\Artisan;

trait InteractsWithQueryEngine
{
    private function engine(): QueryEngine
    {
        MemoryLimit::ensure(config('appgraph.memory_limit', '256M'));

        $path = storage_path(config('appgraph.output_path', 'appgraph/appgraph.json'));

        $this->ensureGraph($path);

        return new QueryEngine(GraphIndex::load($path), new StalenessChecker(new FileFinder()));
    }

    private function refreshedEngine(): QueryEngine
    {
        MemoryLimit::ensure(config('appgraph.memory_limit', '256M'));

        $status = Artisan::call('appgraph:scan');

        if ($status !== 0) {
            throw new \RuntimeException('AppGraph could not refresh the application graph. Run `php artisan appgraph:scan` for details.');
        }

        $path = storage_path(config('appgraph.output_path', 'appgraph/appgraph.json'));

        // A refresh can replace the graph more than once within the filesystem's
        // one-second mtime resolution (common in tests and fast edit loops).
        GraphIndex::forget($path);

        return new QueryEngine(GraphIndex::load($path), new StalenessChecker(new FileFinder()));
    }

    /**
     * Generate the graph on demand so the server works the moment it is installed,
     * without the user having to remember to run `appgraph:scan` first. Honors the
     * `appgraph.mcp.auto_scan` policy: scan when missing, optionally rescan when the
     * existing graph is stale, or stay out of the way entirely.
     */
    private function ensureGraph(string $path): void
    {
        $mode = config('appgraph.mcp.auto_scan', 'missing');

        if ($mode === 'off') {
            return;
        }

        if (! is_file($path)) {
            $this->autoScan();

            return;
        }

        if ($mode === 'stale' && $this->graphIsStale($path)) {
            $this->autoScan();
        }
    }

    private function graphIsStale(string $path): bool
    {
        $staleness = (new StalenessChecker(new FileFinder()))->check($path);

        return ($staleness['newerSourceFiles'] ?? 0) > 0;
    }

    private function autoScan(): void
    {
        Artisan::call('appgraph:scan');
    }
}
