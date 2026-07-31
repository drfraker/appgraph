<?php

namespace pcov;

const inclusive = 1;

final class FakeState
{
    public static bool $collecting = false;

    /** @var array<string, mixed> */
    public static array $coverage = [];

    public static int $startCalls = 0;

    public static int $stopCalls = 0;

    public static int $clearCalls = 0;

    public static int $collectCalls = 0;

    public static bool $throwOnStartAfterActivation = false;

    public static bool $throwOnStop = false;

    public static bool $throwOnClear = false;

    public static bool $throwOnCollect = false;
}

function start(): void
{
    FakeState::$startCalls++;
    FakeState::$collecting = true;

    if (FakeState::$throwOnStartAfterActivation) {
        throw new \RuntimeException('Fake PCOV start failed after activation.');
    }
}

function stop(): void
{
    FakeState::$stopCalls++;

    if (FakeState::$throwOnStop) {
        throw new \RuntimeException('Fake PCOV stop failed.');
    }

    FakeState::$collecting = false;
}

function clear(): void
{
    FakeState::$clearCalls++;

    if (FakeState::$throwOnClear) {
        throw new \RuntimeException('Fake PCOV clear failed.');
    }

    FakeState::$coverage = [];
}

/** @return list<string> */
function waiting(): array
{
    return array_keys(FakeState::$coverage);
}

/**
 * @param list<string> $files
 * @return array<string, mixed>
 */
function collect(mixed $type, array $files): array
{
    FakeState::$collectCalls++;

    if (FakeState::$throwOnCollect) {
        throw new \RuntimeException('Fake PCOV collect failed.');
    }

    return array_intersect_key(FakeState::$coverage, array_fill_keys($files, true));
}
