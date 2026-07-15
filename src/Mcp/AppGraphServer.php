<?php

namespace AppGraph\Mcp;

use AppGraph\AppGraph;
use AppGraph\Mcp\Tools\NodeTool;
use AppGraph\Mcp\Tools\OverviewTool;
use AppGraph\Mcp\Tools\QueryTool;
use AppGraph\Mcp\Tools\RefreshTool;
use AppGraph\Mcp\Tools\SearchTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('AppGraph')]
#[Version(AppGraph::VERSION)]
#[Instructions(<<<'MARKDOWN'
AppGraph is this Laravel application's precomputed cross-layer map for feature work
and refactoring. Call appgraph_overview early for unfamiliar or cross-layer tasks,
appgraph_search to resolve ids, and appgraph_query flow-from or impact-of before
broad source searches. Read the returned source before editing. If overview reports
changed files and current results matter, call appgraph_refresh once.

The graph is static analysis, not live state. Confidence scores are heuristic ranking
signals, not probabilities; verify low-confidence paths and account for dynamic calls.
MARKDOWN)]
class AppGraphServer extends Server
{
    protected array $tools = [
        OverviewTool::class,
        SearchTool::class,
        NodeTool::class,
        QueryTool::class,
        RefreshTool::class,
    ];
}
