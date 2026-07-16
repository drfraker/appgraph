<?php

namespace AppGraph\Support;

interface ScanRunner
{
    /** @return array<string, mixed> */
    public function run(?string $preserveGeneration = null): array;
}
