<?php

namespace AppGraph\Runtime\PHPUnit;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

final readonly class TestFinishedSubscriber implements FinishedSubscriber
{
    public function __construct(private PhpUnitRuntimeSession $session)
    {
    }

    public function notify(Finished $event): void
    {
        $this->session->finished($event);
    }
}
