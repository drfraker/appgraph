<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Scanners\CallScanner;
use AppGraph\Support\ContainerBindingRegistry;
use AppGraph\Support\FileFinder;
use AppGraph\Tests\TestCase;
use Illuminate\Container\Container;
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
        $this->assertSame(35, $this->graphNode($array, $controller.'::update')['endLine']);
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

    public function test_it_resolves_container_helpers_and_injected_contracts_to_observed_implementations(): void
    {
        file_put_contents($this->fixturePath.'/app/Http/Controllers/BoundController.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedBindings;

interface Publisher
{
    public function publish(): void;
}

interface ServiceContract
{
    public function execute(): void;
}

interface UnknownService
{
    public function run(): void;
}

class DefaultPublisher implements Publisher
{
    public function publish(): void
    {
    }
}

class ContextualPublisher implements Publisher
{
    public function publish(): void
    {
    }
}

class GivenPublisher implements Publisher
{
    public function publish(): void
    {
    }
}

class SuppliedPublisher
{
    public function publish(): void
    {
    }
}

class GivenRoutePublisher extends SuppliedPublisher
{
    public function publish(): void
    {
    }
}

class ContextualUnknownBase
{
    public function run(): void
    {
    }
}

class ContextualUnknownImplementation extends ContextualUnknownBase
{
}

class RequestedBoundRequest extends \Illuminate\Foundation\Http\FormRequest
{
}

class RuntimeBoundRequest extends RequestedBoundRequest
{
    public function runtimeOnly(): void
    {
    }
}

class UnknownBoundRequest extends \Illuminate\Foundation\Http\FormRequest
{
    public function mustNotBeGuessed(): void
    {
    }
}

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class DynamicPublisher implements \Illuminate\Contracts\Container\ContextualAttribute
{
    public static function resolve(self $attribute, mixed $container): mixed
    {
        return $container->make(ContextualPublisher::class);
    }
}

class FactoryBuiltService implements ServiceContract
{
    public function __construct(private Publisher $publisher)
    {
    }

    public function execute(): void
    {
        $this->publisher->publish();
    }
}

class BoundController
{
    public function __construct(
        private Publisher $publisher,
        private ServiceContract $service,
        #[\Illuminate\Container\Attributes\Give(GivenPublisher::class)]
        private Publisher $givenPublisher,
        #[DynamicPublisher]
        private Publisher $dynamicPublisher,
        private ContextualUnknownBase $contextualUnknown,
    )
    {
    }

    public function store(): void
    {
        $this->publisher->publish();
        $this->service->execute();
        $this->givenPublisher->publish();
        $this->dynamicPublisher->publish();
        $this->contextualUnknown->run();
    }

    public function injected(Publisher $publisher, UnknownService $unknown): void
    {
        $publisher->publish();
        $unknown->run();
    }

    public function givenAction(
        #[\Illuminate\Container\Attributes\Give(GivenPublisher::class)]
        Publisher $publisher,
        #[DynamicPublisher]
        Publisher $dynamicPublisher,
    ): void {
        $publisher->publish();
        $dynamicPublisher->publish();
    }

    public function routeSuppliedAction(
        #[\Illuminate\Container\Attributes\Give(GivenRoutePublisher::class)]
        SuppliedPublisher $publisher,
    ): void {
        $publisher->publish();
    }

    public function reboundRequest(RequestedBoundRequest $request): void
    {
        $request->runtimeOnly();
    }

    public function unknownRequest(UnknownBoundRequest $request): void
    {
        $request->mustNotBeGuessed();
    }

    public function helpers(): void
    {
        app(Publisher::class)->publish();
        resolve('publisher')->publish();
        app(UnknownService::class)->run();
    }
}

class ManualConsumer
{
    public function __construct(private Publisher $publisher)
    {
    }

    public function run(): void
    {
        $this->publisher->publish();
    }
}
PHP);

        $contract = 'AppGraph\\Tests\\GeneratedBindings\\Publisher';
        $default = 'AppGraph\\Tests\\GeneratedBindings\\DefaultPublisher';
        $contextual = 'AppGraph\\Tests\\GeneratedBindings\\ContextualPublisher';
        $given = 'AppGraph\\Tests\\GeneratedBindings\\GivenPublisher';
        $supplied = 'AppGraph\\Tests\\GeneratedBindings\\SuppliedPublisher';
        $givenRoute = 'AppGraph\\Tests\\GeneratedBindings\\GivenRoutePublisher';
        $serviceContract = 'AppGraph\\Tests\\GeneratedBindings\\ServiceContract';
        $unknownService = 'AppGraph\\Tests\\GeneratedBindings\\UnknownService';
        $factoryBuiltService = 'AppGraph\\Tests\\GeneratedBindings\\FactoryBuiltService';
        $controller = 'AppGraph\\Tests\\GeneratedBindings\\BoundController';
        $contextualUnknown = 'AppGraph\\Tests\\GeneratedBindings\\ContextualUnknownBase';
        $requestedRequest = 'AppGraph\\Tests\\GeneratedBindings\\RequestedBoundRequest';
        $runtimeRequest = 'AppGraph\\Tests\\GeneratedBindings\\RuntimeBoundRequest';
        $unknownRequest = 'AppGraph\\Tests\\GeneratedBindings\\UnknownBoundRequest';
        $manual = 'AppGraph\\Tests\\GeneratedBindings\\ManualConsumer';
        $container = new Container();
        $container->bind($contract, $default);
        $container->bind('publisher', $default);
        $container->when($controller)->needs($contract)->give($contextual);
        $container->when($controller)->needs($contextualUnknown)->give(static function () {
            throw new \RuntimeException('Contextual factories must not execute during graph scans.');
        });
        $container->bind($serviceContract, static function (): \AppGraph\Tests\GeneratedBindings\FactoryBuiltService {
            throw new \RuntimeException('Container factories must not execute during graph scans.');
        });
        $container->bind($unknownService, static function () {
            throw new \RuntimeException('Container factories must not execute during graph scans.');
        });
        $container->bind($requestedRequest, $runtimeRequest);
        $container->bind($unknownRequest, static function () {
            throw new \RuntimeException('FormRequest factories must not execute during graph scans.');
        });
        $registry = new ContainerBindingRegistry($container, 'testing', 'AppGraph\\Tests\\GeneratedBindings\\');
        $graph = new Graph();
        $route = 'route:POST:/bound';
        $graph->addNode(Node::make($route, 'route', 'POST /bound', [
            'metadata' => ['uri' => '/bound'],
        ]));
        $graph->addEdge(new Edge($route, $controller.'::store', 'routes_to'));
        $graph->addEdge(new Edge($route, $controller.'::injected', 'routes_to'));
        $graph->addEdge(new Edge($route, $controller.'::givenAction', 'routes_to'));
        $suppliedRoute = 'route:GET:/bound/{publisher}';
        $graph->addNode(Node::make($suppliedRoute, 'route', 'GET /bound/{publisher}', [
            'metadata' => ['uri' => '/bound/{publisher}'],
        ]));
        $graph->addEdge(new Edge($suppliedRoute, $controller.'::routeSuppliedAction', 'routes_to'));
        $graph->addEdge(new Edge($route, $controller.'::reboundRequest', 'routes_to'));
        $graph->addEdge(new Edge($route, $controller.'::unknownRequest', 'routes_to'));

        (new CallScanner(new FileFinder($this->fixturePath), $registry))->scan($graph);
        $array = $graph->toArray();

        $this->assertGraphHasEdge($array, $controller.'::store', $contextual.'::publish', 'calls');
        $this->assertGraphHasEdge($array, $controller.'::store', $factoryBuiltService.'::execute', 'calls');
        $this->assertGraphHasEdge($array, $controller.'::store', $given.'::publish', 'calls');
        $this->assertGraphHasEdge($array, $controller.'::injected', $default.'::publish', 'calls');
        $this->assertGraphHasEdge($array, $controller.'::givenAction', $given.'::publish', 'calls');
        $this->assertGraphHasEdge($array, $controller.'::routeSuppliedAction', $supplied.'::publish', 'calls');
        $this->assertGraphHasEdge($array, $controller.'::reboundRequest', $runtimeRequest.'::runtimeOnly', 'calls');
        $this->assertGraphHasEdge($array, $controller.'::helpers', $default.'::publish', 'calls');
        $contextualEdge = $this->graphEdge($array, $controller.'::store', $contextual.'::publish', 'calls');
        $this->assertSame('container_binding', $contextualEdge['metadata']['inference']);
        $this->assertSame($contract, $contextualEdge['metadata']['requestedAbstract']);
        $this->assertSame('contextual', $contextualEdge['metadata']['bindingScope']);
        $this->assertSame($controller, $contextualEdge['metadata']['bindingConsumer']);
        $defaultEdge = $this->graphEdge($array, $controller.'::helpers', $default.'::publish', 'calls');
        $this->assertSame('default', $defaultEdge['metadata']['bindingScope']);
        $this->assertSame('route_controller', $contextualEdge['metadata']['containerManagedBy']);
        $this->assertNull($this->graphEdge($array, $manual.'::run', $default.'::publish', 'calls'));
        $this->assertNull($this->graphEdge($array, $manual.'::run', $contextual.'::publish', 'calls'));
        $this->assertNull($this->graphEdge($array, $factoryBuiltService.'::execute', $default.'::publish', 'calls'));
        $this->assertNull($this->graphEdge($array, $factoryBuiltService.'::execute', $contextual.'::publish', 'calls'));
        $this->assertNull($this->graphEdge($array, $controller.'::helpers', $unknownService.'::run', 'calls'));
        $this->assertNull($this->graphEdge($array, $controller.'::injected', $unknownService.'::run', 'calls'));
        $this->assertNull($this->graphEdge($array, $controller.'::store', $contextualUnknown.'::run', 'calls'));
        $this->assertNull($this->graphEdge($array, $controller.'::givenAction', $default.'::publish', 'calls'));
        $this->assertNull($this->graphEdge($array, $controller.'::givenAction', $contextual.'::publish', 'calls'));
        $this->assertNull($this->graphEdge($array, $controller.'::routeSuppliedAction', $givenRoute.'::publish', 'calls'));
        $this->assertNull($this->graphEdge($array, $controller.'::unknownRequest', $unknownRequest.'::mustNotBeGuessed', 'calls'));

        $givenEdge = $this->graphEdge($array, $controller.'::store', $given.'::publish', 'calls');
        $this->assertSame('Illuminate\\Container\\Attributes\\Give', $givenEdge['metadata']['contextualAttribute']);
        $this->assertSame($given, $givenEdge['metadata']['contextualTarget']);
        $this->assertNull($this->graphEdge($array, $controller.'::store', $default.'::publish', 'calls'));

        $actionEdge = $this->graphEdge($array, $controller.'::injected', $default.'::publish', 'calls');
        $this->assertSame('route_controller_action', $actionEdge['metadata']['containerInvocationBoundary']);
        $this->assertSame('container_invoked_method_parameter', $actionEdge['metadata']['injectionInference']);
        $routeSuppliedEdge = $this->graphEdge($array, $controller.'::routeSuppliedAction', $supplied.'::publish', 'calls');
        $this->assertSame('route_supplied_parameter', $routeSuppliedEdge['metadata']['inference']);
        $this->assertSame('publisher', $routeSuppliedEdge['metadata']['routeParameter']);
        $this->assertSame('Illuminate\\Container\\Attributes\\Give', $routeSuppliedEdge['metadata']['ignoredContextualAttribute']);
        $requestCall = $this->graphEdge($array, $controller.'::reboundRequest', $runtimeRequest.'::runtimeOnly', 'calls');
        $this->assertSame($requestedRequest, $requestCall['metadata']['requestedAbstract']);
        $this->assertSame('route_controller_action', $requestCall['metadata']['containerInvocationBoundary']);
    }

    public function test_factory_built_route_controllers_do_not_imply_constructor_dependency_resolution(): void
    {
        file_put_contents($this->fixturePath.'/app/Http/Controllers/FactoryController.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedFactoryController;

interface Dependency
{
    public function run(): void;
}

class ConcreteDependency implements Dependency
{
    public function run(): void
    {
    }
}

class FactoryController
{
    public function __construct(private Dependency $dependency)
    {
    }

    public function store(): void
    {
        $this->dependency->run();
    }
}
PHP);

        $contract = 'AppGraph\\Tests\\GeneratedFactoryController\\Dependency';
        $concrete = 'AppGraph\\Tests\\GeneratedFactoryController\\ConcreteDependency';
        $controller = 'AppGraph\\Tests\\GeneratedFactoryController\\FactoryController';
        $container = new Container();
        $container->bind($contract, $concrete);
        $container->bind($controller, static function (): \AppGraph\Tests\GeneratedFactoryController\FactoryController {
            throw new \RuntimeException('Container factories must not execute during graph scans.');
        });
        $registry = new ContainerBindingRegistry($container, 'testing', 'AppGraph\\Tests\\GeneratedFactoryController\\');
        $graph = new Graph();
        $route = 'route:POST:/factory-controller';
        $graph->addNode(Node::make($route, 'route', 'POST /factory-controller'));
        $graph->addEdge(new Edge($route, $controller.'::store', 'routes_to'));

        (new CallScanner(new FileFinder($this->fixturePath), $registry))->scan($graph);
        $array = $graph->toArray();

        $this->assertNull($this->graphEdge($array, $controller.'::store', $concrete.'::run', 'calls'));
    }

    public function test_laravel_job_handlers_and_container_called_form_request_hooks_resolve_method_dependencies(): void
    {
        file_put_contents($this->fixturePath.'/app/Http/Controllers/InvocationBoundaries.php', <<<'PHP'
<?php

namespace AppGraph\Tests\GeneratedInvocation;

use Illuminate\Foundation\Http\FormRequest;

interface Service
{
    public function run(): void;
}

class ConcreteService implements Service
{
    public function run(): void
    {
    }
}

abstract class HandlerMethodService
{
    public function run(): void
    {
    }
}

class ConcreteHandlerMethodService extends HandlerMethodService
{
    public function run(): void
    {
    }
}

class DefaultHandlerMethodService extends HandlerMethodService
{
}

interface CommandEnvelope
{
    public function acknowledge(): void;
}

class ProcessJob implements CommandEnvelope
{
    public function handle(Service $service): void
    {
        $service->run();
    }

    public function acknowledge(): void
    {
    }
}

class AlternateCommand implements CommandEnvelope
{
    public function acknowledge(): void
    {
    }
}

class InactiveJob
{
    public function handle(Service $service): void
    {
        $service->run();
    }
}

class MappedHandler
{
    public function __construct(private Service $service)
    {
    }

    public function __invoke(
        #[\Illuminate\Container\Attributes\Give(AlternateCommand::class)]
        CommandEnvelope $command,
        HandlerMethodService $methodService = new DefaultHandlerMethodService(),
    ): void
    {
        $command->acknowledge();
        $this->service->run();
        $methodService->run();
    }
}

class UpdateRequest extends FormRequest
{
    public function rules(Service $service): array
    {
        $service->run();

        return [];
    }

    public function withValidator(Service $service): void
    {
        $service->run();
    }
}
PHP);

        $root = 'AppGraph\\Tests\\GeneratedInvocation\\';
        $contract = $root.'Service';
        $concrete = $root.'ConcreteService';
        $methodContract = $root.'HandlerMethodService';
        $methodConcrete = $root.'ConcreteHandlerMethodService';
        $job = $root.'ProcessJob';
        $inactiveJob = $root.'InactiveJob';
        $mapped = $root.'MappedHandler';
        $alternateCommand = $root.'AlternateCommand';
        $request = $root.'UpdateRequest';
        $container = new Container();
        $container->bind($contract, $concrete);
        $container->bind($methodContract, $methodConcrete);
        $registry = new ContainerBindingRegistry($container, 'testing', $root);
        $graph = new Graph();
        $graph->addNode(Node::make($job, 'job', 'ProcessJob'));
        $graph->addNode(Node::make($inactiveJob, 'job', 'InactiveJob'));
        $graph->addNode(Node::make($request, 'form_request', 'UpdateRequest'));
        $graph->addEdge(new Edge($job, $job.'::handle', 'handled_by', 1.0, [
            'kind' => 'job',
            'handlerClass' => $job,
            'payloadClass' => $job,
            'source' => 'laravel_job_convention',
        ]));
        $graph->addEdge(new Edge($job, $mapped.'::__invoke', 'handled_by', 1.0, [
            'kind' => 'job',
            'handlerClass' => $mapped,
            'payloadClass' => $job,
            'source' => 'explicit_bus_map',
        ]));
        $graph->addEdge(new Edge($inactiveJob, $inactiveJob.'::handle', 'handled_by', 1.0, [
            'kind' => 'job',
            'handlerClass' => $inactiveJob,
            'payloadClass' => $inactiveJob,
            'causalExecutionProven' => false,
        ]));
        $graph->addEdge(new Edge($request, $request.'::rules', 'framework_invokes', 1.0, [
            'hook' => 'rules',
        ]));
        $graph->addEdge(new Edge($request, $request.'::withValidator', 'framework_invokes', 1.0, [
            'hook' => 'withValidator',
        ]));

        (new CallScanner(new FileFinder($this->fixturePath), $registry))->scan($graph);
        $array = $graph->toArray();

        $jobCall = $this->graphEdge($array, $job.'::handle', $concrete.'::run', 'calls');
        $this->assertNotNull($jobCall);
        $this->assertSame('laravel_job_handler', $jobCall['metadata']['containerInvocationBoundary']);
        $mappedCall = $this->graphEdge($array, $mapped.'::__invoke', $concrete.'::run', 'calls');
        $this->assertNotNull($mappedCall);
        $this->assertSame('mapped_job_handler', $mappedCall['metadata']['containerManagedBy']);
        $commandArgumentCall = $this->graphEdge($array, $mapped.'::__invoke', $job.'::acknowledge', 'calls');
        $this->assertNotNull($commandArgumentCall);
        $this->assertSame('mapped_job_payload_parameter', $commandArgumentCall['metadata']['inference']);
        $this->assertSame('mapped_job_handler_direct', $commandArgumentCall['metadata']['jobInvocationBoundary']);
        $this->assertSame('Illuminate\\Container\\Attributes\\Give', $commandArgumentCall['metadata']['ignoredContextualAttribute']);
        $this->assertArrayNotHasKey('injectionInference', $commandArgumentCall['metadata']);
        $this->assertArrayNotHasKey('containerInvocationBoundary', $commandArgumentCall['metadata']);
        $this->assertNull($this->graphEdge($array, $mapped.'::__invoke', $alternateCommand.'::acknowledge', 'calls'));
        $mappedArgumentCall = $this->graphEdge($array, $mapped.'::__invoke', $methodContract.'::run', 'calls');
        $this->assertNotNull($mappedArgumentCall);
        $this->assertSame('typed_parameter', $mappedArgumentCall['metadata']['inference']);
        $this->assertArrayNotHasKey('requestedAbstract', $mappedArgumentCall['metadata']);
        $this->assertArrayNotHasKey('injectionInference', $mappedArgumentCall['metadata']);
        $this->assertArrayNotHasKey('containerManagedBy', $mappedArgumentCall['metadata']);
        $this->assertArrayNotHasKey('containerInvocationBoundary', $mappedArgumentCall['metadata']);
        $this->assertNull($this->graphEdge($array, $mapped.'::__invoke', $methodConcrete.'::run', 'calls'));
        $rulesCall = $this->graphEdge($array, $request.'::rules', $concrete.'::run', 'calls');
        $this->assertNotNull($rulesCall);
        $this->assertSame('form_request_container_callback', $rulesCall['metadata']['containerInvocationBoundary']);
        $this->assertNull($this->graphEdge($array, $inactiveJob.'::handle', $concrete.'::run', 'calls'));
        $this->assertNull($this->graphEdge($array, $request.'::withValidator', $concrete.'::run', 'calls'));
    }
}
