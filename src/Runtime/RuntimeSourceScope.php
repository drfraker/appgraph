<?php

namespace AppGraph\Runtime;

use FilesystemIterator;
use InvalidArgumentException;
use Throwable;

/**
 * Project source discovery adapted from Pest v5.0.2's TIA SourceScope (MIT).
 * See THIRD_PARTY_NOTICES.md.
 *
 * AppGraph deliberately scopes the whole Laravel project rather than only the
 * PHPUnit <source> filter so runtime evidence can include routes, config,
 * migrations, Blade views, and other executable project PHP files.
 */
final class RuntimeSourceScope
{
    public const DEFAULT_MAX_PHP_FILES = 20_000;

    public const MAX_PHP_FILES = 100_000;

    private const MAX_VISITED_ENTRIES = 250_000;

    /** @var list<string> */
    private const TOP_LEVEL_NOISE = [
        'vendor',
        'node_modules',
        '.git',
        '.github',
        '.idea',
        '.vscode',
        '.pest',
        '.phpunit.cache',
        '.cache',
        'coverage',
        'storage',
    ];

    /** @var list<string> */
    private const ANY_DEPTH_NOISE = [
        'vendor',
        'node_modules',
    ];

    /** @var list<string> */
    private const NESTED_NOISE = [
        'bootstrap/cache',
        'public/build',
        'storage/framework',
        'storage/logs',
    ];

    private readonly ProjectPathNormalizer $paths;

    private readonly bool $caseInsensitivePaths;

    /** @var list<string> */
    private array $exclusions = [];

    /** @var array<string, bool> */
    private array $containsCache = [];

    /**
     * @param iterable<string> $exclusions Project-relative or absolute files/directories.
     */
    public function __construct(
        string $projectRoot,
        iterable $exclusions = [],
        private readonly int $maxPhpFiles = self::DEFAULT_MAX_PHP_FILES,
    ) {
        if ($maxPhpFiles < 1 || $maxPhpFiles > self::MAX_PHP_FILES) {
            throw new InvalidArgumentException(sprintf(
                'Runtime source scope PHP file limit must be between 1 and %d.',
                self::MAX_PHP_FILES,
            ));
        }

        $this->paths = new ProjectPathNormalizer($projectRoot);
        $this->caseInsensitivePaths = DIRECTORY_SEPARATOR === '\\'
            || preg_match('/^[A-Za-z]:\//', $this->paths->root()) === 1;

        foreach ($exclusions as $exclusion) {
            if (! is_string($exclusion)) {
                continue;
            }

            $relative = $this->paths->relative($exclusion);

            if ($relative !== null) {
                $relative = $this->comparisonPath($relative);
                $this->exclusions[$relative] = $relative;
            }
        }

        $this->exclusions = array_values($this->exclusions);
        sort($this->exclusions, SORT_STRING);
    }

    public function root(): string
    {
        return $this->paths->root();
    }

    public function contains(string $file): bool
    {
        $relative = $this->paths->relative($file);

        if ($relative === null) {
            return false;
        }

        if (array_key_exists($relative, $this->containsCache)) {
            return $this->containsCache[$relative];
        }

        $contains = ! $this->isExcluded($relative);

        if (count($this->containsCache) < self::MAX_PHP_FILES) {
            $this->containsCache[$relative] = $contains;
        }

        return $contains;
    }

    /**
     * Enumerate a sorted, bounded set of project PHP files for source hash
     * priming. Symlinks are not traversed, preventing cycles and escapes. If
     * the defensive entry bound is exceeded, no partial priming set is
     * returned; sources are still hashed when direct evidence is recorded.
     *
     * @return list<string> Canonical absolute paths.
     */
    public function phpFiles(): array
    {
        $directories = [$this->root()];
        $files = [];
        $visitedEntries = 0;

        while ($directories !== []
            && count($files) < $this->maxPhpFiles
            && $visitedEntries < self::MAX_VISITED_ENTRIES) {
            $directory = array_pop($directories);

            if (! is_string($directory)) {
                continue;
            }

            try {
                $iterator = new FilesystemIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO,
                );
                $entries = [];

                foreach ($iterator as $entry) {
                    $visitedEntries++;

                    if ($visitedEntries > self::MAX_VISITED_ENTRIES) {
                        return [];
                    }

                    $entries[] = $entry->getPathname();
                }

                sort($entries, SORT_STRING);

                $childDirectories = [];

                foreach ($entries as $path) {
                    if (count($files) >= $this->maxPhpFiles) {
                        break;
                    }

                    if (is_link($path)) {
                        continue;
                    }

                    if (is_dir($path)) {
                        if ($this->contains($path.'/__appgraph_scope_probe__.php')) {
                            $childDirectories[] = $path;
                        }

                        continue;
                    }

                    if (! is_file($path)
                        || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php'
                        || ! $this->contains($path)) {
                        continue;
                    }

                    $real = realpath($path);

                    // Revalidate the canonical target after scope acceptance
                    // so a concurrent symlink swap cannot escape the project.
                    if ($real !== false && ! is_link($path) && $this->contains($real)) {
                        $files[$real] = true;
                    }
                }

                // The directory stack is LIFO, so push children in reverse
                // order to visit the lexicographically first directory next.
                foreach (array_reverse($childDirectories) as $childDirectory) {
                    $directories[] = $childDirectory;
                }
            } catch (Throwable) {
                // An unreadable directory must not make runtime evidence fatal.
            }
        }

        $paths = array_keys($files);
        sort($paths, SORT_STRING);

        return $paths;
    }

    private function isExcluded(string $relative): bool
    {
        $relative = str_replace('\\', '/', trim($relative, '/'));
        $comparison = $this->comparisonPath($relative);
        $segments = explode('/', $comparison);

        foreach ($segments as $segment) {
            if ($segment !== '' && str_starts_with($segment, '.')) {
                return true;
            }

            if (in_array($segment, self::ANY_DEPTH_NOISE, true)) {
                return true;
            }
        }

        foreach (self::TOP_LEVEL_NOISE as $noise) {
            if ($this->startsWithPath($comparison, $noise)) {
                return true;
            }
        }

        foreach (self::NESTED_NOISE as $noise) {
            if ($this->startsWithPath($comparison, $noise)) {
                return true;
            }
        }

        foreach ($this->exclusions as $excluded) {
            if ($this->startsWithPath($comparison, $excluded)) {
                return true;
            }
        }

        return false;
    }

    private function startsWithPath(string $candidate, string $directory): bool
    {
        return $candidate === $directory || str_starts_with($candidate, $directory.'/');
    }

    private function comparisonPath(string $path): string
    {
        return $this->caseInsensitivePaths ? strtolower($path) : $path;
    }
}
