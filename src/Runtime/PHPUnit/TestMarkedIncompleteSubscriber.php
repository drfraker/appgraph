<?php

namespace AppGraph\Runtime\PHPUnit;

use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber as Subscriber;

/** Keep an incomplete trace from replacing prior complete evidence. */
final readonly class TestMarkedIncompleteSubscriber implements Subscriber
{
    public function __construct(private PhpUnitRuntimeSession $session)
    {
    }

    public function notify(MarkedIncomplete $event): void
    {
        $this->session->aborted();
    }
}
