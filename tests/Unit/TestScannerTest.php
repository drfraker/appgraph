<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Scanners\TestScanner;
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

use PHPUnit\Framework\Attributes\Test;

class SignNoteTest
{
    public function test_named_route(): void
    {
        $this->post(routeForTenant('progress-notes.sign', 1));
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
        $this->assertStringContainsString('routeForTenant', $namedRoute['metadata']['helper']);
        $this->assertSame(12, $this->graphNode($array, 'test:'.$class.'::test_named_route')['endLine']);
        $this->assertSame(18, $this->graphNode($array, 'test:'.$class.'::literal_route')['endLine']);
    }
}
