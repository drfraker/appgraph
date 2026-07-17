<?php

namespace AppGraph\Mcp\Tools;

use AppGraph\Support\ScanLock;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Name('appgraph_refresh')]
#[Description('Rebuild this Laravel application\'s local AppGraph after a meaningful batch of source changes, then return a fresh overview. Do not call before every lookup.')]
class RefreshTool extends Tool
{
    use InteractsWithQueryEngine;
    use RejectsUnknownInput;

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($error = $this->rejectUnknownInput($request, [])) !== null) {
            return $error;
        }

        $lock = app(ScanLock::class);
        $lockOwner = null;

        try {
            // The scan itself crosses a fresh-process boundary so Laravel's
            // route/event/bus/container registries match the edited source.
            // refreshGraph acquires the parent lock immediately afterward and
            // returns it while the response is built from the published graph.
            $refresh = $this->refreshGraph();
            $lockOwner = $refresh['lockOwner'];
            $result = $refresh['result'];
            $payload = $refresh['engine']->overview();
            $payload['refreshed'] = true;
            $payload['previousGeneration'] = $result['previousGeneration'] ?? null;
            $payload['generationChanged'] = (bool) ($result['created'] ?? false);

            if (($result['mirrorWarnings'] ?? []) !== []) {
                $payload['mirrorWarnings'] = $result['mirrorWarnings'];
            }

            return $this->structuredResponse($payload);
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        } finally {
            if ($lockOwner !== null) {
                $lock->release($lockOwner);
            }
        }
    }
}
