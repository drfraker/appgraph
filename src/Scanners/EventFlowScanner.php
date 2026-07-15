<?php

namespace AppGraph\Scanners;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node as GraphNode;
use AppGraph\Scanners\Concerns\InteractsWithPhpAst;
use AppGraph\Support\ContainerBindingRegistry;
use AppGraph\Support\FileFinder;
use Illuminate\Bus\Dispatcher as LaravelBusDispatcher;
use Illuminate\Events\Dispatcher as LaravelEventDispatcher;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

class EventFlowScanner
{
    use InteractsWithPhpAst;

    private const SHOULD_QUEUE = 'Illuminate\Contracts\Queue\ShouldQueue';
    private const SHOULD_QUEUE_AFTER_COMMIT = 'Illuminate\Contracts\Queue\ShouldQueueAfterCommit';
    private const SHOULD_HANDLE_EVENTS_AFTER_COMMIT = 'Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit';
    private const BUS_DISPATCHABLE = 'Illuminate\Foundation\Bus\Dispatchable';
    private const PREPARES_FOR_DISPATCH = 'Illuminate\Contracts\Queue\PreparesForDispatch';
    private const EVENT_DISPATCHABLE = 'Illuminate\Foundation\Events\Dispatchable';
    private const EVENT_SERVICE_PROVIDER = 'Illuminate\Foundation\Support\Providers\EventServiceProvider';
    private const SERVICE_PROVIDER = 'Illuminate\Support\ServiceProvider';
    private const OBSERVED_BY_ATTRIBUTE = 'Illuminate\Database\Eloquent\Attributes\ObservedBy';
    private const EVENT_FACADE = 'Illuminate\Support\Facades\Event';
    private const BUS_FACADE = 'Illuminate\Support\Facades\Bus';
    private const QUEUE_CONNECTION_ATTRIBUTE = 'Illuminate\Queue\Attributes\Connection';
    private const QUEUE_NAME_ATTRIBUTE = 'Illuminate\Queue\Attributes\Queue';

    /**
     * PendingDispatch methods that Laravel delegates directly to the job.
     * Unknown methods are delegated through PendingDispatch::__call as well.
     *
     * @var array<int, string>
     */
    private const PENDING_DISPATCH_DELEGATED_METHODS = [
        'onConnection',
        'onQueue',
        'onGroup',
        'withDeduplicator',
        'allOnConnection',
        'allOnQueue',
        'delay',
        'withoutDelay',
        'afterCommit',
        'beforeCommit',
        'chain',
    ];

    /** @var array<int, string> */
    private const PENDING_DISPATCH_LOCAL_METHODS = [
        'afterResponse',
        'getJob',
        'when',
        'unless',
    ];

    /**
     * @var array<int, string>
     */
    private array $staticDispatchMethods = [
        'dispatch',
        'dispatchSync',
        'dispatchAfterResponse',
    ];

    private Parser $parser;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $classes = [];

    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $methods = [];

    /** @var array<string, array<int, string>> */
    private array $interfaces = [];

    /** @var array<string, true> */
    private array $dispatchedJobs = [];

    /** @var array<string, true> */
    private array $registeredListeners = [];

    /** @var array<string, true> */
    private array $declaredListenerRegistrations = [];

    /** @var array<string, array{handler: string, file: string|null, line: int, offset: int|null, confidence: float, assumption: string}> */
    private array $jobHandlerMaps = [];

    /** @var array<string, array<string, true>>|null */
    private ?array $bootedListenerRegistrations = null;

    /** @var array<string, string>|null */
    private ?array $bootedBusHandlers = null;

    public function __construct(
        private FileFinder $files,
        private ?ContainerBindingRegistry $containerBindings = null,
    ) {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    protected function scannerName(): string
    {
        return 'events';
    }

    protected function scannerSourceLabel(): string
    {
        return 'event_flow_scanner';
    }

    public function scan(Graph $graph): Graph
    {
        $this->classes = [];
        $this->methods = [];
        $this->interfaces = [];
        $this->dispatchedJobs = [];
        $this->registeredListeners = [];
        $this->declaredListenerRegistrations = [];
        $this->jobHandlerMaps = [];
        $this->bootedListenerRegistrations = $this->snapshotBootedListenerRegistrations();
        $this->bootedBusHandlers = $this->snapshotBootedBusHandlers();

        $graph->addMeta([
            'analysis' => [
                'laravelExecutionRegistry' => [
                    'eventListenersAvailable' => $this->bootedListenerRegistrations !== null,
                    'eventListenerCount' => $this->bootedListenerRegistrations === null
                        ? null
                        : array_sum(array_map('count', $this->bootedListenerRegistrations)),
                    'busHandlersAvailable' => $this->bootedBusHandlers !== null,
                    'busHandlerCount' => $this->bootedBusHandlers === null ? null : count($this->bootedBusHandlers),
                ],
            ],
        ]);

        $files = $this->files->findPhpFiles(['app', 'routes']);

        foreach ($files as $file) {
            $statements = $this->parseFile($file, $graph);

            if ($statements === null) {
                continue;
            }

            $this->indexStatements($statements, $this->files->relativePath($file) ?? $file);
            unset($statements);
        }

        foreach ($this->classes as $class => $record) {
            if ($this->kindOf($class) !== null) {
                $this->ensureTargetNode($graph, $class, $this->kindOf($class));
            }
        }

        // Auto-discovery before explicit wiring: edge merge keeps the highest
        // confidence but lets the later writer's metadata win, and the explicit
        // registration is the better provenance.
        $this->addAutoDiscoveredListeners($graph);
        $this->addListenMapListeners($graph);
        $this->addBootedRuntimeListeners($graph);
        $this->addObservedByAttributes($graph);

        gc_collect_cycles();

        foreach ($files as $file) {
            $statements = $this->parseFile($file, $graph);

            if ($statements === null) {
                continue;
            }

            $this->scanStatements($graph, $statements);
            unset($statements);
        }

        $this->applyBootedBusHandlerTruth();
        $this->addJobHandlers($graph);

        return $graph;
    }

    /**
     * @param array<int, Node> $statements
     */
    private function indexStatements(array $statements, string $relativeFile): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $this->indexStatements($statement->stmts, $relativeFile);
                continue;
            }

            if ($statement instanceof Stmt\Interface_ && $statement->name !== null) {
                $interface = $statement->namespacedName instanceof Name
                    ? $statement->namespacedName->toString()
                    : $statement->name->toString();
                $this->interfaces[$interface] = array_values(array_filter(array_map(
                    fn (Name $parent): ?string => $this->resolvedName($parent),
                    $statement->extends
                )));

                foreach ($statement->getMethods() as $method) {
                    $this->methods[$interface][$method->name->toString()] = [
                        'node' => $method,
                        'file' => $relativeFile,
                        'line' => $method->getStartLine(),
                        'signature' => $this->methodSignature($method),
                        'inputs' => $this->methodInputs($method),
                        'outputs' => $this->methodOutputs($method),
                        'visibility' => 'public',
                        'static' => $method->isStatic(),
                    ];
                }

                continue;
            }

            if ((! $statement instanceof Stmt\Class_ && ! $statement instanceof Stmt\Trait_) || $statement->name === null) {
                continue;
            }

            $class = $this->className($statement);

            if ($class === null) {
                continue;
            }

            $record = [
                'shortName' => $statement->name->toString(),
                'namespace' => $this->namespaceFromClass($class),
                'file' => $relativeFile,
                'line' => $statement->getStartLine(),
                'extends' => $statement instanceof Stmt\Class_ ? $this->resolvedName($statement->extends) : null,
                'declarationKind' => $statement instanceof Stmt\Trait_ ? 'trait' : 'class',
                'abstract' => $statement instanceof Stmt\Class_ && $statement->isAbstract(),
                'implements' => [],
                'traits' => $this->usedTraits($statement),
                'traitAdaptations' => $this->traitAdaptations($statement),
                'listen' => [],
                'observedBy' => [],
                'handleParamType' => null,
                'queue' => $this->queueMetadata($statement),
            ];

            if ($statement instanceof Stmt\Class_) {
                foreach ($statement->implements as $interface) {
                    $resolved = $this->resolvedName($interface);

                    if ($resolved !== null) {
                        $record['implements'][] = $resolved;
                    }
                }

                $record['observedBy'] = $this->observedByFromAttributes($statement);
                $record['listen'] = $this->listenMapFromClass($statement);
            }

            foreach ($statement->getMethods() as $method) {
                $this->methods[$class][$method->name->toString()] = [
                    'node' => $method,
                    'file' => $relativeFile,
                    'line' => $method->getStartLine(),
                    'signature' => $this->methodSignature($method),
                    'inputs' => $this->methodInputs($method),
                    'outputs' => $this->methodOutputs($method),
                    'visibility' => $this->methodVisibility($method),
                    'static' => $method->isStatic(),
                ];

                if ($method->name->toString() === 'handle' && isset($method->params[0])) {
                    $record['handleParamType'] = $this->resolveType($method->params[0]->type, $class);
                }
            }

