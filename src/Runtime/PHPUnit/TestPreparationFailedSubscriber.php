<?php

namespace AppGraph\Runtime\PHPUnit;

use PHPUnit\Event\Test\PreparationFailed;
use PHPUnit\Event\Test\PreparationFailedSubscriber as Subscriber;

/** Discard a segment when setUp or another preparation hook fails. */
final readonly class TestPreparationFailedSubscriber implements Subscriber
{
    public function __construct(private PhpUnitRuntimeSession $session)
    {
    }

    public function notify(PreparationFailed $event): void
    {
        $this->session->aborted();
    }
}
