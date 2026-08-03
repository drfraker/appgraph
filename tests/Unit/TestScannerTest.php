<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Scanners\TestScanner;
use AppGraph\Support\CallableSemanticRegistry;
use AppGraph\Support\FileFinder;
use AppGraph\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

class TestScannerTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = sys_get_temp_dir().'/appgraph-tests-'.bin2hex(random_bytes(4));
        mkdir($this->fixturePath.'/tests/Feature', 0775, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->fixturePath);
        parent::tearDown();
    }

    public function test_it_maps_named_and_literal_route_requests_from_tests(): void
    {
        file_put_contents($this->fixturePath.'/tests/Feature/SignNoteTest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedTests;

use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use PHPUnit\Framework\Attributes\Test;

class SignNoteTest extends LaravelTestCase
{
    public function test_named_route(): void
    {
        $this->post(route('progress-notes.sign', 1));
    }

    #[Test]
    public function literal_route(): void
    {
        $this->postJson('/progress-notes/1/sign');
    }
}
PHP);

        $graph = new Graph();
        $routeId = 'route:POST:/progress-notes/{note}/sign';
        $graph->addNode(Node::make($routeId, 'route', 'POST /progress-notes/{note}/sign', [
            'metadata' => [
                'name' => 'progress-notes.sign',
                'uri' => 'progress-notes/{note}/sign',
                'methods' => ['POST'],
            ],
        ]));

        (new TestScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $class = 'AppGraph\Tests\GeneratedTests\SignNoteTest';

        $this->assertGraphHasEdge($array, 'test:'.$class.'::test_named_route', $routeId, 'tests_route');
        $this->assertGraphHasEdge($array, 'test:'.$class.'::literal_route', $routeId, 'tests_route');
        $namedRoute = $this->graphEdge($array, 'test:'.$class.'::test_named_route', $routeId, 'tests_route');
        $this->assertSame('route', $namedRoute['metadata']['helper']);
        $this->assertSame('route', $namedRoute['metadata']['resolvedHelper']);
        $this->assertSame(13, $this->graphNode($array, 'test:'.$class.'::test_named_route')['endLine']);
        $this->assertSame(19, $this->graphNode($array, 'test:'.$class.'::literal_route')['endLine']);
    }

    public function test_it_maps_configured_callable_semantics_in_fluent_test_calls(): void
    {
        file_put_contents($this->fixturePath.'/tests/Feature/AppointmentTest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\ConfiguredRouteHelpers;

use Illuminate\Foundation\Testing\TestCase;

class AppointmentTest extends TestCase
{
    public function test_update(): void
    {
        $this->actingAs($staff)
            ->from(routeForTenant('appointments.index'))
            ->post(routeForTenant('appointments.update', $appointment))
            ->assertRedirect();
    }

    public function test_dynamic_route_name(): void
    {
        $this->post(routeForTenant($routeName));
    }

    public function test_named_route_name_argument(): void
    {
        $this->post(routeForTenant(routeName: 'appointments.update'));
    }
}
PHP);

        $graph = $this->graphWithNamedPostRoute('appointments.update');

        (new TestScanner(
            new FileFinder($this->fixturePath),
            callableSemantics: new CallableSemanticRegistry([
                $this->namedRouteFunctionSemantic('routeForTenant', 0),
            ]),
        ))->scan($graph);

        $array = $graph->toArray();
        $class = 'AppGraph\Tests\ConfiguredRouteHelpers\AppointmentTest';
        $routeId = 'route:POST:/appointments/{appointment}';
        $edge = $this->graphEdge($array, 'test:'.$class.'::test_update', $routeId, 'tests_route');

        $this->assertNotNull($edge);
        $this->assertSame(0.98, $edge['confidence']);
        $this->assertSame('appointments.update', $edge['metadata']['routeName']);
        $this->assertSame('routeForTenant', $edge['metadata']['helper']);
        $this->assertSame('routeForTenant', $edge['metadata']['resolvedHelper']);
        $this->assertSame('configured_callable_semantic', $edge['metadata']['inference']);
        $this->assertSame('named_route_url', $edge['metadata']['semantic']);
        $this->assertSame('function', $edge['metadata']['callableKind']);
        $this->assertSame(0, $edge['metadata']['routeNameArgument']);
        $this->assertNull($this->graphEdge(
            $array,
            'test:'.$class.'::test_dynamic_route_name',
            $routeId,
            'tests_route',
        ));
        $this->assertNull($this->graphEdge(
            $array,
            'test:'.$class.'::test_named_route_name_argument',
            $routeId,
            'tests_route',
        ));
    }

    public function test_it_uses_exact_function_resolution_for_configured_route_helpers(): void
    {
        file_put_contents($this->fixturePath.'/tests/Feature/ConfiguredRouteHelperResolutionTest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\ConfiguredRouteHelperResolution;

use PHPUnit\Framework\TestCase;
use function routeForTenant as tenantRoute;
use function Trusted\tenantRoute as trustedRoute;
use function Vendor\Helpers\routeForTenant as vendorRoute;

function routeForTenant(string $name): string
{
    return $name;
}

class ConfiguredRouteHelperResolutionTest extends TestCase
{
    public function test_namespaced_shadow(): void
    {
        routeForTenant('appointments.update');
    }

    public function test_explicit_global(): void
    {
        \routeForTenant('appointments.update');
    }

    public function test_global_import_alias(): void
    {
        tenantRoute('appointments.update');
    }

    public function test_configured_fqn_alias(): void
    {
        trustedRoute('tenant', 'appointments.update');
    }

    public function test_unconfigured_import(): void
    {
        vendorRoute('appointments.update');
    }
}
PHP);

        $graph = $this->graphWithNamedPostRoute('appointments.update');

        (new TestScanner(
            new FileFinder($this->fixturePath),
            callableSemantics: new CallableSemanticRegistry([
                $this->namedRouteFunctionSemantic('routeForTenant', 0),
                $this->namedRouteFunctionSemantic('Trusted\\tenantRoute', 1),
            ]),
        ))->scan($graph);

        $array = $graph->toArray();
        $testPrefix = 'test:AppGraph\\Tests\\ConfiguredRouteHelperResolution\\'
            .'ConfiguredRouteHelperResolutionTest::';
        $routeId = 'route:POST:/appointments/{appointment}';

        $this->assertNull($this->graphEdge(
            $array,
            $testPrefix.'test_namespaced_shadow',
            $routeId,
            'tests_route',
        ));
        $this->assertGraphHasEdge(
            $array,
            $testPrefix.'test_explicit_global',
            $routeId,
            'tests_route',
        );

        $globalAlias = $this->graphEdge(
            $array,
            $testPrefix.'test_global_import_alias',
            $routeId,
            'tests_route',
        );
        $this->assertNotNull($globalAlias);
        $this->assertSame('tenantRoute', $globalAlias['metadata']['helper']);
        $this->assertSame('routeForTenant', $globalAlias['metadata']['resolvedHelper']);

        $fqnAlias = $this->graphEdge(
            $array,
            $testPrefix.'test_configured_fqn_alias',
            $routeId,
            'tests_route',
        );
        $this->assertNotNull($fqnAlias);
        $this->assertSame('trustedRoute', $fqnAlias['metadata']['helper']);
        $this->assertSame('Trusted\\tenantRoute', $fqnAlias['metadata']['resolvedHelper']);
        $this->assertSame(1, $fqnAlias['metadata']['routeNameArgument']);
        $this->assertNull($this->graphEdge(
            $array,
            $testPrefix.'test_unconfigured_import',
            $routeId,
            'tests_route',
        ));
    }

    public function test_it_ignores_invalid_helper_configuration_and_preserves_the_builtin_route_helper(): void
    {
        file_put_contents($this->fixturePath.'/tests/Feature/InvalidRouteHelperConfigurationTest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\InvalidRouteHelperConfiguration;

use PHPUnit\Framework\TestCase;

class InvalidRouteHelperConfigurationTest extends TestCase
{
    public function test_builtin_route(): void
    {
        route('appointments.update', 'not-the-route-name');
    }

    public function test_negative_position(): void
    {
        negativeRoute('appointments.update');
    }

    public function test_string_position(): void
    {
        stringRoute('appointments.update');
    }
}
PHP);

        $graph = $this->graphWithNamedPostRoute('appointments.update');

        (new TestScanner(
            new FileFinder($this->fixturePath),
            callableSemantics: new CallableSemanticRegistry([
                $this->namedRouteFunctionSemantic('route', 1),
                $this->namedRouteFunctionSemantic('negativeRoute', -1),
                $this->namedRouteFunctionSemantic('stringRoute', '0'),
                $this->namedRouteFunctionSemantic('not a function', 0),
            ]),
        ))->scan($graph);

        $array = $graph->toArray();
        $testPrefix = 'test:AppGraph\\Tests\\InvalidRouteHelperConfiguration\\'
            .'InvalidRouteHelperConfigurationTest::';
        $routeId = 'route:POST:/appointments/{appointment}';
        $builtin = $this->graphEdge(
            $array,
            $testPrefix.'test_builtin_route',
            $routeId,
            'tests_route',
        );

        $this->assertNotNull($builtin);
        $this->assertSame(1.0, $builtin['confidence']);
        $this->assertSame('route', $builtin['metadata']['resolvedHelper']);
        $this->assertArrayNotHasKey('inference', $builtin['metadata']);
        $this->assertArrayNotHasKey('routeNameArgument', $builtin['metadata']);
        $this->assertNull($this->graphEdge(
            $array,
            $testPrefix.'test_negative_position',
            $routeId,
            'tests_route',
        ));
        $this->assertNull($this->graphEdge(
            $array,
            $testPrefix.'test_string_position',
            $routeId,
            'tests_route',
        ));
    }

    public function test_the_service_provider_injects_callable_semantics_into_the_test_scanner(): void
    {
        file_put_contents($this->fixturePath.'/tests/Feature/ConfiguredRouteHelperTest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\ConfiguredRouteHelperBinding;

use Illuminate\Foundation\Testing\TestCase;

class ConfiguredRouteHelperTest extends TestCase
{
    public function test_update(): void
    {
        $this->post(routeForTenant('appointments.update'));
    }
}
PHP);

        $graph = $this->graphWithNamedPostRoute('appointments.update');
        config()->set('appgraph.extensions.callables', [
            $this->namedRouteFunctionSemantic('routeForTenant', 0),
        ]);
        app()->instance(FileFinder::class, new FileFinder($this->fixturePath));
        app()->forgetInstance(CallableSemanticRegistry::class);
        app()->forgetInstance(TestScanner::class);

        app(TestScanner::class)->scan($graph);

        $edge = $this->graphEdge(
            $graph->toArray(),
            'test:AppGraph\Tests\ConfiguredRouteHelperBinding\ConfiguredRouteHelperTest::test_update',
            'route:POST:/appointments/{appointment}',
            'tests_route',
        );

        $this->assertNotNull($edge);
        $this->assertSame('configured_callable_semantic', $edge['metadata']['inference']);
    }

    public function test_it_uses_hosts_for_literal_requests_and_marks_hostless_domain_matches_as_ambiguous(): void
    {
        file_put_contents($this->fixturePath.'/tests/Feature/DomainRouteTest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedTests;

use Illuminate\Foundation\Testing\TestCase;

class DomainRouteTest extends TestCase
{
    public function test_absolute_domain(): void
    {
        $this->postJson('https://API.EXAMPLE.COM/shared/1');
    }

    public function test_relative_domains(): void
    {
        $this->postJson('/shared/2');
    }

    public function test_named_domain(): void
    {
        $this->post(route('admin.shared'));
    }

    public function test_single_domain_without_host(): void
    {
        $this->postJson('/tenant-only/1');
    }

    public function test_optional_omitted(): void
    {
        $this->getJson('/reports');
    }

    public function test_optional_present(): void
    {
        $this->getJson('/reports/2026');
    }
}
PHP);

        $graph = new Graph();
        $apiRouteId = 'route:POST://api.example.com/shared/{id}';
        $adminRouteId = 'route:POST://admin.example.com/shared/{id}';
        $tenantOnlyRouteId = 'route:POST://tenant.example.com/tenant-only/{id}';
        $optionalRouteId = 'route:GET:/reports/{year?}';
        $graph->addNode(Node::make($apiRouteId, 'route', 'POST api.example.com/shared/{id}', [
            'metadata' => [
                'name' => 'api.shared',
                'uri' => 'shared/{id}',
                'methods' => ['POST'],
                'domain' => 'api.example.com',
            ],
        ]));
        $graph->addNode(Node::make($adminRouteId, 'route', 'POST admin.example.com/shared/{id}', [
            'metadata' => [
                'name' => 'admin.shared',
                'uri' => 'shared/{id}',
                'methods' => ['POST'],
                'domain' => 'admin.example.com',
            ],
        ]));
        $graph->addNode(Node::make($tenantOnlyRouteId, 'route', 'POST tenant.example.com/tenant-only/{id}', [
            'metadata' => [
                'name' => 'tenant.only',
                'uri' => 'tenant-only/{id}',
                'methods' => ['POST'],
                'domain' => 'tenant.example.com',
            ],
        ]));
        $graph->addNode(Node::make($optionalRouteId, 'route', 'GET /reports/{year?}', [
            'metadata' => [
                'name' => 'reports.index',
                'uri' => 'reports/{year?}',
                'methods' => ['GET'],
            ],
        ]));

        (new TestScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $class = 'AppGraph\Tests\GeneratedTests\DomainRouteTest';
        $absoluteId = 'test:'.$class.'::test_absolute_domain';
        $relativeId = 'test:'.$class.'::test_relative_domains';
        $namedId = 'test:'.$class.'::test_named_domain';
        $singleDomainId = 'test:'.$class.'::test_single_domain_without_host';

        $absolute = $this->graphEdge($array, $absoluteId, $apiRouteId, 'tests_route');
        $this->assertNotNull($absolute);
        $this->assertNull($this->graphEdge($array, $absoluteId, $adminRouteId, 'tests_route'));
        $this->assertSame(0.9, $absolute['confidence']);
        $this->assertSame('exact', $absolute['metadata']['matchCertainty']);
        $this->assertSame('api.example.com', $absolute['metadata']['requestedHost']);

        foreach ([$apiRouteId, $adminRouteId] as $routeId) {
            $relative = $this->graphEdge($array, $relativeId, $routeId, 'tests_route');
            $this->assertNotNull($relative);
            $this->assertSame(0.55, $relative['confidence']);
            $this->assertSame('possible', $relative['metadata']['matchCertainty']);
            $this->assertSame('request_host_unavailable', $relative['metadata']['ambiguity']);
            $this->assertSame(2, $relative['metadata']['candidateCount']);
        }

        $named = $this->graphEdge($array, $namedId, $adminRouteId, 'tests_route');
        $this->assertNotNull($named);
        $this->assertNull($this->graphEdge($array, $namedId, $apiRouteId, 'tests_route'));
        $this->assertSame(1.0, $named['confidence']);
        $this->assertSame('exact', $named['metadata']['matchCertainty']);

        $singleDomain = $this->graphEdge($array, $singleDomainId, $tenantOnlyRouteId, 'tests_route');
        $this->assertNotNull($singleDomain);
        $this->assertSame(0.55, $singleDomain['confidence']);
        $this->assertSame('possible', $singleDomain['metadata']['matchCertainty']);
        $this->assertSame('request_host_unavailable', $singleDomain['metadata']['ambiguity']);
        $this->assertSame(1, $singleDomain['metadata']['candidateCount']);

        foreach (['test_optional_omitted', 'test_optional_present'] as $method) {
            $optional = $this->graphEdge($array, 'test:'.$class.'::'.$method, $optionalRouteId, 'tests_route');
            $this->assertNotNull($optional);
            $this->assertSame(0.9, $optional['confidence']);
            $this->assertSame('exact', $optional['metadata']['matchCertainty']);
        }
    }

    public function test_it_requires_proven_http_receivers_and_exact_unshadowed_route_helpers(): void
    {
        file_put_contents($this->fixturePath.'/tests/Feature/FalsePositiveTest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\ReceiverProof;

use PHPUnit\Framework\TestCase;

class FalsePositiveTest extends TestCase
{
    public function test_arbitrary_this_post(): void
    {
        $this->post('/safe');
    }

    public function test_arbitrary_client(): void
    {
        $client->post('/safe');
    }

    public function test_route_for_tenant(): void
    {
        routeForTenant('safe');
    }
}
PHP);
        file_put_contents($this->fixturePath.'/tests/Feature/TraitHttpTest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\ReceiverProof;

use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;
use PHPUnit\Framework\TestCase;

trait SendsHttpRequests
{
    use MakesHttpRequests;
}

class TraitHttpTest extends TestCase
{
    use SendsHttpRequests;

    public function test_proven_trait_receiver(): void
    {
        $this->post('/safe');
    }
}
PHP);
        file_put_contents($this->fixturePath.'/tests/Feature/ShadowedRouteTest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\LocalRouteShadow;

use PHPUnit\Framework\TestCase;

function route(string $name): string
{
    return $name;
}

class ShadowedRouteTest extends TestCase
{
    public function test_shadowed_route(): void
    {
        route('safe');
    }

    public function test_explicit_global_route(): void
    {
        \route('safe');
    }
}
PHP);
        file_put_contents($this->fixturePath.'/tests/Feature/ImportedRouteTest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\ImportedRouteShadow;

use PHPUnit\Framework\TestCase;
use function Vendor\Helpers\route;

class ImportedRouteTest extends TestCase
{
    public function test_imported_route(): void
    {
        route('safe');
    }
}
PHP);
        file_put_contents($this->fixturePath.'/tests/Feature/InheritedHttpTest.php', <<<'PHP'
<?php

namespace AppGraph\Tests\ReceiverProof;

class InheritedHttpTest extends ProjectTestCase
{
    public function test_inherited_receiver(): void
    {
        $this->post('/safe');
    }
}
PHP);
        file_put_contents($this->fixturePath.'/tests/Feature/ProjectTestCase.php', <<<'PHP'
<?php

namespace AppGraph\Tests\ReceiverProof;

use Illuminate\Foundation\Testing\TestCase;

abstract class ProjectTestCase extends TestCase
{
}
PHP);

        $graph = new Graph();
        $routeId = 'route:POST:/safe';
        $graph->addNode(Node::make($routeId, 'route', 'POST /safe', [
            'metadata' => [
                'name' => 'safe',
                'uri' => 'safe',
                'methods' => ['POST'],
            ],
        ]));

        (new TestScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $receiverNamespace = 'AppGraph\Tests\ReceiverProof\\';

        $proven = $this->graphEdge(
            $array,
            'test:'.$receiverNamespace.'TraitHttpTest::test_proven_trait_receiver',
            $routeId,
            'tests_route',
        );
        $this->assertNotNull($proven);
        $this->assertTrue($proven['metadata']['receiverProven']);
        $this->assertGraphHasEdge(
            $array,
            'test:'.$receiverNamespace.'InheritedHttpTest::test_inherited_receiver',
            $routeId,
            'tests_route',
        );

        foreach ([
            'test:'.$receiverNamespace.'FalsePositiveTest::test_arbitrary_this_post',
            'test:'.$receiverNamespace.'FalsePositiveTest::test_arbitrary_client',
            'test:'.$receiverNamespace.'FalsePositiveTest::test_route_for_tenant',
            'test:AppGraph\Tests\LocalRouteShadow\ShadowedRouteTest::test_shadowed_route',
            'test:AppGraph\Tests\ImportedRouteShadow\ImportedRouteTest::test_imported_route',
        ] as $testId) {
            $this->assertNull($this->graphEdge($array, $testId, $routeId, 'tests_route'));
        }

        $globalRoute = $this->graphEdge(
            $array,
            'test:AppGraph\Tests\LocalRouteShadow\ShadowedRouteTest::test_explicit_global_route',
            $routeId,
            'tests_route',
        );
        $this->assertNotNull($globalRoute);
        $this->assertSame('route', $globalRoute['metadata']['resolvedHelper']);
    }

    private function graphWithNamedPostRoute(string $name): Graph
    {
        $graph = new Graph();
        $graph->addNode(Node::make(
            'route:POST:/appointments/{appointment}',
            'route',
            'POST /appointments/{appointment}',
            [
                'metadata' => [
                    'name' => $name,
                    'uri' => 'appointments/{appointment}',
                    'methods' => ['POST'],
                ],
            ],
        ));

        return $graph;
    }

    /**
     * @return array{
     *     match: array{kind: string, name: string},
     *     semantic: array{kind: string, arguments: array{route_name: mixed}}
     * }
     */
    private function namedRouteFunctionSemantic(string $name, mixed $routeNameArgument): array
    {
        return [
            'match' => [
                'kind' => 'function',
                'name' => $name,
            ],
            'semantic' => [
                'kind' => 'named_route_url',
                'arguments' => [
                    'route_name' => $routeNameArgument,
                ],
            ],
        ];
    }
}
