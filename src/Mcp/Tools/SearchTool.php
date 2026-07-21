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
#[Description('Find the exact graph id for a class, method, route, table, event, or job by case-insensitive id, label, or route-name substring. Start here when the target id is not already known.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchTool extends Tool
{
    use InteractsWithQueryEngine;
    use RejectsUnknownInput;

    protected string $name = 'appgraph_search';

    protected string $description = 'Find the exact graph id for a class, method, route, table, event, or job by case-insensitive id, label, or route-name substring. Start here when the target id is not already known.';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'term' => $schema->string()->min(1)->max(512)->description('Literal substring to match against node ids and labels.')->required(),
            'type' => $schema->string()->min(1)->max(128)->description('Optional node type filter: route, method, class, model, table, column, index, foreign_key, form_request, event, or job.'),
            'limit' => $schema->integer()->min(1)->max(200)->description('Maximum results (default 50).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($error = $this->rejectUnknownInput($request, ['term', 'type', 'limit'])) !== null) {
            return $error;
        }

        $validated = $request->validate([
            'term' => ['required', 'string', 'min:1', 'max:512', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && (trim($value) === '' || str_contains($value, "\0") || strlen($value) > 2048)) {
                    $fail("The {$attribute} field must be a nonblank literal no longer than 2048 bytes and without NUL bytes.");
                }
            }],
            'type' => ['sometimes', 'filled', 'string', 'min:1', 'max:128', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && (trim($value) === '' || str_contains($value, "\0") || strlen($value) > 512)) {
                    $fail("The {$attribute} field must be a nonblank node type no longer than 512 bytes and without NUL bytes.");
                }
            }],
            'limit' => ['sometimes', 'integer', 'between:1,200', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_int($value)) {
                    $fail("The {$attribute} field must be a JSON integer.");
                }
            }],
        ]);

        try {
            return $this->structuredResponse($this->searchGraph(
                trim($validated['term']),
                isset($validated['type']) ? trim($validated['type']) : null,
                (int) ($validated['limit'] ?? max(1, min(200, (int) config('appgraph.query.limit', 50)))),
            ));
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
