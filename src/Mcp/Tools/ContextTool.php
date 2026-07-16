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
use InvalidArgumentException;
use RuntimeException;

#[Name('appgraph_context')]
#[Description('Compile a bounded task-specific context for this Laravel application. Returns ranked graph seeds, recommended source reads, causal paths, verification targets, and explicit uncertainties. Supply known targets when available and changed_files when planning around existing edits.')]
#[IsReadOnly]
#[IsIdempotent]
class ContextTool extends Tool
{
    use InteractsWithQueryEngine;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()
                ->min(1)
                ->max(4000)
                ->description('Nonblank task description used to rank relevant application context.')
                ->required(),
            'targets' => $schema->array()
                ->items($schema->string()->min(1)->max(512))
                ->max(10)
                ->unique()
                ->description('Optional exact or fuzzy AppGraph targets, such as a route name, Class::method, model, table, or column.'),
            'changed_files' => $schema->array()
                ->items($schema->string()->min(1)->max(1024))
                ->max(50)
                ->unique()
                ->description('Optional project-relative or in-project absolute files already changed or under consideration.'),
            'token_budget' => $schema->integer()
                ->min(512)
                ->max(16000)
                ->description('Approximate source-reading token budget (default 4000).'),
            'depth' => $schema->integer()
                ->min(1)
                ->max(6)
                ->description('Maximum graph traversal depth (default 4).'),
            'min_confidence' => $schema->number()
                ->min(0)
                ->max(1)
                ->description('Drop graph paths below this confidence ranking (default 0).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'task' => [
                'required',
                'string',
                'max:4000',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && trim($value) === '') {
                        $fail("The {$attribute} field must not be blank.");
                    }
                },
            ],
            'targets' => ['sometimes', 'array', 'max:10'],
            'targets.*' => ['string', 'min:1', 'max:512', 'distinct:strict'],
            'changed_files' => ['sometimes', 'array', 'max:50'],
            'changed_files.*' => ['string', 'min:1', 'max:1024', 'distinct:strict'],
            'token_budget' => ['sometimes', 'integer', 'between:512,16000'],
            'depth' => ['sometimes', 'integer', 'between:1,6'],
            'min_confidence' => ['sometimes', 'numeric', 'between:0,1'],
        ]);

        $defaultTokenBudget = max(512, min(
            16000,
            (int) config('appgraph.query.context.token_budget', 4000),
        ));
        $defaultDepth = max(1, min(6, (int) config('appgraph.query.context.depth', 4)));
        $defaultMinConfidence = max(0.0, min(
            1.0,
            (float) config('appgraph.query.context.min_confidence', 0.0),
        ));

        try {
            return Response::structured($this->engine()->contextForTask(
                trim($validated['task']),
                array_values($validated['targets'] ?? []),
                array_values($validated['changed_files'] ?? []),
                (int) ($validated['token_budget'] ?? $defaultTokenBudget),
                (int) ($validated['depth'] ?? $defaultDepth),
                (float) ($validated['min_confidence'] ?? $defaultMinConfidence),
            ));
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
