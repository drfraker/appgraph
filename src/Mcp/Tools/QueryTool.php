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

#[Name('appgraph_query')]
#[Description('Traverse this Laravel app\'s code graph. Use flow-from on a route or method to orient for feature work; impact-of before changing a table, column, model, or method; routes-touching for HTTP surface; writes-to/reads-from for data access; callers-of/calls-from for call graphs; and models/tables for summaries.')]
#[IsReadOnly]
#[IsIdempotent]
class QueryTool extends Tool
{
    use InteractsWithQueryEngine;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->enum(['flow-from', 'writes-to', 'reads-from', 'callers-of', 'calls-from', 'impact-of', 'routes-touching', 'models', 'tables'])
                ->description('The traversal to run.')
                ->required(),
            'target' => $schema->string()->description('Table name, table.column, model class, or Class::method. Required for every query except models and tables.'),
            'depth' => $schema->integer()->description('Maximum traversal depth (default 4).'),
            'limit' => $schema->integer()->description('Maximum results per group (default 50).'),
            'min_confidence' => $schema->number()->description('Drop results whose path confidence falls below this value (0-1).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $query = (string) $request->get('query');
        $target = (string) ($request->get('target') ?? '');

        if (! in_array($query, ['models', 'tables'], true) && $target === '') {
            return Response::error("The [{$query}] query requires a target.");
        }

        $limit = (int) ($request->get('limit') ?: config('appgraph.query.limit', 50));
        $depth = (int) ($request->get('depth') ?: config('appgraph.query.depth', 4));
        $minConfidence = (float) ($request->get('min_confidence') ?: 0);

        try {
            $engine = $this->engine();

            $payload = match ($query) {
                'writes-to' => $engine->writesTo($target, $limit),
                'reads-from' => $engine->readsFrom($target, $limit),
                'flow-from' => $engine->flowFrom($target, $depth, $limit, $minConfidence),
                'callers-of' => $engine->callersOf($target, $depth, $limit, $minConfidence),
                'calls-from' => $engine->callsFrom($target, $depth, $limit, $minConfidence),
                'impact-of' => $engine->impactOf($target, $depth, $limit, $minConfidence),
                'routes-touching' => $engine->routesTouching($target, $depth, $limit, $minConfidence),
                'models' => $engine->models($limit),
                'tables' => $engine->tables($limit),
                default => null,
            };

            if ($payload === null) {
                return Response::error("Unknown query [{$query}].");
            }

            return Response::structured($payload);
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
