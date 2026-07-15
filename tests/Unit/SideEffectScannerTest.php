<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Scanners\SideEffectScanner;
use AppGraph\Support\FileFinder;
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
}
