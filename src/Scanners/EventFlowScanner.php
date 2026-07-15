<?php

namespace AppGraph\Scanners;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node as GraphNode;
use AppGraph\Scanners\Concerns\InteractsWithPhpAst;
use AppGraph\Support\FileFinder;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;

class EventFlowScanner
{
    use InteractsWithPhpAst;

    private const SHOULD_QUEUE = 'Illuminate\Contracts\Queue\ShouldQueue';
    private const SHOULD_QUEUE_AFTER_COMMIT = 'Illuminate\Contracts\Queue\ShouldQueueAfterCommit';
    private const OBSERVED_BY_ATTRIBUTE = 'Illuminate\Database\Eloquent\Attributes\ObservedBy';
    private const EVENT_FACADE = 'Illuminate\Support\Facades\Event';
    private const BUS_FACADE = 'Illuminate\Support\Facades\Bus';

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

    public function __construct(private FileFinder $files)
    {
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
                'implements' => [],
                'listen' => [],
                'observedBy' => [],
                'handleParamType' => null,
                'queue' => $statement instanceof Stmt\Class_ ? $this->queueMetadata($statement) : [],
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

        if (str_contains('\\'.$class, '\\Events\\') || str_starts_with((string) $record['file'], 'app/Events/')) {
            return 'event';
        }

        if (
            in_array(self::SHOULD_QUEUE, $record['implements'], true)
            || in_array(self::SHOULD_QUEUE_AFTER_COMMIT, $record['implements'], true)
            || str_contains('\\'.$class, '\\Jobs\\')
            || str_starts_with((string) $record['file'], 'app/Jobs/')
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

            $event = $record['handleParamType'];

            if (! is_string($event) || $this->kindOf($event) !== 'event') {
                continue;
            }

            $this->addListensTo($graph, $class, 'handle', $event, 0.85, [
                'source' => 'auto_discovery',
            ]);
        }
    }

