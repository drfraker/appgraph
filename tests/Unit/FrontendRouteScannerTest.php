<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Scanners\FrontendRouteScanner;
use AppGraph\Support\FileFinder;
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

        (new FrontendRouteScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();

        $this->assertGraphHasNode($array, 'frontend:resources/js/Pages/SignNote.vue', 'frontend');
        $this->assertGraphHasEdge($array, 'frontend:resources/js/Pages/SignNote.vue', 'route:POST:/progress-notes/{note}/sign', 'consumes_route');
        $edge = $this->graphEdge($array, 'frontend:resources/js/Pages/SignNote.vue', 'route:POST:/progress-notes/{note}/sign', 'consumes_route');
        $this->assertSame('axios_literal_url', $edge['metadata']['syntax']);
        $this->assertSame(1, $array['meta']['analysis']['frontendRoutes']['unresolvedCount']);
    }
}
