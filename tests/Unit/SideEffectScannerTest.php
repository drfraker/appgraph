<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Scanners\DataFlowScanner;
use AppGraph\Scanners\SideEffectScanner;
use AppGraph\Support\FileFinder;
use AppGraph\Support\PhpFileFacts;
use AppGraph\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

class SideEffectScannerTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = sys_get_temp_dir().'/appgraph-side-effects-'.bin2hex(random_bytes(4));
        mkdir($this->fixturePath.'/app/Services', 0775, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->fixturePath);
        parent::tearDown();
    }

    public function test_it_maps_cache_filesystem_and_http_side_effects(): void
    {
        file_put_contents($this->fixturePath.'/app/Services/NoteSigner.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedSideEffects;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class NoteSigner
{
    public function sign(string $key): void
    {
        Cache::remember('note:1', 60, fn () => null);
        Cache::forget($key);
        cache(['unfinished-visit' => true]);
        Storage::disk('s3')->put('notes/1.pdf', 'signed');
        Http::timeout(3)->post('https://billing.example.test/sync', []);
    }
}
PHP);

        $graph = new Graph();
        (new SideEffectScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $method = 'AppGraph\Tests\GeneratedSideEffects\NoteSigner::sign';

        $this->assertGraphHasEdge($array, $method, 'side-effect:cache:default:note:1', 'reads_cache');
        $this->assertGraphHasEdge($array, $method, 'side-effect:cache:default:note:1', 'writes_cache');
        $this->assertGraphHasEdge($array, $method, 'side-effect:cache:default:{dynamic}', 'writes_cache');
        $this->assertGraphHasEdge($array, $method, 'side-effect:cache:default:unfinished-visit', 'writes_cache');
        $this->assertGraphHasEdge($array, $method, 'side-effect:filesystem:s3:notes/1.pdf', 'writes_filesystem');
        $this->assertGraphHasEdge($array, $method, 'side-effect:external_service:http:https://billing.example.test/sync', 'calls_external');

        $dynamic = $this->graphEdge($array, $method, 'side-effect:cache:default:{dynamic}', 'writes_cache');
        $this->assertSame(0.45, $dynamic['confidence']);
    }

    public function test_different_scanners_share_one_in_memory_php_parse(): void
    {
        file_put_contents($this->fixturePath.'/app/Services/SharedFactsService.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedSideEffects;

use Illuminate\Support\Facades\Cache;

class SharedFactsService
{
    public function load(): mixed
    {
        return Cache::get('shared-facts');
    }
}
PHP);

        $files = new FileFinder($this->fixturePath);
        $facts = new PhpFileFacts();
        (new DataFlowScanner($files, $facts))->scan(new Graph());
        (new SideEffectScanner($files, $facts))->scan(new Graph());

        $stats = $facts->stats();

        $this->assertSame(1, $stats['counters']['parses']);
        $this->assertGreaterThan(0, $stats['counters']['memoryHits']);
    }

    public function test_shared_parse_failures_keep_each_scanners_warning_identity(): void
    {
        file_put_contents(
            $this->fixturePath.'/app/Services/BrokenService.php',
            '<?php namespace AppGraph\\Tests\\GeneratedSideEffects; class BrokenService {',
        );

        $files = new FileFinder($this->fixturePath);
        $facts = new PhpFileFacts();
        $dataFlowGraph = new Graph();
        $sideEffectGraph = new Graph();
        (new DataFlowScanner($files, $facts))->scan($dataFlowGraph);
        (new SideEffectScanner($files, $facts))->scan($sideEffectGraph);

        $dataFlowScanners = array_values(array_unique(array_column(
            $dataFlowGraph->toArray()['meta']['warnings'] ?? [],
            'scanner',
        )));
        $sideEffectScanners = array_values(array_unique(array_column(
            $sideEffectGraph->toArray()['meta']['warnings'] ?? [],
            'scanner',
        )));

        $this->assertSame(['data_flow'], $dataFlowScanners);
        $this->assertSame(['side_effects'], $sideEffectScanners);
        $this->assertSame(1, $facts->stats()['counters']['parses']);
    }
}
