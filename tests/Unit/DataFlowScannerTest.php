<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Scanners\DataFlowScanner;
use AppGraph\Support\FileFinder;
use AppGraph\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

class DataFlowScannerTest extends TestCase
{
    public function test_data_flow_edges_are_possible_when_database_scope_is_ambiguous(): void
    {
        file_put_contents($this->fixturePath.'/app/Http/Controllers/AmbiguousController.php', <<<'PHP'
<?php

namespace AppGraph\Tests\AmbiguousDatabaseFlows;

use Illuminate\Support\Facades\DB;

class AmbiguousController
{
    public function index(): void
    {
        DB::table('users')->get();
    }
}
PHP);
        $graph = new Graph();
        $graph->addNode(Node::make('table:users', 'table', 'users', [
            'metadata' => [
                'identityAmbiguous' => true,
                'schemaIdentities' => ['central' => [], 'tenant' => []],
            ],
        ]));

        (new DataFlowScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $edge = $this->graphEdge(
            $graph->toArray(),
            'AppGraph\Tests\AmbiguousDatabaseFlows\AmbiguousController::index',
            'table:users',
            'reads',
        );

        $this->assertSame(0.5, $edge['confidence']);
        $this->assertSame('possible', $edge['metadata']['matchCertainty']);
        $this->assertSame('database_scope_unresolved', $edge['metadata']['ambiguity']);
        $this->assertSame(2, $edge['metadata']['schemaIdentityCount']);
    }

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

    public function scopeFilter($query, array $filters)
    {
        return $query->when($filters['body'] ?? null, fn ($query, $body) => $query->where('body', $body));
    }

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
        $note->save(['touch' => false]);
    }

    public function bulkUpdate(): void
    {
        ProgressNote::query()->where('id', 1)->update(['body' => 'Changed']);
        DB::table('progress_notes')->count();
        $queryFactory = ProgressNote::query(...);
    }

    public function findByBody(): void
    {
        ProgressNote::query()->where('body', 'Changed')->select('id', 'body as note_body')->get();
        ProgressNote::pluck('body', 'id');
        ProgressNote::select('*')->get();
        $dynamic = ['updated_at'];
        ProgressNote::select('id', ...$dynamic)->get();
    }

    public function queryBuilderReads(): void
    {
        DB::table('progress_notes')->where('body', 'Changed')->select('email')->select('id')->get();
        DB::table('progress_notes')->select('id')->get();
        DB::table('progress_notes')->select('*')->get();
        $dynamic = ['updated_at'];
        DB::table('progress_notes')->select('id', ...$dynamic)->get();
        DB::table('progress_notes')->find(1, ['body']);
    }

    public function queryBuilderWrites(): void
    {
        $payload = ['title' => 'Changed'];
        DB::table('progress_notes')->update(['body' => 'Changed', ...$payload]);
        DB::table('progress_notes')->increment('visits', 1, ['updated_at' => now()]);
        DB::table('progress_notes')->increment('visits', 1, $payload);
        DB::table('progress_notes')->delete();
    }

    public function scopedPagination(): void
    {
        ProgressNote::query()
            ->when(true, fn ($query) => $query->whereRaw('id > 0'))
            ->whereBetween('created_at', ['2026-01-01', '2026-12-31'])
            ->whereDoesntHave('images')
            ->with('images')
            ->withCount('images')
            ->orderBy('created_at')
            ->filter(['body' => 'Changed'])
            ->paginate();
    }

    public function builderOnly(): void
    {
        $query = ProgressNote::query();
    }

