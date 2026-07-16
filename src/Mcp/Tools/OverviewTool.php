<?php

namespace AppGraph\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

#[Name('appgraph_overview')]
#[Description('Orient yourself in this Laravel app\'s code graph: node/edge counts by type, bounded scanner-warning evidence, app metadata, graph age, and how many source files changed since the scan. Call this before other AppGraph tools; if current results matter and source files changed, call appgraph_refresh once.')]
#[IsReadOnly]
#[IsIdempotent]
class OverviewTool extends Tool
{
    use InteractsWithQueryEngine;
    use RejectsUnknownInput;

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($error = $this->rejectUnknownInput($request, [])) !== null) {
            return $error;
        }

        try {
            return $this->structuredResponse($this->engine()->overview());
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
