<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Query\FieldAccessClassifier;
use AppGraph\Tests\TestCase;

class FieldAccessClassifierTest extends TestCase
{
    public function test_task_classification_degrades_an_operation_tail_to_possible_with_bounded_work(): void
    {
        $operations = array_fill(0, 100000, [
            'operation' => 'select',
            'fields' => ['body'],
            'fieldCoverage' => 'complete',
        ]);
        $operations[99999] = [
            'operation' => 'late-proof',
            'fields' => ['title'],
            'fieldCoverage' => 'complete',
        ];
        $startedAt = hrtime(true);

        $classified = (new FieldAccessClassifier)->classifyForTask([
            'metadata' => ['operations' => $operations],
        ], 'title', 'notes');
        $elapsedNanoseconds = hrtime(true) - $startedAt;

        $this->assertSame('possible', $classified['match']);
        $this->assertSame([], $classified['ops']);
        $this->assertSame([], $classified['provenOps']);
        $this->assertSame([], $classified['possibleOps']);
        $this->assertSame(['select'], $classified['excludedOps']);
        $this->assertSame(['body'], $classified['fields']);
        $this->assertTrue($classified['truncated']);
        $this->assertLessThan(1_000_000_000, $elapsedNanoseconds);
    }

    public function test_task_classification_bounds_one_operations_field_list(): void
    {
        $fields = array_fill(0, 100000, 'body');
        $fields[99999] = 'title';

        $classified = (new FieldAccessClassifier)->classifyForTask([
            'metadata' => ['operations' => [[
                'operation' => 'select',
                'fields' => $fields,
                'fieldCoverage' => 'complete',
            ]]],
        ], 'title', 'notes');

        $this->assertSame('possible', $classified['match']);
        $this->assertSame(['select'], $classified['possibleOps']);
        $this->assertSame([], $classified['excludedOps']);
        $this->assertSame(['body'], $classified['fields']);
        $this->assertTrue($classified['truncated']);
    }

    public function test_task_classification_retains_proof_found_before_a_cap(): void
    {
        $operations = array_fill(0, 100000, [
            'operation' => 'select',
            'fields' => ['body'],
            'fieldCoverage' => 'complete',
        ]);
        $operations[0] = [
            'operation' => 'insert',
            'fields' => ['title'],
            'fieldCoverage' => 'complete',
        ];

        $classified = (new FieldAccessClassifier)->classifyForTask([
            'metadata' => ['operations' => $operations],
        ], 'title', 'notes');

        $this->assertSame('proven', $classified['match']);
        $this->assertSame(['insert'], $classified['ops']);
        $this->assertSame(['insert'], $classified['provenOps']);
        $this->assertSame(['select'], $classified['excludedOps']);
        $this->assertTrue($classified['truncated']);
    }

    public function test_it_separates_proven_possible_and_excluded_operations_for_one_column(): void
    {
        $classified = (new FieldAccessClassifier)->classify([
            'metadata' => [
                'operations' => [
                    ['operation' => 'insert', 'fields' => ['title'], 'fieldCoverage' => 'complete'],
                    ['operation' => 'update', 'fields' => ['notes.title->en'], 'fieldCoverage' => 'complete'],
                    ['operation' => 'upsert', 'fields' => ['{dynamic}'], 'fieldCoverage' => 'complete'],
                    ['operation' => 'select', 'fields' => ['body'], 'fieldCoverage' => 'complete'],
                    ['operation' => 'save', 'fields' => [], 'fieldCoverage' => 'whole_row'],
                ],
            ],
        ], 'title', 'notes');

        $this->assertSame('proven', $classified['match']);
        $this->assertSame(['insert', 'save', 'update', 'upsert'], $classified['ops']);
        $this->assertSame(['insert', 'save', 'update'], $classified['provenOps']);
        $this->assertSame(['upsert'], $classified['possibleOps']);
        $this->assertSame(['select'], $classified['excludedOps']);
        $this->assertSame(['body', 'notes.title->en', 'title', '{dynamic}'], $classified['fields']);
    }

    public function test_missing_operation_evidence_remains_possible(): void
    {
        $this->assertSame([
            'match' => 'possible',
            'ops' => [],
            'fields' => [],
            'provenOps' => [],
            'possibleOps' => [],
            'excludedOps' => [],
        ], (new FieldAccessClassifier)->classify([], 'title', 'notes'));
    }

    public function test_legacy_classification_shape_does_not_gain_task_truncation_metadata(): void
    {
        $classified = (new FieldAccessClassifier)->classify([
            'metadata' => ['operations' => [[
                'operation' => 'select',
                'fields' => ['title'],
                'fieldCoverage' => 'complete',
            ]]],
        ], 'title', 'notes');

        $this->assertArrayNotHasKey('truncated', $classified);
        $this->assertSame('proven', $classified['match']);
    }
}
