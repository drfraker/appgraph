<?php

namespace AppGraph\Support;

final class CallableSemanticRegistry
{
    private const MAX_ARGUMENT_POSITION = 32;

    private const MAX_RULES = 64;

    /** @var array<int, string> */
    private const SUPPORTED_SEMANTICS = [
        'named_route_url',
    ];

    /**
     * @var array<string, array<string, array{
     *     match: array{kind: string, name: string},
     *     semantic: array{kind: string, arguments: array<string, int>}
     * }>>
     */
    private array $functionRules = [];

    /** @param array<mixed> $rules */
    public function __construct(array $rules = [])
    {
        $accepted = 0;

        foreach ($rules as $rule) {
            if ($accepted >= self::MAX_RULES) {
                break;
            }

            $normalized = $this->normalizeFunctionRule($rule);

            if ($normalized === null) {
                continue;
            }

            $semantic = $normalized['semantic']['kind'];
            $name = strtolower($normalized['match']['name']);

            if (! isset($this->functionRules[$semantic][$name])) {
                $accepted++;
            }

            $this->functionRules[$semantic][$name] = $normalized;
        }
    }

    /**
     * @return array{
     *     match: array{kind: string, name: string},
     *     semantic: array{kind: string, arguments: array<string, int>}
     * }|null
     */
    public function function(string $resolvedName, string $semanticKind): ?array
    {
        $name = strtolower(ltrim(trim($resolvedName), '\\'));
        $semantic = strtolower(trim($semanticKind));

        if ($name === '' || $semantic === '') {
            return null;
        }

        return $this->functionRules[$semantic][$name] ?? null;
    }

    /**
     * @return array{
     *     match: array{kind: string, name: string},
     *     semantic: array{kind: string, arguments: array<string, int>}
     * }|null
     */
    private function normalizeFunctionRule(mixed $rule): ?array
    {
        if (! is_array($rule)
            || ! is_array($rule['match'] ?? null)
            || ! is_array($rule['semantic'] ?? null)) {
            return null;
        }

        $matchKind = $rule['match']['kind'] ?? null;
        $name = $rule['match']['name'] ?? null;
        $semanticKind = $rule['semantic']['kind'] ?? null;
        $arguments = $rule['semantic']['arguments'] ?? null;

        if ($matchKind !== 'function'
            || ! is_string($name)
            || ! is_string($semanticKind)
            || ! is_array($arguments)) {
            return null;
        }

        $canonicalName = ltrim(trim($name), '\\');
        $canonicalSemantic = strtolower(trim($semanticKind));

        if (! $this->isValidFunctionName($canonicalName)
            || ! in_array($canonicalSemantic, self::SUPPORTED_SEMANTICS, true)) {
            return null;
        }

        $normalizedArguments = [];

        foreach ($arguments as $argument => $position) {
            if (! is_string($argument)
                || preg_match('/^[a-z][a-z0-9_]*$/D', $argument) !== 1
                || ! is_int($position)
                || $position < 0
                || $position > self::MAX_ARGUMENT_POSITION) {
                return null;
            }

            $normalizedArguments[$argument] = $position;
        }

        if (! isset($normalizedArguments['route_name'])) {
            return null;
        }

        return [
            'match' => [
                'kind' => 'function',
                'name' => $canonicalName,
            ],
            'semantic' => [
                'kind' => $canonicalSemantic,
                'arguments' => $normalizedArguments,
            ],
        ];
    }

    private function isValidFunctionName(string $name): bool
    {
        return preg_match(
            '/^[a-zA-Z_\\x80-\\xff][a-zA-Z0-9_\\x80-\\xff]*(?:\\\\[a-zA-Z_\\x80-\\xff][a-zA-Z0-9_\\x80-\\xff]*)*$/D',
            $name,
        ) === 1;
    }
}
