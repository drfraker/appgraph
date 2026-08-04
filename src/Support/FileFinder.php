<?php

namespace AppGraph\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class FileFinder
{
    public function __construct(private ?string $basePath = null)
    {
        $this->basePath ??= function_exists('base_path') ? base_path() : (getcwd() ?: '.');
        $this->basePath = rtrim($this->normalizePath($this->basePath), '/');
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * @param array<int, string>|string $directories
     * @return array<int, string>
     */
    public function findPhpFiles(array|string $directories): array
    {
        return $this->findFiles($directories, ['php']);
    }

    /**
     * @param array<int, string>|string $directories
     * @param array<int, string> $extensions
     * @return array<int, string>
     */
    public function findFiles(array|string $directories, array $extensions): array
    {
        $directories = is_array($directories) ? $directories : [$directories];
        $extensions = array_map('strtolower', $extensions);
        $files = [];

        foreach ($directories as $directory) {
            $absoluteDirectory = $this->absolutePath($directory);

            if (! is_dir($absoluteDirectory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absoluteDirectory, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! in_array(strtolower($file->getExtension()), $extensions, true)) {
                    continue;
                }

                $files[$this->normalizePath($file->getPathname())] = $this->normalizePath($file->getPathname());
            }
        }

        $files = array_values($files);
        sort($files);

        return $files;
    }

    public function absolutePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $this->normalizePath($path);
        }

        return $this->basePath.'/'.ltrim($this->normalizePath($path), '/');
    }

    /**
     * Absolute path with '', '.', and '..' segments lexically collapsed, so
     * configured directories match the normalized paths used by publication
     * manifests regardless of how they were spelled.
     */
    public function canonicalPath(string $path): string
    {
        $path = $this->absolutePath($path);
        $prefix = str_starts_with($path, '/')
            ? '/'
            : (preg_match('/^[A-Z]:\//i', $path) === 1 ? strtoupper(substr($path, 0, 2)).'/' : '');
        $remainder = $prefix === '/'
            ? substr($path, 1)
            : ($prefix !== '' ? substr($path, 3) : $path);
        $segments = [];

        foreach (explode('/', $remainder) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return rtrim($prefix, '/').'/'.implode('/', $segments);
    }

    public function relativePath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = $this->normalizePath($path);
        $basePath = $this->basePath;

        if (str_starts_with($path, $basePath.'/')) {
            return substr($path, strlen($basePath) + 1);
        }

        return $path;
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Z]:\//i', $path) === 1;
    }
}
