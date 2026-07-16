<?php

namespace AppGraph\Mcp\Tools;

use AppGraph\Storage\ChangeVerifier;
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

#[Name('appgraph_verify_change')]
#[Description('Assess graph-visible changes from an explicit pre-edit baseline to a post-edit generation. Selecting the same generation on both sides is reported cautiously because this read-only tool cannot prove a refresh occurred. Reports expected and collateral structural changes, risk-oriented findings, and uncertainties; it does not claim the change is safe or tests passed.')]
#[IsReadOnly]
#[IsIdempotent]
class VerifyChangeTool extends Tool
{
    use RejectsUnknownInput;

    private const MAX_TARGET_CHARACTERS = 4096;

    private const MAX_TARGET_BYTES = 16384;

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'baseline_generation' => $schema->string()->min(1)->max(19)
                ->description('Required positive numeric generation id captured before editing.')->required(),
            'generation' => $schema->string()->min(1)->max(19)
                ->description('Positive numeric post-edit generation id (default current).'),
            'targets' => $schema->array()->items($schema->string()->min(1)->max(self::MAX_TARGET_CHARACTERS))
                ->max(10)->unique()->description('Expected graph ids, labels, route names, or familiar class/method suffixes; ambiguous targets remain explicit.'),
            'changed_files' => $schema->array()->items($schema->string()->min(1)->max(1024))
                ->max(50)->unique()->description('Files intentionally changed, used to classify scope rather than filter results.'),
            'depth' => $schema->integer()->min(1)->max(6)
                ->description('Bound for causal verification checks (default 4).'),
            'min_confidence' => $schema->number()->min(0)->max(1)
                ->description('Minimum graph confidence for causal verification checks (default 0).'),
            'limit' => $schema->integer()->min(1)->max(200)
                ->description('Independent maximum for returned diff details and findings (default 50 each); exact change counts remain complete.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($error = $this->rejectUnknownInput($request, [
            'baseline_generation',
            'generation',
            'targets',
            'changed_files',
            'depth',
            'min_confidence',
            'limit',
        ])) !== null) {
            return $error;
        }

        $validated = $request->validate([
            'baseline_generation' => ['required', 'string', 'regex:/^[1-9]\d*$/D', 'max:19'],
            'generation' => ['sometimes', 'string', 'regex:/^[1-9]\d*$/D', 'max:19'],
            'targets' => ['sometimes', 'array', 'list', 'max:10'],
            'targets.*' => ['string', 'min:1', 'max:'.self::MAX_TARGET_CHARACTERS, 'distinct:strict', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && (str_contains($value, "\0") || strlen($value) > self::MAX_TARGET_BYTES)) {
                    $fail("The {$attribute} field must not exceed ".self::MAX_TARGET_BYTES.' bytes or contain NUL bytes.');
                }
            }],
            'changed_files' => ['sometimes', 'array', 'list', 'max:50'],
            'changed_files.*' => ['string', 'min:1', 'max:1024', 'distinct:strict'],
            'depth' => ['sometimes', 'integer', 'between:1,6', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_int($value)) {
                    $fail("The {$attribute} field must be a JSON integer.");
                }
            }],
            'min_confidence' => ['sometimes', 'numeric', 'between:0,1', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_int($value) && ! is_float($value)) {
                    $fail("The {$attribute} field must be a JSON number.");
                }
            }],
            'limit' => ['sometimes', 'integer', 'between:1,200', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_int($value)) {
                    $fail("The {$attribute} field must be a JSON integer.");
                }
            }],
        ]);

        try {
            return $this->structuredResponse(app(ChangeVerifier::class)->verify(
                trim($validated['baseline_generation']),
                isset($validated['generation']) ? trim($validated['generation']) : 'current',
                array_values($validated['targets'] ?? []),
                array_values($validated['changed_files'] ?? []),
                (int) ($validated['depth'] ?? 4),
                (float) ($validated['min_confidence'] ?? 0.0),
                (int) ($validated['limit'] ?? 50),
            ));
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
