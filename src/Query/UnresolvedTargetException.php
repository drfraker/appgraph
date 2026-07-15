<?php

namespace AppGraph\Query;

use RuntimeException;

class UnresolvedTargetException extends RuntimeException
{
    /**
     * @param array<int, string> $candidates
     */
    public function __construct(string $target, public readonly array $candidates = [])
    {
        $message = "Could not resolve [{$target}] to a graph node.";

        if ($candidates !== []) {
            $message .= ' Did you mean: '.implode(', ', $candidates).'?';
        }

        parent::__construct($message);
    }
}
