<?php

namespace AppGraph\Support;

use InvalidArgumentException;

final class ProjectPathNormalizer
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $resolved = realpath($basePath);

        if ($resolved === false || ! is_dir($resolved)) {
            throw new InvalidArgumentException("Project base path [{$basePath}] is not a readable directory.");
        }

        $normalized = rtrim(str_replace('\\', '/', $resolved), '/');
        $this->basePath = $normalized === '' ? '/' : $normalized;
    }

    public function normalize(string $file): ?string
    {
        if ($file === '' || str_contains($file, "\0")) {
            return null;
        }

        $file = str_replace('\\', '/', trim($file));
        $absolute = str_starts_with($file, '/') || preg_match('/^[A-Z]:\//i', $file) === 1;

        if ($absolute) {
            $resolved = realpath($file);
            $candidate = $resolved !== false
                ? str_replace('\\', '/', $resolved)
                : $this->resolveNonexistentAbsolutePath($file);

            if ($candidate === null || ! $this->insideBase($candidate)) {
                return null;
            }

            $relative = ltrim(substr($candidate, strlen($this->basePath)), '/');

            return $relative !== '' ? $relative : null;
        }

        $segments = [];

        foreach (explode('/', $file) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }

                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            return null;
        }

        $relative = implode('/', $segments);
        $resolved = realpath($this->basePath.'/'.$relative);

        if ($resolved !== false && ! $this->insideBase(str_replace('\\', '/', $resolved))) {
            return null;
        }

        return $relative;
    }

    private function resolveNonexistentAbsolutePath(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = str_starts_with($path, '/')
            ? '/'
            : (preg_match('/^[A-Z]:\//i', $path) === 1 ? strtoupper(substr($path, 0, 2)).'/' : null);

        if ($prefix === null) {
            return null;
        }

        $remainder = $prefix === '/' ? substr($path, 1) : substr($path, 3);
        $segments = [];

        foreach (explode('/', $remainder) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments !== []) {
                    array_pop($segments);
                }

                continue;
            }

            $segments[] = $segment;
        }

        $candidate = rtrim($prefix, '/').'/'.implode('/', $segments);
        $probe = $candidate;
        $tail = [];

        // Resolve the nearest existing ancestor so a missing leaf below a
        // symlink cannot masquerade as an in-project path.
        while (! file_exists($probe) && ! is_link($probe)) {
            $parent = dirname($probe);

            if ($parent === $probe) {
                break;
            }

            array_unshift($tail, basename($probe));
            $probe = $parent;
        }

        $ancestor = realpath($probe);

        if ($ancestor === false) {
            return $candidate;
        }

        return rtrim(str_replace('\\', '/', $ancestor), '/')
            .($tail !== [] ? '/'.implode('/', $tail) : '');
    }

    private function insideBase(string $path): bool
    {
        if ($this->basePath === '/') {
            return str_starts_with($path, '/');
        }

        return $path === $this->basePath || str_starts_with($path, $this->basePath.'/');
    }
}
