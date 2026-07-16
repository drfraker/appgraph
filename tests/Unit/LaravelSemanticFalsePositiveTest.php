<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Scanners\DataFlowScanner;
use AppGraph\Scanners\EventFlowScanner;
use AppGraph\Scanners\PolicyScanner;
use AppGraph\Scanners\SideEffectScanner;
use AppGraph\Support\FileFinder;
use AppGraph\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

class LaravelSemanticFalsePositiveTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = sys_get_temp_dir().'/appgraph-laravel-semantics-'.bin2hex(random_bytes(4));

        foreach ([
            'app/Events',
            'app/Http/Controllers',
            'app/Jobs',
            'app/Models',
            'app/Observers',
            'app/Policies',
            'app/Services',
        ] as $directory) {
            mkdir($this->fixturePath.'/'.$directory, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->fixturePath);

        parent::tearDown();
    }

    public function test_policy_calls_require_a_proven_laravel_authorization_receiver(): void
    {
        $this->write('app/Models/Note.php', <<<'PHP'
<?php
namespace AppGraph\Tests\GeneratedSemanticPolicies\Models;
class Note {}
PHP);
        $this->write('app/Policies/NotePolicy.php', <<<'PHP'
<?php
namespace AppGraph\Tests\GeneratedSemanticPolicies\Policies;
class NotePolicy { public function update(object $user, object $note): bool { return true; } }
PHP);
        $this->write('app/Http/Controllers/PolicyController.php', <<<'PHP'
<?php
namespace AppGraph\Tests\GeneratedSemanticPolicies\Http\Controllers;

use AppGraph\Tests\GeneratedSemanticPolicies\Models\Note;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Gate as LaravelGate;

class Gate
{
    public static function allows(string $ability, object $target): bool { return true; }
}

class AuthorizationLookalike
{
    public function authorize(string $ability, object $target): bool { return true; }
    public function allows(string $ability, object $target): bool { return true; }
    public function can(string $ability, object $target): bool { return true; }
    public function check(string $ability, object $target): bool { return true; }
}

class PolicyController
{
    public function falsePositives(AuthorizationLookalike $service, Note $note): void
    {
        $service->authorize('update', $note);
        $service->allows('update', $note);
        $service->can('update', $note);
        $service->check('update', $note);
        Gate::allows('update', $note);
        $this->authorize('update', $note);
    }

    public function proven(GateContract $gate, Note $note): void
    {
        LaravelGate::allows('update', $note);
        LaravelGate::forUser(new \stdClass())->authorize('update', $note);
        $gate->allows('update', $note);
    }
}
PHP);

        $graph = new Graph();
        (new PolicyScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $root = 'AppGraph\Tests\GeneratedSemanticPolicies\Http\Controllers\PolicyController';
        $policy = 'AppGraph\Tests\GeneratedSemanticPolicies\Policies\NotePolicy::update';

        $this->assertNull($this->graphEdge($array, $root.'::falsePositives', $policy, 'authorizes_via'));
        $this->assertGraphHasEdge($array, $root.'::proven', $policy, 'authorizes_via');
    }

    public function test_side_effects_require_exact_laravel_facades_not_matching_basenames(): void
    {
        $this->write('app/Services/FacadeLookalikes.php', <<<'PHP'
<?php
namespace AppGraph\Tests\GeneratedSemanticSideEffects;

use Illuminate\Support\Facades\Cache as LaravelCache;
use Illuminate\Support\Facades\Http as LaravelHttp;
use Illuminate\Support\Facades\Storage as LaravelStorage;

class Cache { public static function get(string $key): mixed { return null; } }
class Storage { public static function put(string $path, mixed $value): void {} }
class Http { public static function post(string $url, array $payload): void {} }

class FacadeLookalikes
{
    public function falsePositives(): void
    {
        Cache::get('fake-cache');
        Storage::put('fake-file', 'value');
        Http::post('https://fake.example.test', []);
    }

    public function proven(): void
    {
        LaravelCache::get('real-cache');
        LaravelStorage::put('real-file', 'value');
        LaravelHttp::post('https://real.example.test', []);
    }
}
PHP);

        $graph = new Graph();
        (new SideEffectScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $root = 'AppGraph\Tests\GeneratedSemanticSideEffects\FacadeLookalikes';

        $this->assertNull($this->graphNode($array, 'side-effect:cache:default:fake-cache'));
        $this->assertNull($this->graphNode($array, 'side-effect:filesystem:default:fake-file'));
        $this->assertNull($this->graphNode($array, 'side-effect:external_service:http:https://fake.example.test'));
        $this->assertGraphHasEdge(
            $array,
            $root.'::proven',
            'side-effect:cache:default:real-cache',
            'reads_cache',
        );
        $this->assertGraphHasEdge(
            $array,
            $root.'::proven',
            'side-effect:filesystem:default:real-file',
            'writes_filesystem',
        );
        $this->assertGraphHasEdge(
            $array,
            $root.'::proven',
            'side-effect:external_service:http:https://real.example.test',
            'calls_external',
        );
    }

    public function test_data_flow_requires_an_exact_laravel_db_facade_or_manager(): void
    {
        $this->write('app/Http/Controllers/DbLookalikes.php', <<<'PHP'
<?php
namespace AppGraph\Tests\GeneratedSemanticDataFlow;

use Illuminate\Support\Facades\DB as LaravelDB;

class DB
{
    public static function table(string $table): object { return new \stdClass(); }
    public static function select(string $sql): array { return []; }
}

class DbLookalikes
{
    public function falsePositives(): void
    {
        DB::table('fake_table')->get();
        DB::select('select * from fake_raw');
    }

    public function proven(): void
    {
        LaravelDB::table('real_table')->get();
        LaravelDB::select('select * from real_raw');
    }
}
PHP);

        $graph = new Graph();
        (new DataFlowScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $root = 'AppGraph\Tests\GeneratedSemanticDataFlow\DbLookalikes::proven';

        $this->assertNull($this->graphNode($array, 'table:fake_table'));
        $this->assertNull($this->graphNode($array, 'table:fake_raw'));
        $this->assertGraphHasEdge($array, $root, 'table:real_table', 'reads');
        $this->assertGraphHasEdge($array, $root, 'table:real_raw', 'reads');
    }

    public function test_event_observers_and_helpers_require_laravel_semantic_proof(): void
    {
        $this->write('app/Events/SemanticEvents.php', <<<'PHP'
<?php
namespace AppGraph\Tests\GeneratedSemanticEvents\Events;
class ShadowedEvent {}
class ShadowedBroadcast {}
class ImportedEvent {}
class GlobalEvent {}
PHP);
        $this->write('app/Jobs/ShadowedJob.php', <<<'PHP'
<?php
namespace AppGraph\Tests\GeneratedSemanticEvents\Jobs;
class ShadowedJob {}
PHP);
        $this->write('app/Observers/NoteObserver.php', <<<'PHP'
<?php
namespace AppGraph\Tests\GeneratedSemanticEvents\Observers;
class NoteObserver {}
PHP);
        $this->write('app/Models/ActualModel.php', <<<'PHP'
<?php
namespace AppGraph\Tests\GeneratedSemanticEvents\Models;
use AppGraph\Tests\GeneratedSemanticEvents\Observers\NoteObserver;
use Illuminate\Database\Eloquent\Model;
class ActualModel extends Model
{
    public static function boot(): void { static::observe(NoteObserver::class); }
}
class KnownModel
{
    public static function boot(): void { static::observe(NoteObserver::class); }
}
PHP);
        $this->write('app/Http/Controllers/EventLookalikes.php', <<<'PHP'
<?php
namespace AppGraph\Tests\GeneratedSemanticEvents\Http\Controllers;

use AppGraph\Tests\GeneratedSemanticEvents\Events\GlobalEvent;
use AppGraph\Tests\GeneratedSemanticEvents\Events\ImportedEvent;
use AppGraph\Tests\GeneratedSemanticEvents\Events\ShadowedBroadcast;
use AppGraph\Tests\GeneratedSemanticEvents\Events\ShadowedEvent;
use AppGraph\Tests\GeneratedSemanticEvents\Jobs\ShadowedJob;
use AppGraph\Tests\GeneratedSemanticEvents\Observers\NoteObserver;
use function Vendor\event as external_event;

function event(object $event): void {}
function broadcast(object $event): void {}
function dispatch(object $job): void {}

class NotAModel
{
    public static function boot(): void { static::observe(NoteObserver::class); }
}

class EventLookalikes
{
    public function run(): void
    {
        event(new ShadowedEvent());
        broadcast(new ShadowedBroadcast());
        dispatch(new ShadowedJob());
        external_event(new ImportedEvent());
        \event(new GlobalEvent());
    }
}
PHP);

        $graph = new Graph();
        $knownModel = 'AppGraph\Tests\GeneratedSemanticEvents\Models\KnownModel';
        $graph->addNode(Node::make($knownModel, 'model', 'KnownModel'));
        (new EventFlowScanner(new FileFinder($this->fixturePath)))->scan($graph);
        $array = $graph->toArray();
        $observer = 'AppGraph\Tests\GeneratedSemanticEvents\Observers\NoteObserver';
        $models = 'AppGraph\Tests\GeneratedSemanticEvents\Models';
        $caller = 'AppGraph\Tests\GeneratedSemanticEvents\Http\Controllers\EventLookalikes::run';
        $events = 'AppGraph\Tests\GeneratedSemanticEvents\Events';

        $this->assertGraphHasEdge($array, $observer, $models.'\ActualModel', 'observes');
        $this->assertGraphHasEdge($array, $observer, $knownModel, 'observes');
        $this->assertNull($this->graphEdge(
            $array,
            $observer,
            'AppGraph\Tests\GeneratedSemanticEvents\Http\Controllers\NotAModel',
            'observes',
        ));
        $this->assertNull($this->graphEdge($array, $caller, $events.'\ShadowedEvent', 'dispatches'));
        $this->assertNull($this->graphEdge($array, $caller, $events.'\ShadowedBroadcast', 'dispatches'));
        $this->assertNull($this->graphEdge(
            $array,
            $caller,
            'AppGraph\Tests\GeneratedSemanticEvents\Jobs\ShadowedJob',
            'dispatches',
        ));
        $this->assertNull($this->graphEdge($array, $caller, $events.'\ImportedEvent', 'dispatches'));
        $this->assertGraphHasEdge($array, $caller, $events.'\GlobalEvent', 'dispatches');
        $this->assertSame(3, count(array_filter(
            $array['meta']['warnings'] ?? [],
            static fn (array $warning): bool => ($warning['reason'] ?? null) === 'laravel_event_helper_shadowed',
        )));
    }

    private function write(string $path, string $source): void
    {
        file_put_contents($this->fixturePath.'/'.$path, $source);
    }
}
