<?php

namespace AppGraph\Mcp\Tools;

use AppGraph\Storage\ChangeVerifier;
use AppGraph\Support\ScanLock;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Name('appgraph_refresh')]
#[Description('Rebuild this Laravel application\'s local AppGraph after a meaningful batch of source changes. Returns the exact immutable generation, generationChanged, optional mirrorWarnings, and optionally verification against a captured pre-edit baseline. Do not call before every lookup.')]
class RefreshTool extends Tool
{
    use InteractsWithQueryEngine;
    use RejectsUnknownInput;

    private const MAX_TARGET_CHARACTERS = 4096;

    private const MAX_TARGET_BYTES = 16384;

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'baseline_generation' => $schema->string()->min(1)->max(19)
                ->description('Optional positive numeric generation id captured before editing; adds graph-visible change verification.'),
            'targets' => $schema->array()->items($schema->string()->min(1)->max(self::MAX_TARGET_CHARACTERS))->max(10)->unique()
                ->description('Expected graph ids, labels, route names, or familiar suffixes for baseline verification.'),
            'changed_files' => $schema->array()->items($schema->string()->min(1)->max(1024))->max(50)->unique()
                ->description('Intentionally changed files that jointly seed verification scope with targets.'),
            'limit' => $schema->integer()->min(1)->max(200)
                ->description('Independent maximum for diff details and for verification findings (default 50 each).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($error = $this->rejectUnknownInput($request, [
            'baseline_generation',
            'targets',
            'changed_files',
            'limit',
        ])) !== null) {
            return $error;
        }

        $validated = $request->validate([
            'baseline_generation' => ['sometimes', 'filled', 'string', 'regex:/^[1-9]\d*$/D', 'max:19'],
            'targets' => ['sometimes', 'array', 'list', 'max:10'],
            'targets.*' => ['filled', 'string', 'min:1', 'max:'.self::MAX_TARGET_CHARACTERS, 'distinct:strict', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && (str_contains($value, "\0") || strlen($value) > self::MAX_TARGET_BYTES)) {
                    $fail("The {$attribute} field must not exceed ".self::MAX_TARGET_BYTES.' bytes or contain NUL bytes.');
                }
            }],
            'changed_files' => ['sometimes', 'array', 'list', 'max:50'],
            'changed_files.*' => ['filled', 'string', 'min:1', 'max:1024', 'distinct:strict'],
            'limit' => ['sometimes', 'integer', 'between:1,200', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_int($value)) {
                    $fail("The {$attribute} field must be a JSON integer.");
                }
            }],
        ]);

        $lock = app(ScanLock::class);
        $lockOwner = null;

        try {
            if (! isset($validated['baseline_generation'])
                && (($validated['targets'] ?? []) !== []
                    || ($validated['changed_files'] ?? []) !== []
                    || array_key_exists('limit', $validated))) {
                throw new InvalidArgumentException(
                    'AppGraph refresh targets, changed_files, and limit require baseline_generation.'
                );
            }

            $baseline = isset($validated['baseline_generation'])
                ? trim($validated['baseline_generation'])
                : null;

            // The scan itself crosses a fresh-process boundary so Laravel's
            // route/event/bus/container registries match the edited source.
            // refreshGraph acquires the parent lock immediately afterward and
            // returns it for the pinned response/verification scope below.
            $refresh = $this->refreshGraph($baseline);
            $lockOwner = $refresh['lockOwner'];
            $result = $refresh['result'];
            $payload = $refresh['engine']->overview();
            $payload['refreshed'] = true;
            $payload['previousGeneration'] = $result['previousGeneration'] ?? null;
            $afterId = $result['generation']['id'];
            $payload['generationChanged'] = (bool) ($result['created'] ?? false);

            if (($result['mirrorWarnings'] ?? []) !== []) {
                $payload['mirrorWarnings'] = $result['mirrorWarnings'];
            }

            if ($baseline !== null) {
                $payload['verification'] = app(ChangeVerifier::class)->verify(
                    $baseline,
                    $afterId,
                    array_values($validated['targets'] ?? []),
                    array_values($validated['changed_files'] ?? []),
                    4,
                    0.0,
                    (int) ($validated['limit'] ?? 50),
                    confirmedRefreshReuse: ! (bool) ($result['created'] ?? false)
                        && $baseline === $afterId,
                );
            }

            return $this->structuredResponse($payload);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        } finally {
            if ($lockOwner !== null) {
                $lock->release($lockOwner);
            }
        }
    }
}
