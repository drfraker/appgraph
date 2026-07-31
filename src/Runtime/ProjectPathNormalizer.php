<?php

namespace AppGraph\Runtime;

use InvalidArgumentException;

final class ProjectPathNormalizer
{
    public const MAX_PATH_BYTES = 4096;

    private string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $real = realpath($projectRoot);

        if ($real === false || ! is_dir($real)) {
            throw new InvalidArgumentException("Runtime evidence project root [{$projectRoot}] does not exist.");
        }

        $this->projectRoot = $this->normaliseSeparators(rtrim($real, '/\\'));
    }

    public function root(): string
    {
        return $this->projectRoot;
    }

    /**
     * Convert a project-relative or absolute path to a safe, project-relative path.
     *
     * Existing paths are resolved through realpath so a symlink cannot smuggle an
     * outside file into a runtime evidence snapshot. Non-existent relative paths
     * are accepted only when they contain no traversal segments.
     */
    public function relative(string $path): ?string
    {
        if ($path === '' || strlen($path) > self::MAX_PATH_BYTES || str_contains($path, "\0")) {
            return null;
        }

        if (str_contains($path, '://') || str_contains($path, "eval()'d")) {
            return null;
        }

        $normalised = $this->normaliseSeparators($path);

        if ($this->isAbsolute($normalised)) {
            return $this->relativeAbsolute($path, $normalised);
        }

        $relative = $this->safeRelative($normalised);

        if ($relative === null) {
            return null;
        }

        $absolute = $this->projectRoot.'/'.$relative;
        $real = realpath($absolute);

        if ($real !== false) {
            return $this->relativeResolved($this->normaliseSeparators($real));
        }

        return $relative;
    }

    public function absolute(string $path): ?string
    {
        $relative = $this->relative($path);

        if ($relative === null) {
            return null;
        }

        $absolute = $this->projectRoot.'/'.$relative;
        $real = realpath($absolute);

        return $real === false ? $absolute : $real;
    }

    private function relativeAbsolute(string $original, string $normalised): ?string
    {
        $real = realpath($original);
        $resolved = $real === false ? $normalised : $this->normaliseSeparators($real);

        if ($real === false && $this->containsTraversal($resolved)) {
            return null;
        }

        return $this->relativeResolved(rtrim($resolved, '/'));
    }

    private function relativeResolved(string $resolved): ?string
    {
        $root = $this->projectRoot;
        $caseInsensitive = preg_match('/^[A-Za-z]:\//', $root) === 1;
        $candidateForCompare = $caseInsensitive ? strtolower($resolved) : $resolved;
        $rootForCompare = $caseInsensitive ? strtolower($root) : $root;

        if (! str_starts_with($candidateForCompare, $rootForCompare.'/')) {
            return null;
        }

        return $this->safeRelative(substr($resolved, strlen($root) + 1));
    }

    private function safeRelative(string $path): ?string
    {
        $segments = explode('/', $path);
        $safe = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..' || str_contains($segment, "\0")) {
                return null;
            }

            $safe[] = $segment;
        }

        if ($safe === []) {
            return null;
        }

        $relative = implode('/', $safe);

        return strlen($relative) <= self::MAX_PATH_BYTES ? $relative : null;
    }

    private function containsTraversal(string $path): bool
    {
        return in_array('..', explode('/', $path), true);
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1;
    }

    private function normaliseSeparators(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
