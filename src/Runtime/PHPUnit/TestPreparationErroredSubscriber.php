<?php

namespace AppGraph\Runtime\PHPUnit;

use PHPUnit\Event\Test\PreparationErrored;
use PHPUnit\Event\Test\PreparationErroredSubscriber as Subscriber;

/** Discard a segment when setUp or another preparation hook errors. */
final readonly class TestPreparationErroredSubscriber implements Subscriber
{
    public function __construct(private PhpUnitRuntimeSession $session)
    {
    }

    public function notify(PreparationErrored $event): void
    {
        $this->session->aborted();
    }
}
