<?php

namespace AppGraph\Runtime;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class RuntimeTimestamp
{
    public static function normalise(DateTimeInterface|string $value): string
    {
        try {
            $date = is_string($value) ? new DateTimeImmutable($value) : DateTimeImmutable::createFromInterface($value);
        } catch (Throwable $throwable) {
            throw new InvalidArgumentException('Runtime evidence timestamp is invalid.', previous: $throwable);
        }

        return $date
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    public static function now(): string
    {
        return self::normalise(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }
}
