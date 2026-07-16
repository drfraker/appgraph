<?php

namespace AppGraph\Mcp;

use AppGraph\AppGraph;
use AppGraph\Mcp\Tools\ContextTool;
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
and refactoring. Call appgraph_context with the task, any known targets, and any
changed files to get a bounded recommended read set before broad source searches.
Use appgraph_overview for broad architecture orientation, appgraph_search to resolve
ids, and appgraph_query for focused follow-up traversal. Read the recommended source
before editing. If results report stale source and current context matters, call
appgraph_refresh once.

The graph is static analysis, not live state. Confidence scores are heuristic ranking
signals, not probabilities; verify low-confidence paths and account for dynamic calls.
MARKDOWN)]
class AppGraphServer extends Server
{
    protected array $tools = [
        OverviewTool::class,
        ContextTool::class,
        SearchTool::class,
        NodeTool::class,
        QueryTool::class,
        RefreshTool::class,
    ];
}
