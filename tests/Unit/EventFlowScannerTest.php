<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Scanners\EventFlowScanner;
use AppGraph\Support\ContainerBindingRegistry;
use AppGraph\Support\FileFinder;
use AppGraph\Tests\TestCase;
use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Filesystem\Filesystem;

trait ExternalPrimaryHandler
{
    public function handle(): void
    {
    }
}

trait ExternalSecondaryHandler
{
    public function handle(): void
    {
    }
}

trait ExternalAliasSource
{
    public function process(): void
    {
    }
}

trait ExternalQueueOptions
{
    public bool $afterCommit = true;

    public string $connection = 'trait-connection';

    public string $queue = 'trait-queue';
}

interface ExternalNestedQueue extends \Illuminate\Contracts\Queue\ShouldQueue
{
}

class ExternalPrivateHandlerBase
{
    private function handle(): void
    {
    }
}

final class EventFlowConstructionProbe
{
    public static int $factoryCalls = 0;

    public static int $constructorCalls = 0;
}

class ExternalBootedListener
{
    public function __construct()
    {
        EventFlowConstructionProbe::$constructorCalls++;
    }

    public function handle(object $event): void
    {
    }
}

class ExternalBootedBusHandler
{
    public function __construct()
    {
        EventFlowConstructionProbe::$constructorCalls++;
    }

    public function handle(object $job): void
    {
    }
}

class EventFlowScannerTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = sys_get_temp_dir().'/appgraph-event-flow-scanner-'.bin2hex(random_bytes(4));

        foreach (['app/Commands', 'app/Concerns', 'app/Contracts', 'app/Events', 'app/Handlers', 'app/Jobs', 'app/Listeners', 'app/Observers', 'app/Models', 'app/Providers', 'app/Http/Controllers'] as $directory) {
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

use Illuminate\Foundation\Events\Dispatchable;

class NoteSaved
{
    use Dispatchable;
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
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;

class SyncNote implements ShouldQueue
{
    use Dispatchable, Queueable;

    public $afterCommit = true;

    public $connection = 'redis';

    public $queue = 'notes';

    public function handle(): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Jobs/RefreshIndex.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;

class RefreshIndex
{
    use Dispatchable;

    public function __invoke(): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Jobs/GenerateSuperbill.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;

class GenerateSuperbill implements ShouldQueue
{
    use Dispatchable;

    public function handle(): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Listeners/SendNoteNotification.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Listeners;

use AppGraph\Tests\GeneratedEvents\Events\NoteArchived;
use AppGraph\Tests\GeneratedEvents\Events\NoteSaved;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendNoteNotification implements ShouldQueue
{
    public $afterCommit = true;

    public $connection = 'redis';

    public $queue = 'notifications';

    public function handle(NoteSaved $event): void
    {
    }

    public function handleArchive(NoteArchived $event): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Providers/EventServiceProvider.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Providers;

use AppGraph\Tests\GeneratedEvents\Events\NoteArchived;
use AppGraph\Tests\GeneratedEvents\Events\NoteSaved;
use AppGraph\Tests\GeneratedEvents\Listeners\MissingListener;
use AppGraph\Tests\GeneratedEvents\Listeners\SendNoteNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        NoteSaved::class => [
            SendNoteNotification::class,
        ],
    ];

    public function boot(): void
    {
        Event::listen(NoteArchived::class, [SendNoteNotification::class, 'handleArchive']);
        Event::listen(NoteArchived::class, [MissingListener::class, 'missing']);
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
use AppGraph\Tests\GeneratedEvents\Jobs\RefreshIndex;
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

    public function modes(): void
    {
        SyncNote::dispatch(6);
        SyncNote::dispatchSync(7);
        SyncNote::dispatchAfterResponse(8);
        RefreshIndex::dispatch();
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
        $this->assertGraphHasNode($array, $ns.'\Jobs\RefreshIndex', 'job');

        $savedEvent = $this->graphNode($array, $ns.'\Events\NoteSaved');
        $this->assertSame(7, $savedEvent['line']);
        $this->assertSame(10, $savedEvent['endLine']);

        $syncJob = $this->graphNode($array, $ns.'\Jobs\SyncNote');
        $this->assertSame(9, $syncJob['line']);
        $this->assertSame(22, $syncJob['endLine']);

        $this->assertGraphHasEdge($array, $ns.'\Jobs\SyncNote', $ns.'\Jobs\SyncNote::handle', 'handled_by');
        $this->assertGraphHasEdge($array, $ns.'\Jobs\GenerateSuperbill', $ns.'\Jobs\GenerateSuperbill::handle', 'handled_by');
        $this->assertGraphHasEdge($array, $ns.'\Jobs\RefreshIndex', $ns.'\Jobs\RefreshIndex::__invoke', 'handled_by');

        $jobHandler = $this->graphEdge($array, $ns.'\Jobs\SyncNote', $ns.'\Jobs\SyncNote::handle', 'handled_by');
        $this->assertSame('queued', $jobHandler['metadata']['defaultDispatchMode']);
        $this->assertTrue($jobHandler['metadata']['configuredQueued']);
        $this->assertTrue($jobHandler['metadata']['configuredAfterCommit']);
        $this->assertSame('redis', $jobHandler['metadata']['configuredConnection']);
        $this->assertSame('notes', $jobHandler['metadata']['configuredQueue']);
        $this->assertArrayNotHasKey('executionMode', $jobHandler['metadata']);
        $this->assertArrayNotHasKey('afterCommit', $jobHandler['metadata']);

        $invokableHandler = $this->graphEdge($array, $ns.'\Jobs\RefreshIndex', $ns.'\Jobs\RefreshIndex::__invoke', 'handled_by');
        $this->assertSame('sync', $invokableHandler['metadata']['defaultDispatchMode']);
        $this->assertFalse($invokableHandler['metadata']['configuredQueued']);

        $controller = $ns.'\Http\Controllers\NoteController';

        $this->assertGraphHasEdge($array, $controller.'::store', $ns.'\Events\NoteSaved', 'dispatches');
        $this->assertGraphHasEdge($array, $controller.'::store', $ns.'\Events\NoteArchived', 'dispatches');
        $this->assertGraphHasEdge($array, $controller.'::store', $ns.'\Jobs\SyncNote', 'dispatches');
        $this->assertGraphHasEdge($array, $controller.'::archive', $ns.'\Events\NoteArchived', 'dispatches');
        $this->assertGraphHasEdge($array, $controller.'::archive', $ns.'\Jobs\SyncNote', 'dispatches');
        $this->assertGraphHasEdge($array, $controller.'::beforeTransactionCommit', $ns.'\Jobs\SyncNote', 'dispatches');
        $this->assertGraphHasEdge($array, $controller.'::chain', $ns.'\Jobs\SyncNote', 'dispatches');
        $this->assertGraphHasEdge($array, $controller.'::chain', $ns.'\Jobs\GenerateSuperbill', 'dispatches');
        $this->assertGraphHasEdge($array, $controller.'::modes', $ns.'\Jobs\SyncNote', 'dispatches');
        $this->assertGraphHasEdge($array, $controller.'::modes', $ns.'\Jobs\RefreshIndex', 'dispatches');

        $staticDispatch = $this->graphEdge($array, $controller.'::store', $ns.'\Events\NoteSaved', 'dispatches');
        $this->assertSame('event', $staticDispatch['metadata']['kind']);
        $this->assertSame('static', $staticDispatch['metadata']['via']);
        $this->assertSame('sync', $staticDispatch['metadata']['dispatchMode']);

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

        $mixedDispatch = $this->graphEdge($array, $controller.'::modes', $ns.'\Jobs\SyncNote', 'dispatches');
        $this->assertSame('mixed', $mixedDispatch['metadata']['dispatchMode']);
        $this->assertSame(['after_response', 'queued', 'sync'], $mixedDispatch['metadata']['dispatchModes']);
        $this->assertSame(['dispatch', 'dispatchAfterResponse', 'dispatchSync'], $mixedDispatch['metadata']['dispatchMethods']);
        $this->assertCount(3, $mixedDispatch['metadata']['dispatchOccurrences']);
        $this->assertArrayNotHasKey('afterCommit', $mixedDispatch['metadata']);
        $this->assertArrayNotHasKey('connection', $mixedDispatch['metadata']);
        $this->assertArrayNotHasKey('queue', $mixedDispatch['metadata']);
        $this->assertSame([true], $mixedDispatch['metadata']['afterCommitValues']);
        $this->assertSame(['redis'], $mixedDispatch['metadata']['connections']);
        $this->assertSame(['notes'], $mixedDispatch['metadata']['queues']);

        $syncDispatch = $this->graphEdge($array, $controller.'::modes', $ns.'\Jobs\RefreshIndex', 'dispatches');
        $this->assertSame('sync', $syncDispatch['metadata']['dispatchMode']);

        $listener = $ns.'\Listeners\SendNoteNotification';

        $providerListen = $this->graphEdge($array, $listener.'::handle', $ns.'\Events\NoteSaved', 'listens_to');
        $this->assertSame(1.0, $providerListen['confidence']);
        $this->assertSame('event_service_provider', $providerListen['metadata']['source']);
        $this->assertTrue($providerListen['metadata']['queued']);
        $this->assertTrue($providerListen['metadata']['afterCommit']);
        $this->assertSame('redis', $providerListen['metadata']['connection']);
        $this->assertSame('notifications', $providerListen['metadata']['queue']);

        $this->assertGraphHasEdge($array, $ns.'\Events\NoteSaved', $listener.'::handle', 'handled_by');
        $listenerHandler = $this->graphEdge($array, $ns.'\Events\NoteSaved', $listener.'::handle', 'handled_by');
        $this->assertSame('listener', $listenerHandler['metadata']['kind']);
        $this->assertSame('queued', $listenerHandler['metadata']['executionMode']);
        $this->assertTrue($listenerHandler['metadata']['afterCommit']);
        $this->assertNull($this->graphEdge($array, $listener, $listener.'::handle', 'handled_by'));

        $explicitListen = $this->graphEdge($array, $listener.'::handleArchive', $ns.'\Events\NoteArchived', 'listens_to');
        $this->assertSame(0.9, $explicitListen['confidence']);
        $this->assertSame('explicit_listen', $explicitListen['metadata']['source']);
        $this->assertGraphHasEdge($array, $ns.'\Events\NoteArchived', $listener.'::handleArchive', 'handled_by');

        $closureListen = $this->graphEdge($array, $ns.'\Providers\EventServiceProvider::boot', $ns.'\Events\NoteArchived', 'listens_to');
        $this->assertSame(0.7, $closureListen['confidence']);
        $this->assertTrue($closureListen['metadata']['closure']);
        $this->assertNull($this->graphEdge(
            $array,
            $ns.'\Events\NoteArchived',
            $ns.'\Providers\EventServiceProvider::boot',
            'handled_by'
        ));

        $missingMethod = $ns.'\Listeners\MissingListener::missing';
        $this->assertGraphHasNode($array, $missingMethod, 'method');
        $this->assertTrue($this->graphNode($array, $missingMethod)['metadata']['unresolved']);
        $this->assertNull($this->graphEdge($array, $ns.'\Events\NoteArchived', $missingMethod, 'handled_by'));

        $closureWarnings = array_values(array_filter(
            $array['meta']['warnings'] ?? [],
            static fn (array $warning): bool => ($warning['reason'] ?? null) === 'closure_listener_execution_unresolved'
        ));
        $this->assertCount(1, $closureWarnings);
        $this->assertSame($ns.'\Events\NoteArchived', $closureWarnings[0]['event']);

        $attributeObserve = $this->graphEdge($array, $ns.'\Observers\NoteObserver', $ns.'\Models\Note', 'observes');
        $this->assertSame(1.0, $attributeObserve['confidence']);
        $this->assertSame('attribute', $attributeObserve['metadata']['source']);

        $observeCall = $this->graphEdge($array, $ns.'\Observers\NoteObserver', $ns.'\Models\Tag', 'observes');
        $this->assertSame(0.9, $observeCall['confidence']);
        $this->assertSame('observe_call', $observeCall['metadata']['source']);
        $this->assertGraphHasNode($array, $ns.'\Models\Tag', 'model');

        $observerNode = $this->graphNode($array, $ns.'\Observers\NoteObserver');
        $this->assertSame('class', $observerNode['type']);
        $this->assertSame(5, $observerNode['line']);
        $this->assertSame(10, $observerNode['endLine']);

        foreach ($array['edges'] as $edge) {
            $this->assertNotNull($this->graphNode($array, $edge['from']), "Missing edge source [{$edge['from']}].");
            $this->assertNotNull($this->graphNode($array, $edge['to']), "Missing edge target [{$edge['to']}].");
        }
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

    public function test_execution_bridges_follow_actual_dispatches_and_inherited_queue_contracts(): void
    {
        file_put_contents($this->fixturePath.'/app/Events/BridgeEvent.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Events;

class BridgeEvent
{
}
PHP);

        file_put_contents($this->fixturePath.'/app/Commands/BridgeCommands.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Commands;

use Illuminate\Foundation\Bus\Dispatchable;

class DoThing
{
    public function handle(): void
    {
    }
}

class BrokenThing
{
    protected function handle(): void
    {
    }

    public function __invoke(): void
    {
    }
}

class StaticThing
{
    use Dispatchable;

    public function handle(): void
    {
    }
}

class BeforeCommitJob implements \Illuminate\Contracts\Queue\ShouldQueueAfterCommit
{
    public bool $afterCommit = false;

    public function handle(): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Handlers/BridgeHandlers.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Handlers;

use AppGraph\Tests\GeneratedEvents\Events\BridgeEvent;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

abstract class BaseQueuedHandler implements ShouldQueue
{
    public bool $afterCommit = false;

    public string $connection = 'redis';

    public function process(BridgeEvent $event): void
    {
    }
}

class InheritedQueuedHandler extends BaseQueuedHandler
{
}

class AfterCommitHandler implements ShouldHandleEventsAfterCommit
{
    public function handle(BridgeEvent $event): void
    {
    }
}

class BeforeCommitHandler implements ShouldQueueAfterCommit
{
    public bool $afterCommit = false;

    public function handle(BridgeEvent $event): void
    {
    }
}

class OutsideQueuedHandler implements ShouldQueue
{
    public function handle(BridgeEvent $event): void
    {
    }
}

class FakeMappedHandler
{
    public function handle(BridgeEvent $event): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Providers/BridgeProvider.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Providers;

use AppGraph\Tests\GeneratedEvents\Events\BridgeEvent;
use AppGraph\Tests\GeneratedEvents\Handlers\AfterCommitHandler;
use AppGraph\Tests\GeneratedEvents\Handlers\BeforeCommitHandler;
use AppGraph\Tests\GeneratedEvents\Handlers\FakeMappedHandler;
use AppGraph\Tests\GeneratedEvents\Handlers\InheritedQueuedHandler;
use AppGraph\Tests\GeneratedEvents\Handlers\OutsideQueuedHandler;
use Illuminate\Support\Facades\Event;

class BridgeProvider
{
    public function boot(): void
    {
        Event::listen(BridgeEvent::class, [InheritedQueuedHandler::class, 'process']);
        Event::listen(BridgeEvent::class, AfterCommitHandler::class);
        Event::listen(BridgeEvent::class, BeforeCommitHandler::class);
        Event::listen(BridgeEvent::class, OutsideQueuedHandler::class);
    }
}

class NotAnEventServiceProvider
{
    protected $listen = [
        BridgeEvent::class => [FakeMappedHandler::class],
    ];
}
PHP);

        file_put_contents($this->fixturePath.'/app/Http/Controllers/BridgeController.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedEvents\Http\Controllers;

use AppGraph\Tests\GeneratedEvents\Commands\BrokenThing;
use AppGraph\Tests\GeneratedEvents\Commands\BeforeCommitJob;
use AppGraph\Tests\GeneratedEvents\Commands\DoThing;
use AppGraph\Tests\GeneratedEvents\Commands\StaticThing;
use Illuminate\Support\Facades\Bus;

class BridgeController
{
    public function run(): void
    {
        Bus::dispatch(new DoThing());
        Bus::dispatch(new BrokenThing());
        Bus::dispatch(new BeforeCommitJob());
        StaticThing::dispatch();
    }
}
PHP);

        $graph = new Graph();
        (new EventFlowScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $root = 'AppGraph\Tests\GeneratedEvents\\';
        $event = $root.'Events\BridgeEvent';

        foreach (['DoThing', 'StaticThing'] as $command) {
            $class = $root.'Commands\\'.$command;
            $this->assertGraphHasEdge($array, $class, $class.'::handle', 'handled_by');
        }

        $broken = $root.'Commands\BrokenThing';
        $this->assertNull($this->graphEdge($array, $broken, $broken.'::__invoke', 'handled_by'));
        $this->assertContains('job_handler_not_public', array_column($array['meta']['warnings'] ?? [], 'reason'));

        $base = $root.'Handlers\BaseQueuedHandler';
        $inherited = $root.'Handlers\InheritedQueuedHandler';
        $inheritedEdge = $this->graphEdge($array, $event, $base.'::process', 'handled_by');
        $this->assertNotNull($inheritedEdge);
        $this->assertSame($inherited, $inheritedEdge['metadata']['handlerClass']);
        $this->assertTrue($inheritedEdge['metadata']['queued']);
        $this->assertFalse($inheritedEdge['metadata']['afterCommit']);
        $this->assertSame('redis', $inheritedEdge['metadata']['connection']);

        $afterCommit = $root.'Handlers\AfterCommitHandler';
        $afterCommitEdge = $this->graphEdge($array, $event, $afterCommit.'::handle', 'handled_by');
        $this->assertFalse($afterCommitEdge['metadata']['queued']);
        $this->assertTrue($afterCommitEdge['metadata']['afterCommit']);
        $this->assertSame('after_commit', $afterCommitEdge['metadata']['executionMode']);

        $beforeCommit = $root.'Handlers\BeforeCommitHandler';
        $this->assertTrue($this->graphEdge($array, $event, $beforeCommit.'::handle', 'handled_by')['metadata']['afterCommit']);

        $beforeCommitJob = $root.'Commands\BeforeCommitJob';
        $this->assertFalse($this->graphEdge($array, $beforeCommitJob, $beforeCommitJob.'::handle', 'handled_by')['metadata']['configuredAfterCommit']);

        $outside = $root.'Handlers\OutsideQueuedHandler';
        $this->assertGraphHasEdge($array, $event, $outside.'::handle', 'handled_by');
        $this->assertNull($this->graphEdge($array, $outside, $outside.'::handle', 'handled_by'));

        $fake = $root.'Handlers\FakeMappedHandler';
        $this->assertNull($this->graphEdge($array, $event, $fake.'::handle', 'handled_by'));
    }

    public function test_chains_traits_custom_interfaces_and_bus_maps_keep_exact_execution_semantics(): void
    {
        file_put_contents($this->fixturePath.'/app/Contracts/WorkflowContracts.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedWorkflow\Contracts;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

interface QueueContract extends ShouldQueue
{
}

interface NestedQueueContract extends QueueContract
{
}

interface AfterCommitContract extends ShouldHandleEventsAfterCommit
{
}

interface NestedAfterCommitContract extends AfterCommitContract
{
}
PHP);

        file_put_contents($this->fixturePath.'/app/Concerns/WorkflowHandlers.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedWorkflow\Concerns;

trait InnerHandlesWorkflow
{
    public function handle(): void
    {
    }
}

trait OuterHandlesWorkflow
{
    use InnerHandlesWorkflow;
}
PHP);

        file_put_contents($this->fixturePath.'/app/Events/WorkflowEvent.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedWorkflow\Events;

class WorkflowEvent
{
}
PHP);

        file_put_contents($this->fixturePath.'/app/Commands/WorkflowCommands.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedWorkflow\Commands;

use AppGraph\Tests\GeneratedWorkflow\Concerns\OuterHandlesWorkflow;
use AppGraph\Tests\GeneratedWorkflow\Contracts\NestedQueueContract;
use Illuminate\Bus\Queueable;

class ContextCommand
{
    use Queueable;

    public function handle(): void
    {
    }
}

class TraitCommand implements NestedQueueContract
{
    use OuterHandlesWorkflow;

    public function __invoke(): void
    {
    }
}

class MappedCommand
{
    public function handle(): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Handlers/MappedWorkflowHandler.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedWorkflow\Handlers;

use AppGraph\Tests\GeneratedWorkflow\Commands\MappedCommand;

class MappedWorkflowHandler
{
    public function __invoke(MappedCommand $command): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Listeners/AfterCommitWorkflowListener.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedWorkflow\Listeners;

use AppGraph\Tests\GeneratedWorkflow\Contracts\NestedAfterCommitContract;
use AppGraph\Tests\GeneratedWorkflow\Events\WorkflowEvent;

class AfterCommitWorkflowListener implements NestedAfterCommitContract
{
    public function handle(WorkflowEvent $event): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Http/Controllers/WorkflowController.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedWorkflow\Http\Controllers;

use AppGraph\Tests\GeneratedWorkflow\Commands\ContextCommand;
use AppGraph\Tests\GeneratedWorkflow\Commands\MappedCommand;
use AppGraph\Tests\GeneratedWorkflow\Commands\TraitCommand;
use Illuminate\Support\Facades\Bus;

class WorkflowController
{
    public function run(): void
    {
        Bus::dispatch(new ContextCommand());

        Bus::chain([
            new ContextCommand(),
            new TraitCommand(),
            new ContextCommand(),
        ])->onConnection('redis')->onQueue('workflow')->dispatch();

        Bus::dispatch(new MappedCommand());
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Providers/WorkflowServiceProvider.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedWorkflow\Providers;

use AppGraph\Tests\GeneratedWorkflow\Commands\MappedCommand;
use AppGraph\Tests\GeneratedWorkflow\Handlers\MappedWorkflowHandler;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\ServiceProvider;

class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Bus::map([
            MappedCommand::class => MappedWorkflowHandler::class,
        ]);
    }
}
PHP);

        $graph = new Graph();
        (new EventFlowScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $root = 'AppGraph\Tests\GeneratedWorkflow\\';
        $controller = $root.'Http\Controllers\WorkflowController::run';
        $context = $root.'Commands\ContextCommand';
        $traitCommand = $root.'Commands\TraitCommand';
        $mappedCommand = $root.'Commands\MappedCommand';

        $this->assertGraphHasEdge($array, $context, $context.'::handle', 'handled_by');
        $contextDispatch = $this->graphEdge($array, $controller, $context, 'dispatches');
        $this->assertNotNull($contextDispatch);
        $this->assertCount(3, $contextDispatch['metadata']['dispatchOccurrences']);
        $this->assertSame('sync', $contextDispatch['metadata']['dispatchMode']);
        $this->assertArrayNotHasKey('line', $contextDispatch['metadata']);
        $this->assertCount(2, $contextDispatch['metadata']['lines']);
        $this->assertArrayNotHasKey('offset', $contextDispatch['metadata']);
        $this->assertCount(2, $contextDispatch['metadata']['offsets']);
        $this->assertArrayNotHasKey('via', $contextDispatch['metadata']);
        $this->assertSame(['bus_chain', 'facade'], $contextDispatch['metadata']['vias']);
        $this->assertArrayNotHasKey('chained', $contextDispatch['metadata']);
        $this->assertSame([false, true], $contextDispatch['metadata']['chainedValues']);
        $this->assertArrayNotHasKey('chainPosition', $contextDispatch['metadata']);
        $this->assertSame([0, 2], $contextDispatch['metadata']['chainPositions']);
        $this->assertArrayNotHasKey('dispatchConfigurationSource', $contextDispatch['metadata']);
        $this->assertSame(['bus_chain'], $contextDispatch['metadata']['dispatchConfigurationSources']);

        $innerTrait = $root.'Concerns\InnerHandlesWorkflow';
        $this->assertGraphHasEdge($array, $traitCommand, $innerTrait.'::handle', 'handled_by');
        $this->assertNull($this->graphEdge($array, $traitCommand, $traitCommand.'::__invoke', 'handled_by'));
        $traitHandler = $this->graphEdge($array, $traitCommand, $innerTrait.'::handle', 'handled_by');
        $this->assertTrue($traitHandler['metadata']['configuredQueued']);
        $this->assertSame('queued', $traitHandler['metadata']['defaultDispatchMode']);
        $this->assertTrue($this->graphNode($array, $traitCommand)['metadata']['queued']);
        $this->assertSame('queued', $this->graphEdge($array, $controller, $traitCommand, 'dispatches')['metadata']['dispatchMode']);

        $mappedHandler = $root.'Handlers\MappedWorkflowHandler::__invoke';
        $this->assertGraphHasEdge($array, $mappedCommand, $mappedHandler, 'handled_by');
        $mappedEdge = $this->graphEdge($array, $mappedCommand, $mappedHandler, 'handled_by');
        $this->assertSame(0.85, $mappedEdge['confidence']);
        $this->assertSame('explicit_bus_map', $mappedEdge['metadata']['source']);
        $this->assertSame('service_provider_lifecycle_assumed', $mappedEdge['metadata']['assumption']);
        $this->assertSame('app/Providers/WorkflowServiceProvider.php', $mappedEdge['metadata']['file']);
        $this->assertSame('app/Handlers/MappedWorkflowHandler.php', $mappedEdge['metadata']['handlerFile']);
        $this->assertNull($this->graphEdge($array, $mappedCommand, $mappedCommand.'::handle', 'handled_by'));

        $event = $root.'Events\WorkflowEvent';
        $listener = $root.'Listeners\AfterCommitWorkflowListener::handle';
        $this->assertGraphHasEdge($array, $event, $listener, 'handled_by');
        $listenerEdge = $this->graphEdge($array, $event, $listener, 'handled_by');
        $this->assertFalse($listenerEdge['metadata']['queued']);
        $this->assertTrue($listenerEdge['metadata']['afterCommit']);
        $this->assertSame('after_commit', $listenerEdge['metadata']['executionMode']);

        foreach ($array['edges'] as $edge) {
            $this->assertNotNull($this->graphNode($array, $edge['from']), "Missing edge source [{$edge['from']}].");
            $this->assertNotNull($this->graphNode($array, $edge['to']), "Missing edge target [{$edge['to']}].");
        }
    }

    public function test_external_traits_adaptations_queue_precedence_and_fluent_runtime_order_are_proven_conservatively(): void
    {
        file_put_contents($this->fixturePath.'/app/Events/AdvancedEvent.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedAdvanced\Events;

class AdvancedEvent
{
}
PHP);

        file_put_contents($this->fixturePath.'/app/Jobs/AdvancedJobs.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedAdvanced\Jobs;

use AppGraph\Tests\Unit\ExternalAliasSource;
use AppGraph\Tests\Unit\ExternalNestedQueue;
use AppGraph\Tests\Unit\ExternalPrimaryHandler;
use AppGraph\Tests\Unit\ExternalPrivateHandlerBase;
use AppGraph\Tests\Unit\ExternalQueueOptions;
use AppGraph\Tests\Unit\ExternalSecondaryHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class PrecedenceJob
{
    use ExternalPrimaryHandler, ExternalSecondaryHandler {
        ExternalPrimaryHandler::handle insteadof ExternalSecondaryHandler;
        ExternalSecondaryHandler::handle as secondaryHandle;
    }

    public function __invoke(): void
    {
    }
}

class AliasJob
{
    use ExternalAliasSource {
        process as handle;
    }

    public function __invoke(): void
    {
    }
}

class ParentQueueJob
{
    public bool $afterCommit = false;

    public string $connection = 'parent-connection';

    public string $queue = 'parent-queue';
}

class ExternalQueueJob extends ParentQueueJob implements ExternalNestedQueue
{
    use ExternalQueueOptions;

    public function handle(): void
    {
    }
}

class InheritedPrivateJob extends ExternalPrivateHandlerBase
{
    public function __invoke(): void
    {
    }
}

class FluentJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public string $connection = 'own-connection';

    public string $queue = 'own-queue';

    public function handle(): void
    {
    }
}

class ChainFallbackJob implements ShouldQueue
{
    public function handle(): void
    {
    }
}

class FakeStaticJob
{
    public static function dispatch(): void
    {
    }

    public function handle(): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Listeners/AdvancedListeners.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedAdvanced\Listeners;

use AppGraph\Tests\GeneratedAdvanced\Events\AdvancedEvent;
use AppGraph\Tests\Unit\ExternalPrivateHandlerBase;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

class InheritedPrivateListener extends ExternalPrivateHandlerBase
{
    public function __invoke(AdvancedEvent $event): void
    {
    }
}

class QueuedEventAfterCommitListener implements ShouldQueue, ShouldHandleEventsAfterCommit
{
    public function handle(AdvancedEvent $event): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Providers/AdvancedEventServiceProvider.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedAdvanced\Providers;

use AppGraph\Tests\GeneratedAdvanced\Events\AdvancedEvent;
use AppGraph\Tests\GeneratedAdvanced\Listeners\InheritedPrivateListener;
use AppGraph\Tests\GeneratedAdvanced\Listeners\QueuedEventAfterCommitListener;
use Illuminate\Foundation\Support\Providers\EventServiceProvider;

class AdvancedEventServiceProvider extends EventServiceProvider
{
    protected $listen = [
        AdvancedEvent::class => [
            InheritedPrivateListener::class,
            QueuedEventAfterCommitListener::class,
        ],
    ];
}
PHP);

        file_put_contents($this->fixturePath.'/app/Http/Controllers/AdvancedController.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedAdvanced\Http\Controllers;

use AppGraph\Tests\GeneratedAdvanced\Jobs\AliasJob;
use AppGraph\Tests\GeneratedAdvanced\Jobs\ChainFallbackJob;
use AppGraph\Tests\GeneratedAdvanced\Jobs\ExternalQueueJob;
use AppGraph\Tests\GeneratedAdvanced\Jobs\FakeStaticJob;
use AppGraph\Tests\GeneratedAdvanced\Jobs\FluentJob;
use AppGraph\Tests\GeneratedAdvanced\Jobs\InheritedPrivateJob;
use AppGraph\Tests\GeneratedAdvanced\Jobs\PrecedenceJob;
use Illuminate\Support\Facades\Bus;

class AdvancedController
{
    public function jobs(): void
    {
        Bus::dispatch(new PrecedenceJob());
        Bus::dispatch(new AliasJob());
        Bus::dispatch(new ExternalQueueJob());
        Bus::dispatch(new InheritedPrivateJob());
        FakeStaticJob::dispatch();
    }

    public function fluent(): void
    {
        FluentJob::dispatch()
            ->onConnection('first-connection')
            ->onConnection('last-connection')
            ->onQueue('first-queue')
            ->onQueue('last-queue')
            ->afterCommit()
            ->beforeCommit();
    }

    public function chain(): void
    {
        Bus::chain([
            new FluentJob(),
            ChainFallbackJob::class,
        ])->onConnection('first-chain-connection')
            ->onConnection('last-chain-connection')
            ->onQueue('first-chain-queue')
            ->onQueue('last-chain-queue')
            ->dispatch();
    }
}
PHP);

        $graph = new Graph();
        (new EventFlowScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $root = 'AppGraph\Tests\GeneratedAdvanced\\';
        $controller = $root.'Http\Controllers\AdvancedController';

        $precedence = $root.'Jobs\PrecedenceJob';
        $this->assertGraphHasEdge(
            $array,
            $precedence,
            ExternalPrimaryHandler::class.'::handle',
            'handled_by',
        );
        $this->assertNull($this->graphEdge($array, $precedence, $precedence.'::__invoke', 'handled_by'));

        $alias = $root.'Jobs\AliasJob';
        $this->assertGraphHasEdge($array, $alias, $alias.'::handle', 'handled_by');
        $this->assertNull($this->graphEdge($array, $alias, $alias.'::__invoke', 'handled_by'));

        $externalQueue = $root.'Jobs\ExternalQueueJob';
        $queueHandler = $this->graphEdge($array, $externalQueue, $externalQueue.'::handle', 'handled_by');
        $this->assertTrue($queueHandler['metadata']['configuredQueued']);
        $this->assertTrue($queueHandler['metadata']['configuredAfterCommit']);
        $this->assertSame('trait-connection', $queueHandler['metadata']['configuredConnection']);
        $this->assertSame('trait-queue', $queueHandler['metadata']['configuredQueue']);

        $privateJob = $root.'Jobs\InheritedPrivateJob';
        $this->assertNull($this->graphEdge($array, $privateJob, $privateJob.'::__invoke', 'handled_by'));

        $fluent = $this->graphEdge($array, $controller.'::fluent', $root.'Jobs\FluentJob', 'dispatches');
        $this->assertSame('last-connection', $fluent['metadata']['connection']);
        $this->assertSame('last-queue', $fluent['metadata']['queue']);
        $this->assertFalse($fluent['metadata']['afterCommit']);

        $chainedOwn = $this->graphEdge($array, $controller.'::chain', $root.'Jobs\FluentJob', 'dispatches');
        $this->assertSame('own-connection', $chainedOwn['metadata']['connection']);
        $this->assertSame('own-queue', $chainedOwn['metadata']['queue']);
        $this->assertSame('initial_chain_dispatch', $chainedOwn['metadata']['executionSemantics']);
        $this->assertTrue($chainedOwn['metadata']['causalExecutionProven']);
        $this->assertSame(0.9, $chainedOwn['confidence']);

        $chainedFallback = $this->graphEdge($array, $controller.'::chain', $root.'Jobs\ChainFallbackJob', 'dispatches');
        $this->assertSame('last-chain-connection', $chainedFallback['metadata']['connection']);
        $this->assertSame('last-chain-queue', $chainedFallback['metadata']['queue']);
        $this->assertSame('class_string', $chainedFallback['metadata']['chainMemberSyntax']);

        $fakeStatic = $root.'Jobs\FakeStaticJob';
        $this->assertNull($this->graphEdge($array, $controller.'::jobs', $fakeStatic, 'dispatches'));

        $event = $root.'Events\AdvancedEvent';
        $privateListener = $root.'Listeners\InheritedPrivateListener';
        $this->assertGraphHasEdge($array, $event, $privateListener.'::__invoke', 'handled_by');
        $this->assertNull($this->graphEdge($array, $event, ExternalPrivateHandlerBase::class.'::handle', 'handled_by'));

        $queuedListener = $root.'Listeners\QueuedEventAfterCommitListener';
        $queuedListenerEdge = $this->graphEdge($array, $event, $queuedListener.'::handle', 'handled_by');
        $this->assertTrue($queuedListenerEdge['metadata']['queued']);
        $this->assertSame('queued', $queuedListenerEdge['metadata']['executionMode']);
        $this->assertArrayNotHasKey('afterCommit', $queuedListenerEdge['metadata']);

        $warningReasons = array_column($array['meta']['warnings'] ?? [], 'reason');
        $this->assertContains('job_handler_not_public', $warningReasons);
        $this->assertContains('static_dispatch_method_unproven', $warningReasons);
        $this->assertContains('bus_chain_class_string_member_unproven', $warningReasons);
    }

    public function test_container_bindings_map_lifecycle_and_dual_dispatch_roles_remain_explicit(): void
    {
        file_put_contents($this->fixturePath.'/app/Contracts/BindingContracts.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedBindings\Contracts;

use AppGraph\Tests\GeneratedBindings\Events\BoundEvent;

interface RequestedListener
{
    public function handle(BoundEvent $event): void;
}

interface MappedHandlerContract
{
}
PHP);

        file_put_contents($this->fixturePath.'/app/Events/BindingEvents.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedBindings\Events;

class BoundEvent
{
}

class DualPayload
{
    public function handle(): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Commands/BindingCommands.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedBindings\Commands;

class MappedCommand
{
    public function handle(): void
    {
    }
}

class UnknownMappedCommand
{
    public function handle(): void
    {
    }
}

class ArbitraryMapJob
{
    public function handle(): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Handlers/BindingHandlers.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedBindings\Handlers;

use AppGraph\Tests\GeneratedBindings\Commands\MappedCommand;

class ConcreteMappedHandler
{
    public function __invoke(MappedCommand $command): void
    {
    }
}

class UnknownMappedHandler
{
    public function handle(): void
    {
    }
}

class WrongArbitraryHandler
{
    public function handle(): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Listeners/BindingListeners.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedBindings\Listeners;

use AppGraph\Tests\GeneratedBindings\Events\BoundEvent;
use AppGraph\Tests\GeneratedBindings\Events\DualPayload;

class ConcreteListener
{
    public function handle(BoundEvent $event): void
    {
    }
}

class UnknownListener
{
    public function handle(BoundEvent $event): void
    {
    }
}

class DualListener
{
    public function handle(DualPayload $event): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Providers/BindingProviders.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedBindings\Providers;

use AppGraph\Tests\GeneratedBindings\Commands\MappedCommand;
use AppGraph\Tests\GeneratedBindings\Commands\UnknownMappedCommand;
use AppGraph\Tests\GeneratedBindings\Contracts\MappedHandlerContract;
use AppGraph\Tests\GeneratedBindings\Contracts\RequestedListener;
use AppGraph\Tests\GeneratedBindings\Events\BoundEvent;
use AppGraph\Tests\GeneratedBindings\Events\DualPayload;
use AppGraph\Tests\GeneratedBindings\Handlers\UnknownMappedHandler;
use AppGraph\Tests\GeneratedBindings\Listeners\DualListener;
use AppGraph\Tests\GeneratedBindings\Listeners\UnknownListener;
use Illuminate\Foundation\Support\Providers\EventServiceProvider;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\ServiceProvider;

class BindingEventServiceProvider extends EventServiceProvider
{
    protected $listen = [
        BoundEvent::class => [
            RequestedListener::class,
            UnknownListener::class,
        ],
        DualPayload::class => [
            DualListener::class,
        ],
    ];
}

class BindingBusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Bus::map([
            MappedCommand::class => MappedHandlerContract::class,
            UnknownMappedCommand::class => UnknownMappedHandler::class,
        ]);
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Http/Controllers/BindingController.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedBindings\Http\Controllers;

use AppGraph\Tests\GeneratedBindings\Commands\ArbitraryMapJob;
use AppGraph\Tests\GeneratedBindings\Commands\MappedCommand;
use AppGraph\Tests\GeneratedBindings\Commands\UnknownMappedCommand;
use AppGraph\Tests\GeneratedBindings\Events\DualPayload;
use AppGraph\Tests\GeneratedBindings\Handlers\WrongArbitraryHandler;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

class BindingController
{
    public function run(): void
    {
        Bus::dispatch(new MappedCommand());
        Bus::dispatch(new UnknownMappedCommand());
        Bus::dispatch(new ArbitraryMapJob());

        Bus::map([
            ArbitraryMapJob::class => WrongArbitraryHandler::class,
        ]);
    }

    public function dual(): void
    {
        Event::dispatch(new DualPayload());
        Bus::dispatch(new DualPayload());
    }
}
PHP);

        $root = 'AppGraph\Tests\GeneratedBindings\\';
        $container = new Container();
        $container->bind(
            $root.'Contracts\RequestedListener',
            $root.'Listeners\ConcreteListener',
        );
        $container->bind(
            $root.'Contracts\MappedHandlerContract',
            $root.'Handlers\ConcreteMappedHandler',
        );
        $container->bind(
            $root.'Listeners\UnknownListener',
            static fn () => null,
        );
        $container->bind(
            $root.'Handlers\UnknownMappedHandler',
            static fn () => null,
        );

        $registry = new ContainerBindingRegistry($container, 'testing');
        $graph = new Graph();
        (new EventFlowScanner(new FileFinder($this->fixturePath), $registry))->scan($graph);
        $array = $graph->toArray();

        $event = $root.'Events\BoundEvent';
        $concreteListener = $root.'Listeners\ConcreteListener';
        $listenerEdge = $this->graphEdge($array, $event, $concreteListener.'::handle', 'handled_by');
        $this->assertNotNull($listenerEdge);
        $this->assertSame($root.'Contracts\RequestedListener', $listenerEdge['metadata']['bindingAbstract']);
        $this->assertSame($concreteListener, $listenerEdge['metadata']['bindingConcrete']);
        $this->assertSame(1.0, $listenerEdge['metadata']['bindingConfidence']);
        $this->assertNull($this->graphEdge($array, $event, $root.'Listeners\UnknownListener::handle', 'handled_by'));

        $mapped = $root.'Commands\MappedCommand';
        $concreteMapped = $root.'Handlers\ConcreteMappedHandler';
        $mappedEdge = $this->graphEdge($array, $mapped, $concreteMapped.'::__invoke', 'handled_by');
        $this->assertNotNull($mappedEdge);
        $this->assertSame(0.85, $mappedEdge['confidence']);
        $this->assertSame($root.'Contracts\MappedHandlerContract', $mappedEdge['metadata']['bindingAbstract']);
        $this->assertSame($concreteMapped, $mappedEdge['metadata']['handlerClass']);

        $unknownMapped = $root.'Commands\UnknownMappedCommand';
        $this->assertNull($this->graphEdge(
            $array,
            $unknownMapped,
            $root.'Handlers\UnknownMappedHandler::handle',
            'handled_by',
        ));

        $arbitrary = $root.'Commands\ArbitraryMapJob';
        $this->assertGraphHasEdge($array, $arbitrary, $arbitrary.'::handle', 'handled_by');
        $this->assertNull($this->graphEdge(
            $array,
            $arbitrary,
            $root.'Handlers\WrongArbitraryHandler::handle',
            'handled_by',
        ));

        $controller = $root.'Http\Controllers\BindingController';
        $dual = $root.'Events\DualPayload';
        $dualDispatch = $this->graphEdge($array, $controller.'::dual', $dual, 'dispatches');
        $this->assertArrayNotHasKey('kind', $dualDispatch['metadata']);
        $this->assertSame(['event', 'job'], $dualDispatch['metadata']['dispatchKinds']);
        $this->assertSame(
            ['event', 'job'],
            array_values(array_unique(array_column($dualDispatch['metadata']['dispatchOccurrences'], 'kind'))),
        );
        $this->assertSame(['event', 'job'], $this->graphNode($array, $dual)['metadata']['roles']);

        $jobBridge = $this->graphEdge($array, $dual, $dual.'::handle', 'handled_by');
        $this->assertSame('job', $jobBridge['metadata']['kind']);
        $listenerBridge = $this->graphEdge($array, $dual, $root.'Listeners\DualListener::handle', 'handled_by');
        $this->assertSame('listener', $listenerBridge['metadata']['kind']);

        $warningReasons = array_column($array['meta']['warnings'] ?? [], 'reason');
        $this->assertContains('listener_binding_target_unknown', $warningReasons);
        $this->assertContains('mapped_handler_binding_target_unknown', $warningReasons);
        $this->assertContains('bus_map_scope_unproven', $warningReasons);
    }

    public function test_booted_registries_are_authoritative_without_resolving_factories(): void
    {
        file_put_contents($this->fixturePath.'/app/Events/BootedRegistryEvent.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedBootedTruth\Events;

class BootedRegistryEvent
{
}
PHP);

        file_put_contents($this->fixturePath.'/app/Listeners/BootedRegistryListeners.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedBootedTruth\Listeners;

use AppGraph\Tests\GeneratedBootedTruth\Events\BootedRegistryEvent;

class ActiveListener
{
    public function handle(BootedRegistryEvent $event): void
    {
    }
}

class InactiveListener
{
    public function handle(BootedRegistryEvent $event): void
    {
    }
}

class RuntimeOnlyListener
{
    public function process(BootedRegistryEvent $event): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Jobs/BootedRegistryJobs.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedBootedTruth\Jobs;

class MappedJob
{
    public function handle(): void
    {
    }
}

class SourceOnlyMappedJob
{
    public function handle(): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Handlers/BootedRegistryHandlers.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedBootedTruth\Handlers;

class SourceAHandler
{
    public function handle(object $job): void
    {
    }
}

class SourceZHandler
{
    public function handle(object $job): void
    {
    }
}

class BootedHandler
{
    public function handle(object $job): void
    {
    }
}

class InactiveHandler
{
    public function handle(object $job): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Providers/BootedRegistryProviders.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedBootedTruth\Providers;

use AppGraph\Tests\GeneratedBootedTruth\Events\BootedRegistryEvent;
use AppGraph\Tests\GeneratedBootedTruth\Handlers\InactiveHandler;
use AppGraph\Tests\GeneratedBootedTruth\Handlers\SourceAHandler;
use AppGraph\Tests\GeneratedBootedTruth\Handlers\SourceZHandler;
use AppGraph\Tests\GeneratedBootedTruth\Jobs\MappedJob;
use AppGraph\Tests\GeneratedBootedTruth\Jobs\SourceOnlyMappedJob;
use AppGraph\Tests\GeneratedBootedTruth\Listeners\ActiveListener;
use AppGraph\Tests\GeneratedBootedTruth\Listeners\InactiveListener;
use Illuminate\Foundation\Support\Providers\EventServiceProvider;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\ServiceProvider;

class DeclaredEventProvider extends EventServiceProvider
{
    protected $listen = [
        BootedRegistryEvent::class => [
            ActiveListener::class,
            InactiveListener::class,
        ],
    ];
}

class ASourceBusProvider extends ServiceProvider
{
    public function register(): void
    {
        Bus::map([
            MappedJob::class => SourceAHandler::class,
            SourceOnlyMappedJob::class => InactiveHandler::class,
        ]);
    }
}

class ZSourceBusProvider extends ServiceProvider
{
    public function boot(): void
    {
        Bus::map([
            MappedJob::class => SourceZHandler::class,
        ]);
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Http/Controllers/BootedRegistryController.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedBootedTruth\Http\Controllers;

use AppGraph\Tests\GeneratedBootedTruth\Events\BootedRegistryEvent;
use AppGraph\Tests\GeneratedBootedTruth\Jobs\MappedJob;
use AppGraph\Tests\GeneratedBootedTruth\Jobs\SourceOnlyMappedJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

class BootedRegistryController
{
    public function run(): void
    {
        Event::dispatch(new BootedRegistryEvent());
        Bus::dispatch(new MappedJob());
        Bus::dispatch(new SourceOnlyMappedJob());
    }
}
PHP);

        $root = 'AppGraph\Tests\GeneratedBootedTruth\\';
        $event = $root.'Events\BootedRegistryEvent';
        $active = $root.'Listeners\ActiveListener';
        $inactive = $root.'Listeners\InactiveListener';
        $runtimeOnly = $root.'Listeners\RuntimeOnlyListener';
        $mappedJob = $root.'Jobs\MappedJob';
        $sourceOnlyJob = $root.'Jobs\SourceOnlyMappedJob';
        $bootedHandler = $root.'Handlers\BootedHandler';

        EventFlowConstructionProbe::$factoryCalls = 0;
        EventFlowConstructionProbe::$constructorCalls = 0;

        $container = new Container();
        $events = new EventDispatcher($container);
        $bus = new BusDispatcher($container);
        $container->instance('events', $events);
        $container->instance('Illuminate\Contracts\Bus\Dispatcher', $bus);
        $container->bind($active, static function (): ExternalBootedListener {
            EventFlowConstructionProbe::$factoryCalls++;

            return new ExternalBootedListener();
        });
        $container->bind($bootedHandler, static function (): ExternalBootedBusHandler {
            EventFlowConstructionProbe::$factoryCalls++;

            return new ExternalBootedBusHandler();
        });

        $events->listen($event, $active);
        $events->listen($event, [$runtimeOnly, 'process']);
        $bus->map([$mappedJob => $bootedHandler]);

        $graph = new Graph();
        $registry = new ContainerBindingRegistry($container, 'testing', $root);
        (new EventFlowScanner(new FileFinder($this->fixturePath), $registry))->scan($graph);
        $array = $graph->toArray();

        $activeEdge = $this->graphEdge($array, $event, ExternalBootedListener::class.'::handle', 'handled_by');
        $this->assertNotNull($activeEdge);
        $this->assertTrue($activeEdge['metadata']['causalExecutionProven']);
        $this->assertSame('booted_dispatcher', $activeEdge['metadata']['registrationEvidence']);
        $activeRegistration = $this->graphEdge($array, $active.'::handle', $event, 'listens_to');
        $this->assertFalse($activeRegistration['metadata']['runtimeOnly']);
        $this->assertSame('event_service_provider', $activeRegistration['metadata']['source']);

        $runtimeEdge = $this->graphEdge($array, $event, $runtimeOnly.'::process', 'handled_by');
        $this->assertNotNull($runtimeEdge);
        $this->assertTrue($runtimeEdge['metadata']['causalExecutionProven']);
        $this->assertSame('booted_event_dispatcher', $runtimeEdge['metadata']['source']);
        $runtimeRegistration = $this->graphEdge($array, $runtimeOnly.'::process', $event, 'listens_to');
        $this->assertTrue($runtimeRegistration['metadata']['runtimeOnly']);

        $inactiveEdge = $this->graphEdge($array, $event, $inactive.'::handle', 'handled_by');
        $this->assertNotNull($inactiveEdge);
        $this->assertFalse($inactiveEdge['metadata']['causalExecutionProven']);
        $this->assertSame('not_in_booted_dispatcher', $inactiveEdge['metadata']['registrationEvidence']);

        $mappedEdge = $this->graphEdge($array, $mappedJob, ExternalBootedBusHandler::class.'::handle', 'handled_by');
        $this->assertNotNull($mappedEdge);
        $this->assertTrue($mappedEdge['metadata']['causalExecutionProven']);
        $this->assertSame('booted_bus_dispatcher', $mappedEdge['metadata']['source']);
        $this->assertNull($this->graphEdge($array, $mappedJob, $root.'Handlers\SourceAHandler::handle', 'handled_by'));
        $this->assertNull($this->graphEdge($array, $mappedJob, $root.'Handlers\SourceZHandler::handle', 'handled_by'));

        $sourceOnlyEdge = $this->graphEdge($array, $sourceOnlyJob, $sourceOnlyJob.'::handle', 'handled_by');
        $this->assertNotNull($sourceOnlyEdge);
        $this->assertSame('laravel_job_convention', $sourceOnlyEdge['metadata']['source']);
        $this->assertNotSame(false, $sourceOnlyEdge['metadata']['causalExecutionProven'] ?? null);
        $this->assertNull($this->graphEdge($array, $sourceOnlyJob, $root.'Handlers\InactiveHandler::handle', 'handled_by'));

        $this->assertSame(0, EventFlowConstructionProbe::$factoryCalls);
        $this->assertSame(0, EventFlowConstructionProbe::$constructorCalls);
    }

    public function test_listener_selection_and_queueing_follow_the_registered_class_before_the_resolved_target(): void
    {
        file_put_contents($this->fixturePath.'/app/Events/ListenerSelectionEvent.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedListenerSelection\Events;

class ListenerSelectionEvent
{
}
PHP);

        file_put_contents($this->fixturePath.'/app/Contracts/ListenerSelectionContracts.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedListenerSelection\Contracts;

use AppGraph\Tests\GeneratedListenerSelection\Events\ListenerSelectionEvent;

interface SyncListenerContract
{
    public function handle(ListenerSelectionEvent $event): void;
}

interface EmptyListenerContract
{
}
PHP);

        file_put_contents($this->fixturePath.'/app/Handlers/ListenerSelectionTargets.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedListenerSelection\Handlers;

use AppGraph\Tests\GeneratedListenerSelection\Events\ListenerSelectionEvent;
use Illuminate\Contracts\Queue\ShouldQueue;

class ConcreteQueuedListener implements ShouldQueue
{
    public string $queue = 'effective-queue';

    public function handle(ListenerSelectionEvent $event): void
    {
    }
}

class ConcreteSyncListener
{
    public string $queue = 'ignored-effective-queue';

    public function handle(ListenerSelectionEvent $event): void
    {
    }
}

class ConcreteHandleOnlyListener
{
    public function handle(ListenerSelectionEvent $event): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Listeners/ListenerSelectionListeners.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedListenerSelection\Listeners;

use AppGraph\Tests\GeneratedListenerSelection\Events\ListenerSelectionEvent;
use Illuminate\Contracts\Queue\ShouldQueue;

class RequestedQueuedListener implements ShouldQueue
{
    public string $queue = 'requested-queue';

    public function handle(ListenerSelectionEvent $event): void
    {
    }
}

class InvokableListener
{
    public function __invoke(ListenerSelectionEvent $event): void
    {
    }
}

class NeverQueueListener implements ShouldQueue
{
    public function shouldQueue(ListenerSelectionEvent $event): bool
    {
        return false;
    }

    public function handle(ListenerSelectionEvent $event): void
    {
    }
}

class PrivateShouldQueueParent
{
    private function shouldQueue(ListenerSelectionEvent $event): bool
    {
        return true;
    }
}

class InheritedPrivateShouldQueueListener extends PrivateShouldQueueParent implements ShouldQueue
{
    public function handle(ListenerSelectionEvent $event): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Providers/ListenerSelectionProvider.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedListenerSelection\Providers;

use AppGraph\Tests\GeneratedListenerSelection\Contracts\EmptyListenerContract;
use AppGraph\Tests\GeneratedListenerSelection\Contracts\SyncListenerContract;
use AppGraph\Tests\GeneratedListenerSelection\Events\ListenerSelectionEvent;
use AppGraph\Tests\GeneratedListenerSelection\Listeners\InheritedPrivateShouldQueueListener;
use AppGraph\Tests\GeneratedListenerSelection\Listeners\InvokableListener;
use AppGraph\Tests\GeneratedListenerSelection\Listeners\NeverQueueListener;
use AppGraph\Tests\GeneratedListenerSelection\Listeners\RequestedQueuedListener;
use Illuminate\Foundation\Support\Providers\EventServiceProvider;
use Illuminate\Support\Facades\Event;

class ListenerSelectionProvider extends EventServiceProvider
{
    protected $listen = [
        ListenerSelectionEvent::class => [
            SyncListenerContract::class,
            EmptyListenerContract::class,
            RequestedQueuedListener::class,
            NeverQueueListener::class,
            InheritedPrivateShouldQueueListener::class,
        ],
    ];

    public function boot(): void
    {
        Event::listen(ListenerSelectionEvent::class, [InvokableListener::class, 'missing']);
    }
}
PHP);

        $root = 'AppGraph\Tests\GeneratedListenerSelection\\';
        $event = $root.'Events\ListenerSelectionEvent';
        $syncContract = $root.'Contracts\SyncListenerContract';
        $emptyContract = $root.'Contracts\EmptyListenerContract';
        $requestedQueued = $root.'Listeners\RequestedQueuedListener';
        $never = $root.'Listeners\NeverQueueListener';
        $inheritedPrivate = $root.'Listeners\InheritedPrivateShouldQueueListener';
        $invokable = $root.'Listeners\InvokableListener';
        $concreteQueued = $root.'Handlers\ConcreteQueuedListener';
        $concreteSync = $root.'Handlers\ConcreteSyncListener';
        $handleOnly = $root.'Handlers\ConcreteHandleOnlyListener';

        $container = new Container();
        $events = new EventDispatcher($container);
        $container->instance('events', $events);
        $container->bind($syncContract, $concreteQueued);
        $container->bind($emptyContract, $handleOnly);
        $container->bind($requestedQueued, $concreteSync);

        $events->listen($event, $syncContract);
        $events->listen($event, $emptyContract);
        $events->listen($event, $requestedQueued);
        $events->listen($event, $never);
        $events->listen($event, $inheritedPrivate);
        $events->listen($event, [$invokable, 'missing']);

        $graph = new Graph();
        $registry = new ContainerBindingRegistry($container, 'testing', $root);
        (new EventFlowScanner(new FileFinder($this->fixturePath), $registry))->scan($graph);
        $array = $graph->toArray();

        $syncEdge = $this->graphEdge($array, $event, $concreteQueued.'::handle', 'handled_by');
        $this->assertNotNull($syncEdge);
        $this->assertFalse($syncEdge['metadata']['queued']);
        $this->assertSame('sync', $syncEdge['metadata']['executionMode']);
        $this->assertSame($syncContract, $syncEdge['metadata']['requestedHandlerClass']);

        $queuedEdge = $this->graphEdge($array, $event, $concreteSync.'::handle', 'handled_by');
        $this->assertNotNull($queuedEdge);
        $this->assertTrue($queuedEdge['metadata']['queued']);
        $this->assertSame('requested-queue', $queuedEdge['metadata']['queue']);
        $this->assertSame('property_default', $queuedEdge['metadata']['queueSource']);
        $this->assertSame($requestedQueued, $queuedEdge['metadata']['requestedHandlerClass']);

        $this->assertNull($this->graphEdge($array, $event, $handleOnly.'::handle', 'handled_by'));
        $this->assertGraphHasEdge($array, $event, $invokable.'::__invoke', 'handled_by');
        $this->assertNull($this->graphEdge($array, $event, $never.'::handle', 'handled_by'));
        $this->assertNull($this->graphEdge($array, $event, $inheritedPrivate.'::handle', 'handled_by'));

        $neverRegistration = $this->graphEdge($array, $never.'::handle', $event, 'listens_to');
        $this->assertSame('never', $neverRegistration['metadata']['shouldQueueDecision']);
        $this->assertTrue($neverRegistration['metadata']['causalExecutionProven']);

        $warningReasons = array_column($array['meta']['warnings'] ?? [], 'reason');
        $this->assertContains('listener_should_queue_always_false', $warningReasons);
        $this->assertContains('listener_should_queue_not_public', $warningReasons);
    }

    public function test_dispatch_guards_require_executable_laravel_semantics(): void
    {
        mkdir($this->fixturePath.'/app/Support', 0775, true);

        file_put_contents($this->fixturePath.'/app/Events/FacadeDecoyEvent.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedDispatchGuards\Events;

class FacadeDecoyEvent
{
}
PHP);

        file_put_contents($this->fixturePath.'/app/Support/FacadeDecoys.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedDispatchGuards\Support;

class Bus
{
    public static function dispatch(object $job): void
    {
    }
}

class Event
{
    public static function dispatch(object $event): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Jobs/DispatchGuardJobs.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedDispatchGuards\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\PreparesForDispatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Queue;

class ValidFluentJob implements ShouldQueue
{
    use Dispatchable;

    public ?bool $afterCommit = null;

    public ?string $connection = null;

    public ?string $queue = null;

    public function onConnection(string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    public function onQueue(string $queue): static
    {
        $this->queue = $queue;

        return $this;
    }

    public function afterCommit(): static
    {
        $this->afterCommit = true;

        return $this;
    }

    public function beforeCommit(): static
    {
        $this->afterCommit = false;

        return $this;
    }

    public function delay(int $seconds): static
    {
        return $this;
    }

    public function handle(): void
    {
    }
}

class InvalidFluentJob implements ShouldQueue
{
    use Dispatchable;

    public function handle(): void
    {
    }
}

class PrepareNeverJob implements ShouldQueue, PreparesForDispatch
{
    use Dispatchable;

    public function prepareForDispatch(): bool
    {
        return false;
    }

    public function handle(): void
    {
    }
}

class ValidChainFirst implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
    }
}

class ValidChainLater implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
    }
}

class InvalidChainFirst implements ShouldQueue
{
    public function handle(): void
    {
    }
}

class PrivateDispatchJob
{
    use Dispatchable {
        dispatch as private;
    }

    public function handle(): void
    {
    }
}

class OverrideAfterResponseJob
{
    use Dispatchable;

    public static function dispatch(...$arguments): object
    {
        return new \stdClass();
    }

    public function handle(): void
    {
    }
}

#[Connection('attribute-connection')]
#[Queue('attribute-queue')]
class AttributedJob implements ShouldQueue
{
    use Dispatchable;

    public string $connection = 'property-connection';

    public string $queue = 'property-queue';

    public function handle(): void
    {
    }
}

class FacadeDecoyJob
{
    public function handle(): void
    {
    }
}

abstract class AbstractJob
{
    use Dispatchable;

    public function handle(): void
    {
    }
}

trait TraitJob
{
    use Dispatchable;

    public function handle(): void
    {
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Providers/DispatchGuardProvider.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedDispatchGuards\Providers;

use AppGraph\Tests\GeneratedDispatchGuards\Jobs\FacadeDecoyJob;
use AppGraph\Tests\GeneratedDispatchGuards\Jobs\TraitJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\ServiceProvider;

class DispatchGuardProvider extends ServiceProvider
{
    public function register(): void
    {
        Bus::map([
            FacadeDecoyJob::class => TraitJob::class,
        ]);
    }
}
PHP);

        file_put_contents($this->fixturePath.'/app/Http/Controllers/DispatchGuardController.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedDispatchGuards\Http\Controllers;

use AppGraph\Tests\GeneratedDispatchGuards\Events\FacadeDecoyEvent;
use AppGraph\Tests\GeneratedDispatchGuards\Jobs\AbstractJob;
use AppGraph\Tests\GeneratedDispatchGuards\Jobs\AttributedJob;
use AppGraph\Tests\GeneratedDispatchGuards\Jobs\FacadeDecoyJob;
use AppGraph\Tests\GeneratedDispatchGuards\Jobs\InvalidChainFirst;
use AppGraph\Tests\GeneratedDispatchGuards\Jobs\InvalidFluentJob;
use AppGraph\Tests\GeneratedDispatchGuards\Jobs\OverrideAfterResponseJob;
use AppGraph\Tests\GeneratedDispatchGuards\Jobs\PrepareNeverJob;
use AppGraph\Tests\GeneratedDispatchGuards\Jobs\PrivateDispatchJob;
use AppGraph\Tests\GeneratedDispatchGuards\Jobs\TraitJob;
use AppGraph\Tests\GeneratedDispatchGuards\Jobs\ValidChainFirst;
use AppGraph\Tests\GeneratedDispatchGuards\Jobs\ValidChainLater;
use AppGraph\Tests\GeneratedDispatchGuards\Jobs\ValidFluentJob;
use AppGraph\Tests\GeneratedDispatchGuards\Support\Bus as LocalBus;
use AppGraph\Tests\GeneratedDispatchGuards\Support\Event as LocalEvent;
use Illuminate\Support\Facades\Bus;

class DispatchGuardController
{
    public function fluent(): void
    {
        ValidFluentJob::dispatch()
            ->onConnection('runtime-connection')
            ->onQueue('runtime-queue')
            ->delay(5)
            ->afterCommit();
    }

    public function invalidFluent(): void
    {
        InvalidFluentJob::dispatch()->onQueue('must-not-be-trusted');
    }

    public function invalidDelegatedFluent(): void
    {
        InvalidFluentJob::dispatch()->delay(5);
    }

    public function prepare(): void
    {
        PrepareNeverJob::dispatch();
    }

    public function validChain(): void
    {
        Bus::chain([
            new ValidChainFirst(),
            new ValidChainLater(),
        ])->dispatch();
    }

    public function invalidChain(): void
    {
        Bus::chain([
            new InvalidChainFirst(),
            new ValidChainLater(),
        ])->dispatch();
    }

    public function staticGuards(): void
    {
        PrivateDispatchJob::dispatch();
        OverrideAfterResponseJob::dispatchAfterResponse();
        AbstractJob::dispatch();
        TraitJob::dispatch();
    }

    public function facadeNonConcrete(): void
    {
        Bus::dispatch(new AbstractJob());
        Bus::dispatch(new TraitJob());
    }

    public function helperNonConcrete(): void
    {
        dispatch(new AbstractJob());
        dispatch(new TraitJob());
    }

    public function chainNonConcrete(): void
    {
        Bus::chain([
            new AbstractJob(),
            new ValidChainLater(),
        ])->dispatch();
    }

    public function attributes(): void
    {
        AttributedJob::dispatch();
    }

    public function facadeDecoys(): void
    {
        LocalBus::dispatch(new FacadeDecoyJob());
        LocalEvent::dispatch(new FacadeDecoyEvent());
    }
}
PHP);

        $graph = new Graph();
        (new EventFlowScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $root = 'AppGraph\Tests\GeneratedDispatchGuards\\';
        $controller = $root.'Http\Controllers\DispatchGuardController';

        $validFluent = $this->graphEdge($array, $controller.'::fluent', $root.'Jobs\ValidFluentJob', 'dispatches');
        $this->assertNotNull($validFluent);
        $this->assertTrue($validFluent['metadata']['causalExecutionProven']);
        $this->assertSame('runtime-connection', $validFluent['metadata']['connection']);
        $this->assertSame('runtime-queue', $validFluent['metadata']['queue']);
        $this->assertTrue($validFluent['metadata']['afterCommit']);

        $invalidFluent = $this->graphEdge($array, $controller.'::invalidFluent', $root.'Jobs\InvalidFluentJob', 'dispatches');
        $this->assertNotNull($invalidFluent);
        $this->assertFalse($invalidFluent['metadata']['causalExecutionProven']);
        $this->assertArrayNotHasKey('queue', $invalidFluent['metadata']);

        $invalidDelegatedFluent = $this->graphEdge($array, $controller.'::invalidDelegatedFluent', $root.'Jobs\InvalidFluentJob', 'dispatches');
        $this->assertNotNull($invalidDelegatedFluent);
        $this->assertFalse($invalidDelegatedFluent['metadata']['causalExecutionProven']);

        $prepare = $this->graphEdge($array, $controller.'::prepare', $root.'Jobs\PrepareNeverJob', 'dispatches');
        $this->assertNotNull($prepare);
        $this->assertFalse($prepare['metadata']['causalExecutionProven']);
        $this->assertSame('never', $prepare['metadata']['prepareForDispatchDecision']);

        $first = $this->graphEdge($array, $controller.'::validChain', $root.'Jobs\ValidChainFirst', 'dispatches');
        $later = $this->graphEdge($array, $controller.'::validChain', $root.'Jobs\ValidChainLater', 'dispatches');
        $this->assertTrue($first['metadata']['causalExecutionProven']);
        $this->assertFalse($later['metadata']['causalExecutionProven']);
        $this->assertSame('initial_chain_dispatch', $first['metadata']['executionSemantics']);
        $this->assertSame('declared_chain_membership', $later['metadata']['executionSemantics']);

        $invalidFirst = $this->graphEdge($array, $controller.'::invalidChain', $root.'Jobs\InvalidChainFirst', 'dispatches');
        $this->assertNotNull($invalidFirst);
        $this->assertFalse($invalidFirst['metadata']['causalExecutionProven']);

        $this->assertNull($this->graphEdge($array, $controller.'::staticGuards', $root.'Jobs\PrivateDispatchJob', 'dispatches'));
        $this->assertNull($this->graphEdge($array, $controller.'::staticGuards', $root.'Jobs\OverrideAfterResponseJob', 'dispatches'));
        $this->assertNull($this->graphEdge($array, $controller.'::staticGuards', $root.'Jobs\AbstractJob', 'dispatches'));
        $this->assertNull($this->graphEdge($array, $controller.'::staticGuards', $root.'Jobs\TraitJob', 'dispatches'));
        $this->assertNull($this->graphEdge($array, $controller.'::facadeNonConcrete', $root.'Jobs\AbstractJob', 'dispatches'));
        $this->assertNull($this->graphEdge($array, $controller.'::facadeNonConcrete', $root.'Jobs\TraitJob', 'dispatches'));
        $this->assertNull($this->graphEdge($array, $controller.'::helperNonConcrete', $root.'Jobs\AbstractJob', 'dispatches'));
        $this->assertNull($this->graphEdge($array, $controller.'::helperNonConcrete', $root.'Jobs\TraitJob', 'dispatches'));
        $this->assertNull($this->graphEdge($array, $controller.'::chainNonConcrete', $root.'Jobs\AbstractJob', 'dispatches'));
        $declaredLater = $this->graphEdge($array, $controller.'::chainNonConcrete', $root.'Jobs\ValidChainLater', 'dispatches');
        $this->assertNotNull($declaredLater);
        $this->assertFalse($declaredLater['metadata']['causalExecutionProven']);

        $attributed = $this->graphEdge($array, $controller.'::attributes', $root.'Jobs\AttributedJob', 'dispatches');
        $this->assertSame('attribute-connection', $attributed['metadata']['connection']);
        $this->assertSame('attribute-queue', $attributed['metadata']['queue']);
        $attributedNode = $this->graphNode($array, $root.'Jobs\AttributedJob');
        $this->assertSame('class_attribute', $attributedNode['metadata']['connectionSource']);
        $this->assertSame('class_attribute', $attributedNode['metadata']['queueSource']);

        $this->assertNull($this->graphEdge($array, $controller.'::facadeDecoys', $root.'Jobs\FacadeDecoyJob', 'dispatches'));
        $this->assertNull($this->graphEdge($array, $controller.'::facadeDecoys', $root.'Events\FacadeDecoyEvent', 'dispatches'));
        $this->assertNull($this->graphEdge($array, $root.'Jobs\AbstractJob', $root.'Jobs\AbstractJob::handle', 'handled_by'));
        $this->assertNull($this->graphEdge($array, $root.'Jobs\TraitJob', $root.'Jobs\TraitJob::handle', 'handled_by'));
        $this->assertNull($this->graphEdge($array, $root.'Jobs\FacadeDecoyJob', $root.'Jobs\TraitJob::handle', 'handled_by'));

        $warningReasons = array_column($array['meta']['warnings'] ?? [], 'reason');
        $this->assertContains('pending_dispatch_job_method_unproven', $warningReasons);
        $this->assertContains('bus_chain_first_job_method_unproven', $warningReasons);
        $this->assertContains('static_dispatch_method_unproven', $warningReasons);
        $this->assertContains('job_not_instantiable', $warningReasons);
        $this->assertContains('dispatch_target_not_instantiable', $warningReasons);
        $this->assertContains('job_handler_not_instantiable', $warningReasons);
    }
}
