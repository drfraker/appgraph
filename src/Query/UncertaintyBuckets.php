<?php

namespace AppGraph\Query;

/**
 * Agent-facing confidence policy. Numeric confidence remains available to the
 * CLI and legacy queries; focused MCP responses expose only coarse buckets.
 */
final class UncertaintyBuckets
{
    /**
     * @param array<string, mixed> $metadata
     * @return array{uncertainty?: string, uncertaintyReason?: string}
     */
    public static function fields(float $confidence, array $metadata = []): array
    {
        $bucket = self::forConfidence($confidence);

        if ($bucket === null) {
            return [];
        }

        $fields = ['uncertainty' => $bucket];
        $reason = self::reason($metadata);

        if ($reason !== null) {
            $fields['uncertaintyReason'] = $reason;
        }

        return $fields;
    }

    public static function forConfidence(float $confidence): ?string
    {
        if ($confidence >= 0.9) {
            return null;
        }

        return $confidence >= 0.6 ? 'inferred' : 'low';
    }

    /** @param array<string, mixed> $metadata */
    private static function reason(array $metadata): ?string
    {
        foreach (['reason', 'ambiguity', 'inference'] as $key) {
            $reason = $metadata[$key] ?? null;

            if (! is_string($reason)
                || $reason === ''
                || strlen($reason) > 128
                || preg_match('/^[A-Za-z0-9_.:-]+$/D', $reason) !== 1) {
                continue;
            }

            return $reason;
        }

        return null;
    }
}
