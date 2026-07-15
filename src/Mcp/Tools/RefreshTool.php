<?php

namespace AppGraph\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Name('appgraph_refresh')]
#[Description('Rebuild this Laravel application\'s local AppGraph after a meaningful batch of source changes, then return fresh overview and staleness information. Call once when appgraph_overview reports changed files and current graph results matter; do not call before every lookup.')]
class RefreshTool extends Tool
{
    use InteractsWithQueryEngine;

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $payload = $this->refreshedEngine()->overview();
            $payload['refreshed'] = true;

            return Response::structured($payload);
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
