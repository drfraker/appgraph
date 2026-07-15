<?php

namespace AppGraph\Tests\Feature;

use AppGraph\Support\ScanFingerprint;
use AppGraph\Tests\Fixtures\ProgressNoteController;
use AppGraph\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ScanCommandTest extends TestCase
{
    public function test_scan_command_writes_valid_json_graph(): void
    {
        $this->useFileBackedSqliteDatabase();
        $this->createCommandModelFixture();
        $this->createFormRequestAndEventFixtures();

        Route::put('/progress-notes/{note}', [ProgressNoteController::class, 'update'])
            ->name('progress-notes.update');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });

        Schema::create('progress_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('title');
        });

        $outputPath = storage_path('appgraph-test/appgraph.json');
        $overviewPath = storage_path('appgraph-test/overview.json');
        @unlink($outputPath);
        @unlink($overviewPath);

        $this->artisan('appgraph:scan', [
            '--output' => $outputPath,
            '--pretty' => true,
        ])->assertSuccessful();

        $this->assertFileExists($outputPath);

        $graph = json_decode((string) file_get_contents($outputPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('0.4.0', $graph['meta']['appgraphVersion']);
        $this->assertSame(ScanFingerprint::VERSION, $graph['meta']['scan']['version']);
        $this->assertSame('sha256', $graph['meta']['scan']['algorithm']);
        $this->assertSame('testing', $graph['meta']['scan']['applicationEnvironment']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $graph['meta']['scan']['containerBindings']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $graph['meta']['scan']['laravelExecutionRegistry']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $graph['meta']['scan']['fingerprint']);
        $this->assertNotEmpty($graph['meta']['scan']['files']);
        $this->assertGraphHasNode($graph, 'route:PUT:/progress-notes/{note}', 'route');
        $this->assertGraphHasNode($graph, ProgressNoteController::class.'::update', 'method');
        $this->assertGraphHasNode($graph, 'table:progress_notes', 'table');
        $this->assertGraphHasNode($graph, 'column:progress_notes.title', 'column');
        $this->assertGraphHasNode($graph, 'App\Models\CommandProgressNote', 'model');
        $this->assertGraphHasEdge($graph, 'route:PUT:/progress-notes/{note}', ProgressNoteController::class.'::update', 'routes_to');
        $this->assertGraphHasEdge($graph, 'App\Models\CommandProgressNote', 'table:progress_notes', 'uses_table');
        $this->assertGraphHasEdge($graph, 'table:progress_notes', 'column:progress_notes.title', 'has_column');

        // FormRequest rules extraction and event flow edges.
        $this->assertGraphHasNode($graph, 'App\Http\Requests\CommandNoteRequest', 'form_request');
        $request = $this->graphNode($graph, 'App\Http\Requests\CommandNoteRequest');
        $this->assertSame(['title' => 'required|string'], $request['metadata']['rules']);
        $this->assertGraphHasEdge(
            $graph,
            'App\Http\Requests\CommandNoteRequest',
            'App\Http\Requests\CommandNoteRequest::rules',
            'framework_invokes',
        );

        $this->assertGraphHasNode($graph, 'App\Events\CommandNoteSaved', 'event');
        $this->assertGraphHasEdge($graph, 'App\Services\CommandNotePublisher::publish', 'App\Events\CommandNoteSaved', 'dispatches');
        $this->assertGraphHasEdge($graph, 'App\Listeners\CommandNoteListener::handle', 'App\Events\CommandNoteSaved', 'listens_to');
        $this->assertGraphHasEdge($graph, 'App\Events\CommandNoteSaved', 'App\Listeners\CommandNoteListener::handle', 'handled_by');
        $this->assertSame('testing', $graph['meta']['analysis']['containerBindings']['environment']);

        // Token-efficient shape: null/empty fields are omitted, schema sources are interned.
        $routeNode = $this->graphNode($graph, 'route:PUT:/progress-notes/{note}');
        $this->assertArrayNotHasKey('summary', $routeNode, 'Null fields must be omitted from the export.');
        $this->assertArrayHasKey('sources', $graph['meta'], 'Schema sources must be interned into meta.');
        $tableNode = $this->graphNode($graph, 'table:progress_notes');
        $this->assertArrayNotHasKey('schemaSources', $tableNode['metadata'], 'Embedded schemaSources blob must be gone.');
        $this->assertContains($tableNode['metadata']['sources'][0], array_keys($graph['meta']['sources']));

        // Overview projection is emitted alongside the main graph and is well-formed.
        $this->assertFileExists($overviewPath);

        $overview = json_decode((string) file_get_contents($overviewPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('0.4.0', $overview['meta']['appgraphVersion']);
        $this->assertSame('progress_notes', $overview['models']['App\Models\CommandProgressNote']);
        $this->assertArrayHasKey('byNodeType', $overview['counts']);
        $this->assertSame(1, $overview['events']['App\Events\CommandNoteSaved']['listeners']);
        $this->assertSame(1, $overview['events']['App\Events\CommandNoteSaved']['dispatchSites']);

        $route = collect($overview['routes'])->firstWhere('route', 'PUT /progress-notes/{note}');
        $this->assertNotNull($route, 'Overview must list the scanned route.');
        $this->assertSame(ProgressNoteController::class.'::update', $route['action']);
    }

    private function createFormRequestAndEventFixtures(): void
    {
        $files = new Filesystem();

        foreach (['app/Http/Requests', 'app/Events', 'app/Listeners', 'app/Services'] as $directory) {
            $files->ensureDirectoryExists(base_path($directory));
        }

        file_put_contents(base_path('app/Http/Requests/CommandNoteRequest.php'), <<<'PHP'
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommandNoteRequest extends FormRequest
{
    public function rules(): array
    {
        return ['title' => 'required|string'];
    }
}
PHP);

        file_put_contents(base_path('app/Events/CommandNoteSaved.php'), <<<'PHP'
<?php

namespace App\Events;

class CommandNoteSaved
{
}
PHP);

        file_put_contents(base_path('app/Listeners/CommandNoteListener.php'), <<<'PHP'
<?php

namespace App\Listeners;

use App\Events\CommandNoteSaved;

class CommandNoteListener
{
    public function handle(CommandNoteSaved $event): void
    {
    }
}
PHP);

        file_put_contents(base_path('app/Services/CommandNotePublisher.php'), <<<'PHP'
<?php

namespace App\Services;

use App\Events\CommandNoteSaved;

class CommandNotePublisher
{
    public function publish(): void
    {
        event(new CommandNoteSaved());
    }
}
PHP);
    }

    private function createCommandModelFixture(): void
    {
        $directory = base_path('app/Models');
        (new Filesystem())->ensureDirectoryExists($directory);

        file_put_contents($directory.'/CommandProgressNote.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommandProgressNote extends Model
{
    protected $table = 'progress_notes';
}
PHP);
    }
}
