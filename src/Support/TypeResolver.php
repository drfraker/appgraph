<?php

namespace AppGraph\Support;

class TypeResolver
{
    /**
     * @return array{namespace: string, uses: array<string, string>}
     */
    public function namespaceAndUses(string $file): array
    {
        $source = file_get_contents($file);

        if ($source === false) {
            return ['namespace' => '', 'uses' => []];
        }

        preg_match('/^\s*namespace\s+([^;]+);/m', $source, $namespaceMatch);
        preg_match_all('/^\s*use\s+([^;{]+);/m', $source, $useMatches);

        $uses = [];

        foreach ($useMatches[1] ?? [] as $use) {
            $use = trim($use);

            if (str_contains($use, ' function ') || str_contains($use, ' const ')) {
                continue;
            }

            $parts = preg_split('/\s+as\s+/i', $use);
            $class = trim($parts[0] ?? '', " \t\n\r\0\x0B\\");
            $position = strrpos($class, '\\');
            $alias = $parts[1] ?? ($position === false ? $class : substr($class, $position + 1));

            if ($class !== '' && $alias !== '') {
                $uses[$alias] = $class;
            }
        }

        return [
            'namespace' => trim($namespaceMatch[1] ?? '', " \t\n\r\0\x0B\\"),
            'uses' => $uses,
        ];
    }

    /**
     * @param array<string, string> $uses
     */
    public function resolveClassConstant(string $raw, string $namespace = '', array $uses = []): ?string
    {
        if (! preg_match('/([A-Za-z_][A-Za-z0-9_\\\\]*)\s*::\s*class/', $raw, $matches)) {
            return null;
        }

        $raw = ltrim($raw);
        $absolute = str_starts_with($raw, '\\');
        $class = trim($matches[1], '\\');

        if (str_contains($class, '\\')) {
            [$head, $tail] = explode('\\', $class, 2);

            if (isset($uses[$head])) {
                return $uses[$head].'\\'.$tail;
            }

            if ($absolute) {
                return ltrim($matches[1], '\\');
            }

            return trim($namespace.'\\'.$class, '\\');
        }

        if (isset($uses[$class])) {
            return $uses[$class];
        }

        return trim($namespace.'\\'.$class, '\\');
    }
}
