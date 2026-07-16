<?php

namespace AppGraph\Graph;

class Graph
{
    /**
     * @var array<string, Node>
     */
    private array $nodes = [];

    /**
     * @var array<string, Edge>
     */
    private array $edges = [];

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(private array $meta = [])
    {
    }

    public function addNode(Node $node): Node
    {
        if (isset($this->nodes[$node->id])) {
            return $this->nodes[$node->id]->merge($node);
        }

        $this->nodes[$node->id] = $node;

        return $node;
    }

    public function addEdge(Edge $edge): Edge
    {
        $this->attachEvidence($edge);
        $key = $edge->key();

        if (isset($this->edges[$key])) {
            return $this->edges[$key]->merge($edge);
        }

        $this->edges[$key] = $edge;

        return $edge;
    }

    private function attachEvidence(Edge $edge): void
    {
        $from = $this->nodes[$edge->from] ?? null;
        $file = $edge->metadata['file'] ?? $from?->file;
        $line = $edge->metadata['line'] ?? $from?->line;
        $record = array_filter([
            'file' => is_string($file) && $file !== '' ? $file : null,
            'line' => is_int($line) ? $line : null,
            'source' => $edge->metadata['source'] ?? null,
            'rule' => $edge->metadata['rule'] ?? null,
            'inference' => $edge->metadata['inference'] ?? null,
            'syntax' => $edge->metadata['syntax'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        if ($record === []) {
            return;
        }

        $digest = substr(hash('sha256', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 0, 8);
        $key = sprintf('%09d:%s', $record['line'] ?? 0, $digest);
        $edge->metadata['evidence'] ??= [];
        $edge->metadata['evidence'][$key] = $record;
        ksort($edge->metadata['evidence']);
    }

    public function hasNode(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    public function node(string $id): ?Node
    {
        return $this->nodes[$id] ?? null;
    }

    /**
     * @return array<int, Node>
     */
    public function nodes(): array
    {
        return array_values($this->nodes);
    }

    /**
     * @return array<int, Edge>
     */
    public function edges(): array
    {
        return array_values($this->edges);
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function addMeta(array $values): void
    {
        $this->meta = array_replace_recursive($this->meta, $values);
    }

    /**
     * Replace metadata when an authoritative persisted generation is reused.
     * Node and edge content is already identical at that point; retaining fresh
     * scan warnings or timestamps would make a mirror disagree with its id.
     *
     * @param array<string, mixed> $values
     */
    public function replaceMeta(array $values): void
    {
        $this->meta = $values;
    }

    /**
     * @param array<string, mixed> $warning
     */
    public function addWarning(array $warning): void
    {
        $warnings = $this->meta['warnings'] ?? [];
        $warnings[] = $warning;

        $this->meta['warnings'] = $warnings;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $nodes = array_map(
            static fn (Node $node): array => $node->toArray(),
            $this->nodes()
        );

        usort($nodes, static fn (array $a, array $b): int => [$a['type'], $a['id']] <=> [$b['type'], $b['id']]);

        $edges = array_map(
            static fn (Edge $edge): array => $edge->toArray(),
            $this->edges()
        );

        usort($edges, static fn (array $a, array $b): int => [$a['from'], $a['type'], $a['to']] <=> [$b['from'], $b['type'], $b['to']]);

        return [
            'meta' => $this->meta,
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }
}
