<?php

namespace AppGraph\Support;

use RuntimeException;

final class PhpFileFactsParseException extends RuntimeException
{
    public function __construct(
        public readonly string $sourceFile,
        public readonly string $sourceHash,
        public readonly string $parserErrorClass,
        public readonly string $rawMessage,
        public readonly int $startLine = -1,
        public readonly int $endLine = -1,
    ) {
        parent::__construct($rawMessage.($startLine > 0 ? ' on line '.$startLine : ' on unknown line'));
    }

    /** @param array<string, mixed> $record */
    public static function fromRecord(string $sourceFile, string $sourceHash, array $record): self
    {
        return new self(
            $sourceFile,
            $sourceHash,
            is_string($record['class'] ?? null) ? $record['class'] : 'PhpParser\\Error',
            is_string($record['message'] ?? null) ? $record['message'] : 'Unknown PHP parse error',
            is_int($record['startLine'] ?? null) ? $record['startLine'] : -1,
            is_int($record['endLine'] ?? null) ? $record['endLine'] : -1,
        );
    }
}
