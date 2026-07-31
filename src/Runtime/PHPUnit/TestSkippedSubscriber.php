<?php

namespace AppGraph\Runtime\PHPUnit;

use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber as Subscriber;

/** Keep an incomplete/skipped trace from replacing prior complete evidence. */
final readonly class TestSkippedSubscriber implements Subscriber
{
    public function __construct(private PhpUnitRuntimeSession $session)
    {
    }

    public function notify(Skipped $event): void
    {
        $this->session->aborted();
    }
}
