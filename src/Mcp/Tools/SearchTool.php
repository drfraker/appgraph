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

#[Name('appgraph_search')]
#[Description('Find nodes in this Laravel app\'s code graph by substring (case-insensitive over ids and labels). Use to locate the exact node id for a class, method, route, table, event, or job before calling appgraph_node or appgraph_query.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchTool extends Tool
{
    use InteractsWithQueryEngine;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'term' => $schema->string()->description('Substring to match against node ids and labels.')->required(),
            'type' => $schema->string()->description('Optional node type filter: route, method, class, model, table, column, index, foreign_key, form_request, event, or job.'),
            'limit' => $schema->integer()->description('Maximum results (default 50).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            return Response::structured($this->engine()->search(
                (string) $request->get('term'),
                $request->get('type') ?: null,
                (int) ($request->get('limit') ?: config('appgraph.query.limit', 50)),
            ));
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
