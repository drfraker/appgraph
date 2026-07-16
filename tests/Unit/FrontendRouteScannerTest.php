<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Scanners\FrontendRouteScanner;
use AppGraph\Support\FileFinder;
use AppGraph\Support\SourceFileObservations;
use AppGraph\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

class FrontendRouteScannerTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = sys_get_temp_dir().'/appgraph-frontend-routes-'.bin2hex(random_bytes(4));
        mkdir($this->fixturePath.'/resources/js/Pages', 0775, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->fixturePath);
        parent::tearDown();
    }

    public function test_it_maps_ziggy_named_route_consumers(): void
    {
        file_put_contents($this->fixturePath.'/resources/js/Pages/SignNote.vue', <<<'VUE'
<script setup>
import axios from 'axios'

axios.post(route('progress-notes.sign', { note: 1 }))
axios.post('/progress-notes/1/sign')
route('missing.route')
</script>
VUE);

        $graph = new Graph();
        $graph->addNode(Node::make('route:POST:/progress-notes/{note}/sign', 'route', 'POST /progress-notes/{note}/sign', [
            'metadata' => [
                'name' => 'progress-notes.sign',
                'uri' => 'progress-notes/{note}/sign',
                'methods' => ['POST'],
            ],
        ]));

        $observations = new SourceFileObservations();
        (new FrontendRouteScanner(new FileFinder($this->fixturePath), $observations))->scan($graph);
        $array = $graph->toArray();

        $this->assertGraphHasNode($array, 'frontend:resources/js/Pages/SignNote.vue', 'frontend');
        $this->assertGraphHasEdge($array, 'frontend:resources/js/Pages/SignNote.vue', 'route:POST:/progress-notes/{note}/sign', 'consumes_route');
        $edge = $this->graphEdge($array, 'frontend:resources/js/Pages/SignNote.vue', 'route:POST:/progress-notes/{note}/sign', 'consumes_route');
        $this->assertSame('axios_literal_url', $edge['metadata']['syntax']);
        $this->assertSame(1, $array['meta']['analysis']['frontendRoutes']['unresolvedCount']);
        $this->assertSame([
            hash('sha256', (string) file_get_contents($this->fixturePath.'/resources/js/Pages/SignNote.vue')),
        ], $observations->hashes()[$this->fixturePath.'/resources/js/Pages/SignNote.vue']);
    }

    public function test_it_uses_hosts_for_literal_urls_and_marks_hostless_domain_matches_as_ambiguous(): void
    {
        file_put_contents($this->fixturePath.'/resources/js/Pages/Absolute.vue', <<<'VUE'
<script setup>
import axios from 'axios'

axios.post('https://API.EXAMPLE.COM/shared/1')
</script>
VUE);
        file_put_contents($this->fixturePath.'/resources/js/Pages/Relative.vue', <<<'VUE'
<script setup>
import axios from 'axios'

axios.post('/shared/2')
</script>
VUE);
        file_put_contents($this->fixturePath.'/resources/js/Pages/Named.vue', <<<'VUE'
<script setup>
route('admin.shared')
</script>
VUE);
        file_put_contents($this->fixturePath.'/resources/js/Pages/SingleDomain.vue', <<<'VUE'
<script setup>
import axios from 'axios'

axios.post('/tenant-only/1')
</script>
VUE);
        file_put_contents($this->fixturePath.'/resources/js/Pages/OptionalOmitted.vue', <<<'VUE'
<script setup>
import axios from 'axios'

axios.get('/reports')
</script>
VUE);
        file_put_contents($this->fixturePath.'/resources/js/Pages/OptionalPresent.vue', <<<'VUE'
<script setup>
import axios from 'axios'

axios.get('/reports/2026')
</script>
VUE);

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

        (new FrontendRouteScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $absoluteId = 'frontend:resources/js/Pages/Absolute.vue';
        $relativeId = 'frontend:resources/js/Pages/Relative.vue';
        $namedId = 'frontend:resources/js/Pages/Named.vue';

        $absolute = $this->graphEdge($array, $absoluteId, $apiRouteId, 'consumes_route');
        $this->assertNotNull($absolute);
        $this->assertNull($this->graphEdge($array, $absoluteId, $adminRouteId, 'consumes_route'));
        $this->assertSame(0.9, $absolute['confidence']);
        $this->assertSame('exact', $absolute['metadata']['matchCertainty']);
        $this->assertSame('api.example.com', $absolute['metadata']['requestedHost']);

        foreach ([$apiRouteId, $adminRouteId] as $routeId) {
            $relative = $this->graphEdge($array, $relativeId, $routeId, 'consumes_route');
            $this->assertNotNull($relative);
            $this->assertSame(0.55, $relative['confidence']);
            $this->assertSame('possible', $relative['metadata']['matchCertainty']);
            $this->assertSame('request_host_unavailable', $relative['metadata']['ambiguity']);
            $this->assertSame(2, $relative['metadata']['candidateCount']);
        }

        $named = $this->graphEdge($array, $namedId, $adminRouteId, 'consumes_route');
        $this->assertNotNull($named);
        $this->assertNull($this->graphEdge($array, $namedId, $apiRouteId, 'consumes_route'));
        $this->assertSame(0.98, $named['confidence']);
        $this->assertSame('exact', $named['metadata']['matchCertainty']);

        $singleDomain = $this->graphEdge(
            $array,
            'frontend:resources/js/Pages/SingleDomain.vue',
            $tenantOnlyRouteId,
            'consumes_route',
        );
        $this->assertNotNull($singleDomain);
        $this->assertSame(0.55, $singleDomain['confidence']);
        $this->assertSame('possible', $singleDomain['metadata']['matchCertainty']);
        $this->assertSame('request_host_unavailable', $singleDomain['metadata']['ambiguity']);
        $this->assertSame(1, $singleDomain['metadata']['candidateCount']);

        foreach (['OptionalOmitted.vue', 'OptionalPresent.vue'] as $file) {
            $optional = $this->graphEdge(
                $array,
                'frontend:resources/js/Pages/'.$file,
                $optionalRouteId,
                'consumes_route',
            );
            $this->assertNotNull($optional);
            $this->assertSame(0.9, $optional['confidence']);
            $this->assertSame('exact', $optional['metadata']['matchCertainty']);
        }
    }
}
