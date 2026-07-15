<?php

namespace AppGraph\Query;

use AppGraph\Support\FileFinder;
use AppGraph\Support\ScanFingerprint;

class StalenessChecker
{
    public function __construct(
        private FileFinder $files,
        private ?ScanFingerprint $fingerprint = null,
    ) {
        $this->fingerprint ??= new ScanFingerprint($this->files);
    }

    /**
     * @param array<string, mixed>|null $recordedScan
     * @return array<string, mixed>
     */
    public function check(string $graphPath, ?array $recordedScan = null): array
    {
        if (! is_file($graphPath)) {
            return [];
        }

        $recordedScan ??= $this->recordedScan($graphPath);

        if (isset($recordedScan['fingerprint'], $recordedScan['files']) && is_array($recordedScan['files'])) {
            $current = $this->fingerprint->capture();
            $recordedFiles = $recordedScan['files'];
            $currentFiles = $current['files'];
            $changed = [];
            $added = [];
            $removed = [];

            foreach ($currentFiles as $path => $hash) {
                if (! array_key_exists($path, $recordedFiles)) {
                    $added[] = $path;
                } elseif (! hash_equals((string) $recordedFiles[$path], $hash)) {
                    $changed[] = $path;
                }
            }

            foreach ($recordedFiles as $path => $_hash) {
                if (! array_key_exists($path, $currentFiles)) {
                    $removed[] = $path;
                }
            }

            sort($changed);
            sort($added);
            sort($removed);
            $matches = hash_equals((string) $recordedScan['fingerprint'], $current['fingerprint']);

            return array_filter([
                'stale' => ! $matches,
                'fingerprintMatches' => $matches,
                'changedFiles' => count($changed),
                'addedFiles' => count($added),
                'removedFiles' => count($removed),
                'configurationChanged' => ($recordedScan['configuration'] ?? null) !== $current['configuration'],
                'versionChanged' => ($recordedScan['appgraphVersion'] ?? null) !== $current['appgraphVersion']
                    || ($recordedScan['laravelVersion'] ?? null) !== ($current['laravelVersion'] ?? null),
                'samplePaths' => array_slice([...$changed, ...$added, ...$removed], 0, 20),
            ], static fn (mixed $value): bool => $value !== []);
        }

        $graphMtime = (int) filemtime($graphPath);
        $newer = 0;

        foreach ($this->files->findPhpFiles(['app', 'routes', 'database/migrations']) as $file) {
            if ((int) filemtime($file) > $graphMtime) {
                $newer++;
            }
        }

        return [
            'stale' => $newer > 0,
            'newerSourceFiles' => $newer,
            'mode' => 'mtime_fallback',
        ];
    }

    /** @return array<string, mixed>|null */
    private function recordedScan(string $graphPath): ?array
    {
        $json = file_get_contents($graphPath);

        if ($json === false) {
            return null;
        }

        $graph = json_decode($json, true);

        return is_array($graph['meta']['scan'] ?? null) ? $graph['meta']['scan'] : null;
    }
}
