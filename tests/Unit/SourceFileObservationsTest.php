<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Support\SourceFileObservations;
use PHPUnit\Framework\TestCase;

class SourceFileObservationsTest extends TestCase
{
    public function test_it_records_unique_sorted_hashes_and_can_be_reset(): void
    {
        $observations = new SourceFileObservations();
        $first = $observations->record('/tmp/source.php', 'first');
        $observations->record('/tmp/source.php', 'second');
        $observations->record('/tmp/source.php', 'first');
        $other = $observations->record('C:\\project\\source.js', 'frontend');
        $expected = [$first, hash('sha256', 'second')];
        sort($expected);

        $this->assertSame([
            '/tmp/source.php' => $expected,
            'C:/project/source.js' => [$other],
        ], $observations->hashes());

        $observations->reset();

        $this->assertSame([], $observations->hashes());
    }
}
