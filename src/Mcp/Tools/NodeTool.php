<?php

namespace AppGraph\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

#[Name('appgraph_node')]
#[Description('Inspect one node in this Laravel app\'s code graph and its incoming/outgoing edges. Accepts exact ids, bare table names, table.column, Class::method, or unambiguous class basenames. Use after appgraph_search to drill into a specific class, method, table, event, or job.')]
#[IsReadOnly]
#[IsIdempotent]
class NodeTool extends Tool
{
    use InteractsWithQueryEngine;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Node id or fuzzy target, e.g. "users", "users.email", "App\\Models\\User", or "UserController::update".')->required(),
            'full' => $schema->boolean()->description('Include the node\'s full metadata payload.'),
            'limit' => $schema->integer()->description('Maximum edges per direction (default 50).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            return Response::structured($this->engine()->node(
                (string) $request->get('id'),
                (int) ($request->get('limit') ?: config('appgraph.query.limit', 50)),
                (bool) $request->get('full'),
            ));
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
