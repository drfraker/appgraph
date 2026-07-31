<?php

namespace AppGraph\Tests\Fixtures\Runtime {
    final class FakeXdebugState
    {
        public static bool $collecting = false;

        /** @var array<string, mixed> */
        public static array $coverage = [];

        public static int $startCalls = 0;

        public static int $stopCalls = 0;

        public static bool $throwOnStartAfterActivation = false;

        public static bool $throwOnGet = false;
    }
}

namespace {
    function xdebug_code_coverage_started(): bool
    {
        return \AppGraph\Tests\Fixtures\Runtime\FakeXdebugState::$collecting;
    }

    function xdebug_start_code_coverage(): void
    {
        \AppGraph\Tests\Fixtures\Runtime\FakeXdebugState::$startCalls++;
        \AppGraph\Tests\Fixtures\Runtime\FakeXdebugState::$collecting = true;

        if (\AppGraph\Tests\Fixtures\Runtime\FakeXdebugState::$throwOnStartAfterActivation) {
            throw new \RuntimeException('Fake Xdebug start failed after activation.');
        }
    }

    /** @return array<string, mixed> */
    function xdebug_get_code_coverage(): array
    {
        if (\AppGraph\Tests\Fixtures\Runtime\FakeXdebugState::$throwOnGet) {
            throw new \RuntimeException('Fake Xdebug coverage read failed.');
        }

        return \AppGraph\Tests\Fixtures\Runtime\FakeXdebugState::$coverage;
    }

    function xdebug_stop_code_coverage(bool $cleanup = true): void
    {
        \AppGraph\Tests\Fixtures\Runtime\FakeXdebugState::$stopCalls++;
        \AppGraph\Tests\Fixtures\Runtime\FakeXdebugState::$collecting = false;
    }
}