    private function addListenMapListeners(Graph $graph): void
    {
        foreach ($this->classes as $record) {
            foreach ($record['listen'] as $event => $listeners) {
                foreach ($listeners as $listener) {
                    $this->addListensTo($graph, $listener, 'handle', $event, 1.0, [
                        'source' => 'event_service_provider',
                    ]);
                }
            }
        }
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
                    $this->addDispatches($graph, $context, $event, 'event', 'facade', 0.9, $call->getStartLine());
                }
            }

            if ($operation === 'listen') {
                $this->inspectEventListen($graph, $call, $context);
            }

            return;
        }

        if ($this->isFacade($class, self::BUS_FACADE, 'Bus')) {
            if (in_array($operation, $this->staticDispatchMethods, true)) {
                $job = $this->eventClassFromArg($call->args[0]->value ?? null);

                if ($job !== null) {
                    $this->addDispatches($graph, $context, $job, 'job', 'facade', 0.9, $call->getStartLine());
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

        // SomeEvent::dispatch() / SomeJob::dispatch() — the kind comes from the
        // indexed classification, so unindexed (vendor) targets are skipped here.
        if (in_array($operation, $this->staticDispatchMethods, true)) {
            $kind = $this->kindOf($class);

            if ($kind !== null) {
                $this->addDispatches($graph, $context, $class, $kind, 'static', 0.9, $call->getStartLine());
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
                $this->addDispatches($graph, $context, $event, 'event', 'helper', 0.95, $call->getStartLine());
            }

            return;
        }

        if ($function === 'dispatch') {
            $arg = $call->args[0]->value ?? null;

            if ($arg instanceof Expr\New_) {
                $job = $this->resolvedName($arg->class instanceof Name ? $arg->class : null);

                if ($job !== null) {
                    $this->addDispatches($graph, $context, $job, 'job', 'helper', 0.95, $call->getStartLine());
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

        while ($expression instanceof Expr\MethodCall) {
            if ($expression->name instanceof Identifier) {
                $operation = $expression->name->toString();

                if ($operation === 'afterCommit') {
                    $metadata['afterCommit'] = true;
                    $metadata['transactionTimingSource'] = 'pending_dispatch_chain';
                } elseif ($operation === 'beforeCommit') {
                    $metadata['afterCommit'] = false;
                    $metadata['transactionTimingSource'] = 'pending_dispatch_chain';
                } elseif (in_array($operation, ['onQueue', 'onConnection'], true)) {
                    $value = $expression->args[0]->value ?? null;

                    if ($value instanceof Scalar\String_) {
                        $metadata[$operation === 'onQueue' ? 'queue' : 'connection'] = $value->value;
                    }
                }
            }

            $expression = $expression->var;
        }

        if (! $expression instanceof Expr\StaticCall
            || ! $expression->name instanceof Identifier
            || ! in_array($expression->name->toString(), $this->staticDispatchMethods, true)) {
            return false;
        }

        $target = $this->resolveStaticClass($expression->class, $context['class']);
        $kind = $target === null ? null : $this->kindOf($target);

        if ($target !== null && $kind !== null) {
            $this->addDispatches($graph, $context, $target, $kind, 'static', 0.95, $call->getStartLine(), [
                ...$metadata,
                'dispatchConfigurationSource' => 'pending_dispatch_chain',
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

        while ($expression instanceof Expr\MethodCall) {
            if ($expression->name instanceof Identifier) {
                $operation = $expression->name->toString();

                if ($operation === 'dispatch') {
                    $dispatchSeen = true;
                } elseif (in_array($operation, ['onQueue', 'onConnection'], true)) {
                    $value = $expression->args[0]->value ?? null;

                    if ($value instanceof Scalar\String_) {
                        $metadata[$operation === 'onQueue' ? 'queue' : 'connection'] = $value->value;
                    }
                }
            }

            $expression = $expression->var;
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

        foreach ($jobs as $position => $job) {
            if ($this->kindOf($job) !== 'job') {
                continue;
            }

            $this->addDispatches($graph, $context, $job, 'job', 'bus_chain', 0.9, $call->getStartLine(), [
                ...$metadata,
                'chained' => true,
                'chainPosition' => $position,
                'dispatchConfigurationSource' => 'bus_chain',
            ]);
        }

        return $jobs !== [];
    }

    /** @return array<int, string> */
    private function jobClassesFromChainExpression(Expr $expression): array
    {
        if ($expression instanceof Expr\New_ && $expression->class instanceof Name) {
            $class = $this->resolvedName($expression->class);

            return $class === null ? [] : [$class];
        }

        if ($expression instanceof Expr\ClassConstFetch) {
            return $this->classNamesFromExpr($expression);
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

            return;
        }

        if ($listenerArg instanceof Expr\Array_ && count($listenerArg->items) === 2) {
            $listener = $this->classNamesFromExpr($listenerArg->items[0]->value)[0] ?? null;
            $method = $listenerArg->items[1]->value instanceof Scalar\String_ ? $listenerArg->items[1]->value->value : null;

            if ($listener !== null && $method !== null) {
                $this->addListensTo($graph, $listener, $method, $event, 0.9, [
                    'source' => 'explicit_listen',
                ]);
            }

            return;
        }

        if ($listenerArg !== null) {
            $listener = $this->classNamesFromExpr($listenerArg)[0] ?? null;

            if ($listener !== null) {
                $this->addListensTo($graph, $listener, 'handle', $event, 0.9, [
                    'source' => 'explicit_listen',
                ]);
            }
        }
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
    private function addDispatches(Graph $graph, array $context, string $target, string $kind, string $via, float $confidence, int $line, array $metadata = []): void
    {
        $callerId = $context['class'].'::'.$context['method'];

        $this->ensureMethodNode($graph, $context['class'], $context['method']);
        $this->ensureTargetNode($graph, $target, $kind);

        $queue = $this->classes[$target]['queue'] ?? [];

        $graph->addEdge(new Edge($callerId, $target, 'dispatches', $confidence, array_filter([
            'kind' => $kind,
            'via' => $via,
            'line' => $line,
            'afterCommit' => $metadata['afterCommit'] ?? $queue['afterCommit'] ?? null,
            'transactionTimingSource' => $metadata['transactionTimingSource'] ?? (isset($queue['afterCommit']) ? 'job_configuration' : null),
        ], static fn ($value): bool => $value !== null) + $metadata));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function addListensTo(Graph $graph, string $listener, string $method, string $event, float $confidence, array $metadata): void
    {
        $this->ensureMethodNode($graph, $listener, $method);
        $this->ensureTargetNode($graph, $event, 'event');

        $implements = $this->classes[$listener]['implements'] ?? [];
        $queued = in_array(self::SHOULD_QUEUE, $implements, true) || in_array(self::SHOULD_QUEUE_AFTER_COMMIT, $implements, true);
        $afterCommit = in_array(self::SHOULD_QUEUE_AFTER_COMMIT, $implements, true)
            ? true
            : ($this->classes[$listener]['queue']['afterCommit'] ?? null);

        $graph->addEdge(new Edge($listener.'::'.$method, $event, 'listens_to', $confidence, array_filter([
            'queued' => $queued ?: null,
            'afterCommit' => $afterCommit,
        ], static fn ($value): bool => $value !== null) + $metadata));
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

        $graph->addNode(GraphNode::make($class, $type, class_basename($class), array_filter([
            'namespace' => $this->namespaceFromClass($class),
            'class' => class_basename($class),
            'file' => $record['file'] ?? null,
            'line' => $record['line'] ?? null,
            'metadata' => $record['queue'] ?? [],
        ], static fn ($value): bool => $value !== null)));
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
        return $class === $facadeClass || $class === $basename || class_basename($class) === $basename;
    }

    /** @return array<string, mixed> */
    private function queueMetadata(Stmt\Class_ $class): array
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
                }
            }
        }

        if (in_array(self::SHOULD_QUEUE_AFTER_COMMIT, array_map(fn (Name $interface): ?string => $this->resolvedName($interface), $class->implements), true)) {
            $metadata['afterCommit'] = true;
        }

        return $metadata;
    }
}
