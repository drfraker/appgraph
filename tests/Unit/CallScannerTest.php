<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Scanners\CallScanner;
use AppGraph\Support\FileFinder;
use AppGraph\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

class CallScannerTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = sys_get_temp_dir().'/appgraph-call-scanner-'.bin2hex(random_bytes(4));
        mkdir($this->fixturePath.'/app/Models', 0775, true);
        mkdir($this->fixturePath.'/app/Http/Controllers', 0775, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->fixturePath);

        parent::tearDown();
    }

    public function test_it_scans_method_calls_from_typed_parameters_this_static_and_relationship_scopes(): void
    {
        file_put_contents($this->fixturePath.'/app/Models/ProgressNote.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedCalls\Models;

use Illuminate\Database\Eloquent\Model;

class ProgressNote extends Model
{
    public function billingCodes()
    {
        return $this->belongsToMany(BillingCode::class);
    }

    public function scopeNonAppointment($query)
    {
        return $query->whereNull('notable_id');
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Models/BillingCode.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedCalls\Models;

use Illuminate\Database\Eloquent\Model;

class BillingCode extends Model
{
}
PHP);

        file_put_contents($this->fixturePath.'/app/Models/Client.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedCalls\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    public function progressNotes()
    {
        return $this->hasMany(ProgressNote::class);
    }

    public function nonAppointmentProgressNotes()
    {
        return $this->progressNotes()->nonAppointment();
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Http/Controllers/ProgressNoteController.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedCalls\Http\Controllers;

use AppGraph\Tests\GeneratedCalls\Models\ProgressNote;

class ProgressNoteController
    extends BaseProgressNoteController
{
    use TracksProgressNotes;

    private NoteService $legacyService;

    public function __construct(
        private NoteStateManager $noteStateManager,
        NoteService $legacyService,
    ) {
        $this->legacyService = $legacyService;
    }

    public function update(ProgressNote $note): void
    {
        $note->billingCodes();
        $this->helper();
        $this->inheritedHelper();
        $this->traitHelper();
        self::staticHelper();
        $callable = self::staticHelper(...);
        app(NoteService::class)->execute();
        resolve('AppGraph\\Tests\\GeneratedCalls\\Http\\Controllers\\StringResolvedService')->run();
        $this->noteStateManager->handleNoteSigned();
        $this->legacyService->execute();
        $method = 'execute';
        app(NoteService::class)->{$method}();
    }

    public function frameworkCalls(ProgressNote $note, UpdateProgressNoteRequest $request): void
    {
        $note->update(['is_draft' => false]);
        $request->validated();
        $this->currentNote()->billingCodes();
        ProgressNote::query()->where('is_draft', true)->firstOrFail()->billingCodes();
        ProgressNote::query()->get()->each(fn ($item) => $item->billingCodes());
        collect([$note])->filter(fn ($item) => $item->billingCodes())->first()->billingCodes();
        QueueSigning::dispatch()->onQueue('notes')->afterCommit();
        \Illuminate\Support\Facades\Bus::chain([new QueueSigning()])->onQueue('notes')->dispatch();
        \Illuminate\Support\Facades\Validator::make([], [])->after(fn () => null)->validate();
        \Illuminate\Support\Facades\Gate::forUser(new \stdClass())->authorize('update', $note);
    }

    private function currentNote(): ProgressNote
    {
        return new ProgressNote();
    }

    protected function helper(): void
    {
    }

    public static function staticHelper(): void
    {
    }
}

abstract class BaseProgressNoteController
{
    protected function inheritedHelper(): void
    {
    }
}

trait TracksProgressNotes
{
    protected function traitHelper(): void
    {
    }
}

class NoteService
{
    public function execute(): void
    {
    }
}

class NoteStateManager
{
    public function handleNoteSigned(): void
    {
    }
}

class StringResolvedService
{
    public function run(): void
    {
    }
}

class UpdateProgressNoteRequest extends \Illuminate\Foundation\Http\FormRequest
{
}

class QueueSigning
{
    use \Illuminate\Foundation\Bus\Dispatchable;
}
PHP);

        $scanner = new CallScanner(new FileFinder($this->fixturePath));
        $graph = new Graph();

        $scanner->scan($graph);

        $array = $graph->toArray();
        $controller = 'AppGraph\Tests\GeneratedCalls\Http\Controllers\ProgressNoteController';
        $baseController = 'AppGraph\Tests\GeneratedCalls\Http\Controllers\BaseProgressNoteController';
        $controllerTrait = 'AppGraph\Tests\GeneratedCalls\Http\Controllers\TracksProgressNotes';
        $service = 'AppGraph\Tests\GeneratedCalls\Http\Controllers\NoteService';
        $stateManager = 'AppGraph\Tests\GeneratedCalls\Http\Controllers\NoteStateManager';
        $stringResolvedService = 'AppGraph\Tests\GeneratedCalls\Http\Controllers\StringResolvedService';
        $progressNote = 'AppGraph\Tests\GeneratedCalls\Models\ProgressNote';
        $client = 'AppGraph\Tests\GeneratedCalls\Models\Client';

        $this->assertGraphHasNode($array, $progressNote.'::billingCodes', 'method');
        $this->assertGraphHasEdge($array, $controller.'::update', $progressNote.'::billingCodes', 'calls');
        $this->assertGraphHasEdge($array, $controller.'::update', $controller.'::helper', 'calls');
        $this->assertGraphHasEdge($array, $controller.'::update', $baseController.'::inheritedHelper', 'calls');
        $this->assertGraphHasEdge($array, $controller.'::update', $controllerTrait.'::traitHelper', 'calls');
        $this->assertGraphHasEdge($array, $controller.'::update', $service.'::execute', 'calls');
        $this->assertGraphHasEdge($array, $controller.'::update', $stateManager.'::handleNoteSigned', 'calls');
        $this->assertGraphHasEdge($array, $controller.'::update', $stringResolvedService.'::run', 'calls');
        $this->assertGraphHasEdge($array, $controller.'::update', $controller.'::staticHelper', 'calls');
        $this->assertGraphHasEdge($array, $controller.'::frameworkCalls', $controller.'::currentNote', 'calls');
        $this->assertGraphHasEdge($array, $controller.'::frameworkCalls', $progressNote.'::billingCodes', 'calls');
        $this->assertGraphHasEdge($array, $client.'::nonAppointmentProgressNotes', $client.'::progressNotes', 'calls');
        $this->assertGraphHasEdge($array, $client.'::nonAppointmentProgressNotes', $progressNote.'::scopeNonAppointment', 'calls');

        $edge = $this->graphEdge($array, $controller.'::update', $progressNote.'::billingCodes', 'calls');

        $this->assertSame(0.9, $edge['confidence']);
        $this->assertSame('typed_parameter', $edge['metadata']['inference']);

        $injectedEdge = $this->graphEdge($array, $controller.'::update', $stateManager.'::handleNoteSigned', 'calls');
        $this->assertSame('constructor_promoted_property', $injectedEdge['metadata']['inference']);
        $this->assertGreaterThanOrEqual(1, $array['meta']['analysis']['callResolution']['unresolvedCount']);
        $this->assertContains('dynamic_method_name', array_column($array['meta']['analysis']['callResolution']['samples'], 'reason'));
        $this->assertGreaterThanOrEqual(1, $array['meta']['analysis']['callResolution']['boundaryCount']);
        $this->assertArrayNotHasKey($controller.'::frameworkCalls', $array['meta']['analysis']['callResolution']['byCaller']);
        $this->assertArrayHasKey($controller.'::frameworkCalls', $array['meta']['analysis']['callResolution']['boundaryByCaller']);
        $this->assertContains(
            $progressNote.'::update',
            array_column($array['meta']['analysis']['callResolution']['boundaryByCaller'][$controller.'::frameworkCalls']['samples'], 'target')
        );
    }
}
