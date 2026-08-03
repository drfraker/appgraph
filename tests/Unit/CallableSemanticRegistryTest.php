<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Support\CallableSemanticRegistry;
use PHPUnit\Framework\TestCase;

class CallableSemanticRegistryTest extends TestCase
{
    public function test_it_indexes_exact_function_semantics_case_insensitively(): void
    {
        $registry = new CallableSemanticRegistry([
            $this->functionRule('\\App\\Support\\TenantRoute', 'named_route_url', [
                'route_name' => 1,
            ]),
        ]);

        $rule = $registry->function('app\\support\\tenantroute', 'NAMED_ROUTE_URL');

        $this->assertNotNull($rule);
        $this->assertSame('App\\Support\\TenantRoute', $rule['match']['name']);
        $this->assertSame(1, $rule['semantic']['arguments']['route_name']);
    }

    public function test_invalid_rules_do_not_consume_the_bounded_valid_rule_capacity(): void
    {
        $rules = array_fill(0, 64, ['match' => 'invalid']);
        $rules[] = $this->functionRule('routeForTenant', 'named_route_url', [
            'route_name' => 0,
        ]);

        $registry = new CallableSemanticRegistry($rules);

        $this->assertNotNull($registry->function('routeForTenant', 'named_route_url'));
    }

    public function test_it_ignores_unsupported_match_kinds_and_invalid_arguments(): void
    {
        $registry = new CallableSemanticRegistry([
            [
                'match' => [
                    'kind' => 'method',
                    'receiver' => 'App\\Support\\TenantRouter',
                    'name' => 'route',
                ],
                'semantic' => [
                    'kind' => 'named_route_url',
                    'arguments' => ['route_name' => 0],
                ],
            ],
            $this->functionRule('negativeRoute', 'named_route_url', [
                'route_name' => -1,
            ]),
            $this->functionRule('stringRoute', 'named_route_url', [
                'route_name' => '0',
            ]),
            $this->functionRule('eventHelper', 'dispatches_event', [
                'event' => 0,
            ]),
            $this->functionRule('missingRouteName', 'named_route_url', [
                'url' => 0,
            ]),
        ]);

        $this->assertNull($registry->function('route', 'named_route_url'));
        $this->assertNull($registry->function('negativeRoute', 'named_route_url'));
        $this->assertNull($registry->function('stringRoute', 'named_route_url'));
        $this->assertNull($registry->function('eventHelper', 'dispatches_event'));
        $this->assertNull($registry->function('missingRouteName', 'named_route_url'));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function functionRule(string $name, string $semantic, array $arguments): array
    {
        return [
            'match' => [
                'kind' => 'function',
                'name' => $name,
            ],
            'semantic' => [
                'kind' => $semantic,
                'arguments' => $arguments,
            ],
        ];
    }
}
