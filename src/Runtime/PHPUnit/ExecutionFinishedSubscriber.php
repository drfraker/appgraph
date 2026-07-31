<?php

namespace AppGraph\Runtime\PHPUnit;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber as Subscriber;

final readonly class ExecutionFinishedSubscriber implements Subscriber
{
    public function __construct(private PhpUnitRuntimeSession $session)
    {
    }

    public function notify(ExecutionFinished $event): void
    {
        $this->session->flush();
    }
}
