<?php

namespace AppGraph\Graph;

class Edge
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $from,
        public string $to,
        public string $type,
        public float $confidence = 1.0,
        public array $metadata = [],
    ) {
    }

    public function key(): string
    {
        return implode("\0", [$this->from, $this->to, $this->type]);
    }

    public function merge(self $edge): self
    {
        $this->confidence = max($this->confidence, $edge->confidence);

        // `operations` is a map keyed by a stable op-key. array_replace_recursive would
        // deep-merge same-key op records instead of treating the map as a set, so pull it
        // out, recursively merge the rest, then union by key (first writer wins) + ksort.
        $ownOperations = $this->metadata['operations'] ?? [];
        $otherOperations = $edge->metadata['operations'] ?? [];
        $ownEvidence = $this->metadata['evidence'] ?? [];
        $otherEvidence = $edge->metadata['evidence'] ?? [];
        unset(
            $this->metadata['operations'],
            $edge->metadata['operations'],
            $this->metadata['evidence'],
            $edge->metadata['evidence'],
        );

        $this->metadata = array_replace_recursive($this->metadata, $edge->metadata);

        $operations = $ownOperations + $otherOperations;

        if ($operations !== []) {
            ksort($operations);
            $this->metadata['operations'] = $operations;
        }

        $evidence = $ownEvidence + $otherEvidence;

        if ($evidence !== []) {
            ksort($evidence);
            $this->metadata['evidence'] = $evidence;
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'from' => $this->from,
            'to' => $this->to,
            'type' => $this->type,
            'confidence' => $this->confidence,
        ];

        if ($this->metadata !== []) {
            $data['metadata'] = $this->metadata;
        }

        return $data;
    }
}
