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
#[Description('Get broad architecture and graph-health orientation: node/edge counts by type, bounded scanner-warning evidence, app metadata, graph age, and source freshness. Use for unfamiliar areas or whole-app questions, not before every focused lookup.')]
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
