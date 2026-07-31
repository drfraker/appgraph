<?php

namespace AppGraph\Runtime\PHPUnit;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

/** Begin before setUp() so Laravel boot and fixture queries are observable. */
final readonly class TestPreparationStartedSubscriber implements PreparationStartedSubscriber
{
    public function __construct(private PhpUnitRuntimeSession $session)
    {
    }

    public function notify(PreparationStarted $event): void
    {
        $this->session->preparationStarted($event);
    }
}
