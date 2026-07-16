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
    use RejectsUnknownInput;

    private const MAX_TARGET_CHARACTERS = 4096;

    private const MAX_TARGET_BYTES = 16384;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->min(1)->max(self::MAX_TARGET_CHARACTERS)
                ->description('Node id or fuzzy target, e.g. "users", "users.email", "App\\Models\\User", or "UserController::update".')
                ->required(),
            'full' => $schema->boolean()->description('Include the node\'s full metadata payload.'),
            'limit' => $schema->integer()->min(1)->max(200)
                ->description('Maximum edges per direction (default 50).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($error = $this->rejectUnknownInput($request, ['id', 'full', 'limit'])) !== null) {
            return $error;
        }

        $validated = $request->validate([
            'id' => [
                'required',
                'filled',
                'string',
                'min:1',
                'max:'.self::MAX_TARGET_CHARACTERS,
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value)
                        && (trim($value) === ''
                            || str_contains($value, "\0")
                            || strlen($value) > self::MAX_TARGET_BYTES)) {
                        $fail("The {$attribute} field must be a nonblank target no longer than ".self::MAX_TARGET_BYTES.' bytes and without NUL bytes.');
                    }
                },
            ],
            'full' => ['sometimes', 'boolean:strict'],
            'limit' => ['sometimes', 'integer:strict', 'between:1,200'],
        ]);
        $defaultLimit = max(1, min(200, (int) config('appgraph.query.limit', 50)));

        try {
            return $this->structuredResponse($this->engine()->node(
                trim($validated['id']),
                (int) ($validated['limit'] ?? $defaultLimit),
                $validated['full'] ?? false,
            ));
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
