<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Query\UncertaintyBuckets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UncertaintyBucketsTest extends TestCase
{
    #[DataProvider('thresholds')]
    public function test_thresholds(float $confidence, ?string $expected): void
    {
        $this->assertSame($expected, UncertaintyBuckets::forConfidence($confidence));
    }

    /** @return array<string, array{float, ?string}> */
    public static function thresholds(): array
    {
        return [
            'certain' => [1.0, null],
            'certain boundary' => [0.9, null],
            'inferred upper' => [0.8999, 'inferred'],
            'inferred boundary' => [0.6, 'inferred'],
            'low upper' => [0.5999, 'low'],
            'low floor' => [0.0, 'low'],
        ];
    }

    public function test_fields_include_only_recorded_bounded_reason_codes(): void
    {
        $this->assertSame([
            'uncertainty' => 'inferred',
            'uncertaintyReason' => 'receiver_type_unknown',
        ], UncertaintyBuckets::fields(0.75, [
            'reason' => 'receiver_type_unknown',
            'inference' => 'less_specific_fallback',
        ]));

        $this->assertSame([
            'uncertainty' => 'low',
            'uncertaintyReason' => 'raw_sql_table_name_regex',
        ], UncertaintyBuckets::fields(0.4, [
            'inference' => 'raw_sql_table_name_regex',
        ]));

        $this->assertSame(
            ['uncertainty' => 'low'],
            UncertaintyBuckets::fields(0.4, ['reason' => 'free prose is not a code']),
        );
        $this->assertSame([], UncertaintyBuckets::fields(0.9, ['reason' => 'recorded_but_certain']));
    }
}
