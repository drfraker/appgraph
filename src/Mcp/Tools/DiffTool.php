<?php

namespace AppGraph\Mcp\Tools;

use AppGraph\Storage\GenerationDiffer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

#[Name('appgraph_diff')]
#[Description('Compare two explicit, complete AppGraph generations. Returns exact change counts and bounded deterministic details across routes, writes, authorization, queues, tests, nodes, and edges. This is static structural evidence, not proof of correctness.')]
#[IsReadOnly]
#[IsIdempotent]
class DiffTool extends Tool
{
    use RejectsUnknownInput;

    private const CATEGORIES = ['nodes', 'edges', 'routes', 'writes', 'authorization', 'queues', 'tests'];

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from_generation' => $schema->string()->min(1)->max(19)
                ->description('Captured positive numeric baseline generation id.')->required(),
            'to_generation' => $schema->string()->min(1)->max(19)
                ->description('Positive numeric comparison generation id (default current).'),
            'categories' => $schema->array()
                ->items($schema->string()->enum(self::CATEGORIES))
                ->max(7)->unique()
                ->description('Optional detail categories; exact overall counts are never filtered.'),
            'limit' => $schema->integer()->min(1)->max(200)
                ->description('Global maximum details shared fairly across selected categories (default 50).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($error = $this->rejectUnknownInput($request, [
            'from_generation',
            'to_generation',
            'categories',
            'limit',
        ])) !== null) {
            return $error;
        }

        $validated = $request->validate([
            'from_generation' => ['required', 'string', 'regex:/^[1-9]\d*$/D', 'max:19'],
            'to_generation' => ['sometimes', 'string', 'regex:/^[1-9]\d*$/D', 'max:19'],
            'categories' => ['sometimes', 'array', 'list', 'max:7'],
            'categories.*' => ['string', Rule::in(self::CATEGORIES), 'distinct:strict'],
            'limit' => ['sometimes', 'integer', 'between:1,200', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_int($value)) {
                    $fail("The {$attribute} field must be a JSON integer.");
                }
            }],
        ]);

        try {
            return $this->structuredResponse(app(GenerationDiffer::class)->diff(
                trim($validated['from_generation']),
                isset($validated['to_generation']) ? trim($validated['to_generation']) : 'current',
                array_values($validated['categories'] ?? []),
                (int) ($validated['limit'] ?? 50),
            ));
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
