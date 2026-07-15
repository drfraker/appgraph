<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Scanners\EventFlowScanner;
use AppGraph\Support\FileFinder;
use AppGraph\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

class EventFlowScannerTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = sys_get_temp_dir().'/appgraph-event-flow-scanner-'.bin2hex(random_bytes(4));

        foreach (['app/Events', 'app/Jobs', 'app/Listeners', 'app/Observers', 'app/Models', 'app/Providers', 'app/Http/Controllers'] as $directory) {
            mkdir($this->fixturePath.'/'.$directory, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->fixturePath);

        parent::tearDown();
    }

    public function test_it_maps_dispatches_listeners_and_observers(): void
    {
        $ns = 'AppGraph\Tests\GeneratedEvents';

        file_put_contents($this->fixturePath.'/app/Events/NoteSaved.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Events;

class NoteSaved
{
}
PHP);

        file_put_contents($this->fixturePath.'/app/Events/NoteArchived.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Events;

class NoteArchived
{
}
PHP);

        file_put_contents($this->fixturePath.'/app/Jobs/SyncNote.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

class SyncNote implements ShouldQueue
{
    public $connection = 'redis';

    public $queue = 'notes';

    public function handle(): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Jobs/GenerateSuperbill.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

class GenerateSuperbill implements ShouldQueue
{
    public function handle(): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Listeners/SendNoteNotification.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Listeners;

use AppGraph\Tests\GeneratedEvents\Events\NoteSaved;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendNoteNotification implements ShouldQueue
{
    public function handle(NoteSaved $event): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Providers/EventServiceProvider.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Providers;

use AppGraph\Tests\GeneratedEvents\Events\NoteArchived;
use AppGraph\Tests\GeneratedEvents\Events\NoteSaved;
use AppGraph\Tests\GeneratedEvents\Listeners\SendNoteNotification;
use Illuminate\Support\Facades\Event;

class EventServiceProvider
{
    protected $listen = [
        NoteSaved::class => [
            SendNoteNotification::class,
        ],
    ];

    public function boot(): void
    {
        Event::listen(NoteArchived::class, [SendNoteNotification::class, 'handleArchive']);
        Event::listen(function (NoteArchived $event): void {
            //
        });
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Observers/NoteObserver.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Observers;

class NoteObserver
{
    public function saved(): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Models/Note.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Models;

use AppGraph\Tests\GeneratedEvents\Observers\NoteObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(NoteObserver::class)]
class Note extends Model
{
}
PHP);

        file_put_contents($this->fixturePath.'/app/Models/Tag.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Models;

use AppGraph\Tests\GeneratedEvents\Observers\NoteObserver;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    public static function boot(): void
    {
        static::observe(NoteObserver::class);
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Http/Controllers/NoteController.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Http\Controllers;

use AppGraph\Tests\GeneratedEvents\Events\NoteArchived;
use AppGraph\Tests\GeneratedEvents\Events\NoteSaved;
use AppGraph\Tests\GeneratedEvents\Jobs\GenerateSuperbill;
use AppGraph\Tests\GeneratedEvents\Jobs\SyncNote;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

class NoteController
{
    public function store(): void
    {
        NoteSaved::dispatch();
        event(new NoteArchived());
        SyncNote::dispatch(1)->onConnection('database')->onQueue('signatures')->afterCommit();
    }

    public function archive(): void
    {
        Event::dispatch(new NoteArchived());
        dispatch(new SyncNote(2));
        Bus::dispatch(new SyncNote(3));
    }

    public function beforeTransactionCommit(): void
    {
        SyncNote::dispatch(4)->beforeCommit();
    }

    public function chain(): void
    {
        Bus::chain([
            new SyncNote(5),
            GenerateSuperbill::class,
        ])->onConnection('redis')->onQueue('billing')->dispatch();
    }
}
PHP);

        $graph = new Graph();
        (new EventFlowScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();

        $this->assertGraphHasNode($array, $ns.'\Events\NoteSaved', 'event');
        $this->assertGraphHasNode($array, $ns.'\Events\NoteArchived', 'event');
        $this->assertGraphHasNode($array, $ns.'\Jobs\SyncNote', 'job');
        $this->assertGraphHasNode($array, $ns.'\Jobs\GenerateSuperbill', 'job');

        $controller = $ns.'\Http\Controllers\NoteController';

        $this->assertGraphHasEdge($array, $controller.'::store', $ns.'\Events\NoteSaved', 'dispatches');
        $this->assertGraphHasEdge($array, $controller.'::store', $ns.'\Events\NoteArchived', 'dispatches');
        $this->assertGraphHasEdge($array, $controller.'::store', $ns.'\Jobs\SyncNote', 'dispatches');
        $this->assertGraphHasEdge($array, $controller.'::archive', $ns.'\Events\NoteArchived', 'dispatches');
        $this->assertGraphHasEdge($array, $controller.'::archive', $ns.'\Jobs\SyncNote', 'dispatches');
        $this->assertGraphHasEdge($array, $controller.'::beforeTransactionCommit', $ns.'\Jobs\SyncNote', 'dispatches');
        $this->assertGraphHasEdge($array, $controller.'::chain', $ns.'\Jobs\SyncNote', 'dispatches');
        $this->assertGraphHasEdge($array, $controller.'::chain', $ns.'\Jobs\GenerateSuperbill', 'dispatches');

        $staticDispatch = $this->graphEdge($array, $controller.'::store', $ns.'\Events\NoteSaved', 'dispatches');
        $this->assertSame('event', $staticDispatch['metadata']['kind']);
        $this->assertSame('static', $staticDispatch['metadata']['via']);

        $helperDispatch = $this->graphEdge($array, $controller.'::store', $ns.'\Events\NoteArchived', 'dispatches');
        $this->assertSame('helper', $helperDispatch['metadata']['via']);
        $this->assertSame(0.95, $helperDispatch['confidence']);

        $jobDispatch = $this->graphEdge($array, $controller.'::store', $ns.'\Jobs\SyncNote', 'dispatches');
        $this->assertTrue($jobDispatch['metadata']['afterCommit']);
        $this->assertSame('database', $jobDispatch['metadata']['connection']);
        $this->assertSame('signatures', $jobDispatch['metadata']['queue']);
        $jobNode = $this->graphNode($array, $ns.'\Jobs\SyncNote');
        $this->assertSame('redis', $jobNode['metadata']['connection']);
        $this->assertSame('notes', $jobNode['metadata']['queue']);

        $beforeCommit = $this->graphEdge($array, $controller.'::beforeTransactionCommit', $ns.'\Jobs\SyncNote', 'dispatches');
        $this->assertFalse($beforeCommit['metadata']['afterCommit']);

        $chainedSuperbill = $this->graphEdge($array, $controller.'::chain', $ns.'\Jobs\GenerateSuperbill', 'dispatches');
        $this->assertSame('bus_chain', $chainedSuperbill['metadata']['via']);
        $this->assertTrue($chainedSuperbill['metadata']['chained']);
        $this->assertSame(1, $chainedSuperbill['metadata']['chainPosition']);
        $this->assertSame('redis', $chainedSuperbill['metadata']['connection']);
        $this->assertSame('billing', $chainedSuperbill['metadata']['queue']);

        $listener = $ns.'\Listeners\SendNoteNotification';

        $providerListen = $this->graphEdge($array, $listener.'::handle', $ns.'\Events\NoteSaved', 'listens_to');
        $this->assertSame(1.0, $providerListen['confidence']);
        $this->assertSame('event_service_provider', $providerListen['metadata']['source']);
        $this->assertTrue($providerListen['metadata']['queued']);

        $explicitListen = $this->graphEdge($array, $listener.'::handleArchive', $ns.'\Events\NoteArchived', 'listens_to');
        $this->assertSame(0.9, $explicitListen['confidence']);
        $this->assertSame('explicit_listen', $explicitListen['metadata']['source']);

        $closureListen = $this->graphEdge($array, $ns.'\Providers\EventServiceProvider::boot', $ns.'\Events\NoteArchived', 'listens_to');
        $this->assertSame(0.7, $closureListen['confidence']);
        $this->assertTrue($closureListen['metadata']['closure']);

        $attributeObserve = $this->graphEdge($array, $ns.'\Observers\NoteObserver', $ns.'\Models\Note', 'observes');
        $this->assertSame(1.0, $attributeObserve['confidence']);
        $this->assertSame('attribute', $attributeObserve['metadata']['source']);

        $observeCall = $this->graphEdge($array, $ns.'\Observers\NoteObserver', $ns.'\Models\Tag', 'observes');
        $this->assertSame(0.9, $observeCall['confidence']);
        $this->assertSame('observe_call', $observeCall['metadata']['source']);
        $this->assertGraphHasNode($array, $ns.'\Models\Tag', 'model');
    }

    public function test_auto_discovery_yields_to_explicit_provider_registration(): void
    {
        file_put_contents($this->fixturePath.'/app/Events/OrphanEvent.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Events;

class OrphanEvent
{
}
PHP);

        file_put_contents($this->fixturePath.'/app/Listeners/OrphanListener.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Listeners;

use AppGraph\Tests\GeneratedEvents\Events\OrphanEvent;

class OrphanListener
{
    public function handle(OrphanEvent $event): void
    {
    }
}
PHP);

        $graph = new Graph();
        (new EventFlowScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();

        $ns = 'AppGraph\Tests\GeneratedEvents';
        $edge = $this->graphEdge($array, $ns.'\Listeners\OrphanListener::handle', $ns.'\Events\OrphanEvent', 'listens_to');

        $this->assertSame(0.85, $edge['confidence']);
        $this->assertSame('auto_discovery', $edge['metadata']['source']);
        $this->assertArrayNotHasKey('queued', $edge['metadata']);
    }
}
