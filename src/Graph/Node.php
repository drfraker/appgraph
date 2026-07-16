<?php

namespace AppGraph\Graph;

class Node
{
    /**
     * @param array<int, array<string, mixed>> $inputs
     * @param array<int, array<string, mixed>> $outputs
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $label,
        public ?string $namespace = null,
        public ?string $class = null,
        public ?string $method = null,
        public ?string $file = null,
        public ?int $line = null,
        public ?string $signature = null,
        public array $inputs = [],
        public array $outputs = [],
        public ?string $summary = null,
        public array $metadata = [],
        public ?int $endLine = null,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function make(string $id, string $type, string $label, array $attributes = []): self
    {
        return new self(
            id: $id,
            type: $type,
            label: $label,
            namespace: $attributes['namespace'] ?? null,
            class: $attributes['class'] ?? null,
            method: $attributes['method'] ?? null,
            file: $attributes['file'] ?? null,
            line: $attributes['line'] ?? null,
            signature: $attributes['signature'] ?? null,
            inputs: $attributes['inputs'] ?? [],
            outputs: $attributes['outputs'] ?? [],
            summary: $attributes['summary'] ?? null,
            metadata: $attributes['metadata'] ?? [],
            endLine: $attributes['endLine'] ?? null,
        );
    }

    public function merge(self $node): self
    {
        if ($this->type === 'unknown' && $node->type !== 'unknown') {
            $this->type = $node->type;
        }

        if ($this->label === $this->id && $node->label !== $node->id) {
            $this->label = $node->label;
        }

        foreach (['namespace', 'class', 'method', 'file', 'line', 'endLine', 'signature', 'summary'] as $property) {
            if ($this->{$property} === null && $node->{$property} !== null) {
                $this->{$property} = $node->{$property};
            }
        }

        if ($this->inputs === [] && $node->inputs !== []) {
            $this->inputs = $node->inputs;
        }

        if ($this->outputs === [] && $node->outputs !== []) {
            $this->outputs = $node->outputs;
        }

        // `sources` is a list of interned schema-source ids. array_replace_recursive
        // would merge lists positionally (producing duplicates/garbage), so pull it
        // out of both sides, recursively merge the rest, then union + sort it back in.
        $ownSources = $this->metadata['sources'] ?? [];
        $otherSources = $node->metadata['sources'] ?? [];
        unset($this->metadata['sources'], $node->metadata['sources']);

        $this->metadata = array_replace_recursive($this->metadata, $node->metadata);

        $mergedSources = array_values(array_unique([...$ownSources, ...$otherSources]));

        if ($mergedSources !== []) {
            sort($mergedSources);
            $this->metadata['sources'] = $mergedSources;
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        // Required keys are always emitted; everything else is omitted when null/empty
        // to keep the exported graph token-efficient. Key order is fixed so output stays
        // diff-stable under the deterministic usort() in Graph::toArray().
        $data = [
            'id' => $this->id,
            'type' => $this->type,
            'label' => $this->label,
        ];

        foreach (['namespace', 'class', 'method', 'file', 'line', 'endLine', 'signature', 'summary'] as $key) {
            if ($this->{$key} !== null) {
                $data[$key] = $this->{$key};
            }
        }

        if ($this->inputs !== []) {
            $data['inputs'] = $this->inputs;
        }

        if ($this->outputs !== []) {
            $data['outputs'] = $this->outputs;
        }

        if ($this->metadata !== []) {
            $data['metadata'] = $this->metadata;
        }

        return $data;
    }
}
