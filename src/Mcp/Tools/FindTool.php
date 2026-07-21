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

#[Name('appgraph_find')]
#[Description('Resolve one fuzzy application target into a bounded graph node card, ranked exact candidate rows, or an honest not-observed status.')]
#[IsReadOnly]
#[IsIdempotent]
class FindTool extends Tool
{
    use InteractsWithQueryEngine;
    use RejectsUnknownInput;

    protected string $name = 'appgraph_find';

    protected string $description = 'Resolve one fuzzy application target into a bounded graph node card, ranked exact candidate rows, or an honest not-observed status.';

    private const MAX_TARGET_CHARACTERS = 4096;

    private const MAX_TARGET_BYTES = 16384;

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'target' => $schema->string()->min(1)->max(self::MAX_TARGET_CHARACTERS)
                ->description('Node id or fuzzy target, e.g. "notes.update", "NoteService::save", "App\\Models\\Note", or "notes.title".')
                ->required(),
            'limit' => $schema->integer()->min(1)->max(200)
                ->description('Maximum candidate rows or edges per direction (default 50).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($error = $this->rejectUnknownInput($request, ['target', 'limit'])) !== null) {
            return $error;
        }

        $validated = $request->validate([
            'target' => [
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
            'limit' => ['sometimes', 'integer:strict', 'between:1,200'],
        ]);
        $defaultLimit = max(1, min(200, (int) config('appgraph.query.limit', 50)));

        try {
            return $this->structuredResponse($this->engine()->find(
                trim($validated['target']),
                (int) ($validated['limit'] ?? $defaultLimit),
            ));
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
