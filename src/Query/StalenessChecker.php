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
        if ($recordedScan === null) {
            if (! is_file($graphPath)) {
                return [];
            }

            $recordedScan = $this->recordedScan($graphPath);
        }

        if (isset($recordedScan['fingerprint'], $recordedScan['files']) && is_array($recordedScan['files'])) {
            $additionalFiles = [];

            foreach ((array) ($recordedScan['additionalFiles'] ?? []) as $path) {
                if (is_string($path) && $path !== '' && ! str_contains($path, "\0")) {
                    $additionalFiles[] = $this->files->absolutePath($path);
                }
            }

            $current = $this->fingerprint->capture(additionalFiles: $additionalFiles);
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
            $fullFingerprintMatches = hash_equals(
                (string) $recordedScan['fingerprint'],
                $current['fingerprint'],
            );
            $staticFingerprintMatches = isset(
                $recordedScan['staticFingerprint'],
                $current['staticFingerprint'],
            )
                ? hash_equals(
                    (string) $recordedScan['staticFingerprint'],
                    (string) $current['staticFingerprint'],
                )
                : $fullFingerprintMatches;
            $recordedRuntimeSession = $recordedScan['runtimeEvidenceSession'] ?? null;
            $currentRuntimeSession = $current['runtimeEvidenceSession'] ?? null;
            $runtimeEvidenceComparable = $recordedRuntimeSession === null
                && $currentRuntimeSession === null;

            if (is_string($recordedRuntimeSession) && is_string($currentRuntimeSession)) {
                $runtimeEvidenceComparable = hash_equals(
                    $recordedRuntimeSession,
                    $currentRuntimeSession,
                );
            }

            $matches = $staticFingerprintMatches
                && (! $runtimeEvidenceComparable || $fullFingerprintMatches);

            $databaseSchema = is_array($recordedScan['databaseSchema'] ?? null)
                ? $recordedScan['databaseSchema']
                : null;
            $liveSchemaFreshnessUnknown = ($databaseSchema['source'] ?? null) === 'live';

            return array_filter([
                'stale' => ! $matches,
                'fingerprintMatches' => $matches,
                'staticFingerprintMatches' => $staticFingerprintMatches,
                'changedFiles' => count($changed),
                'addedFiles' => count($added),
                'removedFiles' => count($removed),
                'configurationChanged' => $runtimeEvidenceComparable
                    ? ($recordedScan['configuration'] ?? null) !== $current['configuration']
                    : null,
                'environmentChanged' => $runtimeEvidenceComparable
                    ? ($recordedScan['applicationEnvironment'] ?? null)
                        !== ($current['applicationEnvironment'] ?? null)
                    : null,
                'runtimeEvidenceComparable' => $runtimeEvidenceComparable,
                'runtimeEvidenceFreshness' => $runtimeEvidenceComparable
                    ? 'compared_same_process'
                    : 'unknown_cross_process',
                'effectiveConfigurationFreshness' => $runtimeEvidenceComparable
                    ? 'compared_same_process'
                    : 'unknown_cross_process',
                'containerBindingsChanged' => $runtimeEvidenceComparable
                    ? ($recordedScan['containerBindings'] ?? null) !== ($current['containerBindings'] ?? null)
                    : null,
                'laravelExecutionRegistryChanged' => $runtimeEvidenceComparable
                    ? ($recordedScan['laravelExecutionRegistry'] ?? null)
                        !== ($current['laravelExecutionRegistry'] ?? null)
                    : null,
                'versionChanged' => ($recordedScan['appgraphVersion'] ?? null) !== $current['appgraphVersion']
                    || ($recordedScan['laravelVersion'] ?? null) !== ($current['laravelVersion'] ?? null),
                'databaseSchemaFreshness' => $liveSchemaFreshnessUnknown ? 'unknown_after_scan' : null,
                'databaseSchemaFreshnessUnknown' => $liveSchemaFreshnessUnknown ?: null,
                'samplePaths' => array_slice([...$changed, ...$added, ...$removed], 0, 20),
            ], static fn (mixed $value): bool => $value !== [] && $value !== null);
        }

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
