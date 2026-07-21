<?php

namespace AppGraph\Mcp\Tools;

use AppGraph\Query\UnresolvedAnchorException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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

#[Name('appgraph_slice')]
#[Description('Turn explicit graph anchors or project files into a bounded source reading plan in stable Laravel-lifecycle order, with per-answer freshness. Returns source spans and reason codes, never source text.')]
#[IsReadOnly]
#[IsIdempotent]
class SliceTool extends Tool
{
    use InteractsWithQueryEngine;
    use RejectsUnknownInput;

    protected string $name = 'appgraph_slice';

    protected string $description = 'Turn explicit graph anchors or project files into a bounded source reading plan in stable Laravel-lifecycle order, with per-answer freshness. Returns source spans and reason codes, never source text.';

    private const MAX_ANCHOR_CHARACTERS = 4096;

    private const MAX_ANCHOR_BYTES = 16384;

    private const MAX_FILE_CHARACTERS = 1024;

    private const MAX_FILE_BYTES = 4096;

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'anchors' => $schema->array()
                ->items($schema->string()->min(1)->max(self::MAX_ANCHOR_CHARACTERS))
                ->max(20)
                ->unique()
                ->description('Explicit route, class, method, table, column, or file anchors. Optional prefixes: route:, class:, method:, table:, column:, file:.'),
            'files' => $schema->array()
                ->items($schema->string()->min(1)->max(self::MAX_FILE_CHARACTERS))
                ->max(50)
                ->unique()
                ->description('Changed project-relative source files to include as explicit reading anchors.'),
            'read_budget' => $schema->integer()->min(512)->max(16000)
                ->description('Maximum estimated source tokens across returned spans (default 4000).'),
            'depth' => $schema->integer()->min(1)->max(6)
                ->description('Maximum graph expansion depth (default 4).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($error = $this->rejectUnknownInput($request, ['anchors', 'files', 'read_budget', 'depth'])) !== null) {
            return $error;
        }

        $validated = $request->validate([
            'anchors' => ['sometimes', 'array', 'max:20', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_array($value) && ! array_is_list($value)) {
                    $fail("The {$attribute} field must be a JSON array.");
                }
            }],
            'anchors.*' => [
                'filled',
                'string',
                'min:1',
                'max:'.self::MAX_ANCHOR_CHARACTERS,
                'distinct:strict',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value)
                        && (trim($value) === ''
                            || str_contains($value, "\0")
                            || strlen($value) > self::MAX_ANCHOR_BYTES)) {
                        $fail("The {$attribute} field must be a nonblank anchor no longer than ".self::MAX_ANCHOR_BYTES.' bytes and without NUL bytes.');
                    }
                },
            ],
            'files' => ['sometimes', 'array', 'max:50', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_array($value) && ! array_is_list($value)) {
                    $fail("The {$attribute} field must be a JSON array.");
                }
            }],
            'files.*' => [
                'filled',
                'string',
                'min:1',
                'max:'.self::MAX_FILE_CHARACTERS,
                'distinct:strict',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value)
                        && (trim($value) === ''
                            || str_contains($value, "\0")
                            || strlen($value) > self::MAX_FILE_BYTES)) {
                        $fail("The {$attribute} field must be a nonblank project file no longer than ".self::MAX_FILE_BYTES.' bytes and without NUL bytes.');
                    }
                },
            ],
            'read_budget' => ['sometimes', 'integer:strict', 'between:512,16000'],
            'depth' => ['sometimes', 'integer:strict', 'between:1,6'],
        ]);
        $anchors = $validated['anchors'] ?? [];
        $files = $validated['files'] ?? [];

        if ($anchors === [] && $files === []) {
            return Response::error('appgraph_slice requires at least one anchor or file.');
        }

        try {
            return $this->structuredResponse($this->engine()->slice(
                $anchors,
                $files,
                (int) ($validated['read_budget'] ?? 4000),
                (int) ($validated['depth'] ?? 4),
            ));
        } catch (UnresolvedAnchorException $exception) {
            return $this->structuredErrorResponse($exception->getMessage(), [
                'query' => 'slice',
                'code' => 'unresolved_anchor',
                'anchor' => $exception->anchor,
                'candidates' => array_slice($exception->candidates, 0, 10),
            ]);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
