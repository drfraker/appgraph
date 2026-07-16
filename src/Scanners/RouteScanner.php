<?php

namespace AppGraph\Scanners;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Scanners\Concerns\InteractsWithPhpAst;
use AppGraph\Support\ContainerBindingRegistry;
use AppGraph\Support\FileFinder;
use AppGraph\Support\LaravelIntrospection;
use AppGraph\Support\LaravelMiddlewarePipeline;
use AppGraph\Support\PhpFileFacts;
use AppGraph\Support\PhpReflection;
use Composer\Autoload\ClassLoader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use PhpParser\Node as AstNode;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

class RouteScanner
{
    use InteractsWithPhpAst;

    /** @var array<string, array{extends: string|null}> */
    private array $classes = [];

    /**
     * @var array<string, array{
     *     node: Stmt\Class_,
     *     file: string,
     *     absoluteFile: string,
     *     extends: string|null,
     *     abstract: bool,
     *     traitUses: array<int, array<string, mixed>>
     * }>
     */
    private array $sourceClasses = [];

    /**
     * @var array<string, array{
     *     node: Stmt\Trait_,
     *     file: string,
     *     absoluteFile: string,
     *     traitUses: array<int, array<string, mixed>>
     * }>
     */
    private array $sourceTraits = [];

    /** @var array<string, string> */
    private array $sourceClassNames = [];

    /** @var array<string, string> */
    private array $sourceTraitNames = [];

    /** @var array<string, true> */
    private array $indexedSourceFiles = [];

    /** @var array<string, true> */
    private array $sourceLookupAvailable = [];

    public function __construct(
        private LaravelIntrospection $laravel,
        private LaravelMiddlewarePipeline $middleware,
        private PhpReflection $reflection,
        private ContainerBindingRegistry $containerBindings,
        private FileFinder $files,
        ?PhpFileFacts $phpFileFacts = null,
    ) {
        $this->initializePhpFileFacts($phpFileFacts);
    }

    protected function scannerName(): string
    {
        return 'routes';
    }

    protected function scannerSourceLabel(): string
    {
        return 'laravel_router_source';
    }

    public function scan(Graph $graph): Graph
    {
        $this->classes = [];
        $this->sourceClasses = [];
        $this->sourceTraits = [];
        $this->sourceClassNames = [];
        $this->sourceTraitNames = [];
        $this->indexedSourceFiles = [];
        $this->sourceLookupAvailable = [];

        foreach ($this->laravel->routes() as $route) {
            $uri = $this->laravel->routeUri($route);
            $domain = $this->laravel->routeDomain($route);
            $endpoint = $domain === null ? $uri : '//'.$domain.$uri;
            $action = $this->laravel->controllerAction($route);
            $middleware = $this->middleware->inspect($route);
            $routeParameters = $this->routeParameterNames(($domain === null ? '' : $domain.' ').$uri);

            foreach ($this->laravel->routeMethods($route) as $method) {
                $routeNodeId = 'route:'.strtoupper($method).':'.$endpoint;
                $routeLabel = strtoupper($method).' '.($domain === null ? $uri : $domain.$uri);

                $graph->addNode(Node::make($routeNodeId, 'route', $routeLabel, [
                    'metadata' => [
                        'name' => $route->getName(),
                        'uri' => $uri,
                        'domain' => $domain,
                        'endpoint' => $endpoint,
                        'methods' => $this->laravel->routeMethods($route),
                        'middleware' => $middleware['declared'],
                        'resolved_middleware' => $middleware['resolved'],
                        'action' => $route->getActionName(),
                    ],
                ]));

                $this->addMiddlewareRelationships($graph, $routeNodeId, $middleware);

                if ($action === null) {
                    continue;
                }

                $runtimeAction = $this->resolveControllerAction($graph, $routeNodeId, $action);

                if ($runtimeAction === null) {
                    continue;
                }

                $controllerMethod = $this->addControllerMethod(
                    $graph,
                    $runtimeAction['class'],
                    $runtimeAction['method'],
                    $routeParameters,
                );

                if (! $controllerMethod['executable']) {
                    $graph->addWarning([
                        'scanner' => 'routes',
                        'route' => $routeNodeId,
                        'kind' => 'controller_resolution',
                        'reason' => $controllerMethod['reason'],
                        'controller' => $runtimeAction['class'],
                        'method' => $controllerMethod['method'],
                        'message' => match ($controllerMethod['reason']) {
                            'controller_class_not_concrete' => "Resolved controller [{$runtimeAction['class']}] is not instantiable, so Laravel cannot dispatch its action.",
                            'controller_method_not_public' => "Resolved controller action [{$runtimeAction['class']}::{$controllerMethod['method']}] is not public, so Laravel cannot dispatch it.",
                            'controller_class_not_autoloadable' => "Resolved controller [{$runtimeAction['class']}] has no safely inspectable runtime class or current project source.",
                            default => "Resolved controller action [{$runtimeAction['class']}::{$controllerMethod['method']}] does not exist in current executable source.",
                        },
                    ]);

                    continue;
                }

                $graph->addEdge(new Edge(
                    $routeNodeId,
                    $controllerMethod['id'],
                    'routes_to',
                    round(
                        $controllerMethod['confidence']
                        * $runtimeAction['confidence'],
                        2,
                    ),
                    array_filter([
                        'controller' => $runtimeAction['class'],
                        'runtimeController' => $runtimeAction['class'],
                        'requestedController' => $runtimeAction['requestedClass'] !== $runtimeAction['class']
                            ? $runtimeAction['requestedClass']
                            : null,
                        'method' => $controllerMethod['method'],
                        'requestedMethod' => $runtimeAction['method'] !== $controllerMethod['method']
                            ? $runtimeAction['method']
                            : null,
                        'declaredMethod' => $controllerMethod['declaredMethod'] !== $controllerMethod['method']
                            ? $controllerMethod['declaredMethod']
                            : null,
                        'dynamicAction' => $controllerMethod['dynamicAction'] ?: null,
                        'composedVisibility' => $controllerMethod['composedVisibility'],
                        'source' => 'laravel_router',
                        'rule' => 'container_resolved_controller_action',
                        'container_binding' => $runtimeAction['binding'],
                    ], static fn (mixed $value): bool => $value !== null),
                ));
            }
        }

        return $graph;
    }

