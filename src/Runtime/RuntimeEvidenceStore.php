<?php

namespace AppGraph\Runtime;

interface RuntimeEvidenceStore
{
    public function path(): string;

    public function modifiedAt(): ?int;

    public function read(): ?RuntimeEvidenceSnapshot;

    public function readSource(string $json): RuntimeEvidenceSnapshot;

    public function write(RuntimeEvidenceSnapshot $snapshot): void;

    public function merge(RuntimeEvidenceSnapshot $snapshot): RuntimeEvidenceSnapshot;
}
