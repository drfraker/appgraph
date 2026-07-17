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
#[Description('Run a focused graph traversal after resolving a target. Use flow-from for a route or method, impact-of before changing a table/column/model/method, routes-touching for HTTP surface, writes-to/reads-from for data access, and callers-of/calls-from for call graphs. Read returned source for detail.')]
#[IsReadOnly]
#[IsIdempotent]
class QueryTool extends Tool
{
    use InteractsWithQueryEngine;
    use RejectsUnknownInput;

    private const MAX_TARGET_CHARACTERS = 4096;

    private const MAX_TARGET_BYTES = 16384;

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
            'target' => $schema->string()->min(1)->max(self::MAX_TARGET_CHARACTERS)->description('Table name, table.column, model class, or Class::method. Required for every query except models and tables.'),
            'depth' => $schema->integer()->min(1)->max(6)->description('Maximum traversal depth (default 4). Valid only for traversal queries.'),
            'limit' => $schema->integer()->min(1)->max(200)->description('Maximum results per group (default 50).'),
            'min_confidence' => $schema->number()->min(0)->max(1)->description('Drop traversal results whose path confidence falls below this value (0-1).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($error = $this->rejectUnknownInput($request, [
            'query',
            'target',
            'depth',
            'limit',
            'min_confidence',
        ])) !== null) {
            return $error;
        }

        $validated = $request->validate([
            'query' => ['required', 'string', 'in:flow-from,writes-to,reads-from,callers-of,calls-from,impact-of,routes-touching,models,tables'],
            'target' => ['sometimes', 'filled', 'string', 'min:1', 'max:'.self::MAX_TARGET_CHARACTERS, static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value)
                    && (trim($value) === ''
                        || str_contains($value, "\0")
                        || strlen($value) > self::MAX_TARGET_BYTES)) {
                    $fail("The {$attribute} field must be a nonblank target no longer than ".self::MAX_TARGET_BYTES.' bytes and without NUL bytes.');
                }
            }],
            'depth' => ['sometimes', 'integer', 'between:1,6', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_int($value)) {
                    $fail("The {$attribute} field must be a JSON integer.");
                }
            }],
            'limit' => ['sometimes', 'integer', 'between:1,200', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_int($value)) {
                    $fail("The {$attribute} field must be a JSON integer.");
                }
            }],
            'min_confidence' => ['sometimes', 'numeric', 'between:0,1', static function (string $attribute, mixed $value, \Closure $fail): void {
                if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                    $fail("The {$attribute} field must be a finite JSON number.");
                }
            }],
        ]);
        $query = $validated['query'];
        $target = isset($validated['target']) ? trim($validated['target']) : '';
        $traversals = ['flow-from', 'callers-of', 'calls-from', 'impact-of', 'routes-touching'];

        if (! in_array($query, ['models', 'tables'], true) && $target === '') {
            return Response::error("The [{$query}] query requires a target.");
        }

        if (in_array($query, ['models', 'tables'], true) && array_key_exists('target', $validated)) {
            return Response::error("The [{$query}] query does not accept target.");
        }

        if (! in_array($query, $traversals, true)
            && (array_key_exists('depth', $validated) || array_key_exists('min_confidence', $validated))) {
            return Response::error("The [{$query}] query does not accept depth or min_confidence.");
        }

        $limit = (int) ($validated['limit'] ?? max(1, min(200, (int) config('appgraph.query.limit', 50))));
        $depth = (int) ($validated['depth'] ?? max(1, min(6, (int) config('appgraph.query.depth', 4))));
        $minConfidence = (float) ($validated['min_confidence'] ?? 0.0);

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

            return $this->structuredResponse($payload);
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