    /**
     * @param array{
     *     declared: array<int, mixed>,
     *     resolved: array<int, array<string, mixed>>,
     *     occurrences: array<int, array<string, mixed>>,
     *     diagnostics: array<int, array<string, mixed>>
     * } $pipeline
     */
    private function addMiddlewareRelationships(Graph $graph, string $routeNodeId, array $pipeline): void
    {
        foreach ($pipeline['diagnostics'] as $diagnostic) {
            $this->addMiddlewareWarning($graph, $routeNodeId, $diagnostic);
        }

        foreach ($pipeline['occurrences'] as $occurrence) {
            $class = $occurrence['resolved_class'];

            if (! is_string($class) || $class === '') {
                continue;
            }

            try {
                $edgeConfidence = 1.0;
                $requestedClass = $class;
                $binding = $this->containerBindings->resolve($requestedClass);

                if ($binding !== null) {
                    $class = (string) $binding['concrete'];
                    $occurrence['requested_class'] = $requestedClass;
                    $occurrence['resolved_class'] = $class;
                    $edgeConfidence = (float) $binding['confidence'];
                    $occurrence['confidence'] = $edgeConfidence;
                    $occurrence['container_binding'] = array_filter([
                        'scope' => $binding['scope'],
                        'inference' => $binding['inference'],
                        'terminalInference' => $binding['terminalInference'] ?? $binding['inference'],
                        'confidence' => $binding['confidence'],
                        'environment' => $binding['environment'],
                        'resolution_path' => $binding['resolutionPath'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null);
                } elseif ($this->containerBindings->hasDefaultDeclaration($requestedClass)) {
                    $this->addMiddlewareWarning($graph, $routeNodeId, [
                        'reason' => 'middleware_container_target_unknown',
                        'position' => $occurrence['position'],
                        'scope' => $occurrence['scope'],
                        'declared' => $occurrence['declared'],
                        'resolved' => $occurrence['resolved'],
                        'message' => "The container binding for middleware [{$requestedClass}] has no statically proven target.",
                    ]);

                    continue;
                }

                $sourceHandle = $this->sourceMethod($graph, $class, 'handle');
                $sourceInvoke = $sourceHandle['status'] === 'found'
                    ? ['status' => 'missing']
                    : $this->sourceMethod($graph, $class, '__invoke');
                $reflected = $sourceHandle['status'] === 'unavailable'
                    ? $this->reflectionMiddlewareMethod($class, $sourceHandle, ['status' => 'missing'])
                    : null;
                $sourceExecutable = $sourceHandle['status'] === 'found'
                    ? $sourceHandle
                    : ($reflected === null && $sourceInvoke['status'] === 'found' ? $sourceInvoke : ['status' => 'missing']);

                if ($sourceExecutable['status'] === 'found') {
                    /** @var Stmt\ClassMethod $sourceMethod */
                    $sourceMethod = $sourceExecutable['method'];
                    $declaringClass = $sourceExecutable['class'];
                    $record = $sourceExecutable['record'];
                    $declaredMethod = $sourceExecutable['declaredMethod'];
                    $invocationMethod = $sourceExecutable['invokedMethod'];

                    if ($sourceExecutable['visibility'] !== 'public') {
                        $this->addMiddlewareWarning($graph, $routeNodeId, [
                            'reason' => 'middleware_method_not_public',
                            'position' => $occurrence['position'],
                            'scope' => $occurrence['scope'],
                            'declared' => $occurrence['declared'],
                            'resolved' => $occurrence['resolved'],
                            'method' => $invocationMethod,
                            'message' => "Resolved middleware method [{$class}::{$invocationMethod}] is not public.",
                        ]);

                        continue;
                    }

                    $methodId = $declaringClass.'::'.$declaredMethod;
                    $occurrence['invocation_method'] = $invocationMethod;
                    $occurrence['declaring_class'] = $declaringClass;
                    $occurrence['composed_as'] = $invocationMethod !== $declaredMethod
                        ? $invocationMethod
                        : null;
                    $occurrence['composed_visibility'] = $sourceExecutable['visibility'] !== $sourceExecutable['declaredVisibility']
                        ? $sourceExecutable['visibility']
                        : null;
                    $occurrence = array_filter(
                        $occurrence,
                        static fn (mixed $value): bool => $value !== null,
                    );
                    $occurrenceKey = sprintf('%06d:%s', $occurrence['position'], substr(hash(
                        'sha256',
                        json_encode($occurrence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                    ), 0, 8));

                    $runtimeClass = $this->indexedSourceClassName($class);

                    if ($runtimeClass !== null && $runtimeClass !== $declaringClass) {
                        $graph->addNode($this->sourceDeclarationNode(
                            $runtimeClass,
                            $this->sourceClasses[$runtimeClass],
                            'middleware',
                        ));
                    }

                    $graph->addNode($this->sourceDeclarationNode($declaringClass, $record, 'middleware'));
                    $graph->addNode($this->methodNode($declaringClass, $declaredMethod, [
                        'file' => $record['file'],
                        'line' => $sourceMethod->getStartLine(),
                        'endLine' => $sourceMethod->getEndLine(),
                        'signature' => $this->methodSignature($sourceMethod),
                        'inputs' => $this->methodInputs($sourceMethod),
                        'outputs' => $this->methodOutputs($sourceMethod),
                        'visibility' => $sourceExecutable['declaredVisibility'],
                        'static' => $sourceMethod->isStatic(),
                    ]));
                    $graph->addEdge(new Edge($methodId, $declaringClass, 'defined_in'));
                    $graph->addEdge(new Edge($routeNodeId, $methodId, 'passes_through', $edgeConfidence, [
                        'source' => 'laravel_router',
                        'rule' => 'resolved_route_middleware',
                        'occurrences' => [
                            $occurrenceKey => $occurrence,
                        ],
                    ]));

                    continue;
                }

                if ($sourceHandle['status'] === 'missing'
                    && $sourceInvoke['status'] === 'missing') {
                    $this->addMiddlewareWarning($graph, $routeNodeId, [
                        'reason' => 'middleware_method_not_found',
                        'position' => $occurrence['position'],
                        'scope' => $occurrence['scope'],
                        'declared' => $occurrence['declared'],
                        'resolved' => $occurrence['resolved'],
                        'message' => "Resolved middleware [{$class}] has neither a handle() method nor __invoke() in current source.",
                    ]);

                    continue;
                }

                $reflected ??= $this->reflectionMiddlewareMethod($class, $sourceHandle, $sourceInvoke);

                if ($reflected === null) {
                    $this->addMiddlewareWarning($graph, $routeNodeId, [
                        'reason' => 'middleware_class_unloaded_or_unindexed',
                        'position' => $occurrence['position'],
                        'scope' => $occurrence['scope'],
                        'declared' => $occurrence['declared'],
                        'resolved' => $occurrence['resolved'],
                        'message' => "Resolved middleware [{$class}] had no safe current-source or source-boundary method available for inspection.",
                    ]);

                    continue;
                }

                $handle = $reflected['method'];

                if (! $handle->isPublic()) {
                    $this->addMiddlewareWarning($graph, $routeNodeId, [
                        'reason' => 'middleware_method_not_public',
                        'position' => $occurrence['position'],
                        'scope' => $occurrence['scope'],
                        'declared' => $occurrence['declared'],
                        'resolved' => $occurrence['resolved'],
                        'method' => $handle->getName(),
                        'message' => "Resolved middleware method [{$class}::{$handle->getName()}] is not public.",
                    ]);

                    continue;
                }

                $declaringClass = $handle->getDeclaringClass();
                $methodId = $declaringClass->getName().'::'.$handle->getName();
                $occurrence['invocation_method'] = $handle->getName();
                $occurrence['declaring_class'] = $declaringClass->getName();
                $occurrenceKey = sprintf('%06d:%s', $occurrence['position'], substr(hash(
                    'sha256',
                    json_encode($occurrence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                ), 0, 8));

                $runtimeClass = $this->indexedSourceClassName($class);

                if ($runtimeClass !== null) {
                    $graph->addNode($this->sourceDeclarationNode(
                        $runtimeClass,
                        $this->sourceClasses[$runtimeClass],
                        'middleware',
                    ));
                } elseif (class_exists($class, false)) {
                    $graph->addNode($this->reflection->classNode(new ReflectionClass($class), 'middleware'));
                }

                $graph->addNode($this->reflection->classNode($declaringClass, 'middleware'));
                $graph->addNode($this->reflection->methodNode($handle));
                $graph->addEdge(new Edge($methodId, $declaringClass->getName(), 'defined_in'));
                $graph->addEdge(new Edge($routeNodeId, $methodId, 'passes_through', $edgeConfidence, [
                    'source' => 'laravel_router',
                    'rule' => 'resolved_route_middleware',
                    'occurrences' => [
                        $occurrenceKey => $occurrence,
                    ],
                ]));
            } catch (Throwable $exception) {
                $this->addMiddlewareWarning($graph, $routeNodeId, [
                    'reason' => 'middleware_reflection_failed',
                    'position' => $occurrence['position'],
                    'scope' => $occurrence['scope'],
                    'declared' => $occurrence['declared'],
                    'resolved' => $occurrence['resolved'],
                    'message' => $exception->getMessage(),
                    'class' => $exception::class,
                ]);
            }
        }
    }

    /**
     * Reflection is allowed only at the exact source-unavailable boundary. In a
     * long-lived process, reflecting the original local class could resurrect a
     * method that current project source has removed since the class was loaded.
     *
     * @param array<string, mixed> $handleState
     * @param array<string, mixed> $invokeState
     * @return array{method: ReflectionMethod}|null
     */
    private function reflectionMiddlewareMethod(
        string $runtimeClass,
        array $handleState,
        array $invokeState,
    ): ?array {
        foreach ([['handle', $handleState], ['__invoke', $invokeState]] as [$method, $state]) {
            if (($state['status'] ?? null) !== 'unavailable') {
                continue;
            }

            $boundary = is_string($state['reflectionClass'] ?? null)
                ? $state['reflectionClass']
                : $runtimeClass;

            if (! class_exists($boundary, false) && ! trait_exists($boundary, false)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($boundary);

                if (! $reflection->hasMethod($method)) {
                    continue;
                }

                $candidate = $reflection->getMethod($method);

                if (($state['projectSourceObserved'] ?? false)
                    && ! $this->reflectedMethodIsOutsideProjectSource($candidate)) {
                    continue;
                }

                return ['method' => $candidate];
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $diagnostic
     */
    private function addMiddlewareWarning(Graph $graph, string $routeNodeId, array $diagnostic): void
    {
        $graph->addWarning([
            'scanner' => 'routes',
            'route' => $routeNodeId,
            'kind' => 'middleware_resolution',
        ] + $diagnostic);
    }

    /**
     * @param array{class: string, method: string} $action
     * @return array{class: string, requestedClass: string, method: string, confidence: float, binding: array<string, mixed>|null}|null
     */
    private function resolveControllerAction(Graph $graph, string $routeNodeId, array $action): ?array
    {
        $requestedClass = $action['class'];
        $binding = $this->containerBindings->resolve($requestedClass);

        if ($binding === null) {
            if ($this->containerBindings->hasDefaultDeclaration($requestedClass)) {
                $graph->addWarning([
                    'scanner' => 'routes',
                    'route' => $routeNodeId,
                    'kind' => 'controller_resolution',
                    'reason' => 'controller_container_target_unknown',
                    'controller' => $requestedClass,
                    'method' => $action['method'],
                    'message' => "The container binding for controller [{$requestedClass}] has no statically proven runtime target.",
                ]);

                return null;
            }

            return [
                'class' => $requestedClass,
                'requestedClass' => $requestedClass,
                'method' => $action['method'],
                'confidence' => 1.0,
                'binding' => null,
            ];
        }

        return [
            'class' => (string) $binding['concrete'],
            'requestedClass' => $requestedClass,
            'method' => $action['method'],
            'confidence' => (float) $binding['confidence'],
            'binding' => array_filter([
                'scope' => $binding['scope'],
                'inference' => $binding['inference'],
                'terminalInference' => $binding['terminalInference'] ?? $binding['inference'],
                'confidence' => $binding['confidence'],
                'environment' => $binding['environment'],
                'resolutionPath' => $binding['resolutionPath'] ?? null,
                'effectiveLifetime' => $binding['effectiveLifetime'] ?? $binding['lifetime'],
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * Route objects necessarily hold already-loaded controller class names, but
     * a long-lived MCP process cannot trust PHP reflection after the source file
     * changes. Prefer the current shared AST whenever the class file can be
     * located; reflection remains a fallback only for source-unavailable code.
     *
     * @param array<int, string> $routeParameters
     * @return array{
     *     id: string,
     *     confidence: float,
     *     executable: bool,
     *     reason: string|null,
     *     method: string,
     *     declaredMethod: string,
     *     dynamicAction: bool,
     *     composedVisibility: string|null
     * }
     */
    private function addControllerMethod(Graph $graph, string $class, string $method, array $routeParameters): array
    {
        $source = $this->sourceMethod($graph, $class, $method);

        if ($source['status'] === 'found') {
            /** @var Stmt\ClassMethod $methodNode */
            $methodNode = $source['method'];
            $declaringClass = $source['class'];
            $record = $source['record'];
            $declaredMethod = $source['declaredMethod'];
            $invokedMethod = $source['invokedMethod'];
            $methodId = $declaringClass.'::'.$declaredMethod;
            $runtimeClass = $this->indexedSourceClassName($class);

            if ($runtimeClass !== null && $runtimeClass !== $declaringClass) {
                $graph->addNode($this->sourceDeclarationNode(
                    $runtimeClass,
                    $this->sourceClasses[$runtimeClass],
                ));
            }

            $graph->addNode($this->sourceDeclarationNode($declaringClass, $record));
            $graph->addNode($this->methodNode($declaringClass, $declaredMethod, [
                'file' => $record['file'],
                'line' => $methodNode->getStartLine(),
                'endLine' => $methodNode->getEndLine(),
                'signature' => $this->methodSignature($methodNode),
                'inputs' => $this->methodInputs($methodNode),
                'outputs' => $this->methodOutputs($methodNode),
                'visibility' => $source['declaredVisibility'],
                'static' => $methodNode->isStatic(),
            ]));
            $graph->addEdge(new Edge($methodId, $declaringClass, 'defined_in'));

            foreach ($methodNode->params as $parameter) {
                $this->addSourceParameterRelationships(
                    $graph,
                    $methodId,
                    $parameter,
                    $source['resolutionClass'],
                    $routeParameters,
                );
            }

            $runtimeRecord = $runtimeClass !== null ? $this->sourceClasses[$runtimeClass] : null;

            return [
                'id' => $methodId,
                'confidence' => 1.0,
                'executable' => $source['visibility'] === 'public'
                    && ($runtimeRecord === null || ! $runtimeRecord['abstract']),
                'reason' => $runtimeRecord !== null && $runtimeRecord['abstract']
                    ? 'controller_class_not_concrete'
                    : ($source['visibility'] !== 'public' ? 'controller_method_not_public' : null),
                'method' => $invokedMethod,
                'declaredMethod' => $declaredMethod,
                'dynamicAction' => false,
                'composedVisibility' => $source['visibility'] !== $source['declaredVisibility']
                    ? $source['visibility']
                    : null,
            ];
        }

        if ($source['status'] === 'missing') {
            $dynamic = $this->dynamicControllerActionResult($graph, $class, $method);

            if ($dynamic !== null) {
                return $dynamic;
            }

            $this->addUnknownControllerMethod($graph, $class, $method, 'controller_method_not_found_in_current_source');

            return [
                'id' => $class.'::'.$method,
                'confidence' => 0.0,
                'executable' => false,
                'reason' => 'controller_method_not_found_in_current_source',
                'method' => $method,
                'declaredMethod' => $method,
                'dynamicAction' => false,
                'composedVisibility' => null,
            ];
        }

        try {
            $runtimeSourceClass = $this->indexedSourceClassName($class);
            $boundaryClass = is_string($source['reflectionClass'] ?? null)
                ? $source['reflectionClass']
                : $class;
            $projectSourceObserved = (bool) ($source['projectSourceObserved'] ?? false);
            $runtimeController = null;
            $runtimeInstantiable = true;

            if ($runtimeSourceClass !== null) {
                $runtimeRecord = $this->sourceClasses[$runtimeSourceClass];
                $runtimeInstantiable = ! $runtimeRecord['abstract'];
                $graph->addNode($this->sourceDeclarationNode($runtimeSourceClass, $runtimeRecord));
            } elseif (class_exists($class)) {
                $runtimeController = new ReflectionClass($class);
                $runtimeInstantiable = $runtimeController->isInstantiable();
                $graph->addNode($this->reflection->classNode($runtimeController));
            } else {
                $this->addUnknownControllerMethod($graph, $class, $method, 'controller_class_not_autoloadable');

                return [
                    'id' => $class.'::'.$method,
                    'confidence' => 0.0,
                    'executable' => false,
                    'reason' => 'controller_class_not_autoloadable',
                    'method' => $method,
                    'declaredMethod' => $method,
                    'dynamicAction' => false,
                    'composedVisibility' => null,
                ];
            }

            if (! class_exists($boundaryClass, false) && ! trait_exists($boundaryClass, false)) {
                $dynamic = $this->dynamicControllerActionResult($graph, $class, $method);

                if ($dynamic !== null) {
                    return $dynamic;
                }

                $this->addUnknownControllerMethod($graph, $class, $method, 'controller_source_boundary_unavailable');

                return [
                    'id' => $class.'::'.$method,
                    'confidence' => 0.0,
                    'executable' => false,
                    'reason' => 'controller_method_not_found',
                    'method' => $method,
                    'declaredMethod' => $method,
                    'dynamicAction' => false,
                    'composedVisibility' => null,
                ];
            }

            $lookupClass = new ReflectionClass($boundaryClass);

            if (! $lookupClass->hasMethod($method)) {
                $dynamic = $this->dynamicControllerActionResult($graph, $class, $method);

                if ($dynamic !== null) {
                    return $dynamic;
                }

                $this->addUnknownControllerMethod($graph, $class, $method, 'controller_method_not_found');

                return [
                    'id' => $class.'::'.$method,
                    'confidence' => 0.0,
                    'executable' => false,
                    'reason' => 'controller_method_not_found',
                    'method' => $method,
                    'declaredMethod' => $method,
                    'dynamicAction' => false,
                    'composedVisibility' => null,
                ];
            }

            $controllerMethod = $lookupClass->getMethod($method);

            if ($projectSourceObserved && ! $this->reflectedMethodIsOutsideProjectSource($controllerMethod)) {
                $this->addUnknownControllerMethod($graph, $class, $method, 'stale_project_reflection_rejected');

                return [
                    'id' => $class.'::'.$method,
                    'confidence' => 0.0,
                    'executable' => false,
                    'reason' => 'controller_method_not_found_in_current_source',
                    'method' => $method,
                    'declaredMethod' => $method,
                    'dynamicAction' => false,
                    'composedVisibility' => null,
                ];
            }

            $declaringClass = $controllerMethod->getDeclaringClass();
            $declaredMethod = $controllerMethod->getName();
            $methodId = $declaringClass->getName().'::'.$declaredMethod;

            $graph->addNode($this->reflection->methodNode($controllerMethod));
            $graph->addNode($this->reflection->classNode($declaringClass));
            $graph->addEdge(new Edge($methodId, $declaringClass->getName(), 'defined_in'));

            foreach ($controllerMethod->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type !== null && ! $type instanceof ReflectionNamedType) {
                    $this->addControllerDependencyWarning(
                        $graph,
                        $methodId,
                        $parameter->getName(),
                        'composite_typed_parameter_not_container_resolved',
                        'Laravel does not container-resolve union or intersection controller parameters as a class dependency.',
                    );

                    continue;
                }

                if ($type instanceof ReflectionNamedType
                    && ! $type->isBuiltin()
                    && $parameter->isDefaultValueAvailable()) {
                    $this->addControllerDependencyWarning(
                        $graph,
                        $methodId,
                        $parameter->getName(),
                        'optional_typed_parameter_uses_default',
                        'Laravel leaves an optional class-typed controller parameter at its declared default instead of resolving it from the container.',
                    );

                    continue;
                }

                $this->addParameterRelationships(
                    $graph,
                    $methodId,
                    $parameter->getName(),
                    $type instanceof ReflectionNamedType && ! $type->isBuiltin()
                        ? $type->getName()
                        : null,
                    $routeParameters,
                );
            }

            return [
                'id' => $methodId,
                'confidence' => 1.0,
                'executable' => $runtimeInstantiable && $controllerMethod->isPublic(),
                'reason' => ! $runtimeInstantiable
                    ? 'controller_class_not_concrete'
                    : (! $controllerMethod->isPublic() ? 'controller_method_not_public' : null),
                'method' => $declaredMethod,
                'declaredMethod' => $declaredMethod,
                'dynamicAction' => false,
                'composedVisibility' => null,
            ];
        } catch (Throwable $exception) {
            $this->addUnknownControllerMethod($graph, $class, $method, $exception->getMessage());

            return [
                'id' => $class.'::'.$method,
                'confidence' => 0.75,
                'executable' => true,
                'reason' => null,
                'method' => $method,
                'declaredMethod' => $method,
                'dynamicAction' => false,
                'composedVisibility' => null,
            ];
        }
    }

    /**
     * @return array{
     *     id: string,
     *     confidence: float,
     *     executable: bool,
     *     reason: string|null,
     *     method: string,
     *     declaredMethod: string,
     *     dynamicAction: bool,
     *     composedVisibility: string|null
     * }|null
     */
    private function dynamicControllerActionResult(
        Graph $graph,
        string $class,
        string $method,
    ): ?array {
        $magic = $this->sourceMethod($graph, $class, '__call');
        $usable = false;

        if ($magic['status'] === 'found') {
            $runtimeClass = $this->indexedSourceClassName($class);
            $runtimeRecord = $runtimeClass !== null ? $this->sourceClasses[$runtimeClass] : null;
            $usable = $magic['visibility'] === 'public'
                && ($runtimeRecord === null || ! $runtimeRecord['abstract'])
                && $this->sourceMagicMethodCanHandle($magic['method']);
        }

        if (! $usable) {
            return null;
        }

        $this->addUnknownControllerMethod($graph, $class, $method, 'controller_dynamic_action_via_magic_call');

        return [
            'id' => $class.'::'.$method,
            'confidence' => 0.5,
            'executable' => true,
            'reason' => null,
            'method' => $method,
            'declaredMethod' => $method,
            'dynamicAction' => true,
            'composedVisibility' => null,
        ];
    }

    private function sourceMagicMethodCanHandle(Stmt\ClassMethod $method): bool
    {
        $statements = $method->stmts ?? [];

        if ($statements === []) {
            return false;
        }

        // Laravel's base Controller::__call is a single unconditional throw.
        // Treat similarly shaped magic methods as non-dispatching rather than
        // claiming that a missing route action can execute through them.
        return count($statements) !== 1 || ! $statements[0] instanceof Stmt\Throw_;
    }

    /**
     * @return array{
     *     status: 'found',
     *     class: string,
     *     method: Stmt\ClassMethod,
     *     record: array<string, mixed>,
     *     declaredMethod: string,
     *     invokedMethod: string,
     *     visibility: 'public'|'protected'|'private',
     *     declaredVisibility: 'public'|'protected'|'private',
     *     resolutionClass: string
     * }|array{status: 'missing'}|array{
     *     status: 'unavailable',
     *     reflectionClass?: string,
     *     projectSourceObserved?: bool
     * }
     */
    private function sourceMethod(Graph $graph, string $class, string $method): array
    {
        $current = ltrim($class, '\\');
        $visited = [];
        $sourceObserved = false;

        while ($current !== '' && ! isset($visited[strtolower($current)])) {
            $visited[strtolower($current)] = true;
            $record = $this->sourceClass($graph, $current);

            if ($record === null) {
                return $this->sourceLookupWasAvailable($current)
                    ? ['status' => 'missing']
                    : [
                        'status' => 'unavailable',
                        'reflectionClass' => $current,
                        'projectSourceObserved' => $sourceObserved,
                    ];
            }

            $sourceObserved = true;
            $current = $this->indexedSourceClassName($current) ?? $current;

            foreach ($record['node']->getMethods() as $candidate) {
                if ($this->samePhpName($candidate->name->toString(), $method)) {
                    $declaredMethod = $candidate->name->toString();

                    $visibility = $this->methodVisibility($candidate);

                    return [
                        'status' => 'found',
                        'class' => $current,
                        'method' => $candidate,
                        'record' => $record,
                        'declaredMethod' => $declaredMethod,
                        'invokedMethod' => $declaredMethod,
                        'visibility' => $visibility,
                        'declaredVisibility' => $visibility,
                        'resolutionClass' => $current,
                    ];
                }
            }

            $traitVisited = [];
            $traitState = $this->resolveSourceTraitUsesMethod(
                $graph,
                $record['traitUses'],
                $method,
                $current,
                $traitVisited,
            );

            if ($traitState['status'] === 'found') {
                return $traitState;
            }

            if ($traitState['status'] === 'unavailable') {
                return $traitState;
            }

            $current = $record['extends'] ?? '';
        }

        return ['status' => 'missing'];
    }

    /**
     * @param array<int, array<string, mixed>> $uses
     * @param array<string, true> $visited
     * @return array<string, mixed>
     */
    private function resolveSourceTraitUsesMethod(
        Graph $graph,
        array $uses,
        string $method,
        string $resolutionClass,
        array &$visited,
    ): array {
        $candidates = [];
        $unavailable = [];
        $invalid = false;

        foreach ($uses as $use) {
            [$groupCandidates, $groupUnavailable, $groupInvalid] = $this->sourceTraitUseCandidates(
                $graph,
                $use,
                $method,
                $resolutionClass,
                $visited,
            );
            array_push($candidates, ...$groupCandidates);
            array_push($unavailable, ...$groupUnavailable);
            $invalid = $invalid || $groupInvalid;
        }

        if ($unavailable !== []) {
            $boundaries = array_values(array_unique(array_filter(array_map(
                static fn (array $state): ?string => is_string($state['reflectionClass'] ?? null)
                    ? $state['reflectionClass']
                    : null,
                $unavailable,
            ))));

            return array_filter([
                'status' => 'unavailable',
                'reflectionClass' => count($boundaries) === 1 ? $boundaries[0] : null,
                'projectSourceObserved' => true,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $candidates = $this->uniqueSourceTraitCandidates($candidates);

        if ($invalid || count($candidates) > 1) {
            return ['status' => 'missing'];
        }

        return $candidates[0] ?? ['status' => 'missing'];
    }

    /**
     * @param array<string, mixed> $use
     * @param array<string, true> $visited
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: bool}
     */
    private function sourceTraitUseCandidates(
        Graph $graph,
        array $use,
        string $method,
        string $resolutionClass,
        array &$visited,
    ): array {
        [$candidates, $unavailable, $invalid] = $this->sourceTraitCandidatesForOriginalMethod(
            $graph,
            $use,
            $method,
            $resolutionClass,
            $visited,
        );

        foreach ($use['aliases'] ?? [] as $alias) {
            if (! $this->samePhpName($alias['alias'] ?? null, $method)) {
                continue;
            }

            [$aliasCandidates, $aliasUnavailable, $aliasInvalid] = $this->sourceTraitCandidatesForOriginalMethod(
                $graph,
                $use,
                (string) ($alias['method'] ?? ''),
                $resolutionClass,
                $visited,
                is_string($alias['trait'] ?? null) ? $alias['trait'] : null,
            );

            foreach ($aliasCandidates as &$candidate) {
                $candidate['invokedMethod'] = (string) $alias['alias'];

                if (is_string($alias['visibility'] ?? null)) {
                    $candidate['visibility'] = $alias['visibility'];
                }
            }
            unset($candidate);

            array_push($candidates, ...$aliasCandidates);
            array_push($unavailable, ...$aliasUnavailable);
            $invalid = $invalid || $aliasInvalid;
        }

        // A visibility-only adaptation changes the original imported method.
        // A renamed alias changes only that alias and leaves the original intact.
        foreach ($use['aliases'] ?? [] as $alias) {
            if (($alias['alias'] ?? null) !== null
                || ! is_string($alias['visibility'] ?? null)
                || ! $this->samePhpName($alias['method'] ?? null, $method)) {
                continue;
            }

            foreach ($candidates as &$candidate) {
                if (($alias['trait'] ?? null) === null
                    || $this->samePhpName($candidate['topTrait'] ?? null, $alias['trait'])) {
                    $candidate['visibility'] = $alias['visibility'];
                }
            }
            unset($candidate);
        }

        return [$candidates, $unavailable, $invalid];
    }

    /**
     * @param array<string, mixed> $use
     * @param array<string, true> $visited
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: bool}
     */
    private function sourceTraitCandidatesForOriginalMethod(
        Graph $graph,
        array $use,
        string $method,
        string $resolutionClass,
        array &$visited,
        ?string $onlyTrait = null,
    ): array {
        $candidates = [];
        $unavailable = [];
        $invalid = false;

        foreach ($use['traits'] ?? [] as $trait) {
            if (! is_string($trait)
                || ($onlyTrait !== null && ! $this->samePhpName($trait, $onlyTrait))) {
                continue;
            }

            $topTrait = $this->indexedSourceTraitName($trait) ?? ltrim($trait, '\\');
            $branchVisited = $visited;
            $state = $this->sourceTraitMethod(
                $graph,
                $topTrait,
                $method,
                $resolutionClass,
                $branchVisited,
            );
            $state['topTrait'] = $topTrait;

            if ($state['status'] === 'found') {
                $candidates[] = $state;
            } elseif ($state['status'] === 'unavailable') {
                $unavailable[] = $state;
            }
        }

        foreach ($use['precedences'] ?? [] as $precedence) {
            if (! $this->samePhpName($precedence['method'] ?? null, $method)) {
                continue;
            }

            $selected = $precedence['trait'] ?? null;

            if (is_string($selected)
                && ! (bool) array_filter(
                    [...$candidates, ...$unavailable],
                    fn (array $candidate): bool => $this->samePhpName($candidate['topTrait'] ?? null, $selected),
                )) {
                $invalid = true;
            }

            $excluded = array_values(array_filter(
                $precedence['insteadOf'] ?? [],
                static fn (mixed $trait): bool => is_string($trait),
            ));
            $candidates = array_values(array_filter(
                $candidates,
                fn (array $candidate): bool => ! (bool) array_filter(
                    $excluded,
                    fn (string $trait): bool => $this->samePhpName($candidate['topTrait'] ?? null, $trait),
                ),
            ));
            $unavailable = array_values(array_filter(
                $unavailable,
                fn (array $candidate): bool => ! (bool) array_filter(
                    $excluded,
                    fn (string $trait): bool => $this->samePhpName($candidate['topTrait'] ?? null, $trait),
                ),
            ));
        }

        return [$candidates, $unavailable, $invalid];
    }

    /** @param array<string, true> $visited */
    private function sourceTraitMethod(
        Graph $graph,
        string $trait,
        string $method,
        string $resolutionClass,
        array &$visited,
    ): array {
        $trait = $this->indexedSourceTraitName($trait) ?? ltrim($trait, '\\');
        $visitKey = 'trait:'.strtolower($trait);

        if (isset($visited[$visitKey])) {
            return ['status' => 'missing'];
        }

        $visited[$visitKey] = true;
        $record = $this->sourceTrait($graph, $trait);

        if ($record === null) {
            return $this->sourceLookupWasAvailable($trait)
                ? ['status' => 'missing']
                : [
                    'status' => 'unavailable',
                    'reflectionClass' => $trait,
                    'projectSourceObserved' => true,
                ];
        }

        $trait = $this->indexedSourceTraitName($trait) ?? $trait;

        foreach ($record['node']->getMethods() as $candidate) {
            if (! $this->samePhpName($candidate->name->toString(), $method)) {
                continue;
            }

            $declaredMethod = $candidate->name->toString();

            $visibility = $this->methodVisibility($candidate);

            return [
                'status' => 'found',
                'class' => $trait,
                'method' => $candidate,
                'record' => $record,
                'declaredMethod' => $declaredMethod,
                'invokedMethod' => $declaredMethod,
                'visibility' => $visibility,
                'declaredVisibility' => $visibility,
                'resolutionClass' => $resolutionClass,
            ];
        }

        return $this->resolveSourceTraitUsesMethod(
            $graph,
            $record['traitUses'],
            $method,
            $resolutionClass,
            $visited,
        );
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function uniqueSourceTraitCandidates(array $candidates): array
    {
        $unique = [];

        foreach ($candidates as $candidate) {
            $key = strtolower(implode('|', [
                $candidate['topTrait'] ?? '',
                $candidate['class'] ?? '',
                $candidate['declaredMethod'] ?? '',
                $candidate['invokedMethod'] ?? '',
                $candidate['visibility'] ?? '',
            ]));
            $unique[$key] = $candidate;
        }

        return array_values($unique);
    }

    /**
     * @return array{node: Stmt\Class_, file: string, absoluteFile: string, extends: string|null, abstract: bool}|null
     */
    private function sourceClass(Graph $graph, string $class): ?array
    {
        $class = ltrim($class, '\\');
        $indexedClass = $this->indexedSourceClassName($class);

        if ($indexedClass !== null) {
            return $this->sourceClasses[$indexedClass];
        }

        $file = $this->classSourceFile($class);

        if ($file === null) {
            return null;
        }

        $this->sourceLookupAvailable[strtolower($class)] = true;

        if (isset($this->indexedSourceFiles[$file])) {
            $indexedClass = $this->indexedSourceClassName($class);

            return $indexedClass !== null ? $this->sourceClasses[$indexedClass] : null;
        }

        $this->indexedSourceFiles[$file] = true;
        $statements = $this->parseFile($file, $graph);

        if ($statements === null) {
            return null;
        }

        $this->indexSourceStatements($statements, $file);

        $indexedClass = $this->indexedSourceClassName($class);

        return $indexedClass !== null ? $this->sourceClasses[$indexedClass] : null;
    }

    /** @return array{node: Stmt\Trait_, file: string, absoluteFile: string, traitUses: array<int, array<string, mixed>>}|null */
    private function sourceTrait(Graph $graph, string $trait): ?array
    {
        $trait = ltrim($trait, '\\');
        $indexedTrait = $this->indexedSourceTraitName($trait);

        if ($indexedTrait !== null) {
            return $this->sourceTraits[$indexedTrait];
        }

        $file = $this->classSourceFile($trait);

        if ($file === null) {
            return null;
        }

        $this->sourceLookupAvailable[strtolower($trait)] = true;

        if (! isset($this->indexedSourceFiles[$file])) {
            $this->indexedSourceFiles[$file] = true;
            $statements = $this->parseFile($file, $graph);

            if ($statements === null) {
                return null;
            }

            $this->indexSourceStatements($statements, $file);
        }

        $indexedTrait = $this->indexedSourceTraitName($trait);

        return $indexedTrait !== null ? $this->sourceTraits[$indexedTrait] : null;
    }

    /** @param array<int, AstNode> $nodes */
    private function indexSourceStatements(array $nodes, string $file): void
    {
        foreach ($nodes as $node) {
            if (! $node instanceof AstNode) {
                continue;
            }

            if ($node instanceof Stmt\Class_ && ($class = $this->className($node)) !== null) {
                $extends = $this->resolvedName($node->extends);
                $this->classes[$class] = ['extends' => $extends];
                $this->sourceClasses[$class] = [
                    'node' => $node,
                    'file' => $this->files->relativePath($file) ?? $file,
                    'absoluteFile' => $file,
                    'extends' => $extends,
                    'abstract' => $node->isAbstract(),
                    'traitUses' => $this->sourceTraitUses($node),
                ];
                $this->sourceClassNames[strtolower($class)] = $class;
            }

            if ($node instanceof Stmt\Trait_ && ($trait = $this->className($node)) !== null) {
                $this->sourceTraits[$trait] = [
                    'node' => $node,
                    'file' => $this->files->relativePath($file) ?? $file,
                    'absoluteFile' => $file,
                    'traitUses' => $this->sourceTraitUses($node),
                ];
                $this->sourceTraitNames[strtolower($trait)] = $trait;
            }

            foreach ($node->getSubNodeNames() as $name) {
                $child = $node->{$name};

                if ($child instanceof AstNode) {
                    $this->indexSourceStatements([$child], $file);
                } elseif (is_array($child)) {
                    $this->indexSourceStatements($child, $file);
                }
            }
        }
    }

    /**
     * @return array<int, array{
     *     traits: array<int, string>,
     *     precedences: array<int, array{trait: string|null, method: string, insteadOf: array<int, string>}>,
     *     aliases: array<int, array{trait: string|null, method: string, alias: string|null, visibility: string|null}>
     * }>
     */
    private function sourceTraitUses(Stmt\Class_|Stmt\Trait_ $statement): array
    {
        $uses = [];

        foreach ($statement->stmts as $member) {
            if (! $member instanceof Stmt\TraitUse) {
                continue;
            }

            $use = [
                'traits' => array_values(array_filter(array_map(
                    fn (AstNode\Name $trait): ?string => $this->resolvedName($trait),
                    $member->traits,
                ))),
                'precedences' => [],
                'aliases' => [],
            ];

            foreach ($member->adaptations as $adaptation) {
                if ($adaptation instanceof Stmt\TraitUseAdaptation\Precedence) {
                    $use['precedences'][] = [
                        'trait' => $this->resolvedName($adaptation->trait),
                        'method' => $adaptation->method->toString(),
                        'insteadOf' => array_values(array_filter(array_map(
                            fn (AstNode\Name $trait): ?string => $this->resolvedName($trait),
                            $adaptation->insteadof,
                        ))),
                    ];

                    continue;
                }

                if ($adaptation instanceof Stmt\TraitUseAdaptation\Alias) {
                    $use['aliases'][] = [
                        'trait' => $this->resolvedName($adaptation->trait),
                        'method' => $adaptation->method->toString(),
                        'alias' => $adaptation->newName?->toString(),
                        'visibility' => match ($adaptation->newModifier) {
                            Stmt\Class_::MODIFIER_PUBLIC => 'public',
                            Stmt\Class_::MODIFIER_PROTECTED => 'protected',
                            Stmt\Class_::MODIFIER_PRIVATE => 'private',
                            default => null,
                        },
                    ];
                }
            }

            $uses[] = $use;
        }

        return $uses;
    }

    private function indexedSourceClassName(string $class): ?string
    {
        return $this->sourceClassNames[strtolower(ltrim($class, '\\'))] ?? null;
    }

    private function indexedSourceTraitName(string $trait): ?string
    {
        return $this->sourceTraitNames[strtolower(ltrim($trait, '\\'))] ?? null;
    }

    private function sourceLookupWasAvailable(string $symbol): bool
    {
        return isset($this->sourceLookupAvailable[strtolower(ltrim($symbol, '\\'))]);
    }

    private function samePhpName(mixed $left, mixed $right): bool
    {
        return is_string($left)
            && is_string($right)
            && strcasecmp(ltrim($left, '\\'), ltrim($right, '\\')) === 0;
    }

    private function classSourceFile(string $class): ?string
    {
        try {
            if (class_exists($class, false)
                || interface_exists($class, false)
                || trait_exists($class, false)
                || (function_exists('enum_exists') && enum_exists($class, false))) {
                $file = (new ReflectionClass($class))->getFileName();

                if (is_string($file) && $file !== '' && is_file($file)) {
                    $file = str_replace('\\', '/', realpath($file) ?: $file);

                    if ($this->isProjectPhpSource($file)) {
                        return $file;
                    }
                }
            }
        } catch (Throwable) {
            // Fall through to Composer's non-executing class-file lookup.
        }

        foreach (spl_autoload_functions() as $autoload) {
            if (! is_array($autoload) || ! ($autoload[0] ?? null) instanceof ClassLoader) {
                continue;
            }

            $file = $autoload[0]->findFile($class);

            if (is_string($file) && $file !== '' && is_file($file)) {
                $file = str_replace('\\', '/', realpath($file) ?: $file);

                if ($this->isProjectPhpSource($file)) {
                    return $file;
                }
            }
        }

        return null;
    }

    private function isProjectPhpSource(string $file): bool
    {
        $relative = $this->files->relativePath($file);

        return is_string($relative)
            && ! str_starts_with($relative, '/')
            && preg_match('/^(?:app|routes|database\/migrations|tests)\//D', $relative) === 1;
    }

    private function reflectedMethodIsOutsideProjectSource(ReflectionMethod $method): bool
    {
        $file = $method->getFileName();

        if (! is_string($file) || $file === '') {
            return true;
        }

        $file = str_replace('\\', '/', realpath($file) ?: $file);

        return ! $this->isProjectPhpSource($file);
    }

    /** @param array<string, mixed> $record */
    private function sourceDeclarationNode(string $class, array $record, string $classType = 'class'): Node
    {
        $isTrait = $record['node'] instanceof Stmt\Trait_;

        return Node::make($class, $isTrait ? 'trait' : $classType, class_basename($class), [
            'namespace' => str_contains($class, '\\') ? substr($class, 0, (int) strrpos($class, '\\')) : null,
            'class' => class_basename($class),
            'file' => $record['file'],
            'line' => $record['node']->getStartLine(),
            'endLine' => $record['node']->getEndLine(),
            'metadata' => [
                'abstract' => $isTrait ? null : ($record['abstract'] ?? false),
                'declarationKind' => $isTrait ? 'trait' : 'class',
                'source' => $this->scannerSourceLabel(),
            ],
        ]);
    }

    private function addUnknownControllerMethod(Graph $graph, string $class, string $method, string $reason): void
    {
        $shortClass = class_basename($class);
        $namespace = str_contains($class, '\\') ? substr($class, 0, (int) strrpos($class, '\\')) : null;

        $graph->addNode(Node::make($class.'::'.$method, 'method', $shortClass.'::'.$method, [
            'namespace' => $namespace,
            'class' => $shortClass,
            'method' => $method,
            'signature' => $method.'()',
            'metadata' => [
                'unresolved' => true,
                'reason' => $reason,
            ],
        ]));
    }

    /** @param array<int, string> $routeParameters */
    private function addSourceParameterRelationships(
        Graph $graph,
        string $methodId,
        AstNode\Param $parameter,
        string $resolutionClass,
        array $routeParameters,
    ): void {
        $parameterName = $parameter->var instanceof Expr\Variable
            && is_string($parameter->var->name)
                ? $parameter->var->name
                : 'parameter';

        if ($parameter->type instanceof AstNode\UnionType
            || $parameter->type instanceof AstNode\IntersectionType) {
            $this->addControllerDependencyWarning(
                $graph,
                $methodId,
                $parameterName,
                'composite_typed_parameter_not_container_resolved',
                'Laravel does not container-resolve union or intersection controller parameters as a class dependency.',
            );

            return;
        }

        $class = $this->resolveType($parameter->type, $resolutionClass);

        if ($class !== null && $parameter->default !== null) {
            $this->addControllerDependencyWarning(
                $graph,
                $methodId,
                $parameterName,
                'optional_typed_parameter_uses_default',
                'Laravel leaves an optional class-typed controller parameter at its declared default instead of resolving it from the container.',
            );

            return;
        }

        $this->addParameterRelationships(
            $graph,
            $methodId,
            $parameterName,
            $class,
            $routeParameters,
        );
    }

    private function addControllerDependencyWarning(
        Graph $graph,
        string $methodId,
        string $parameter,
        string $reason,
        string $message,
    ): void {
        $graph->addWarning([
            'scanner' => 'routes',
            'kind' => 'controller_dependency_resolution',
            'controllerMethod' => $methodId,
            'parameter' => $parameter,
            'reason' => $reason,
            'message' => $message,
        ]);
    }

    /** @param array<int, string> $routeParameters */
    private function addParameterRelationships(
        Graph $graph,
        string $methodId,
        string $parameter,
        ?string $class,
        array $routeParameters,
    ): void
    {
        if ($class === null || $class === '') {
            return;
        }

        if (class_exists(FormRequest::class) && $this->isCurrentSubclassOf($graph, $class, FormRequest::class)) {
            $requestedRequest = $class;

            $runtimeRequest = $requestedRequest;
            $binding = $this->containerBindings->resolve($requestedRequest);
            $bindingMetadata = null;
            $confidence = 1.0;

            if ($binding === null && $this->containerBindings->hasDefaultDeclaration($requestedRequest)) {
                $this->addFormRequestWarning($graph, $methodId, $parameter, [
                    'reason' => 'form_request_container_target_unknown',
                    'requestedRequest' => $requestedRequest,
                    'message' => "The container binding for FormRequest [{$requestedRequest}] has no statically proven runtime target.",
                ]);

                return;
            }

            if ($binding !== null) {
                $runtimeRequest = (string) ($binding['concrete'] ?? '');
                $confidence = (float) ($binding['confidence'] ?? 0.0);
                $bindingMetadata = array_filter([
                    'scope' => $binding['scope'] ?? null,
                    'inference' => $binding['inference'] ?? null,
                    'terminalInference' => $binding['terminalInference'] ?? $binding['inference'] ?? null,
                    'confidence' => $binding['confidence'] ?? null,
                    'environment' => $binding['environment'] ?? null,
                    'resolutionPath' => $binding['resolutionPath'] ?? null,
                    'effectiveLifetime' => $binding['effectiveLifetime'] ?? $binding['lifetime'] ?? null,
                ], static fn (mixed $value): bool => $value !== null);

                try {
                    $validRuntimeRequest = $this->isCurrentSubclassOf(
                        $graph,
                        $runtimeRequest,
                        FormRequest::class,
                    ) && $this->isCurrentConcreteClass($graph, $runtimeRequest);
                } catch (Throwable) {
                    $validRuntimeRequest = false;
                }

                if (! $validRuntimeRequest) {
                    $this->addFormRequestWarning($graph, $methodId, $parameter, [
                        'reason' => 'form_request_container_target_not_concrete',
                        'requestedRequest' => $requestedRequest,
                        'runtimeRequest' => $runtimeRequest !== '' ? $runtimeRequest : null,
                        'message' => "The container target for FormRequest [{$requestedRequest}] is not a concrete FormRequest.",
                    ]);

                    return;
                }

                $graph->addNode(Node::make($requestedRequest, 'form_request', class_basename($requestedRequest), [
                    'namespace' => str_contains($requestedRequest, '\\') ? substr($requestedRequest, 0, (int) strrpos($requestedRequest, '\\')) : null,
                    'class' => class_basename($requestedRequest),
                    'metadata' => [
                        'source' => 'controller_method_parameter',
                        'parameter' => $parameter,
                        'runtimeRequest' => $runtimeRequest,
                        'containerBinding' => $bindingMetadata,
                    ],
                ]));

                if ($runtimeRequest !== $requestedRequest) {
                    $graph->addNode(Node::make($runtimeRequest, 'form_request', class_basename($runtimeRequest), [
                        'namespace' => str_contains($runtimeRequest, '\\') ? substr($runtimeRequest, 0, (int) strrpos($runtimeRequest, '\\')) : null,
                        'class' => class_basename($runtimeRequest),
                        'metadata' => [
                            'source' => 'container_resolved_form_request',
                            'requestedRequest' => $requestedRequest,
                            'parameter' => $parameter,
                        ],
                    ]));
                    $graph->addEdge(new Edge($requestedRequest, $runtimeRequest, 'resolves_to', $confidence, [
                        'source' => 'booted_container',
                        'environment' => $binding['environment'] ?? null,
                        'bindings' => ['default' => $bindingMetadata],
                    ]));
                }

                // Existing instances are returned before Container::resolve()
                // fires FormRequest resolving callbacks, so no automatic
                // validation lifecycle is entered for this route argument.
                if (($binding['terminalInference'] ?? $binding['inference'] ?? null) === 'existing_instance') {
                    $this->addFormRequestWarning($graph, $methodId, $parameter, [
                        'reason' => 'form_request_existing_instance_bypasses_lifecycle',
                        'requestedRequest' => $requestedRequest,
                        'runtimeRequest' => $runtimeRequest,
                        'message' => "Existing-instance FormRequest binding [{$requestedRequest}] bypasses Laravel's resolving callbacks.",
                    ]);

                    return;
                }
            }

            $graph->addNode(Node::make($runtimeRequest, 'form_request', class_basename($runtimeRequest), [
                'namespace' => str_contains($runtimeRequest, '\\') ? substr($runtimeRequest, 0, (int) strrpos($runtimeRequest, '\\')) : null,
                'class' => class_basename($runtimeRequest),
                'metadata' => [
                    'source' => 'controller_method_parameter',
                    'parameter' => $parameter,
                    'requestedRequest' => $runtimeRequest !== $requestedRequest ? $requestedRequest : null,
                ],
            ]));

            $graph->addEdge(new Edge($methodId, $runtimeRequest, 'validates_with', $confidence, array_filter([
                'parameter' => $parameter,
                'requestedRequest' => $runtimeRequest !== $requestedRequest ? $requestedRequest : null,
                'runtimeRequest' => $runtimeRequest,
                'container_binding' => $bindingMetadata,
                'sharesRouteParameterName' => in_array($parameter, $routeParameters, true) ?: null,
                'dependencyPresenceRule' => 'existing_parameter_instance_of_type',
                'causalExecutionProven' => true,
            ], static fn (mixed $value): bool => $value !== null)));
        }

        if ($this->isCurrentSubclassOf($graph, $class, Model::class)) {
            $graph->addNode(Node::make($class, 'model', class_basename($class), [
                'namespace' => str_contains($class, '\\') ? substr($class, 0, (int) strrpos($class, '\\')) : null,
                'class' => class_basename($class),
                'metadata' => [
                    'source' => 'controller_method_parameter',
                    'parameter' => $parameter,
                ],
            ]));

            $graph->addEdge(new Edge($methodId, $class, 'uses_model', 0.9, [
                'parameter' => $parameter,
                'inference' => 'typed_controller_parameter',
            ]));
        }
    }

    private function isCurrentSubclassOf(Graph $graph, string $class, string $base): bool
    {
        $current = ltrim($class, '\\');
        $base = ltrim($base, '\\');
        $visited = [];
        $sourceObserved = false;

        while ($current !== '' && ! isset($visited[strtolower($current)])) {
            if ($this->samePhpName($current, $base)) {
                return true;
            }

            $visited[strtolower($current)] = true;
            $record = $this->sourceClass($graph, $current);

            if ($record === null) {
                if ($this->sourceLookupWasAvailable($current)) {
                    return false;
                }

                return $sourceObserved
                    ? class_exists($current, false) && is_subclass_of($current, $base)
                    : class_exists($class) && is_subclass_of($class, $base);
            }

            $sourceObserved = true;
            $current = $record['extends'] ?? '';
        }

        return false;
    }

    private function isCurrentConcreteClass(Graph $graph, string $class): bool
    {
        $record = $this->sourceClass($graph, $class);

        if ($record !== null) {
            return ! $record['abstract'];
        }

        if ($this->sourceLookupWasAvailable($class)) {
            return false;
        }

        try {
            return class_exists($class) && (new ReflectionClass($class))->isInstantiable();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<int, string> */
    private function routeParameterNames(string $uri): array
    {
        if (preg_match_all('/\{([^}:?]+)(?:[^}]*)\}/', $uri, $matches) === 0) {
            return [];
        }

        $parameters = array_values(array_unique($matches[1]));
        sort($parameters);

        return $parameters;
    }

    /** @param array<string, mixed> $warning */
    private function addFormRequestWarning(
        Graph $graph,
        string $methodId,
        string $parameter,
        array $warning,
    ): void {
        $graph->addWarning(array_filter([
            'scanner' => 'routes',
            'kind' => 'form_request_resolution',
            'controllerMethod' => $methodId,
            'parameter' => $parameter,
            ...$warning,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
