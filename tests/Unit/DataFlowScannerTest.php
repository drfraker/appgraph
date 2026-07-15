<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Scanners\DataFlowScanner;
use AppGraph\Support\FileFinder;
use AppGraph\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

class DataFlowScannerTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = sys_get_temp_dir().'/appgraph-data-flow-scanner-'.bin2hex(random_bytes(4));
        mkdir($this->fixturePath.'/app/Models', 0775, true);
        mkdir($this->fixturePath.'/app/Http/Controllers', 0775, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->fixturePath);

        parent::tearDown();
    }

    public function test_it_scans_model_relationship_pivot_and_query_table_data_flows(): void
    {
        file_put_contents($this->fixturePath.'/app/Models/ProgressNote.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedDataFlows\Models;

use Illuminate\Database\Eloquent\Model;

class ProgressNote extends AbstractNote
{
    protected $table = 'progress_notes';

    public function images()
    {
        return $this->hasMany(ProgressNoteImage::class);
    }

    public function billingCodes()
    {
        return $this->morphToMany(BillingCode::class, 'billable', 'billables');
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Models/AbstractNote.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedDataFlows\Models;

use Illuminate\Database\Eloquent\Model;

abstract class AbstractNote extends Model
{
}
PHP);

        file_put_contents($this->fixturePath.'/app/Models/ProgressNoteImage.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedDataFlows\Models;

use Illuminate\Database\Eloquent\Model;

class ProgressNoteImage extends Model
{
    protected $table = 'progress_note_images';
}
PHP);

        file_put_contents($this->fixturePath.'/app/Models/BillingCode.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedDataFlows\Models;

use Illuminate\Database\Eloquent\Model;

class BillingCode extends Model
{
    protected $table = 'billing_codes';
}
PHP);

        file_put_contents($this->fixturePath.'/app/Http/Controllers/ProgressNoteController.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedDataFlows\Http\Controllers;

use AppGraph\Tests\GeneratedDataFlows\Models\ProgressNote;
use AppGraph\Tests\GeneratedDataFlows\Models\AbstractNote;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ProgressNoteController
{
    public function update(ProgressNote $note): void
    {
        $note->billingCodes()->syncWithoutDetaching([1]);
        $note->images()->create(['path' => 'scan.png']);
        $note->delete();
        $note->billingCodes()->pluck('id');
        $note->meta['signed_at'] = now();
        $note->update(['body' => 'Changed', 'meta' => ['signed_by' => 1]]);
    }

    public function bulkUpdate(): void
    {
        ProgressNote::query()->where('id', 1)->update(['body' => 'Changed']);
        DB::table('progress_notes')->count();
        $queryFactory = ProgressNote::query(...);
    }

    public function mutateAbstractModel(AbstractNote $note): void
    {
        $note->update(['body' => 'Do not invent an abstract_notes table']);
    }
}
PHP);

        $scanner = new DataFlowScanner(new FileFinder($this->fixturePath));
        $graph = new Graph();

        $scanner->scan($graph);

        $array = $graph->toArray();
        $controller = 'AppGraph\Tests\GeneratedDataFlows\Http\Controllers\ProgressNoteController';

        $this->assertGraphHasNode($array, 'table:progress_notes', 'table');
        $this->assertGraphHasNode($array, 'table:progress_note_images', 'table');
        $this->assertGraphHasNode($array, 'table:billables', 'table');
        $this->assertGraphHasNode($array, 'table:billing_codes', 'table');
        $this->assertNull($this->graphNode($array, 'table:abstract_notes'));

        $this->assertGraphHasEdge($array, $controller.'::update', 'table:progress_notes', 'writes');
        $this->assertGraphHasEdge($array, $controller.'::update', 'table:progress_note_images', 'writes');
        $this->assertGraphHasEdge($array, $controller.'::update', 'table:billables', 'writes');
        $this->assertGraphHasEdge($array, $controller.'::update', 'table:billing_codes', 'reads');
        $this->assertGraphHasEdge($array, $controller.'::bulkUpdate', 'table:progress_notes', 'writes');
        $this->assertGraphHasEdge($array, $controller.'::bulkUpdate', 'table:progress_notes', 'reads');
        $this->assertGraphHasEdge($array, $controller.'::mutateAbstractModel', 'table:progress_notes', 'writes');

        $pivotEdge = $this->graphEdge($array, $controller.'::update', 'table:billables', 'writes');

        $this->assertSame('relationship_pivot_table', $pivotEdge['metadata']['targetRole']);

        $updateEdge = $this->graphEdge($array, $controller.'::update', 'table:progress_notes', 'writes');
        $fields = array_merge(...array_map(
            static fn (array $operation): array => $operation['fields'] ?? [],
            array_values($updateEdge['metadata']['operations'])
        ));
        $this->assertContains('meta.signed_at', $fields);
        $this->assertContains('meta.signed_by', $fields);
        $this->assertContains('body', $fields);

        $abstractEdge = $this->graphEdge($array, $controller.'::mutateAbstractModel', 'table:progress_notes', 'writes');
        $this->assertSame('possible_model_table', $abstractEdge['metadata']['targetRole']);
        $abstractOperation = array_values($abstractEdge['metadata']['operations'])[0];
        $this->assertSame(['AppGraph\Tests\GeneratedDataFlows\Models\ProgressNote'], $abstractOperation['possibleModels']);
    }
}
