<?php

namespace AppGraph\Mcp;

use AppGraph\AppGraph;
use AppGraph\Mcp\Tools\ContextTool;
use AppGraph\Mcp\Tools\DiffTool;
use AppGraph\Mcp\Tools\GenerationsTool;
use AppGraph\Mcp\Tools\NodeTool;
use AppGraph\Mcp\Tools\OverviewTool;
use AppGraph\Mcp\Tools\QueryTool;
use AppGraph\Mcp\Tools\RefreshTool;
use AppGraph\Mcp\Tools\SearchTool;
use AppGraph\Mcp\Tools\VerifyChangeTool;
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

For before/after verification, retain the positive numeric generation id returned by
context or overview before editing; never substitute current or previous. Prefer passing
that baseline directly to appgraph_refresh so it is checked before scanning, protected
through publication, and verified in the same call. A separate appgraph_verify_change
checks it only when that tool runs. An identical integrated refresh can reuse the baseline
with generationChanged false and a no-new-generation uncertainty; standalone same-id
verification instead reports that the same generation was selected because it cannot
prove a refresh occurred. Use appgraph_diff for raw exact generation counts and bounded
details, and appgraph_generations to find retained ids.

The graph is static analysis, not live state. Confidence scores are heuristic ranking
signals, not probabilities. Change verification is structural evidence, not a safety
claim or proof that tests passed; targets and changed files seed scope without filtering
the full diff. Inspect uncertainties, truncation, lower-bound findings, and collateral
changes. Verification keeps ambiguous or unresolved targets explicit instead of guessing;
their scope attribution is inexact and their collateral count is unknown.
MARKDOWN)]
class AppGraphServer extends Server
{
    protected array $tools = [
        OverviewTool::class,
        ContextTool::class,
        SearchTool::class,
        NodeTool::class,
        QueryTool::class,
        GenerationsTool::class,
        DiffTool::class,
        VerifyChangeTool::class,
        RefreshTool::class,
    ];
}
