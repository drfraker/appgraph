<?php

namespace AppGraph\Mcp\Tools;

use AppGraph\Storage\GraphStore;
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

#[Name('appgraph_generations')]
#[Description('List retained, immutable AppGraph scan generations. Capture the current positive numeric generation id before editing, refresh after edits, then pass that exact baseline to appgraph_verify_change or appgraph_diff.')]
#[IsReadOnly]
#[IsIdempotent]
class GenerationsTool extends Tool
{
    use RejectsUnknownInput;

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(100)
                ->description('Maximum generations in descending order (default 20).'),
            'before_generation' => $schema->string()->min(1)->max(19)
                ->description('Optional positive numeric generation-id cutoff for stable older-page pagination; the cursor need not still be retained.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($error = $this->rejectUnknownInput($request, ['limit', 'before_generation'])) !== null) {
            return $error;
        }

        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_int($value)) {
                    $fail("The {$attribute} field must be a JSON integer.");
                }
            }],
            'before_generation' => ['sometimes', 'filled', 'string', 'regex:/^[1-9]\d*$/D', 'max:19'],
        ]);

        try {
            return $this->structuredResponse(['query' => 'generations'] + app(GraphStore::class)->generations(
                (int) ($validated['limit'] ?? 20),
                isset($validated['before_generation']) ? trim($validated['before_generation']) : null,
            ));
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
