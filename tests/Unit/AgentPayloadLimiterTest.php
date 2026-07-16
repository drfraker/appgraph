<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Support\AgentPayloadLimiter;
use PHPUnit\Framework\TestCase;

class AgentPayloadLimiterTest extends TestCase
{
    public function test_small_payloads_are_not_changed(): void
    {
        $payload = [
            'query' => 'node',
            'results' => [['id' => 'App\\Service::run']],
        ];

        $this->assertSame($payload, AgentPayloadLimiter::limit($payload));
    }

    public function test_large_nested_payloads_have_a_hard_utf8_safe_byte_ceiling(): void
    {
        $payload = [
            'query' => 'writes-to',
            'generation' => ['id' => '12', 'current' => true],
            'counts' => ['total' => 80],
            'results' => array_fill(0, 80, [
                'id' => str_repeat('identifier-', 500),
                'fields' => array_fill(0, 20, str_repeat('field🙂', 1000)),
                'summary' => str_repeat('descriptive text 🙂 ', 1000),
            ]),
        ];

        $bounded = AgentPayloadLimiter::limit($payload);
        $json = json_encode($bounded, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertLessThanOrEqual(AgentPayloadLimiter::MAX_BYTES, strlen($json));
        $this->assertTrue($bounded['responseTruncated']);
        $this->assertSame('writes-to', $bounded['query']);
        $this->assertSame(80, $bounded['counts']['total']);
        $this->assertSame('12', $bounded['generation']['id']);
        $this->assertGreaterThan(0, $bounded['responseBounds']['omittedValues']);
        $this->assertGreaterThan(0, $bounded['responseBounds']['truncatedStrings']);
        $this->assertSame(1, preg_match('//u', $json));
    }

    public function test_pretty_encoding_falls_back_to_compact_json_when_whitespace_crosses_the_ceiling(): void
    {
        $payload = [
            'query' => 'search',
            'results' => array_fill(0, 45000, ['id' => 'x']),
        ];

        $compact = AgentPayloadLimiter::encode($payload);
        $pretty = AgentPayloadLimiter::encode($payload, true);

        $this->assertLessThanOrEqual(AgentPayloadLimiter::MAX_BYTES, strlen($pretty));
        $this->assertSame($compact, $pretty);
        $this->assertSame(
            json_decode($compact, true, 512, JSON_THROW_ON_ERROR),
            json_decode($pretty, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function test_oversized_identity_rows_are_omitted_instead_of_returning_invented_ids(): void
    {
        $id = str_repeat('🙂', 4096);
        $payload = [
            'query' => 'search',
            'results' => array_fill(0, 200, [
                'id' => $id,
                'type' => 'route',
            ]),
        ];

        $bounded = AgentPayloadLimiter::limit($payload);
        $ids = array_column($bounded['results'], 'id');

        $this->assertTrue($bounded['responseTruncated']);
        $this->assertNotEmpty($ids);
        $this->assertLessThan(200, count($ids));
        $this->assertSame([$id], array_values(array_unique($ids)));
        $this->assertSame(0, $bounded['responseBounds']['truncatedStrings']);
        $this->assertLessThanOrEqual(
            AgentPayloadLimiter::MAX_BYTES,
            strlen(json_encode($bounded, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        );
    }

    public function test_diff_identity_and_path_seed_values_are_never_shortened(): void
    {
        $identity = str_repeat('node-identity-', 1200);
        $seed = str_repeat('path-seed-', 1600);
        $changedField = '/metadata/'.str_repeat('field-', 1600);
        $payload = [
            'query' => 'diff',
            'changes' => array_fill(0, 100, [
                'identity' => $identity,
                'path' => ['seed' => $seed, 'to' => $identity],
                'changedFields' => [$changedField],
                'summary' => str_repeat('descriptive text ', 1000),
            ]),
        ];

        $bounded = AgentPayloadLimiter::limit($payload);

        $this->assertTrue($bounded['responseTruncated']);
        $this->assertNotEmpty($bounded['changes']);
        $this->assertLessThan(100, count($bounded['changes']));

        foreach ($bounded['changes'] as $change) {
            $this->assertSame($identity, $change['identity']);
            $this->assertSame($seed, $change['path']['seed']);
            $this->assertSame($identity, $change['path']['to']);
            $this->assertSame([$changedField], $change['changedFields']);
        }
    }

    public function test_edge_identity_rows_are_omitted_instead_of_partially_returned_at_the_budget_boundary(): void
    {
        $identity = [
            'from' => 'node:from',
            'type' => 'calls',
            'to' => 'node:to',
        ];
        $payload = [
            'query' => 'diff',
            'changes' => array_fill(0, 30000, ['identity' => $identity]),
        ];

        $bounded = AgentPayloadLimiter::limit($payload);

        $this->assertTrue($bounded['responseTruncated']);
        $this->assertNotEmpty($bounded['changes']);
        $this->assertLessThan(30000, count($bounded['changes']));
        $partialRows = array_values(array_filter(
            $bounded['changes'],
            static fn (array $change): bool => ($change['identity'] ?? null) !== $identity,
        ));

        $this->assertSame([], $partialRows);
    }

    public function test_warning_parent_class_references_are_never_shortened(): void
    {
        $parent = 'Vendor\\Package\\'.str_repeat('LongParentNamespace\\', 500).'BaseRecord';
        $payload = [
            'query' => 'verify-change',
            'scannerWarnings' => [
                'current' => array_fill(0, 100, [
                    'parent' => $parent,
                    'message' => str_repeat('diagnostic ', 1000),
                ]),
            ],
        ];

        $bounded = AgentPayloadLimiter::limit($payload);
        $warnings = $bounded['scannerWarnings']['current'] ?? [];

        $this->assertTrue($bounded['responseTruncated']);
        $this->assertNotEmpty($warnings);
        $this->assertLessThan(100, count($warnings));

        foreach ($warnings as $warning) {
            $this->assertSame($parent, $warning['parent']);
        }
    }

    public function test_staleness_and_verification_path_collections_are_exact_or_omitted(): void
    {
        $path = 'app/'.str_repeat('deep-directory/', 600).'Changed.php';

        foreach ([
            ['overview', 'staleness', 'samplePaths'],
            ['verify-change', 'verification', 'unmappedChangedFiles'],
        ] as [$query, $container, $key]) {
            $payload = [
                'query' => $query,
                $container => [
                    $key => array_fill(0, 200, $path),
                ],
            ];
            $bounded = AgentPayloadLimiter::limit($payload);
            $paths = $bounded[$container][$key] ?? [];

            $this->assertTrue($bounded['responseTruncated']);
            $this->assertLessThan(200, count($paths));

            foreach ($paths as $returnedPath) {
                $this->assertSame($path, $returnedPath);
            }
        }
    }

    public function test_laravel_runtime_and_declaration_references_are_exact_fields(): void
    {
        $keys = [
            'abstract',
            'alias',
            'aliasTarget',
            'bindingAbstract',
            'bindingConcrete',
            'bindingConsumer',
            'concrete',
            'consumer',
            'controller',
            'controllerMethod',
            'declaredOn',
            'declaringClass',
            'foreignTable',
            'job',
            'middleware',
            'parameter',
            'parent',
            'pivotTable',
            'queue',
            'reference',
            'relationship',
            'requestedController',
            'requestedRequest',
            'routeName',
            'runtimeController',
            'runtimeRequest',
            'scanner',
            'targetTable',
            'trait',
            'uri',
        ];

        foreach ($keys as $key) {
            $this->assertTrue(
                AgentPayloadLimiter::isExactStringKey($key),
                "Expected {$key} to be protected by exact-or-omit response handling.",
            );
        }

        foreach (['ambiguousLogicalTableSamples', 'foreignColumns', 'middleware', 'relationships', 'routeParameters', 'samplePaths', 'traits', 'unmappedChangedFiles'] as $key) {
            $this->assertTrue(
                AgentPayloadLimiter::isExactStringCollectionKey($key),
                "Expected {$key} values to be protected by exact-or-omit response handling.",
            );
        }
    }
}
