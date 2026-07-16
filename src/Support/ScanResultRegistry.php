<?php

namespace AppGraph\Support;

use RuntimeException;

/**
 * Correlates an Artisan scan result with the MCP caller that requested it.
 * Tokens prevent concurrent refreshes from consuming each other's generation.
 */
final class ScanResultRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $results = [];

    /** @param array<string, mixed> $result */
    public function record(string $token, array $result): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            throw new RuntimeException('Invalid internal AppGraph scan result token.');
        }

        // A long-lived MCP process should remain bounded even if a caller is
        // interrupted after a scan completes and before consuming its result.
        if (count($this->results) >= 32) {
            array_shift($this->results);
        }

        $this->results[$token] = $result;
    }

    /** @return array<string, mixed>|null */
    public function take(string $token): ?array
    {
        $result = $this->results[$token] ?? null;
        unset($this->results[$token]);

        return $result;
    }
}