            $this->classes[$class] = $record;
        }
    }

    /**
     * @return array<int, string>
     */
    private function observedByFromAttributes(Stmt\Class_ $class): array
    {
        $observers = [];

        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->resolvedName($attribute->name) !== self::OBSERVED_BY_ATTRIBUTE) {
                    continue;
                }

                foreach ($attribute->args as $arg) {
                    foreach ($this->classNamesFromExpr($arg->value) as $observer) {
                        $observers[] = $observer;
                    }
                }
            }
        }

        return $observers;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function listenMapFromClass(Stmt\Class_ $class): array
    {
        $listen = [];

        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Stmt\Property) {
                continue;
            }

            foreach ($statement->props as $property) {
                if ($property->name->toString() !== 'listen' || ! $property->default instanceof Expr\Array_) {
                    continue;
                }

                foreach ($property->default->items as $item) {
                    if ($item->key === null) {
                        continue;
                    }

                    $event = $this->classNamesFromExpr($item->key)[0] ?? null;

                    if ($event === null) {
                        continue;
                    }

                    $listen[$event] = array_merge(
                        $listen[$event] ?? [],
                        $this->classNamesFromExpr($item->value)
                    );
                }
            }
        }

        return $listen;
    }

    /**
     * Class names referenced by an expression: Foo::class, 'App\Foo' strings,
     * and flat arrays of either.
     *
     * @return array<int, string>
     */
    private function classNamesFromExpr(Expr $expr): array
    {
        if ($expr instanceof Expr\ClassConstFetch && $expr->name instanceof Identifier && $expr->name->toString() === 'class') {
            $resolved = $this->resolvedName($expr->class);

            return $resolved !== null ? [$resolved] : [];
        }

        if ($expr instanceof Scalar\String_ && str_contains($expr->value, '\\')) {
            return [ltrim($expr->value, '\\')];
        }

        if ($expr instanceof Expr\Array_) {
            $classes = [];

            foreach ($expr->items as $item) {
                if ($item->key === null) {
                    $classes = array_merge($classes, $this->classNamesFromExpr($item->value));
                }
            }

            return $classes;
        }

        return [];
    }

    private function kindOf(string $class): ?string
    {
        $record = $this->classes[$class] ?? null;

        if ($record === null) {
            return null;
        }

        if (($record['declarationKind'] ?? 'class') !== 'class') {
            return null;
        }

        if (str_contains('\\'.$class, '\\Events\\')
            || str_starts_with((string) $record['file'], 'app/Events/')
            || in_array(self::EVENT_DISPATCHABLE, $this->classTraits($class), true)
        ) {
            return 'event';
        }

        if (str_contains('\\'.$class, '\\Jobs\\')
            || str_starts_with((string) $record['file'], 'app/Jobs/')
            || in_array(self::BUS_DISPATCHABLE, $this->classTraits($class), true)
        ) {
            return 'job';
        }

        return null;
    }

    private function addAutoDiscoveredListeners(Graph $graph): void
    {
        foreach ($this->classes as $class => $record) {
            if (! str_starts_with((string) $record['file'], 'app/Listeners/')) {
                continue;
            }

            if (($record['declarationKind'] ?? 'class') !== 'class' || ($record['abstract'] ?? false)) {
                $graph->addWarning([
                    'scanner' => $this->scannerName(),
                    'reason' => 'listener_not_instantiable',
                    'listener' => $class,
                    'file' => $record['file'] ?? null,
                    'line' => $record['line'] ?? null,
                    'message' => 'A trait or abstract class under app/Listeners is not an auto-discoverable executable listener.',
                ]);

                continue;
            }

            foreach ($this->methods[$class] ?? [] as $method => $methodRecord) {
                if (($methodRecord['visibility'] ?? null) !== 'public'
                    || (! str_starts_with($method, 'handle') && $method !== '__invoke')) {
                    continue;
                }

                $node = $methodRecord['node'] ?? null;
                $event = $node instanceof Stmt\ClassMethod && isset($node->params[0])
                    ? $this->resolveType($node->params[0]->type, $class)
                    : null;

                if (! is_string($event) || $this->kindOf($event) !== 'event') {
                    continue;
                }

                $this->addListensTo($graph, $class, $method, $event, 0.85, [
                    'source' => 'auto_discovery',
                    'classStringListener' => true,
                ]);
            }
        }
    }

    private function addJobHandlers(Graph $graph): void
    {
        $jobs = $this->dispatchedJobs;

        foreach (array_keys($this->jobHandlerMaps) as $job) {
            $jobs[$job] = true;
        }

        foreach (array_keys($this->classes) as $class) {
            if ($this->kindOf($class) === 'job') {
                $jobs[$class] = true;
            }
        }

        ksort($jobs);

        foreach (array_keys($jobs) as $job) {
            if ((! isset($this->classes[$job]) && ! isset($this->jobHandlerMaps[$job]))
                || (isset($this->registeredListeners[$job])
                    && ! isset($this->dispatchedJobs[$job])
                    && ! isset($this->jobHandlerMaps[$job]))) {
                continue;
            }

            if ($this->isKnownNonInstantiableClass($job)) {
                $graph->addWarning([
                    'scanner' => $this->scannerName(),
                    'reason' => 'job_not_instantiable',
                    'job' => $job,
                    'file' => $this->classes[$job]['file'] ?? null,
                    'line' => $this->classes[$job]['line'] ?? null,
                    'message' => 'A trait, interface, or abstract job declaration is retained as structure but has no executable convention handler.',
                ]);

                continue;
            }

            $mapping = $this->jobHandlerMaps[$job] ?? null;
            $requestedHandlerClass = $mapping['handler'] ?? $job;
            $binding = $mapping !== null
                ? $this->containerTarget($requestedHandlerClass)
                : ['status' => 'resolved', 'class' => $requestedHandlerClass, 'confidence' => 1.0, 'metadata' => []];

            if ($binding['status'] !== 'resolved') {
                $graph->addWarning(array_filter([
                    'scanner' => $this->scannerName(),
                    'reason' => 'mapped_handler_binding_target_unknown',
                    'job' => $job,
                    'handlerClass' => $requestedHandlerClass,
                    'file' => $mapping['file'] ?? null,
                    'line' => $mapping['line'] ?? null,
                    'message' => 'The mapped handler has a declared container binding whose concrete target cannot be proven without executing application code.',
                ], static fn (mixed $value): bool => $value !== null));

                continue;
            }

            $handlerClass = $binding['class'];

            if ($this->isKnownNonInstantiableClass($handlerClass)) {
                $graph->addWarning(array_filter([
                    'scanner' => $this->scannerName(),
                    'reason' => 'job_handler_not_instantiable',
                    'job' => $job,
                    'handlerClass' => $handlerClass,
                    'file' => $mapping['file'] ?? ($this->classes[$handlerClass]['file'] ?? null),
                    'line' => $mapping['line'] ?? ($this->classes[$handlerClass]['line'] ?? null),
                    'message' => 'The selected job handler is a trait, interface, or abstract class and cannot execute.',
                ], static fn (mixed $value): bool => $value !== null));

                continue;
            }

            $selection = $this->jobHandlerSelection($handlerClass);

            if ($selection['status'] !== 'found') {
                if ($selection['status'] === 'non_public') {
                    $declaration = $selection['declaration'];
                    $graph->addWarning([
                        'scanner' => $this->scannerName(),
                        'reason' => 'job_handler_not_public',
                        'job' => $job,
                        'handler' => $declaration['class'].'::'.$selection['method'],
                        'message' => 'Laravel selects this handler by method existence, but the method is not public; __invoke is not a fallback in this case.',
                    ]);
                } elseif ($mapping !== null || $selection['status'] === 'unproven') {
                    $graph->addWarning(array_filter([
                        'scanner' => $this->scannerName(),
                        'reason' => $mapping !== null ? 'mapped_job_handler_unresolved' : 'job_handler_resolution_unproven',
                        'job' => $job,
                        'handlerClass' => $handlerClass,
                        'file' => $mapping['file'] ?? null,
                        'line' => $mapping['line'] ?? null,
                        'message' => 'The executable job handler method could not be proven; __invoke was not assumed across an unresolved method-existence boundary.',
                    ], static fn (mixed $value): bool => $value !== null));
                }

                continue;
            }

            $declaration = $selection['declaration'];
            $method = $selection['method'];

            $this->ensureTargetNode($graph, $job, 'job');
            $this->ensureMethodNode($graph, $declaration['class'], $method);

            $edgeConfidence = ($mapping['confidence'] ?? 0.95) * $binding['confidence'];

            $graph->addEdge(new Edge(
                $job,
                $declaration['class'].'::'.$method,
                'handled_by',
                $edgeConfidence,
                array_filter([
                    'kind' => 'job',
                    'handlerClass' => $handlerClass,
                    'requestedHandlerClass' => $requestedHandlerClass !== $handlerClass ? $requestedHandlerClass : null,
                    'payloadClass' => $job,
                    'declaringClass' => $declaration['class'] !== $handlerClass ? $declaration['class'] : null,
                    'method' => $method,
                    'source' => $mapping['source'] ?? ($mapping !== null ? 'explicit_bus_map' : 'laravel_job_convention'),
                    'assumption' => $mapping['assumption'] ?? ($mapping === null ? 'no_explicit_bus_handler_map_observed' : null),
                    'registrationEvidence' => $mapping['registrationEvidence'] ?? null,
                    'causalExecutionProven' => $mapping['causalExecutionProven'] ?? null,
                    'file' => $mapping['file'] ?? ($declaration['record']['file'] ?? null),
                    'line' => $mapping['line'] ?? ($declaration['record']['line'] ?? null),
                    'offset' => $mapping['offset'] ?? null,
                    'handlerFile' => $declaration['record']['file'] ?? null,
                    'handlerLine' => $declaration['record']['line'] ?? null,
                    ...$binding['metadata'],
                    ...$this->configuredJobMetadata($job),
                ], static fn (mixed $value): bool => $value !== null),
            ));
        }
    }

    private function addListenMapListeners(Graph $graph): void
    {
        foreach ($this->classes as $provider => $record) {
            if (! $this->isEventServiceProvider($provider)) {
                continue;
            }

            foreach ($record['listen'] as $event => $listeners) {
                foreach ($listeners as $listenerSpec) {
                    [$listener, $method] = $this->parseListenerSpec($listenerSpec);
                    $this->addListensTo($graph, $listener, $method, $event, 1.0, [
                        'source' => 'event_service_provider',
                        'classStringListener' => true,
                    ]);
                }
            }
        }
    }

    /**
     * Laravel's event dispatcher retains the exact raw registrations that won
     * after providers, event caching, and withEvents() configuration ran. Read
     * its framework-owned property directly so no listener preparation or
     * application factory is executed.
     *
     * @return array<string, array<string, true>>|null
     */
    private function snapshotBootedListenerRegistrations(): ?array
    {
        $dispatcher = $this->containerBindings?->existingInstance('events')
            ?? $this->containerBindings?->existingInstance('Illuminate\\Contracts\\Events\\Dispatcher')
            ?? $this->containerBindings?->existingInstance(LaravelEventDispatcher::class);

        if (! $dispatcher instanceof LaravelEventDispatcher || $dispatcher::class !== LaravelEventDispatcher::class) {
            return null;
        }

        try {
            $reflection = new ReflectionClass(LaravelEventDispatcher::class);
            $raw = $reflection->getProperty('listeners')->getValue($dispatcher);
            $wildcards = $reflection->getProperty('wildcards')->getValue($dispatcher);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($raw) || ! is_array($wildcards)) {
            return null;
        }

        $raw = array_replace_recursive($raw, $wildcards);

        $registrations = [];

        foreach ($raw as $event => $listeners) {
            if (! is_string($event) || ! is_array($listeners)) {
                continue;
            }

            foreach ($listeners as $listener) {
                $key = $this->rawListenerRegistrationKey($listener);

                if ($key !== null) {
                    $registrations[ltrim($event, '\\')][$key] = true;
                }
            }
        }

        ksort($registrations);

        foreach ($registrations as &$listeners) {
            ksort($listeners);
        }
        unset($listeners);

        return $registrations;
    }

    private function rawListenerRegistrationKey(mixed $listener): ?string
    {
        if (is_string($listener)) {
            [$class, $method] = array_pad(explode('@', $listener, 2), 2, '*');

            return ltrim($class, '\\').'@'.($method !== '' ? $method : '*');
        }

        if (is_array($listener) && count($listener) === 2 && is_string($listener[1] ?? null)) {
            $target = $listener[0] ?? null;
            // Existing object listeners bypass the container and cannot be
            // reconstructed from a stable persisted identity alone.
            $class = is_string($target) ? $target : null;

            return is_string($class) ? ltrim($class, '\\').'@'.$listener[1] : null;
        }

        return null;
    }

    private function listenerRegistrationState(string $event, string $listener, string $method): string
    {
        if ($this->bootedListenerRegistrations === null) {
            return 'unavailable';
        }

        $event = ltrim($event, '\\');
        $listener = ltrim($listener, '\\');

        foreach ($this->bootedListenerRegistrations as $registeredEvent => $listeners) {
            if (! $this->registrationAppliesToEvent($registeredEvent, $event)) {
                continue;
            }

            if (isset($listeners[$listener.'@'.$method]) || isset($listeners[$listener.'@*'])) {
                return 'booted_dispatcher';
            }
        }

        return 'not_in_booted_dispatcher';
    }

    /** @return array<string, string>|null */
    private function snapshotBootedBusHandlers(): ?array
    {
        $dispatcher = $this->containerBindings?->existingInstance('Illuminate\\Contracts\\Bus\\Dispatcher')
            ?? $this->containerBindings?->existingInstance('Illuminate\\Contracts\\Bus\\QueueingDispatcher')
            ?? $this->containerBindings?->existingInstance(LaravelBusDispatcher::class);

        if (! $dispatcher instanceof LaravelBusDispatcher || $dispatcher::class !== LaravelBusDispatcher::class) {
            return null;
        }

        try {
            $property = (new ReflectionClass(LaravelBusDispatcher::class))->getProperty('handlers');
            $raw = $property->getValue($dispatcher);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($raw)) {
            return null;
        }

        $handlers = [];

        foreach ($raw as $job => $handler) {
            if (is_string($job) && is_string($handler) && $job !== '' && $handler !== '') {
                $handlers[ltrim($job, '\\')] = ltrim($handler, '\\');
            }
        }

        ksort($handlers);

        return $handlers;
    }

    private function applyBootedBusHandlerTruth(): void
    {
        if ($this->bootedBusHandlers === null) {
            return;
        }

        // The booted dispatcher is authoritative. A source Bus::map declaration
        // that is absent here was not registered (or was removed), so Laravel
        // falls back to the command's own handle/__invoke convention.
        foreach (array_keys($this->jobHandlerMaps) as $job) {
            if (! array_key_exists($job, $this->bootedBusHandlers)) {
                unset($this->jobHandlerMaps[$job]);
            }
        }

        foreach ($this->bootedBusHandlers as $job => $handler) {
            if (! isset($this->classes[$job])
                && ! isset($this->dispatchedJobs[$job])
                && ! isset($this->jobHandlerMaps[$job])) {
                continue;
            }

            $declaration = $this->jobHandlerMaps[$job] ?? [];
            $matchesDeclaration = ($declaration['handler'] ?? null) === $handler;
            $this->jobHandlerMaps[$job] = [
                'handler' => $handler,
                'file' => $matchesDeclaration ? ($declaration['file'] ?? null) : null,
                'line' => $matchesDeclaration ? ($declaration['line'] ?? null) : null,
                'offset' => $matchesDeclaration ? ($declaration['offset'] ?? null) : null,
                'confidence' => 1.0,
                'assumption' => 'observed_in_booted_bus_dispatcher',
                'registrationEvidence' => 'booted_dispatcher',
                'causalExecutionProven' => true,
                'source' => 'booted_bus_dispatcher',
            ];
        }
    }

    private function addBootedRuntimeListeners(Graph $graph): void
    {
        if ($this->bootedListenerRegistrations === null) {
            return;
        }

        foreach ($this->classes as $event => $record) {
            if ($this->kindOf($event) !== 'event') {
                continue;
            }

            foreach ($this->bootedListenerRegistrations as $registeredEvent => $listeners) {
                if (! $this->registrationAppliesToEvent($registeredEvent, $event)) {
                    continue;
                }

                foreach (array_keys($listeners) as $registration) {
                    [$listener, $method] = array_pad(explode('@', $registration, 2), 2, '*');
                    $method = $method === '*' ? 'handle' : $method;

                    if ((! isset($this->classes[$listener]) && ! class_exists($listener, false))
                        || $listener === '') {
                        continue;
                    }

                    // Exact source declarations were already reconciled against
                    // this snapshot. Re-adding them would overwrite their source
                    // provenance and falsely label them as runtime-only.
                    if ($registeredEvent === $event
                        && isset($this->declaredListenerRegistrations[$this->listenerRegistrationKey($registeredEvent, $listener, $method)])) {
                        continue;
                    }

                    $this->addListensTo(
                        $graph,
                        $listener,
                        $method,
                        $event,
                        1.0,
                        [
                            'source' => 'booted_event_dispatcher',
                            'classStringListener' => true,
                            'runtimeOnly' => true,
                            'registeredEvent' => $registeredEvent !== $event ? $registeredEvent : null,
                        ],
                    );
                }
            }
        }
    }

    private function registrationAppliesToEvent(string $registeredEvent, string $event): bool
    {
        if ($registeredEvent === $event || (str_contains($registeredEvent, '*') && Str::is($registeredEvent, $event))) {
            return true;
        }

        return in_array($registeredEvent, $this->implementedInterfaces($event), true);
    }

    private function listenerRegistrationKey(string $event, string $listener, string $method): string
    {
        return implode("\0", [ltrim($event, '\\'), ltrim($listener, '\\'), $method]);
    }

    private function addObservedByAttributes(Graph $graph): void
    {
        foreach ($this->classes as $model => $record) {
            foreach ($record['observedBy'] as $observer) {
                $this->addObserves($graph, $observer, $model, 1.0, 'attribute');
            }
        }
    }

    /**
     * @param array<int, Node> $statements
     */
    private function scanStatements(Graph $graph, array $statements): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $this->scanStatements($graph, $statement->stmts);
                continue;
            }

            if (! $statement instanceof Stmt\Class_ && ! $statement instanceof Stmt\Trait_) {
                continue;
            }

            $class = $this->className($statement);

            if ($class === null) {
                continue;
            }

            foreach ($statement->getMethods() as $method) {
                $context = [
                    'class' => $class,
                    'method' => $method->name->toString(),
                ];

                foreach ($method->stmts ?? [] as $methodStatement) {
                    $this->walk($graph, $methodStatement, $context);
                }
            }
        }
    }

    /**
     * @param array{class: string, method: string} $context
     */
    private function walk(Graph $graph, Node $node, array $context): void
    {
        if ($node instanceof Expr\StaticCall && $node->name instanceof Identifier) {
            $this->inspectStaticCall($graph, $node, $context);
        }

        if ($node instanceof Expr\MethodCall && $node->name instanceof Identifier) {
            if ($this->inspectDispatchChain($graph, $node, $context)) {
                return;
            }

            if ($this->inspectBusChain($graph, $node, $context)) {
                return;
            }
        }

        if ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
            $this->inspectFuncCall($graph, $node, $context);
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof Node) {
                $this->walk($graph, $value, $context);
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $this->walk($graph, $item, $context);
                    }
                }
            }
        }
    }

    /**
     * @param array{class: string, method: string} $context
     */
    private function inspectStaticCall(Graph $graph, Expr\StaticCall $call, array $context): void
    {
        $operation = $call->name->toString();
        $class = $this->resolveStaticClass($call->class, $context['class']);

        if ($class === null) {
            return;
        }

        if ($this->isFacade($class, self::EVENT_FACADE, 'Event')) {
            if ($operation === 'dispatch') {
                $event = $this->eventClassFromArg($call->args[0]->value ?? null);

                if ($event !== null) {
                    $this->addDispatches($graph, $context, $event, 'event', 'facade', 0.9, $call->getStartLine(), $call->getStartFilePos(), $operation);
                }
            }

            if ($operation === 'listen') {
                $this->inspectEventListen($graph, $call, $context);
            }

            return;
        }

        if ($this->isFacade($class, self::BUS_FACADE, 'Bus')) {
            if ($operation === 'map') {
                $this->inspectBusHandlerMap($graph, $call, $context);

                return;
            }

            if (in_array($operation, $this->staticDispatchMethods, true)) {
                $job = $this->eventClassFromArg($call->args[0]->value ?? null);

                if ($job !== null) {
                    $this->addDispatches($graph, $context, $job, 'job', 'facade', 0.9, $call->getStartLine(), $call->getStartFilePos(), $operation);
                }
            }

            return;
        }

        if ($operation === 'observe') {
            foreach ($this->classNamesFromExpr($call->args[0]->value ?? new Expr\Array_([])) as $observer) {
                $this->addObserves($graph, $observer, $class, 0.9, 'observe_call');
            }

            return;
        }

        if (in_array($operation, $this->staticDispatchMethods, true)) {
            $kind = $this->provenStaticDispatchKind($class, $operation);

            if ($kind !== null) {
                $this->addDispatches(
                    $graph,
                    $context,
                    $class,
                    $kind,
                    'static',
                    0.9,
                    $call->getStartLine(),
                    $call->getStartFilePos(),
                    $operation,
                    $this->pendingDispatchMetadata($class, $operation),
                );
            } elseif ($this->kindOf($class) !== null) {
                $graph->addWarning(array_filter([
                    'scanner' => $this->scannerName(),
                    'reason' => 'static_dispatch_method_unproven',
                    'class' => $class,
                    'method' => $operation,
                    'file' => $this->classes[$context['class']]['file'] ?? null,
                    'line' => $call->getStartLine(),
                    'message' => 'A static dispatch-looking call was skipped because the corresponding Laravel Dispatchable trait method was not proven.',
                ], static fn (mixed $value): bool => $value !== null));
            }
        }
    }

    /**
     * @param array{class: string, method: string} $context
     */
    private function inspectBusHandlerMap(Graph $graph, Expr\StaticCall $call, array $context): void
    {
        if (! in_array($context['method'], ['boot', 'register'], true)
            || ! $this->isServiceProvider($context['class'])) {
            $graph->addWarning(array_filter([
                'scanner' => $this->scannerName(),
                'reason' => 'bus_map_scope_unproven',
                'file' => $this->classes[$context['class']]['file'] ?? null,
                'line' => $call->getStartLine(),
                'registration' => $context['class'].'::'.$context['method'],
                'message' => 'Bus::map was outside a proven service-provider boot/register lifecycle and was not treated as global handler truth.',
            ], static fn (mixed $value): bool => $value !== null));

            return;
        }

        $map = $call->args[0]->value ?? null;

        if (! $map instanceof Expr\Array_) {
            return;
        }

        foreach ($map->items as $item) {
            if ($item === null || $item->key === null) {
                continue;
            }

            $job = $this->classNamesFromExpr($item->key)[0] ?? null;
            $handler = $this->classNamesFromExpr($item->value)[0] ?? null;

            if ($job !== null && $handler !== null) {
                $this->jobHandlerMaps[$job] = [
                    'handler' => $handler,
                    'file' => $this->classes[$context['class']]['file'] ?? null,
                    'line' => $call->getStartLine(),
                    'offset' => $call->getStartFilePos() >= 0 ? $call->getStartFilePos() : null,
                    'confidence' => 0.85,
                    'assumption' => 'service_provider_lifecycle_assumed',
                    'registrationEvidence' => $this->bootedBusHandlers === null ? 'unavailable' : 'absent_or_overridden',
                    'causalExecutionProven' => false,
                ];
            }
        }
    }

    /**
     * @param array{class: string, method: string} $context
     */
    private function inspectFuncCall(Graph $graph, Expr\FuncCall $call, array $context): void
    {
        $function = strtolower($this->resolvedName($call->name) ?? '');

        if (in_array($function, ['event', 'broadcast'], true)) {
            $event = $this->eventClassFromArg($call->args[0]->value ?? null);

            if ($event !== null) {
                $this->addDispatches($graph, $context, $event, 'event', 'helper', 0.95, $call->getStartLine(), $call->getStartFilePos(), $function);
            }

            return;
        }

        if ($function === 'dispatch') {
            $arg = $call->args[0]->value ?? null;

            if ($arg instanceof Expr\New_) {
                $job = $this->resolvedName($arg->class instanceof Name ? $arg->class : null);

                if ($job !== null) {
                    $this->addDispatches($graph, $context, $job, 'job', 'helper', 0.95, $call->getStartLine(), $call->getStartFilePos(), 'dispatch');
                }
            }
        }
    }

    /**
     * Capture PendingDispatch configuration such as
     * SomeJob::dispatch(...)->afterCommit()/beforeCommit(). The underlying
     * static call is scanned as well; Graph edge merging retains this stronger
     * transaction-timing metadata on the same dispatch edge.
     *
     * @param array{class: string, method: string} $context
     */
    private function inspectDispatchChain(Graph $graph, Expr\MethodCall $call, array $context): bool
    {
        $expression = $call;
        $metadata = [];
        $fluentCalls = [];

        while ($expression instanceof Expr\MethodCall) {
            $fluentCalls[] = $expression;
            $expression = $expression->var;
        }

        // Method-call nodes are encountered outside-in, while Laravel applies
        // the fluent configuration inside-out. Replaying in runtime order means
        // a later/outer option correctly wins.
        foreach (array_reverse($fluentCalls) as $fluentCall) {
            if ($fluentCall->name instanceof Identifier) {
                $operation = $fluentCall->name->toString();

                if ($operation === 'afterCommit') {
                    $metadata['afterCommit'] = true;
                    $metadata['transactionTimingSource'] = 'pending_dispatch_chain';
                } elseif ($operation === 'beforeCommit') {
                    $metadata['afterCommit'] = false;
                    $metadata['transactionTimingSource'] = 'pending_dispatch_chain';
                } elseif (in_array($operation, ['onQueue', 'onConnection'], true)) {
                    $value = $fluentCall->args[0]->value ?? null;

                    if ($value instanceof Scalar\String_) {
                        $metadata[$operation === 'onQueue' ? 'queue' : 'connection'] = $value->value;
                    }
                }
            }
        }

        if (! $expression instanceof Expr\StaticCall
            || ! $expression->name instanceof Identifier
            || ! in_array($expression->name->toString(), $this->staticDispatchMethods, true)) {
            return false;
        }

        $target = $this->resolveStaticClass($expression->class, $context['class']);
        $kind = $target === null ? null : $this->provenStaticDispatchKind($target, $expression->name->toString());

        if ($target !== null && $kind !== null) {
            $fluentProof = true;

            foreach ($fluentCalls as $fluentCall) {
                if (! $fluentCall->name instanceof Identifier) {
                    continue;
                }

                $operation = $fluentCall->name->toString();

                // PendingDispatch owns a small set of local fluent methods. Its
                // documented configuration methods and every unknown method
                // otherwise call the same method on the underlying job.
                $delegatesToJob = in_array($operation, self::PENDING_DISPATCH_DELEGATED_METHODS, true)
                    || ! in_array($operation, self::PENDING_DISPATCH_LOCAL_METHODS, true);

                if (! $delegatesToJob) {
                    continue;
                }

                $state = $this->publicMethodState($target, $operation);

                if ($state !== 'public') {
                    $fluentProof = false;

                    if ($operation === 'onQueue') {
                        unset($metadata['queue']);
                    } elseif ($operation === 'onConnection') {
                        unset($metadata['connection']);
                    } elseif (in_array($operation, ['afterCommit', 'beforeCommit'], true)) {
                        unset($metadata['afterCommit'], $metadata['transactionTimingSource']);
                    }

                    $graph->addWarning(array_filter([
                        'scanner' => $this->scannerName(),
                        'reason' => 'pending_dispatch_job_method_unproven',
                        'job' => $target,
                        'method' => $operation,
                        'state' => $state,
                        'file' => $this->classes[$context['class']]['file'] ?? null,
                        'line' => $fluentCall->getStartLine(),
                        'message' => 'PendingDispatch delegates this fluent operation to the job, but a public job method was not proven.',
                    ], static fn (mixed $value): bool => $value !== null));
                }
            }

            $pendingMetadata = $this->pendingDispatchMetadata($target, $expression->name->toString());

            $this->addDispatches($graph, $context, $target, $kind, 'static', 0.95, $call->getStartLine(), $expression->getStartFilePos(), $expression->name->toString(), [
                ...$metadata,
                ...$pendingMetadata,
                'dispatchConfigurationSource' => 'pending_dispatch_chain',
                'causalExecutionProven' => $fluentProof
                    && (($pendingMetadata['causalExecutionProven'] ?? true) !== false),
                'executionSemantics' => $fluentProof
                    ? ($pendingMetadata['executionSemantics'] ?? 'pending_dispatch')
                    : 'invalid_or_unproven_pending_dispatch_fluent_call',
            ]);

            return true;
        }

        return false;
    }

    /**
     * Capture Bus::chain([...])->onConnection(...)->onQueue(...)->dispatch().
     * Each statically named job receives its own dispatch edge and chain
     * position so consumers can recover the complete queued workflow.
     *
     * @param array{class: string, method: string} $context
     */
    private function inspectBusChain(Graph $graph, Expr\MethodCall $call, array $context): bool
    {
        $expression = $call;
        $metadata = [];
        $dispatchSeen = false;
        $fluentCalls = [];

        while ($expression instanceof Expr\MethodCall) {
            $fluentCalls[] = $expression;
            $expression = $expression->var;
        }

        foreach (array_reverse($fluentCalls) as $fluentCall) {
            if ($fluentCall->name instanceof Identifier) {
                $operation = $fluentCall->name->toString();

                if ($operation === 'dispatch') {
                    $dispatchSeen = true;
                } elseif (in_array($operation, ['onQueue', 'onConnection'], true)) {
                    $value = $fluentCall->args[0]->value ?? null;

                    if ($value instanceof Scalar\String_) {
                        $metadata[$operation === 'onQueue' ? 'chainQueue' : 'chainConnection'] = $value->value;
                    }
                }
            }
        }

        if (! $dispatchSeen
            || ! $expression instanceof Expr\StaticCall
            || ! $expression->name instanceof Identifier
            || $expression->name->toString() !== 'chain') {
            return false;
        }

        $facade = $this->resolveStaticClass($expression->class, $context['class']);

        if ($facade === null || ! $this->isFacade($facade, self::BUS_FACADE, 'Bus')) {
            return false;
        }

        $jobsExpression = $expression->args[0]->value ?? null;

        if (! $jobsExpression instanceof Expr) {
            return false;
        }

        $jobs = $this->jobClassesFromChainExpression($jobsExpression);
        $firstJob = $jobs[0]['class'] ?? null;
        $firstChainMethodState = is_string($firstJob)
            ? $this->publicMethodState($firstJob, 'chain')
            : 'unproven';

        if ($jobs !== [] && $firstChainMethodState !== 'public') {
            $graph->addWarning(array_filter([
                'scanner' => $this->scannerName(),
                'reason' => 'bus_chain_first_job_method_unproven',
                'job' => $firstJob,
                'method' => 'chain',
                'state' => $firstChainMethodState,
                'file' => $this->classes[$context['class']]['file'] ?? null,
                'line' => $call->getStartLine(),
                'message' => 'PendingChain calls chain() on its first job before dispatch; a public method was not proven.',
            ], static fn (mixed $value): bool => $value !== null));
        }

        foreach ($jobs as $position => $jobRecord) {
            $job = $jobRecord['class'];

            if ($position === 0 && $this->dispatchMode($job, 'job', 'dispatch') === 'sync') {
                $graph->addWarning(array_filter([
                    'scanner' => $this->scannerName(),
                    'reason' => 'bus_chain_sync_first_job',
                    'file' => $this->classes[$context['class']]['file'] ?? null,
                    'line' => $call->getStartLine(),
                    'job' => $job,
                    'message' => 'The first declared Bus chain member is synchronous, so downstream causal execution cannot be guaranteed from the declaration alone.',
                ], static fn (mixed $value): bool => $value !== null));
            }

            if ($position > 0 && $jobRecord['syntax'] === 'class_string') {
                $graph->addWarning(array_filter([
                    'scanner' => $this->scannerName(),
                    'reason' => 'bus_chain_class_string_member_unproven',
                    'file' => $this->classes[$context['class']]['file'] ?? null,
                    'line' => $call->getStartLine(),
                    'job' => $job,
                    'chainPosition' => $position,
                    'message' => 'A later Bus chain class-string is retained as declared workflow membership; construction and eventual execution are not proven here.',
                ], static fn (mixed $value): bool => $value !== null));
            }

            $initialDispatchProven = $position === 0 && $firstChainMethodState === 'public';
            $this->addDispatches($graph, $context, $job, 'job', 'bus_chain', $initialDispatchProven ? 0.9 : 0.7, $call->getStartLine(), $expression->getStartFilePos(), 'dispatch', [
                ...$metadata,
                'chained' => true,
                'chainPosition' => $position,
                'dispatchConfigurationSource' => 'bus_chain',
                'executionSemantics' => $initialDispatchProven
                    ? 'initial_chain_dispatch'
                    : 'declared_chain_membership',
                'causalExecutionProven' => $initialDispatchProven,
                'chainMemberSyntax' => $jobRecord['syntax'],
            ]);
        }

        return $jobs !== [];
    }

    /** @return array<int, array{class: string, syntax: string}> */
    private function jobClassesFromChainExpression(Expr $expression): array
    {
        if ($expression instanceof Expr\New_ && $expression->class instanceof Name) {
            $class = $this->resolvedName($expression->class);

            return $class === null ? [] : [['class' => $class, 'syntax' => 'new_object']];
        }

        if ($expression instanceof Expr\ClassConstFetch) {
            $class = $this->classNamesFromExpr($expression)[0] ?? null;

            return $class === null ? [] : [['class' => $class, 'syntax' => 'class_string']];
        }

        if (! $expression instanceof Expr\Array_) {
            return [];
        }

        $jobs = [];

        foreach ($expression->items as $item) {
            if ($item !== null) {
                $jobs = array_merge($jobs, $this->jobClassesFromChainExpression($item->value));
            }
        }

        return $jobs;
    }

    /**
     * @param array{class: string, method: string} $context
     */
    private function inspectEventListen(Graph $graph, Expr\StaticCall $call, array $context): void
    {
        $eventArg = $call->args[0]->value ?? null;
        $listenerArg = $call->args[1]->value ?? null;

        // Closure form: Event::listen(function (SomeEvent $event) { ... }) — the
        // event comes from the closure's typed parameter and the edge is
        // attributed to the registering method.
        if ($eventArg instanceof Expr\Closure || $eventArg instanceof Expr\ArrowFunction) {
            $paramType = isset($eventArg->params[0])
                ? $this->resolveType($eventArg->params[0]->type, $context['class'])
                : null;

            if (is_string($paramType)) {
                $this->addListensTo($graph, $context['class'], $context['method'], $paramType, 0.7, [
                    'source' => 'explicit_listen',
                    'closure' => true,
                ]);
                $this->addClosureListenerWarning($graph, $context, $paramType, $call->getStartLine());
            }

            return;
        }

        $event = $eventArg !== null ? ($this->classNamesFromExpr($eventArg)[0] ?? null) : null;

        if ($event === null) {
            return;
        }

        if ($listenerArg instanceof Expr\Closure || $listenerArg instanceof Expr\ArrowFunction) {
            $this->addListensTo($graph, $context['class'], $context['method'], $event, 0.7, [
                'source' => 'explicit_listen',
                'closure' => true,
            ]);
            $this->addClosureListenerWarning($graph, $context, $event, $call->getStartLine());

            return;
        }

        if ($listenerArg instanceof Expr\Array_ && count($listenerArg->items) === 2) {
            $listener = $this->classNamesFromExpr($listenerArg->items[0]->value)[0] ?? null;
            $method = $listenerArg->items[1]->value instanceof Scalar\String_ ? $listenerArg->items[1]->value->value : null;

            if ($listener !== null && $method !== null) {
                $this->addListensTo($graph, $listener, $method, $event, 0.9, [
                    'source' => 'explicit_listen',
                    'classStringListener' => true,
                ]);
            }

            return;
        }

        if ($listenerArg !== null) {
            $listenerSpec = $this->classNamesFromExpr($listenerArg)[0] ?? null;

            if ($listenerSpec !== null) {
                [$listener, $method] = $this->parseListenerSpec($listenerSpec);
                $this->addListensTo($graph, $listener, $method, $event, 0.9, [
                    'source' => 'explicit_listen',
                    'classStringListener' => true,
                ]);
            }
        }
    }

    /** @return array{string, string} */
    private function parseListenerSpec(string $listener): array
    {
        [$class, $method] = array_pad(explode('@', $listener, 2), 2, 'handle');

        return [ltrim($class, '\\'), $method !== '' ? $method : 'handle'];
    }

    private function eventClassFromArg(?Expr $arg): ?string
    {
        if ($arg instanceof Expr\New_ && $arg->class instanceof Name) {
            return $this->resolvedName($arg->class);
        }

        if ($arg !== null) {
            return $this->classNamesFromExpr($arg)[0] ?? null;
        }

        return null;
    }

    /**
     * @param array{class: string, method: string} $context
     */
    private function addDispatches(Graph $graph, array $context, string $target, string $kind, string $via, float $confidence, int $line, int $offset, string $operation, array $metadata = []): void
    {
        $callerId = $context['class'].'::'.$context['method'];

        $this->ensureMethodNode($graph, $context['class'], $context['method']);

        if ($this->isKnownNonInstantiableClass($target)) {
            $graph->addWarning(array_filter([
                'scanner' => $this->scannerName(),
                'reason' => 'dispatch_target_not_instantiable',
                'target' => $target,
                'kind' => $kind,
                'file' => $this->classes[$context['class']]['file'] ?? null,
                'line' => $line,
                'message' => 'Laravel cannot dispatch a trait, interface, or abstract class instance, so no dispatch execution edge was emitted.',
            ], static fn (mixed $value): bool => $value !== null));

            return;
        }

        $this->ensureTargetNode($graph, $target, $kind);

        if ($kind === 'job') {
            $this->dispatchedJobs[$target] = true;
        }

        $queue = $this->queueConfiguration($target);

        if ($kind === 'job' && isset($this->classes[$target])) {
            $queue = array_replace($queue, $this->queueExecutionMetadata($target, true));
        }

        $dispatchMode = $this->dispatchMode($target, $kind, $operation);
        $afterCommit = $metadata['afterCommit'] ?? ($dispatchMode === 'queued' ? ($queue['afterCommit'] ?? null) : null);
        $transactionTimingSource = $metadata['transactionTimingSource']
            ?? ($dispatchMode === 'queued' && isset($queue['afterCommit']) ? 'job_configuration' : null);
        $connection = $metadata['connection']
            ?? ($dispatchMode === 'queued' ? ($queue['connection'] ?? ($metadata['chainConnection'] ?? null)) : null);
        $queueName = $metadata['queue']
            ?? ($dispatchMode === 'queued' ? ($queue['queue'] ?? ($metadata['chainQueue'] ?? null)) : null);
        $occurrence = array_filter([
            'kind' => $kind,
            'line' => $line,
            'offset' => $offset >= 0 ? $offset : null,
            'via' => $via,
            'method' => $operation,
            'mode' => $dispatchMode,
            'chained' => (bool) ($metadata['chained'] ?? false),
            'chainPosition' => $metadata['chainPosition'] ?? null,
            'dispatchConfigurationSource' => $metadata['dispatchConfigurationSource'] ?? null,
            'afterCommit' => $afterCommit,
            'transactionTimingSource' => $transactionTimingSource,
            'connection' => $connection,
            'queue' => $queueName,
            'chainConnection' => $metadata['chainConnection'] ?? null,
            'chainQueue' => $metadata['chainQueue'] ?? null,
            'executionSemantics' => $metadata['executionSemantics'] ?? null,
            'causalExecutionProven' => $metadata['causalExecutionProven'] ?? null,
            'chainMemberSyntax' => $metadata['chainMemberSyntax'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
        $chainKey = array_key_exists('chainPosition', $metadata)
            ? sprintf(':chain-%09d', $metadata['chainPosition'])
            : '';
        $occurrenceKey = sprintf('%09d:%09d:%s:%s%s', $line, max(0, $offset), $via, $operation, $chainKey);
        $edgeMetadata = array_replace(array_filter([
            'kind' => $kind,
            'via' => $via,
            'line' => $line,
            'dispatchMethod' => $operation,
            'dispatchMode' => $dispatchMode,
            'afterCommit' => $afterCommit,
            'transactionTimingSource' => $transactionTimingSource,
            'connection' => $connection,
            'queue' => $queueName,
        ], static fn (mixed $value): bool => $value !== null), $metadata);
        $edgeMetadata['dispatchOccurrences'] = [$occurrenceKey => $occurrence];

        $edge = $graph->addEdge(new Edge($callerId, $target, 'dispatches', $confidence, $edgeMetadata));
        $this->normalizeDispatchModes($edge);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function addListensTo(Graph $graph, string $listener, string $method, string $event, float $confidence, array $metadata): void
    {
        if (! ($metadata['closure'] ?? false)) {
            $registeredEvent = is_string($metadata['registeredEvent'] ?? null)
                ? $metadata['registeredEvent']
                : $event;
            $registrationKey = $this->listenerRegistrationKey($registeredEvent, $listener, $method);

            if (($metadata['source'] ?? null) === 'booted_event_dispatcher') {
                $metadata['runtimeOnly'] = ! isset($this->declaredListenerRegistrations[$registrationKey]);
            } else {
                $this->declaredListenerRegistrations[$registrationKey] = true;
                $metadata['runtimeOnly'] = false;
            }
        }

        // Laravel chooses handle versus __invoke, and decides whether the
        // listener is queued, from the registered class *before* resolving it
        // through the container. A binding can change the object that receives
        // the call, but it cannot change that selected method or queue branch.
        $registeredSelection = ($metadata['closure'] ?? false)
            ? ['status' => 'found', 'method' => $method, 'declaration' => $this->executableMethod($listener, $method)]
            : $this->listenerHandlerSelection(
                $listener,
                $method,
                (bool) ($metadata['classStringListener'] ?? false),
            );
        $registeredExecutable = ($registeredSelection['status'] ?? null) === 'found'
            && is_array($registeredSelection['declaration'] ?? null)
                ? $registeredSelection['declaration']
                : null;

        $binding = ($metadata['closure'] ?? false)
            ? ['status' => 'resolved', 'class' => $listener, 'confidence' => 1.0, 'metadata' => []]
            : $this->containerTarget($listener);
        $effectiveListener = $binding['status'] === 'resolved' ? $binding['class'] : $listener;
        $registrationState = ($metadata['closure'] ?? false)
            ? 'closure_unmapped'
            : $this->listenerRegistrationState($event, $listener, $method);
        $metadata = [
            ...$metadata,
            'registrationEvidence' => $registrationState,
            'causalExecutionProven' => $registrationState === 'booted_dispatcher',
        ];

        if (! ($metadata['closure'] ?? false) && $registrationState === 'booted_dispatcher') {
            $this->registeredListeners[$listener] = true;
        }

        $this->ensureRegisteredMethodNode($graph, $listener, $method, $registeredExecutable);
        $this->ensureTargetNode($graph, $event, 'event');

        $queueMetadata = ($metadata['closure'] ?? false)
            ? []
            : $this->listenerExecutionMetadata($listener, $effectiveListener, false);
        $shouldQueue = ($queueMetadata['queued'] ?? false) === true
            ? $this->listenerShouldQueueDecision($effectiveListener)
            : 'not_applicable';

        $graph->addEdge(new Edge($listener.'::'.$method, $event, 'listens_to', $confidence, array_filter([
            'queued' => ($queueMetadata['queued'] ?? false) ?: null,
            'afterCommit' => $queueMetadata['afterCommit'] ?? null,
            'connection' => $queueMetadata['connection'] ?? null,
            'queue' => $queueMetadata['queue'] ?? null,
            'shouldQueueDecision' => $shouldQueue !== 'not_applicable' ? $shouldQueue : null,
        ], static fn (mixed $value): bool => $value !== null) + $metadata));

        // A closure is executed by Laravel, but the surrounding provider method
        // only registers it. Reversing the legacy listens_to edge would falsely
        // claim that boot() handles the event, so retain the registration edge
        // and leave the semantic execution gap explicit.
        if ($metadata['closure'] ?? false) {
            return;
        }

        if (($registeredSelection['status'] ?? null) !== 'found') {
            if (($registeredSelection['status'] ?? null) === 'non_public') {
                $declaration = $registeredSelection['declaration'];
                $graph->addWarning([
                    'scanner' => $this->scannerName(),
                    'reason' => 'listener_handler_not_public',
                    'listener' => $listener,
                    'handler' => $declaration['class'].'::'.$registeredSelection['method'],
                    'event' => $event,
                    'message' => 'The method selected on the registered listener class is not public, so executable handling is not emitted.',
                ]);
            } elseif (($registeredSelection['status'] ?? null) === 'unproven') {
                $graph->addWarning([
                    'scanner' => $this->scannerName(),
                    'reason' => 'listener_handler_resolution_unproven',
                    'listener' => $listener,
                    'event' => $event,
                    'message' => 'Method existence on the registered listener class could not be proven; Laravel\'s __invoke fallback was not guessed.',
                ]);
            }

            return;
        }

        if (in_array($shouldQueue, ['never', 'invalid'], true)) {
            $graph->addWarning([
                'scanner' => $this->scannerName(),
                'reason' => $shouldQueue === 'never'
                    ? 'listener_should_queue_always_false'
                    : 'listener_should_queue_not_public',
                'listener' => $listener,
                'event' => $event,
                'message' => $shouldQueue === 'never'
                    ? 'The queued listener has a literal-false shouldQueue gate, so Laravel will not execute its handler.'
                    : 'Laravel can see shouldQueue on the resolved listener but cannot call it publicly, so handler execution is not emitted.',
            ]);

            return;
        }

        if ($binding['status'] !== 'resolved') {
            $graph->addWarning([
                'scanner' => $this->scannerName(),
                'reason' => 'listener_binding_target_unknown',
                'listener' => $listener,
                'event' => $event,
                'message' => 'The listener has a declared container binding whose concrete target cannot be proven without executing application code.',
            ]);

            return;
        }

        if (! $this->isInstantiableClass($effectiveListener)
            || (($queueMetadata['queued'] ?? false) === true && ! $this->isInstantiableClass($listener))) {
            $graph->addWarning([
                'scanner' => $this->scannerName(),
                'reason' => 'listener_not_instantiable',
                'listener' => $listener,
                'effectiveListener' => $effectiveListener,
                'event' => $event,
                'message' => 'Laravel cannot instantiate the class required for this listener execution branch.',
            ]);

            return;
        }

        $selectedMethod = $registeredSelection['method'];
        $selection = $this->methodResolution($effectiveListener, $selectedMethod);

        if (($selection['status'] ?? null) === 'found') {
            $declaration = $selection['declaration'];
            $selection = [
                'status' => ($declaration['record']['visibility'] ?? null) === 'public' ? 'found' : 'non_public',
                'method' => $selectedMethod,
                'declaration' => $declaration,
            ];
        }

        if ($selection['status'] !== 'found') {
            if ($selection['status'] === 'non_public') {
                $declaration = $selection['declaration'];
                $graph->addWarning([
                    'scanner' => $this->scannerName(),
                    'reason' => 'listener_handler_not_public',
                    'listener' => $listener,
                    'handler' => $declaration['class'].'::'.$selection['method'],
                    'event' => $event,
                    'message' => 'The method Laravel selected on the registered class is not public on the resolved listener target, so executable handling is not emitted.',
                ]);
            } elseif ($selection['status'] === 'unproven') {
                $graph->addWarning([
                    'scanner' => $this->scannerName(),
                    'reason' => 'listener_handler_resolution_unproven',
                    'listener' => $listener,
                    'event' => $event,
                    'message' => 'The already-selected listener method could not be proven on the resolved container target; a second __invoke fallback was not assumed.',
                ]);
            }

            return;
        }

        $executable = $selection['declaration'];
        $executableMethod = $selection['method'];

        $this->ensureMethodNode($graph, $executable['class'], $executableMethod);

        $graph->addEdge(new Edge(
            $event,
            $executable['class'].'::'.$executableMethod,
            'handled_by',
            $confidence * $binding['confidence'],
            array_filter([
                'kind' => 'listener',
                'handlerClass' => $effectiveListener,
                'requestedHandlerClass' => $listener !== $effectiveListener ? $listener : null,
                'declaringClass' => $executable['class'] !== $effectiveListener ? $executable['class'] : null,
                'method' => $executableMethod,
                'source' => $metadata['source'] ?? 'listener_registration',
                'registrationEvidence' => $metadata['registrationEvidence'] ?? null,
                'causalExecutionProven' => $metadata['causalExecutionProven'] ?? null,
                'conditionalExecution' => $shouldQueue === 'conditional' ? 'shouldQueue' : null,
                'file' => $executable['record']['file'] ?? null,
                'line' => $executable['record']['line'] ?? null,
                ...$binding['metadata'],
                ...$this->listenerExecutionMetadata($listener, $effectiveListener, true),
            ], static fn (mixed $value): bool => $value !== null)
        ));
    }

    /**
     * @return array{class: string, record: array<string, mixed>}|null
     */
    private function executableMethod(string $class, string $method): ?array
    {
        $declaration = $this->methodDeclaration($class, $method);

        return ($declaration['record']['visibility'] ?? null) === 'public'
            ? $declaration
            : null;
    }

    /**
     * @return array{class: string, record: array<string, mixed>}|null
     */
    private function methodDeclaration(string $class, string $method, array $visited = []): ?array
    {
        $resolution = $this->methodResolution($class, $method, $visited);

        return $resolution['status'] === 'found'
            ? $resolution['declaration']
            : null;
    }

    /**
     * Resolve Laravel's method-existence decision without silently turning an
     * unknown package trait/parent into "method absent". That distinction is
     * what makes an __invoke fallback safe.
     *
     * @return array<string, mixed>
     */
    private function methodResolution(string $class, string $method, array $visited = []): array
    {
        $class = ltrim($class, '\\');

        if (isset($visited[$class])) {
            return ['status' => 'unproven', 'reason' => 'method_resolution_cycle'];
        }

        $visited[$class] = true;

        if (isset($this->methods[$class][$method])) {
            return [
                'status' => 'found',
                'declaration' => ['class' => $class, 'record' => $this->methods[$class][$method]],
            ];
        }

        if (isset($this->interfaces[$class])) {
            $candidates = [];
            $unproven = false;

            foreach ($this->interfaces[$class] as $parent) {
                $resolution = $this->methodResolution($parent, $method, $visited);

                if (($resolution['status'] ?? null) === 'found') {
                    $declaration = $resolution['declaration'];
                    $candidates[$declaration['class'].'::'.$method] = $declaration;
                } elseif (($resolution['status'] ?? null) === 'unproven') {
                    $unproven = true;
                }
            }

            if (count($candidates) === 1 && ! $unproven) {
                return ['status' => 'found', 'declaration' => array_values($candidates)[0]];
            }

            if ($candidates !== [] || $unproven) {
                return ['status' => 'unproven', 'reason' => 'interface_method_ambiguous_or_unproven'];
            }

            return ['status' => 'absent'];
        }

        if (! isset($this->classes[$class])) {
            return $this->reflectedMethodResolution($class, $method, $visited);
        }

        $record = $this->methods[$class][$method] ?? null;

        if (is_array($record)) {
            return [
                'status' => 'found',
                'declaration' => ['class' => $class, 'record' => $record],
            ];
        }

        $adaptations = $this->classes[$class]['traitAdaptations'] ?? [];
        $aliases = $adaptations['aliases'][$method] ?? [];

        if ($aliases !== []) {
            $aliasCandidates = [];
            $aliasUnproven = false;

            foreach ($aliases as $alias) {
                $traits = is_string($alias['trait'] ?? null)
                    ? [$alias['trait']]
                    : ($this->classes[$class]['traits'] ?? []);

                foreach ($traits as $trait) {
                    $source = $this->methodResolution($trait, $alias['method'], $visited);

                    if ($source['status'] === 'unproven') {
                        $aliasUnproven = true;
                        continue;
                    }

                    if ($source['status'] !== 'found') {
                        continue;
                    }

                    $key = $source['declaration']['class'].'::'.$alias['method'];
                    $aliasCandidates[$key] = [$source['declaration'], $alias];
                }
            }

            if (count($aliasCandidates) === 1) {
                [$source, $alias] = array_values($aliasCandidates)[0];
                $aliasRecord = $source['record'];
                $aliasRecord['visibility'] = $alias['visibility'] ?? $aliasRecord['visibility'];
                $aliasRecord['signature'] = preg_replace(
                    '/^[^(]+/',
                    $method,
                    (string) $aliasRecord['signature'],
                ) ?? $method.'()';
                $aliasRecord['aliasOf'] = $source['class'].'::'.$alias['method'];
                $aliasRecord['originClass'] = $source['record']['originClass'] ?? $source['class'];
                $this->methods[$class][$method] = $aliasRecord;

                return [
                    'status' => 'found',
                    'declaration' => ['class' => $class, 'record' => $aliasRecord],
                ];
            }

            return [
                'status' => 'unproven',
                'reason' => $aliasUnproven ? 'trait_alias_source_unproven' : 'trait_alias_ambiguous',
            ];
        }

        $excludedTraits = [];

        foreach ($adaptations['precedence'][$method] ?? [] as $precedence) {
            foreach ($precedence['insteadOf'] as $excluded) {
                $excludedTraits[$excluded] = true;
            }
        }

        $traitCandidates = [];
        $traitUnproven = false;

        foreach ($this->classes[$class]['traits'] ?? [] as $trait) {
            if (isset($excludedTraits[$trait])) {
                continue;
            }

            $resolution = $this->methodResolution($trait, $method, $visited);

            if ($resolution['status'] === 'unproven') {
                $traitUnproven = true;
                continue;
            }

            if ($resolution['status'] === 'found') {
                $declaration = $resolution['declaration'];
                $key = $declaration['class'].'::'.$method;
                $traitCandidates[$key] = $declaration;
            }
        }

        if (count($traitCandidates) === 1 && ! $traitUnproven) {
            return [
                'status' => 'found',
                'declaration' => array_values($traitCandidates)[0],
            ];
        }

        if (count($traitCandidates) > 1 || $traitUnproven) {
            return [
                'status' => 'unproven',
                'reason' => count($traitCandidates) > 1 ? 'trait_method_ambiguous' : 'trait_method_unproven',
            ];
        }

        $parent = $this->classes[$class]['extends'] ?? null;

        if (is_string($parent) && $parent !== '') {
            return $this->methodResolution($parent, $method, $visited);
        }

        return ['status' => 'absent'];
    }

    /** @return array<string, mixed> */
    private function reflectedMethodResolution(string $class, string $method, array $visited): array
    {
        $reflection = $this->safeReflection($class);

        if ($reflection === null) {
            return ['status' => 'unproven', 'reason' => 'external_type_not_reflectable'];
        }

        try {
            if ($reflection->hasMethod($method)) {
                $reflectedMethod = $reflection->getMethod($method);
                $declaringClass = $reflectedMethod->getDeclaringClass()->getName();
                $record = $this->reflectionMethodRecord($reflectedMethod);
                $this->methods[$declaringClass][$method] = $record;

                return [
                    'status' => 'found',
                    'declaration' => ['class' => $declaringClass, 'record' => $record],
                ];
            }

            // ReflectionClass::hasMethod() omits a parent's private method on a
            // child. Laravel's job-object method_exists() decision still gives
            // that method precedence over __invoke, so inspect the parent too.
            $parent = $reflection->getParentClass();

            if ($parent instanceof ReflectionClass) {
                return $this->methodResolution($parent->getName(), $method, $visited);
            }

            return ['status' => 'absent'];
        } catch (Throwable) {
            return ['status' => 'unproven', 'reason' => 'external_method_reflection_failed'];
        }
    }

    /** @return array<string, mixed> */
    private function reflectionMethodRecord(ReflectionMethod $method): array
    {
        $file = $method->getFileName();
        $file = is_string($file) ? ($this->files->relativePath($file) ?? $file) : null;
        $parameters = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $parameters[] = [
                'name' => $parameter->getName(),
                'type' => $type instanceof ReflectionNamedType ? $type->getName() : ($type !== null ? (string) $type : null),
                'allowsNull' => $type?->allowsNull() ?? true,
                'optional' => $parameter->isOptional(),
                'variadic' => $parameter->isVariadic(),
                'byReference' => $parameter->isPassedByReference(),
            ];
        }

        $returnType = $method->getReturnType();
        $signature = $method->getName().'('.implode(', ', array_map(
            static fn (array $parameter): string => ($parameter['type'] !== null ? $parameter['type'].' ' : '').'$'.$parameter['name'],
            $parameters,
        )).')';

        if ($returnType !== null) {
            $signature .= ': '.($returnType instanceof ReflectionNamedType ? $returnType->getName() : (string) $returnType);
        }

        return [
            'node' => null,
            'file' => $file,
            'line' => $method->getStartLine() ?: 1,
            'signature' => $signature,
            'inputs' => $parameters,
            'outputs' => $returnType === null ? [] : [[
                'type' => $returnType instanceof ReflectionNamedType ? $returnType->getName() : (string) $returnType,
                'allowsNull' => $returnType->allowsNull(),
            ]],
            'visibility' => $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private'),
            'static' => $method->isStatic(),
            'originClass' => $method->getDeclaringClass()->getName(),
            'reflected' => true,
        ];
    }

    private function safeReflection(string $class): ?ReflectionClass
    {
        try {
            $loaded = class_exists($class, false) || trait_exists($class, false) || interface_exists($class, false);
            $frameworkOwned = str_starts_with(ltrim($class, '\\'), 'Illuminate\\')
                || str_starts_with(ltrim($class, '\\'), 'Symfony\\');

            if (! $loaded && ! $frameworkOwned) {
                return null;
            }

            if (! $loaded
                && ! class_exists($class)
                && ! trait_exists($class)
                && ! interface_exists($class)) {
                return null;
            }

            return new ReflectionClass($class);
        } catch (Throwable) {
            return null;
        }
    }

    private function isInstantiableClass(string $class): bool
    {
        $class = ltrim($class, '\\');

        if (isset($this->classes[$class])) {
            return ($this->classes[$class]['declarationKind'] ?? 'class') === 'class'
                && ! ($this->classes[$class]['abstract'] ?? false);
        }

        if (isset($this->interfaces[$class])
            || (! class_exists($class, false) && ! trait_exists($class, false) && ! interface_exists($class, false))) {
            return false;
        }

        try {
            return (new ReflectionClass($class))->isInstantiable();
        } catch (Throwable) {
            return false;
        }
    }

    private function isKnownNonInstantiableClass(string $class): bool
    {
        $class = ltrim($class, '\\');

        if (isset($this->classes[$class])) {
            return ($this->classes[$class]['declarationKind'] ?? 'class') !== 'class'
                || ($this->classes[$class]['abstract'] ?? false);
        }

        if (isset($this->interfaces[$class])) {
            return true;
        }

        if (! class_exists($class, false)
            && ! trait_exists($class, false)
            && ! interface_exists($class, false)) {
            return false;
        }

        try {
            return ! (new ReflectionClass($class))->isInstantiable();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function jobHandlerSelection(string $class): array
    {
        $handle = $this->methodResolution($class, 'handle');

        if ($handle['status'] === 'found') {
            $declaration = $handle['declaration'];

            return [
                'status' => ($declaration['record']['visibility'] ?? null) === 'public' ? 'found' : 'non_public',
                'method' => 'handle',
                'declaration' => $declaration,
            ];
        }

        if ($handle['status'] === 'unproven') {
            return $handle;
        }

        $invoke = $this->methodResolution($class, '__invoke');

        if ($invoke['status'] !== 'found') {
            return $invoke;
        }

        $declaration = $invoke['declaration'];

        return [
            'status' => ($declaration['record']['visibility'] ?? null) === 'public' ? 'found' : 'non_public',
            'method' => '__invoke',
            'declaration' => $declaration,
        ];
    }

    /** @return array<string, mixed> */
    private function listenerHandlerSelection(string $class, string $method, bool $classStringListener): array
    {
        $resolution = $this->methodResolution($class, $method);

        $inheritedPrivate = $resolution['status'] === 'found'
            && ($resolution['declaration']['record']['visibility'] ?? null) === 'private'
            && $resolution['declaration']['class'] !== $class
            && ! in_array($resolution['declaration']['class'], $this->classTraits($class), true);

        if ($classStringListener
            && ($resolution['status'] === 'absent' || $inheritedPrivate)) {
            $resolution = $this->methodResolution($class, '__invoke');
            $method = '__invoke';
        }

        if ($resolution['status'] !== 'found') {
            return $resolution;
        }

        $declaration = $resolution['declaration'];

        return [
            'status' => ($declaration['record']['visibility'] ?? null) === 'public' ? 'found' : 'non_public',
            'method' => $method,
            'declaration' => $declaration,
        ];
    }

    /** @return array<string, mixed> */
    private function containerTarget(string $abstract): array
    {
        if ($this->containerBindings === null) {
            return ['status' => 'resolved', 'class' => $abstract, 'confidence' => 1.0, 'metadata' => []];
        }

        try {
            $binding = $this->containerBindings->resolve($abstract);

            if ($binding === null) {
                return $this->containerBindings->hasDefaultDeclaration($abstract)
                    ? ['status' => 'unknown']
                    : ['status' => 'resolved', 'class' => $abstract, 'confidence' => 1.0, 'metadata' => []];
            }

            $concrete = $binding['concrete'] ?? null;

            if (! is_string($concrete) || $concrete === '') {
                return ['status' => 'unknown'];
            }

            $bindingConfidence = is_numeric($binding['confidence'] ?? null)
                ? (float) $binding['confidence']
                : 0.8;

            return [
                'status' => 'resolved',
                'class' => ltrim($concrete, '\\'),
                'confidence' => $bindingConfidence,
                'metadata' => array_filter([
                    'bindingAbstract' => $binding['abstract'] ?? $abstract,
                    'bindingConcrete' => $concrete,
                    'bindingScope' => $binding['scope'] ?? null,
                    'bindingInference' => $binding['inference'] ?? null,
                    'bindingConfidence' => $bindingConfidence,
                    'bindingEnvironment' => $binding['environment'] ?? null,
                    'bindingResolutionPath' => $binding['resolutionPath'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
            ];
        } catch (Throwable) {
            return ['status' => 'unknown'];
        }
    }

    /** @return array<string, mixed> */
    private function configuredJobMetadata(string $job): array
    {
        $configuration = $this->queueExecutionMetadata($job, true);

        return array_filter([
            'configuredQueued' => $configuration['queued'] ?? null,
            'defaultDispatchMode' => $configuration['executionMode'] ?? null,
            'configuredAfterCommit' => $configuration['afterCommit'] ?? null,
            'configuredConnection' => $configuration['connection'] ?? null,
            'configuredQueue' => $configuration['queue'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The legacy listens_to edge identifies the registered callable. When its
     * declaration is unavailable, retain that registration with an explicit
     * reference node instead of exporting an edge with a missing endpoint.
     *
     * @param array{class: string, record: array<string, mixed>}|null $executable
     */
    private function ensureRegisteredMethodNode(Graph $graph, string $class, string $method, ?array $executable): void
    {
        $id = $class.'::'.$method;

        if ($graph->hasNode($id)) {
            return;
        }

        if (isset($this->methods[$class][$method])) {
            $this->ensureMethodNode($graph, $class, $method);

            return;
        }

        if ($executable !== null) {
            $this->ensureMethodNode($graph, $executable['class'], $method);
        }

        $classRecord = $this->classes[$class] ?? [];

        $graph->addNode(GraphNode::make($id, 'method', class_basename($class).'::'.$method, array_filter([
            'namespace' => $this->namespaceFromClass($class),
            'class' => class_basename($class),
            'method' => $method,
            'file' => $classRecord['file'] ?? null,
            'line' => $classRecord['line'] ?? null,
            'metadata' => array_filter([
                'source' => $this->scannerSourceLabel(),
                'referenceOnly' => true,
                'unresolved' => $executable === null,
                'declaredBy' => $executable['class'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== false),
        ], static fn (mixed $value): bool => $value !== null)));
    }

    /**
     * @return array<string, mixed>
     */
    private function queueExecutionMetadata(string $class, bool $includeQueuedFalse, bool $listener = false): array
    {
        $implements = $this->implementedInterfaces($class);
        $queued = in_array(self::SHOULD_QUEUE, $implements, true)
            || in_array(self::SHOULD_QUEUE_AFTER_COMMIT, $implements, true);
        $configuration = $this->queueConfiguration($class);
        $afterCommit = null;

        if ($listener) {
            if ($queued) {
                // ShouldHandleEventsAfterCommit governs synchronous event
                // listeners only. A queued listener follows queue semantics:
                // ShouldQueueAfterCommit or its explicit property.
                $afterCommit = in_array(self::SHOULD_QUEUE_AFTER_COMMIT, $implements, true)
                    ? true
                    : ($configuration['afterCommit'] ?? null);
            } else {
                $afterCommit = in_array(self::SHOULD_HANDLE_EVENTS_AFTER_COMMIT, $implements, true)
                    ? true
                    : ($configuration['afterCommit'] ?? null);
            }
        } elseif ($queued) {
            // Queue::shouldDispatchAfterCommit lets a queued job explicitly
            // override ShouldQueueAfterCommit with $afterCommit = false.
            $afterCommit = array_key_exists('afterCommit', $configuration)
                ? $configuration['afterCommit']
                : (in_array(self::SHOULD_QUEUE_AFTER_COMMIT, $implements, true) ? true : null);
        }

        $metadata = array_filter([
            'queued' => $queued || $includeQueuedFalse ? $queued : null,
            'executionMode' => $queued ? 'queued' : ($afterCommit === true ? 'after_commit' : 'sync'),
            'afterCommit' => $afterCommit,
            'connection' => $configuration['connection'] ?? null,
            'queue' => $configuration['queue'] ?? null,
            'connectionSource' => $configuration['connectionSource'] ?? null,
            'queueSource' => $configuration['queueSource'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        if ($listener && $queued && $this->publicMethodState($class, 'viaConnection') === 'public') {
            unset($metadata['connection'], $metadata['connectionSource']);
            $metadata['connectionDynamic'] = true;
            $metadata['connectionSource'] = 'viaConnection';
        }

        if ($listener && $queued && $this->publicMethodState($class, 'viaQueue') === 'public') {
            unset($metadata['queue'], $metadata['queueSource']);
            $metadata['queueDynamic'] = true;
            $metadata['queueSource'] = 'viaQueue';
        }

        return $metadata;
    }

    /**
     * Model Dispatcher::createClassCallable's split decision: ShouldQueue is
     * checked on the registered class, while synchronous after-commit behavior
     * is checked on the container-resolved instance.
     *
     * @return array<string, mixed>
     */
    private function listenerExecutionMetadata(string $registeredClass, string $effectiveClass, bool $includeQueuedFalse): array
    {
        $registered = $this->queueExecutionMetadata($registeredClass, true, true);

        if (($registered['queued'] ?? false) === true) {
            return $registered;
        }

        $interfaces = $this->implementedInterfaces($effectiveClass);
        $configuration = $this->queueConfiguration($effectiveClass);
        $afterCommit = in_array(self::SHOULD_HANDLE_EVENTS_AFTER_COMMIT, $interfaces, true)
            ? true
            : ($configuration['afterCommit'] ?? null);

        return array_filter([
            'queued' => $includeQueuedFalse ? false : null,
            'executionMode' => $afterCommit === true ? 'after_commit' : 'sync',
            'afterCommit' => $afterCommit,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return 'always'|'never'|'conditional'|'invalid'|'unproven'
     */
    private function listenerShouldQueueDecision(string $class): string
    {
        $resolution = $this->methodResolution($class, 'shouldQueue');

        if (($resolution['status'] ?? null) === 'absent') {
            return 'always';
        }

        if (($resolution['status'] ?? null) !== 'found') {
            return 'unproven';
        }

        $declaration = $resolution['declaration'];
        $visibility = $declaration['record']['visibility'] ?? null;

        // Laravel checks method_exists() on the resolved object here. On PHP
        // 8.4 that sees an inherited-private method, but invoking it from the
        // dispatcher still fails, so it is not equivalent to an absent hook.
        if ($visibility !== 'public') {
            return 'invalid';
        }

        $node = $declaration['record']['node'] ?? null;

        if (! $node instanceof Stmt\ClassMethod
            || count($node->stmts ?? []) !== 1
            || ! $node->stmts[0] instanceof Stmt\Return_) {
            return 'conditional';
        }

        $value = $node->stmts[0]->expr;

        if ($value instanceof Expr\ConstFetch) {
            return match (strtolower($value->name->toString())) {
                'true' => 'always',
                'false' => 'never',
                default => 'conditional',
            };
        }

        return 'conditional';
    }

    /** @return 'public'|'non_public'|'absent'|'unproven' */
    private function publicMethodState(string $class, string $method): string
    {
        $resolution = $this->methodResolution($class, $method);

        if (($resolution['status'] ?? null) !== 'found') {
            return in_array($resolution['status'] ?? null, ['absent', 'unproven'], true)
                ? $resolution['status']
                : 'unproven';
        }

        $declaration = $resolution['declaration'];
        $visibility = $declaration['record']['visibility'] ?? null;
        $inheritedPrivate = $visibility === 'private'
            && $declaration['class'] !== $class
            && ! in_array($declaration['class'], $this->classTraits($class), true);

        if ($inheritedPrivate) {
            return 'absent';
        }

        return $visibility === 'public' ? 'public' : 'non_public';
    }

    /** @return array<string, mixed> */
    private function pendingDispatchMetadata(string $class, string $operation): array
    {
        if (! in_array($operation, ['dispatch', 'dispatchAfterResponse'], true)
            || ! in_array(self::PREPARES_FOR_DISPATCH, $this->implementedInterfaces($class), true)) {
            return [];
        }

        $state = $this->publicMethodState($class, 'prepareForDispatch');
        $decision = 'conditional';

        if ($state !== 'public') {
            $decision = 'invalid';
        } else {
            $declaration = $this->methodDeclaration($class, 'prepareForDispatch');
            $node = is_array($declaration) ? ($declaration['record']['node'] ?? null) : null;

            if ($node instanceof Stmt\ClassMethod
                && count($node->stmts ?? []) === 1
                && $node->stmts[0] instanceof Stmt\Return_
                && $node->stmts[0]->expr instanceof Expr\ConstFetch) {
                $decision = match (strtolower($node->stmts[0]->expr->name->toString())) {
                    'true' => 'always',
                    'false' => 'never',
                    default => 'conditional',
                };
            }
        }

        return array_filter([
            'prepareForDispatchDecision' => $decision,
            'conditionalExecution' => $decision === 'conditional' ? 'prepareForDispatch' : null,
            'causalExecutionProven' => in_array($decision, ['never', 'invalid'], true) ? false : null,
            'executionSemantics' => match ($decision) {
                'never' => 'suppressed_by_prepare_for_dispatch',
                'invalid' => 'invalid_prepare_for_dispatch_hook',
                'conditional' => 'conditional_prepare_for_dispatch',
                default => 'pending_dispatch',
            },
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function dispatchMode(string $target, string $kind, string $operation): string
    {
        if ($operation === 'dispatchAfterResponse') {
            return 'after_response';
        }

        if ($operation === 'broadcast') {
            return 'broadcast';
        }

        if ($operation === 'dispatchSync' || $kind === 'event') {
            return 'sync';
        }

        if (! isset($this->classes[$target])) {
            return 'unknown';
        }

        return ($this->queueExecutionMetadata($target, true)['queued'] ?? false)
            ? 'queued'
            : 'sync';
    }

    private function normalizeDispatchModes(Edge $edge): void
    {
        $occurrences = $edge->metadata['dispatchOccurrences'] ?? [];

        if (! is_array($occurrences)) {
            return;
        }

        ksort($occurrences);
        $edge->metadata['dispatchOccurrences'] = $occurrences;
        $modes = [];
        $methods = [];

        foreach ($occurrences as $occurrence) {
            if (! is_array($occurrence)) {
                continue;
            }

            if (is_string($occurrence['mode'] ?? null)) {
                $modes[$occurrence['mode']] = true;
            }

            if (is_string($occurrence['method'] ?? null)) {
                $methods[$occurrence['method']] = true;
            }
        }

        $modeNames = array_keys($modes);
        $methodNames = array_keys($methods);
        sort($modeNames);
        sort($methodNames);

        $edge->metadata['dispatchModes'] = $modeNames;
        $edge->metadata['dispatchMethods'] = $methodNames;
        $edge->metadata['dispatchMode'] = count($modeNames) === 1 ? $modeNames[0] : 'mixed';
        $edge->metadata['dispatchMethod'] = count($methodNames) === 1 ? $methodNames[0] : 'mixed';

        $this->normalizeDispatchField($edge, $occurrences, 'kind', 'kind', 'dispatchKinds');
        $this->normalizeDispatchField($edge, $occurrences, 'afterCommit', 'afterCommit', 'afterCommitValues');
        $this->normalizeDispatchField($edge, $occurrences, 'connection', 'connection', 'connections');
        $this->normalizeDispatchField($edge, $occurrences, 'queue', 'queue', 'queues');
        $this->normalizeDispatchField($edge, $occurrences, 'chainConnection', 'chainConnection', 'chainConnections');
        $this->normalizeDispatchField($edge, $occurrences, 'chainQueue', 'chainQueue', 'chainQueues');
        $this->normalizeDispatchField($edge, $occurrences, 'line', 'line', 'lines');
        $this->normalizeDispatchField($edge, $occurrences, 'offset', 'offset', 'offsets');
        $this->normalizeDispatchField($edge, $occurrences, 'via', 'via', 'vias');
        $this->normalizeDispatchField($edge, $occurrences, 'chained', 'chained', 'chainedValues');
        $this->normalizeDispatchField($edge, $occurrences, 'chainPosition', 'chainPosition', 'chainPositions');
        $this->normalizeDispatchField($edge, $occurrences, 'executionSemantics', 'executionSemantics', 'executionSemanticsValues');
        $this->normalizeDispatchField($edge, $occurrences, 'causalExecutionProven', 'causalExecutionProven', 'causalExecutionProvenValues');
        $this->normalizeDispatchField($edge, $occurrences, 'chainMemberSyntax', 'chainMemberSyntax', 'chainMemberSyntaxValues');
        $this->normalizeDispatchField(
            $edge,
            $occurrences,
            'dispatchConfigurationSource',
            'dispatchConfigurationSource',
            'dispatchConfigurationSources'
        );
        $this->normalizeDispatchField(
            $edge,
            $occurrences,
            'transactionTimingSource',
            'transactionTimingSource',
            'transactionTimingSources'
        );

        if (($edge->metadata['chained'] ?? null) === false) {
            unset($edge->metadata['chained']);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $occurrences
     */
    private function normalizeDispatchField(Edge $edge, array $occurrences, string $field, string $singular, string $plural): void
    {
        $values = [];
        $present = 0;

        foreach ($occurrences as $occurrence) {
            if (! is_array($occurrence) || ! array_key_exists($field, $occurrence)) {
                continue;
            }

            $present++;
            $value = $occurrence[$field];
            $key = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $values[$key] = $value;
        }

        $distinct = array_values($values);
        usort($distinct, static fn (mixed $left, mixed $right): int => $left <=> $right);

        if ($present === count($occurrences) && count($distinct) === 1) {
            $edge->metadata[$singular] = $distinct[0];
            unset($edge->metadata[$plural]);
        } else {
            unset($edge->metadata[$singular]);
            if ($distinct === []) {
                unset($edge->metadata[$plural]);
            } else {
                $edge->metadata[$plural] = $distinct;
            }
        }
    }

    /**
     * @param array{class: string, method: string} $context
     */
    private function addClosureListenerWarning(Graph $graph, array $context, string $event, int $line): void
    {
        $graph->addWarning(array_filter([
            'scanner' => $this->scannerName(),
            'reason' => 'closure_listener_execution_unresolved',
            'file' => $this->classes[$context['class']]['file'] ?? null,
            'line' => $line,
            'event' => $event,
            'registration' => $context['class'].'::'.$context['method'],
            'message' => 'Closure listener registration has no stable executable method node.',
        ], static fn (mixed $value): bool => $value !== null));
    }

    private function addObserves(Graph $graph, string $observer, string $model, float $confidence, string $source): void
    {
        $this->ensureClassNode($graph, $observer, 'class');
        $this->ensureClassNode($graph, $model, 'model');

        $graph->addEdge(new Edge($observer, $model, 'observes', $confidence, [
            'source' => $source,
        ]));
    }

    private function ensureTargetNode(Graph $graph, string $class, string $type): void
    {
        $record = $this->classes[$class] ?? null;
        $metadata = $this->queueConfiguration($class);

        if ($type === 'job' && $record !== null) {
            $metadata = array_replace($metadata, $this->queueExecutionMetadata($class, true));
        }

        $node = $graph->addNode(GraphNode::make($class, $type, class_basename($class), array_filter([
            'namespace' => $this->namespaceFromClass($class),
            'class' => class_basename($class),
            'file' => $record['file'] ?? null,
            'line' => $record['line'] ?? null,
            'metadata' => $metadata,
        ], static fn ($value): bool => $value !== null)));

        if (in_array($type, ['event', 'job'], true)) {
            $roles = array_values(array_unique([
                ...($node->metadata['roles'] ?? []),
                ...(in_array($node->type, ['event', 'job'], true) ? [$node->type] : []),
                $type,
            ]));
            sort($roles);

            if (count($roles) > 1) {
                $node->metadata['roles'] = $roles;
            }
        }
    }

    private function ensureClassNode(Graph $graph, string $class, string $type): void
    {
        if ($graph->hasNode($class)) {
            return;
        }

        $this->ensureTargetNode($graph, $class, $type);
    }

    private function ensureMethodNode(Graph $graph, string $class, string $method): void
    {
        if ($graph->hasNode($class.'::'.$method) || ! isset($this->methods[$class][$method])) {
            return;
        }

        $graph->addNode($this->methodNode($class, $method, $this->methods[$class][$method]));
    }

    private function isFacade(string $class, string $facadeClass, string $basename): bool
    {
        return ltrim($class, '\\') === ltrim($facadeClass, '\\');
    }

    /** @return array<string, mixed> */
    private function queueMetadata(Stmt\Class_|Stmt\Trait_ $class): array
    {
        $metadata = [];

        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Stmt\Property) {
                continue;
            }

            foreach ($statement->props as $property) {
                $name = $property->name->toString();

                if (! in_array($name, ['afterCommit', 'connection', 'queue'], true)) {
                    continue;
                }

                $value = match (true) {
                    $property->default instanceof Scalar\String_ => $property->default->value,
                    $property->default instanceof Expr\ConstFetch && strtolower($property->default->name->toString()) === 'true' => true,
                    $property->default instanceof Expr\ConstFetch && strtolower($property->default->name->toString()) === 'false' => false,
                    default => null,
                };

                if ($value !== null) {
                    $metadata[$name] = $value;

                    if (in_array($name, ['connection', 'queue'], true)) {
                        $metadata[$name.'Source'] = 'property_default';
                    }
                }
            }
        }

        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $name = $this->resolvedName($attribute->name);
                $field = match ($name) {
                    self::QUEUE_CONNECTION_ATTRIBUTE => 'connection',
                    self::QUEUE_NAME_ATTRIBUTE => 'queue',
                    default => null,
                };

                if ($field === null) {
                    continue;
                }

                $argument = $attribute->args[0]->value ?? null;

                if ($argument instanceof Scalar\String_) {
                    // ReadsClassAttributes gives a class attribute precedence
                    // over a default property declared on the same class.
                    $metadata[$field] = $argument->value;
                    $metadata[$field.'Source'] = 'class_attribute';
                }
            }
        }

        return $metadata;
    }

    /** @return array<int, string> */
    private function usedTraits(Stmt\Class_|Stmt\Trait_ $class): array
    {
        $traits = [];

        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Stmt\TraitUse) {
                continue;
            }

            foreach ($statement->traits as $trait) {
                $resolved = $this->resolvedName($trait);

                if ($resolved !== null) {
                    $traits[] = $resolved;
                }
            }
        }

        return array_values(array_unique($traits));
    }

    /** @return array<string, mixed> */
    private function traitAdaptations(Stmt\Class_|Stmt\Trait_ $class): array
    {
        $adaptations = [
            'precedence' => [],
            'aliases' => [],
        ];

        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Stmt\TraitUse) {
                continue;
            }

            foreach ($statement->adaptations as $adaptation) {
                if ($adaptation instanceof Stmt\TraitUseAdaptation\Precedence) {
                    $trait = $this->resolvedName($adaptation->trait);

                    if ($trait === null) {
                        continue;
                    }

                    $adaptations['precedence'][$adaptation->method->toString()][] = [
                        'trait' => $trait,
                        'insteadOf' => array_values(array_filter(array_map(
                            fn (Name $excluded): ?string => $this->resolvedName($excluded),
                            $adaptation->insteadof,
                        ))),
                    ];

                    continue;
                }

                if (! $adaptation instanceof Stmt\TraitUseAdaptation\Alias) {
                    continue;
                }

                $sourceMethod = $adaptation->method->toString();
                $effectiveMethod = $adaptation->newName?->toString() ?? $sourceMethod;
                $visibility = match ($adaptation->newModifier) {
                    Stmt\Class_::MODIFIER_PUBLIC => 'public',
                    Stmt\Class_::MODIFIER_PROTECTED => 'protected',
                    Stmt\Class_::MODIFIER_PRIVATE => 'private',
                    default => null,
                };

                $adaptations['aliases'][$effectiveMethod][] = [
                    'trait' => $this->resolvedName($adaptation->trait),
                    'method' => $sourceMethod,
                    'visibility' => $visibility,
                ];
            }
        }

        return array_filter([
            'precedence' => array_filter($adaptations['precedence']),
            'aliases' => array_filter($adaptations['aliases']),
        ]);
    }

    /** @return array<int, string> */
    private function classTraits(string $class, array $visited = []): array
    {
        if (isset($visited[$class])) {
            return [];
        }

        $visited[$class] = true;

        if (! isset($this->classes[$class])) {
            $reflection = $this->safeReflection($class);

            if ($reflection === null) {
                return [];
            }

            try {
                $traits = $reflection->getTraitNames();

                foreach ($traits as $trait) {
                    $traits = [...$traits, ...$this->classTraits($trait, $visited)];
                }

                $parent = $reflection->getParentClass();

                if ($parent instanceof ReflectionClass) {
                    $traits = [...$traits, ...$this->classTraits($parent->getName(), $visited)];
                }

                return array_values(array_unique($traits));
            } catch (Throwable) {
                return [];
            }
        }

        $traits = $this->classes[$class]['traits'] ?? [];

        foreach ($traits as $trait) {
            $traits = [...$traits, ...$this->classTraits($trait, $visited)];
        }

        $parent = $this->classes[$class]['extends'] ?? null;

        if (is_string($parent)) {
            $traits = [...$traits, ...$this->classTraits($parent, $visited)];
        }

        return array_values(array_unique($traits));
    }

    /** @return array<int, string> */
    private function implementedInterfaces(string $class, array $visited = []): array
    {
        if (isset($visited[$class])) {
            return [];
        }

        $visited[$class] = true;
        $interfaces = [];

        if (! isset($this->classes[$class])) {
            $reflection = $this->safeReflection($class);

            if ($reflection === null) {
                return [];
            }

            try {
                foreach ($reflection->getInterfaceNames() as $interface) {
                    $interfaces = [...$interfaces, ...$this->interfaceHierarchy($interface)];
                }

                return array_values(array_unique($interfaces));
            } catch (Throwable) {
                return [];
            }
        }

        foreach ($this->classes[$class]['implements'] ?? [] as $interface) {
            $interfaces = [...$interfaces, ...$this->interfaceHierarchy($interface)];
        }

        $parent = $this->classes[$class]['extends'] ?? null;

        if (is_string($parent)) {
            $interfaces = [...$interfaces, ...$this->implementedInterfaces($parent, $visited)];
        }

        return array_values(array_unique($interfaces));
    }

    /** @return array<int, string> */
    private function interfaceHierarchy(string $interface, array $visited = []): array
    {
        if (isset($visited[$interface])) {
            return [];
        }

        $visited[$interface] = true;
        $parents = $this->interfaces[$interface] ?? match ($interface) {
            self::SHOULD_QUEUE_AFTER_COMMIT => [self::SHOULD_QUEUE],
            default => [],
        };

        if (! isset($this->interfaces[$interface])) {
            $reflection = $this->safeReflection($interface);

            if ($reflection !== null) {
                try {
                    $parents = array_values(array_filter(
                        $reflection->getInterfaceNames(),
                        static fn (string $parent): bool => $parent !== $interface,
                    ));
                } catch (Throwable) {
                    // Retain the small Laravel contract fallback above.
                }
            }
        }
        $interfaces = [$interface];

        foreach ($parents as $parent) {
            $interfaces = [...$interfaces, ...$this->interfaceHierarchy($parent, $visited)];
        }

        return array_values(array_unique($interfaces));
    }

    /** @return array<string, mixed> */
    private function queueConfiguration(string $class, array $visited = []): array
    {
        if (isset($visited[$class])) {
            return [];
        }

        $visited[$class] = true;

        if (! isset($this->classes[$class])) {
            $reflection = $this->safeReflection($class);

            if ($reflection === null) {
                return [];
            }

            try {
                $configuration = $this->queueConfigurationFromDefaults($reflection->getDefaultProperties());

                foreach ([
                    self::QUEUE_CONNECTION_ATTRIBUTE => 'connection',
                    self::QUEUE_NAME_ATTRIBUTE => 'queue',
                ] as $attributeClass => $field) {
                    $current = $reflection;

                    do {
                        $attributes = $current->getAttributes($attributeClass);

                        if ($attributes !== []) {
                            $arguments = $attributes[0]->getArguments();
                            $value = $arguments[0] ?? $arguments[$field] ?? null;

                            if (is_string($value)) {
                                $configuration[$field] = $value;
                                $configuration[$field.'Source'] = 'class_attribute';
                            }

                            break;
                        }
                    } while ($current = $current->getParentClass());
                }

                return $configuration;
            } catch (Throwable) {
                return [];
            }
        }

        $parent = $this->classes[$class]['extends'] ?? null;
        $configuration = is_string($parent)
            ? $this->queueConfiguration($parent, $visited)
            : [];

        // A trait's property is inserted into the current class, so it shadows
        // an inherited property. A directly declared compatible class property
        // is applied last. Invalid trait-property conflicts are deliberately not
        // guessed into a runtime configuration.
        foreach ($this->classes[$class]['traits'] ?? [] as $trait) {
            $configuration = array_replace($configuration, $this->queueConfiguration($trait, $visited));
        }

        return array_replace($configuration, $this->classes[$class]['queue'] ?? []);
    }

    /** @param array<string, mixed> $defaults */
    private function queueConfigurationFromDefaults(array $defaults): array
    {
        $configuration = [];

        foreach (['afterCommit', 'connection', 'queue'] as $property) {
            if (array_key_exists($property, $defaults)
                && (is_string($defaults[$property]) || is_bool($defaults[$property]))) {
                $configuration[$property] = $defaults[$property];

                if (in_array($property, ['connection', 'queue'], true)) {
                    $configuration[$property.'Source'] = 'property_default';
                }
            }
        }

        return $configuration;
    }

    private function provenStaticDispatchKind(string $class, string $operation): ?string
    {
        if (! $this->isInstantiableClass($class)) {
            return null;
        }

        $expectedTrait = $operation === 'dispatch' && $this->kindOf($class) === 'event'
            ? self::EVENT_DISPATCHABLE
            : self::BUS_DISPATCHABLE;

        if ($expectedTrait === self::EVENT_DISPATCHABLE && $operation !== 'dispatch') {
            return null;
        }

        $resolution = $this->methodResolution($class, $operation);

        if ($resolution['status'] !== 'found') {
            return null;
        }

        $record = $resolution['declaration']['record'];
        $origin = $record['originClass'] ?? $resolution['declaration']['class'];

        if (($record['visibility'] ?? null) !== 'public'
            || ! ($record['static'] ?? false)
            || $origin !== $expectedTrait
            || ! in_array($expectedTrait, $this->classTraits($class), true)) {
            return null;
        }

        // Dispatchable::dispatchAfterResponse delegates through self::dispatch.
        // An override or visibility adaptation changes that runtime call, so
        // both trait methods must remain the public Laravel implementations.
        if ($operation === 'dispatchAfterResponse') {
            $dispatch = $this->methodResolution($class, 'dispatch');
            $dispatchRecord = $dispatch['declaration']['record'] ?? null;
            $dispatchOrigin = is_array($dispatchRecord)
                ? ($dispatchRecord['originClass'] ?? ($dispatch['declaration']['class'] ?? null))
                : null;

            if (($dispatch['status'] ?? null) !== 'found'
                || ! is_array($dispatchRecord)
                || ($dispatchRecord['visibility'] ?? null) !== 'public'
                || ! ($dispatchRecord['static'] ?? false)
                || $dispatchOrigin !== self::BUS_DISPATCHABLE) {
                return null;
            }
        }

        return $expectedTrait === self::EVENT_DISPATCHABLE ? 'event' : 'job';
    }

    private function isEventServiceProvider(string $class, array $visited = []): bool
    {
        if (isset($visited[$class]) || ! isset($this->classes[$class])) {
            return false;
        }

        $visited[$class] = true;
        $parent = $this->classes[$class]['extends'] ?? null;

        return $parent === self::EVENT_SERVICE_PROVIDER
            || (is_string($parent) && $this->isEventServiceProvider($parent, $visited));
    }

    private function isServiceProvider(string $class, array $visited = []): bool
    {
        if (isset($visited[$class])) {
            return false;
        }

        $visited[$class] = true;

        if (! isset($this->classes[$class])) {
            $reflection = $this->safeReflection($class);

            if ($reflection === null) {
                return false;
            }

            try {
                return $reflection->isSubclassOf(self::SERVICE_PROVIDER)
                    || $reflection->getName() === self::SERVICE_PROVIDER;
            } catch (Throwable) {
                return false;
            }
        }

        $parent = $this->classes[$class]['extends'] ?? null;

        return $parent === self::SERVICE_PROVIDER
            || $parent === self::EVENT_SERVICE_PROVIDER
            || (is_string($parent) && $this->isServiceProvider($parent, $visited));
    }
}
