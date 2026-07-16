<?php

namespace AppGraph\Support;

/**
 * Records the exact source bytes consumed by scanners during one scan.
 *
 * Endpoint fingerprints alone cannot detect an A/B/A edit where a scanner
 * reads B but the file contains A before and after analysis. Keeping every
 * observed content hash lets publication reject both mixed scans and reads
 * that do not match the final source manifest.
 */
final class SourceFileObservations
{
    /** @var array<string, array<string, true>> */
    private array $hashes = [];

    public function record(string $file, string $source): string
    {
        $path = str_replace('\\', '/', $file);
        $hash = hash('sha256', $source);
        $this->hashes[$path][$hash] = true;

        return $hash;
    }

    public function reset(): void
    {
        $this->hashes = [];
    }

    /** @return array<string, array<int, string>> */
    public function hashes(): array
    {
        $observed = [];

        foreach ($this->hashes as $file => $hashes) {
            $observed[$file] = array_keys($hashes);
            sort($observed[$file]);
        }

        ksort($observed);

        return $observed;
    }
}
