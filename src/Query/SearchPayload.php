<?php

namespace AppGraph\Query;

/**
 * Shared agent-facing projection for store-backed search results. Search is a
 * locator: it returns stable source coordinates (including endLine so callers
 * can plan bounded reads) and a scalar revision, never full node facts or
 * generation metadata.
 */
final class SearchPayload
{
    /**
     * @param array<string, mixed> $search
     * @return array<string, mixed>
     */
    public static function fromStoreResult(string $term, array $search): array
    {
        $generation = $search['generation'];
        $payload = [
            'query' => 'search',
            'target' => $term,
            'revision' => (string) $generation['id'],
            'generatedAt' => $generation['generatedAt'],
            'results' => array_map(
                static fn (array $node): array => array_filter([
                    'id' => $node['id'],
                    'type' => $node['type'],
                    'label' => $node['label'] ?? null,
                    'file' => $node['file'] ?? null,
                    'line' => $node['line'] ?? null,
                    'endLine' => $node['endLine'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
                $search['results'],
            ),
        ];

        if ($search['truncated']) {
            $payload['truncated'] = true;
        }

        return $payload;
    }
}
