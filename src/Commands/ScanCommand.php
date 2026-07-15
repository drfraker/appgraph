<?php

namespace AppGraph\Commands;

use AppGraph\AppGraph;
use AppGraph\Graph\Graph;
use AppGraph\Graph\GraphExporter;
use AppGraph\Graph\OverviewBuilder;
use AppGraph\Scanners\CallScanner;
use AppGraph\Scanners\DatabaseSchemaScanner;
use AppGraph\Scanners\DataFlowScanner;
use AppGraph\Scanners\EventFlowScanner;
use AppGraph\Scanners\FrontendRouteScanner;
use AppGraph\Scanners\FormRequestScanner;
use AppGraph\Scanners\ModelScanner;
use AppGraph\Scanners\PolicyScanner;
use AppGraph\Scanners\RouteScanner;
use AppGraph\Scanners\SideEffectScanner;
use AppGraph\Scanners\TestScanner;
use AppGraph\Support\MemoryLimit;
use Illuminate\Console\Command;
use Throwable;

class ScanCommand extends Command
{
    protected $signature = 'appgraph:scan
        {--output= : JSON output path. Relative paths resolve from the Laravel base path.}
        {--include-db : Include database schema scanning, even if disabled in config.}
        {--include-routes : Include route scanning, even if disabled in config.}
        {--overview : Write the overview projection, even if disabled in config.}
        {--no-overview : Skip writing the overview projection.}
        {--pretty : Pretty-print the JSON output.}';

    protected $description = 'Scan the Laravel application and export an AppGraph JSON knowledge graph.';

    public function handle(
        RouteScanner $routeScanner,
        ModelScanner $modelScanner,
        DatabaseSchemaScanner $databaseSchemaScanner,
        FormRequestScanner $formRequestScanner,
        CallScanner $callScanner,
        DataFlowScanner $dataFlowScanner,
        EventFlowScanner $eventFlowScanner,
        SideEffectScanner $sideEffectScanner,
        FrontendRouteScanner $frontendRouteScanner,
        TestScanner $testScanner,
        PolicyScanner $policyScanner,
        GraphExporter $exporter,
    ): int {
        MemoryLimit::ensure(config('appgraph.memory_limit', '256M'));

        $graph = new Graph([
            'generatedAt' => now()->toISOString(),
            'appName' => config('app.name'),
            'laravelVersion' => app()->version(),
            'appgraphVersion' => AppGraph::VERSION,
        ]);

        $includeRoutes = (bool) config('appgraph.scan.routes', true) || (bool) $this->option('include-routes');
        $includeDatabase = (bool) config('appgraph.scan.database', true) || (bool) $this->option('include-db');
        $includeModels = (bool) config('appgraph.scan.models', true);
        $includeFormRequests = (bool) config('appgraph.scan.form_requests', true);
        $includeCalls = (bool) config('appgraph.scan.calls', true);
        $includeDataFlow = (bool) config('appgraph.scan.data_flow', true);
        $includeEvents = (bool) config('appgraph.scan.events', true);
        $includeSideEffects = (bool) config('appgraph.scan.side_effects', true);
        $includeFrontend = (bool) config('appgraph.scan.frontend', true);
        $includeTests = (bool) config('appgraph.scan.tests', true);
        $includePolicies = (bool) config('appgraph.scan.policies', true);

        if ($includeRoutes) {
            $this->runScanner($graph, 'routes', fn () => $routeScanner->scan($graph));
        }

        if ($includeDatabase) {
            $this->runScanner($graph, 'database', fn () => $databaseSchemaScanner->scan($graph));
        }

        if ($includeModels) {
            $this->runScanner($graph, 'models', fn () => $modelScanner->scan($graph));
        }

        if ($includeFormRequests) {
            $this->runScanner($graph, 'form_requests', fn () => $formRequestScanner->scan($graph));
        }

        if ($includeCalls) {
            $this->runScanner($graph, 'calls', fn () => $callScanner->scan($graph));
        }

        if ($includeDataFlow) {
            $this->runScanner($graph, 'data_flow', fn () => $dataFlowScanner->scan($graph));
        }

        if ($includeEvents) {
            $this->runScanner($graph, 'events', fn () => $eventFlowScanner->scan($graph));
        }

        if ($includeSideEffects) {
            $this->runScanner($graph, 'side_effects', fn () => $sideEffectScanner->scan($graph));
        }

        if ($includeFrontend) {
            $this->runScanner($graph, 'frontend', fn () => $frontendRouteScanner->scan($graph));
        }

        if ($includeTests) {
            $this->runScanner($graph, 'tests', fn () => $testScanner->scan($graph));
        }

        if ($includePolicies) {
            $this->runScanner($graph, 'policies', fn () => $policyScanner->scan($graph));
        }

        $path = $this->outputPath();
        $pretty = (bool) $this->option('pretty');
        $exporter->export($graph, $path, $pretty);

        $this->info("AppGraph scan written to {$path}");

        if ($this->shouldWriteOverview()) {
            $overviewPath = $this->overviewPath($path);
            $exporter->exportData((new OverviewBuilder())->build($graph), $overviewPath, $pretty);

            $this->info("AppGraph overview written to {$overviewPath}");
        }

        return self::SUCCESS;
    }

    private function shouldWriteOverview(): bool
    {
        if ((bool) $this->option('no-overview')) {
            return false;
        }

        return (bool) config('appgraph.overview.enabled', true) || (bool) $this->option('overview');
    }

    private function runScanner(Graph $graph, string $name, callable $scanner): void
    {
        try {
            $scanner();
        } catch (Throwable $throwable) {
            $graph->addWarning([
                'scanner' => $name,
                'message' => $throwable->getMessage(),
                'class' => $throwable::class,
            ]);

            $this->warn("AppGraph {$name} scanner skipped: {$throwable->getMessage()}");
        }
    }

    private function outputPath(): string
    {
        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            return $this->isAbsolutePath($output) ? $output : base_path($output);
        }

        return storage_path(config('appgraph.output_path', 'appgraph/appgraph.json'));
    }

    private function overviewPath(string $mainPath): string
    {
        $configured = config('appgraph.overview.path', 'overview.json');
        $configured = is_string($configured) && $configured !== '' ? $configured : 'overview.json';

        if ($this->isAbsolutePath($configured)) {
            return $configured;
        }

        // A bare filename is placed next to the main output file so that an --output
        // relocation keeps the two files together; a relative path resolves from base.
        if (str_contains($configured, '/')) {
            return base_path($configured);
        }

        return dirname($mainPath).'/'.$configured;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Z]:[\/\\\\]/i', $path) === 1;
    }
}
