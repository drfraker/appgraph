<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Scanners\ModelScanner;
use AppGraph\Support\FileFinder;
use AppGraph\Support\PhpReflection;
use AppGraph\Support\TypeResolver;
use AppGraph\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

class ModelScannerTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = sys_get_temp_dir().'/appgraph-model-scanner-'.bin2hex(random_bytes(4));
        mkdir($this->fixturePath.'/app/Models', 0775, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->fixturePath);

        parent::tearDown();
    }

    public function test_it_scans_models_and_infers_table_relationships(): void
    {
        file_put_contents($this->fixturePath.'/app/Models/User.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedModels;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';
}
PHP);

        file_put_contents($this->fixturePath.'/app/Models/ProgressNote.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedModels;

use Illuminate\Database\Eloquent\Model;

class ProgressNote extends Model
{
    protected $table = 'progress_notes';

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
PHP);

        $files = new FileFinder($this->fixturePath);
        $scanner = new ModelScanner($files, new PhpReflection($files), new TypeResolver());
        $graph = new Graph();

        $scanner->scan($graph);

        $array = $graph->toArray();
        $modelId = 'AppGraph\Tests\GeneratedModels\ProgressNote';
        $userId = 'AppGraph\Tests\GeneratedModels\User';

        $this->assertGraphHasNode($array, $modelId, 'model');
        $this->assertGraphHasNode($array, 'table:progress_notes', 'table');
        $this->assertGraphHasEdge($array, $modelId, 'table:progress_notes', 'uses_table');
        $this->assertGraphHasEdge($array, $modelId, $userId, 'belongs_to');

        $edge = $this->graphEdge($array, $modelId, 'table:progress_notes', 'uses_table');

        $this->assertSame(1.0, $edge['confidence']);
        $this->assertSame('explicit_table_property', $edge['metadata']['source']);
    }
}
