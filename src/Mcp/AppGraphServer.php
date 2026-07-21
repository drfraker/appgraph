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
AppGraph is this Laravel application's precomputed cross-layer map. It tells you where
to look and why; application source remains the authority for what the code does.

For a known area, use appgraph_search to resolve the exact id, appgraph_node for direct
relationships, and appgraph_query only when you need a focused flow or impact traversal.
Use appgraph_overview only for broad architecture orientation. Read the returned source
before drawing conclusions or editing. If current results matter after source changes,
call appgraph_refresh once; its change receipt shows what actually changed by category,
including collateral changes you did not intend.

The graph is static analysis, not live state. Confidence scores rank static evidence;
they are not probabilities. Treat analysis warnings as prompts to inspect the referenced
source, and narrow truncated results instead of assuming the omitted portion is empty.
MARKDOWN)]
class AppGraphServer extends Server
{
    protected array $tools = [
        OverviewTool::class,
        SearchTool::class,
        NodeTool::class,
        QueryTool::class,
        SliceTool::class,
        FindTool::class,
        RefreshTool::class,
    ];
}
