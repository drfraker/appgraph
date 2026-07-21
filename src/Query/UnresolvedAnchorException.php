<?php

namespace AppGraph\Query;

use RuntimeException;

final class UnresolvedAnchorException extends RuntimeException
{
    /**
     * @param array<int, string> $candidates
     */
    public function __construct(
        public readonly string $anchor,
        public readonly array $candidates = [],
    ) {
        $message = "Could not resolve AppGraph slice anchor [{$anchor}].";

        if ($candidates !== []) {
            $message .= ' Candidates: '.implode(', ', array_slice($candidates, 0, 10)).'.';
        }

        parent::__construct($message);
    }
}
