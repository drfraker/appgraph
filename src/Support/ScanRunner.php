<?php

namespace AppGraph\Support;

interface ScanRunner
{
    /** @return array<string, mixed> */
    public function run(): array;
}
