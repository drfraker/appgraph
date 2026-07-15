<?php

namespace AppGraph\Query;

use AppGraph\Support\FileFinder;

class StalenessChecker
{
    public function __construct(private FileFinder $files)
    {
    }

    /**
     * Count source files modified after the graph was written. Walks app/,
     * routes/, and database/migrations, so callers should reserve this for
     * orientation queries rather than every lookup.
     *
     * @return array<string, int>
     */
    public function check(string $graphPath): array
    {
        if (! is_file($graphPath)) {
            return [];
        }

        $graphMtime = (int) filemtime($graphPath);
        $newer = 0;

        foreach ($this->files->findPhpFiles(['app', 'routes', 'database/migrations']) as $file) {
            if ((int) filemtime($file) > $graphMtime) {
                $newer++;
            }
        }

        return ['newerSourceFiles' => $newer];
    }
}
