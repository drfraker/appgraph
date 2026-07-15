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
        $key = $edge->key();

        if (isset($this->edges[$key])) {
            return $this->edges[$key]->merge($edge);
        }

        $this->edges[$key] = $edge;

        return $edge;
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