    public function sameLineWrites(): void
    {
        DB::table('progress_notes')->update(['alpha' => 1]); DB::table('progress_notes')->update(['omega' => 1]);
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
        $this->assertGraphHasEdge($array, $controller.'::findByBody', 'table:progress_notes', 'reads');
        $this->assertGraphHasEdge($array, $controller.'::queryBuilderReads', 'table:progress_notes', 'reads');
        $this->assertGraphHasEdge($array, $controller.'::queryBuilderWrites', 'table:progress_notes', 'writes');
        $this->assertGraphHasEdge($array, $controller.'::scopedPagination', 'table:progress_notes', 'reads');
        $this->assertGraphHasEdge($array, $controller.'::sameLineWrites', 'table:progress_notes', 'writes');
        $this->assertGraphHasEdge($array, $controller.'::mutateAbstractModel', 'table:progress_notes', 'writes');
        $this->assertNull($this->graphEdge($array, $controller.'::builderOnly', 'table:progress_notes', 'reads'));

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
        $fieldCoverage = array_column(array_values($updateEdge['metadata']['operations']), 'fieldCoverage');
        $this->assertContains('complete', $fieldCoverage);
        $this->assertContains('unknown', $fieldCoverage);
        $saveOperation = collect(array_values($updateEdge['metadata']['operations']))
            ->firstWhere('operation', 'save');
        $this->assertSame([], $saveOperation['fields']);
        $this->assertSame('unknown', $saveOperation['fieldCoverage']);

        $readEdge = $this->graphEdge($array, $controller.'::findByBody', 'table:progress_notes', 'reads');
        $readOperations = array_values($readEdge['metadata']['operations']);
        $this->assertSame(['get', 'pluck'], array_values(array_unique(array_column($readOperations, 'operation'))));
        $this->assertSame(['body', 'id'], $readOperations[0]['fields']);
        $this->assertSame(['body', 'id'], $readOperations[1]['fields']);
        $this->assertSame('*', $readOperations[2]['fields'][0]);
        $this->assertSame(['id'], $readOperations[3]['fields']);
        $this->assertSame(['unknown', 'unknown', 'unknown', 'unknown'], array_column($readOperations, 'fieldCoverage'));

        $queryReadEdge = $this->graphEdge($array, $controller.'::queryBuilderReads', 'table:progress_notes', 'reads');
        $queryReadOperations = array_values($queryReadEdge['metadata']['operations']);
        $this->assertSame(['body', 'id'], $queryReadOperations[0]['fields']);
        $this->assertNotContains('email', $queryReadOperations[0]['fields']);
        $this->assertSame(['id'], $queryReadOperations[1]['fields']);
        $this->assertSame(['*'], $queryReadOperations[2]['fields']);
        $this->assertSame(['id'], $queryReadOperations[3]['fields']);
        $this->assertSame(['body', 'id'], $queryReadOperations[4]['fields']);
        $this->assertSame(['complete', 'complete', 'whole_row', 'unknown', 'complete'], array_column($queryReadOperations, 'fieldCoverage'));

        $queryWriteEdge = $this->graphEdge($array, $controller.'::queryBuilderWrites', 'table:progress_notes', 'writes');
        $queryWriteOperations = array_values($queryWriteEdge['metadata']['operations']);
        $this->assertSame(['body'], $queryWriteOperations[0]['fields']);
        $this->assertSame('unknown', $queryWriteOperations[0]['fieldCoverage']);
        $this->assertSame(['updated_at', 'visits'], $queryWriteOperations[1]['fields']);
        $this->assertSame('complete', $queryWriteOperations[1]['fieldCoverage']);
        $this->assertSame(['visits'], $queryWriteOperations[2]['fields']);
        $this->assertSame('unknown', $queryWriteOperations[2]['fieldCoverage']);
        $this->assertSame([], $queryWriteOperations[3]['fields']);
        $this->assertSame('whole_row', $queryWriteOperations[3]['fieldCoverage']);

        $paginationEdge = $this->graphEdge($array, $controller.'::scopedPagination', 'table:progress_notes', 'reads');
        $paginationOperation = collect(array_values($paginationEdge['metadata']['operations']))
            ->firstWhere('operation', 'paginate');
        $this->assertSame(
            'AppGraph\\Tests\\GeneratedDataFlows\\Models\\ProgressNote::scopeFilter',
            $paginationOperation['localScope'],
        );

        $sameLineEdge = $this->graphEdge($array, $controller.'::sameLineWrites', 'table:progress_notes', 'writes');
        $sameLineOperations = array_values($sameLineEdge['metadata']['operations']);
        $this->assertCount(2, $sameLineOperations);
        $this->assertSame(['alpha', 'omega'], array_merge(...array_column($sameLineOperations, 'fields')));

        $abstractEdge = $this->graphEdge($array, $controller.'::mutateAbstractModel', 'table:progress_notes', 'writes');
        $this->assertSame('possible_model_table', $abstractEdge['metadata']['targetRole']);
        $abstractOperation = array_values($abstractEdge['metadata']['operations'])[0];
        $this->assertSame(['AppGraph\Tests\GeneratedDataFlows\Models\ProgressNote'], $abstractOperation['possibleModels']);
    }
}
