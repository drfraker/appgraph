<?php

namespace AppGraph\Mcp;

use AppGraph\AppGraph;
use AppGraph\Mcp\Tools\FindTool;
use AppGraph\Mcp\Tools\NodeTool;
use AppGraph\Mcp\Tools\OverviewTool;
use AppGraph\Mcp\Tools\QueryTool;
use AppGraph\Mcp\Tools\RefreshTool;
use AppGraph\Mcp\Tools\SearchTool;
use AppGraph\Mcp\Tools\SliceTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('AppGraph')]
#[Version(AppGraph::VERSION)]
#[Instructions(<<<'MARKDOWN'
AppGraph is this Laravel application's precomputed cross-layer map; application source remains authoritative.
Use appgraph_find to resolve one target and appgraph_slice to get a budgeted source-reading plan.
Call appgraph_refresh after source changes when current results matter.
Static analysis can be incomplete; `not_observed` does not mean nonexistent, so inspect returned source.
MARKDOWN)]
class AppGraphServer extends Server
{
    // Property fallbacks keep the declared laravel/mcp 0.5 compatibility;
    // attribute metadata is consumed by laravel/mcp 0.6+.
    protected string $name = 'AppGraph';

    protected string $version = AppGraph::VERSION;

    protected string $instructions = <<<'MARKDOWN'
AppGraph is this Laravel application's precomputed cross-layer map; application source remains authoritative.
Use appgraph_find to resolve one target and appgraph_slice to get a budgeted source-reading plan.
Call appgraph_refresh after source changes when current results matter.
Static analysis can be incomplete; `not_observed` does not mean nonexistent, so inspect returned source.
MARKDOWN;

    protected function boot(): void
    {
        $this->tools = [
            FindTool::class,
            SliceTool::class,
            RefreshTool::class,
        ];

        if (config('appgraph.mcp.legacy_tools', false)) {
            array_push(
                $this->tools,
                OverviewTool::class,
                SearchTool::class,
                NodeTool::class,
                QueryTool::class,
            );
        }

        parent::boot();
    }
}
