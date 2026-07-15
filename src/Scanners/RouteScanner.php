<?php

namespace AppGraph\Scanners;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Support\ContainerBindingRegistry;
use AppGraph\Support\LaravelIntrospection;
use AppGraph\Support\LaravelMiddlewarePipeline;
use AppGraph\Support\PhpReflection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

class RouteScanner
{
    public function __construct(
        private LaravelIntrospection $laravel,
        private LaravelMiddlewarePipeline $middleware,
        private PhpReflection $reflection,
        private ContainerBindingRegistry $containerBindings,
    ) {
    }

    public function scan(Graph $graph): Graph
    {
        foreach ($this->laravel->routes() as $route) {
            $uri = $this->laravel->routeUri($route);
            $action = $this->laravel->controllerAction($route);
            $middleware = $this->middleware->inspect($route);
            $routeParameters = $this->routeParameterNames($uri);

            foreach ($this->laravel->routeMethods($route) as $method) {
                $routeNodeId = 'route:'.strtoupper($method).':'.$uri;

                $graph->addNode(Node::make($routeNodeId, 'route', strtoupper($method).' '.$uri, [
                    'metadata' => [
                        'name' => $route->getName(),
                        'uri' => $uri,
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

                $controllerMethodId = $this->addControllerMethod(
                    $graph,
                    $runtimeAction['class'],
                    $runtimeAction['method'],
                    $routeParameters,
                );
                $graph->addEdge(new Edge(
                    $routeNodeId,
                    $controllerMethodId,
                    'routes_to',
                    round(
                        $this->routeConfidence($runtimeAction['class'], $runtimeAction['method'])
                        * $runtimeAction['confidence'],
                        2,
                    ),
                    array_filter([
                        'controller' => $runtimeAction['class'],
                        'runtimeController' => $runtimeAction['class'],
                        'requestedController' => $runtimeAction['requestedClass'] !== $runtimeAction['class']
                            ? $runtimeAction['requestedClass']
                            : null,
                        'method' => $runtimeAction['method'],
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

                if (! class_exists($class, false)) {
                    $this->addMiddlewareWarning($graph, $routeNodeId, [
                        'reason' => 'middleware_class_unloaded_or_unindexed',
                        'position' => $occurrence['position'],
                        'scope' => $occurrence['scope'],
                        'declared' => $occurrence['declared'],
                        'resolved' => $occurrence['resolved'],
                        'message' => "Resolved middleware class [{$class}] was not already loaded, so its executable method was not reflected through the application autoloader.",
                    ]);

                    continue;
                }

                $middlewareClass = new ReflectionClass($class);
                $handle = $this->middlewareMethod($middlewareClass);

                if ($handle === null) {
                    $this->addMiddlewareWarning($graph, $routeNodeId, [
                        'reason' => 'middleware_method_not_found',
                        'position' => $occurrence['position'],
                        'scope' => $occurrence['scope'],
                        'declared' => $occurrence['declared'],
                        'resolved' => $occurrence['resolved'],
                        'message' => "Resolved middleware [{$class}] has neither a handle() method nor __invoke().",
                    ]);

                    continue;
                }

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

                $graph->addNode($this->reflection->classNode($middlewareClass, 'middleware'));
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

    private function middlewareMethod(ReflectionClass $class): ?ReflectionMethod
    {
        if ($class->hasMethod('handle')) {
            return $class->getMethod('handle');
        }

        if ($class->hasMethod('__invoke')) {
            return $class->getMethod('__invoke');
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

    /** @param array<int, string> $routeParameters */
    private function addControllerMethod(Graph $graph, string $class, string $method, array $routeParameters): string
    {
        try {
            if (! class_exists($class)) {
                $this->addUnknownControllerMethod($graph, $class, $method, 'controller_class_not_autoloadable');

                return $class.'::'.$method;
            }

            $controller = new ReflectionClass($class);

            $graph->addNode($this->reflection->classNode($controller));

            if (! $controller->hasMethod($method)) {
                $this->addUnknownControllerMethod($graph, $class, $method, 'controller_method_not_found');

                return $class.'::'.$method;
            }

            $controllerMethod = $controller->getMethod($method);
            $declaringClass = $controllerMethod->getDeclaringClass();
            $methodId = $declaringClass->getName().'::'.$method;

            $graph->addNode($this->reflection->methodNode($controllerMethod));
            $graph->addNode($this->reflection->classNode($declaringClass));
            $graph->addEdge(new Edge($methodId, $declaringClass->getName(), 'defined_in'));

            foreach ($controllerMethod->getParameters() as $parameter) {
                $this->addParameterRelationships($graph, $methodId, $parameter, $routeParameters);
            }

            return $methodId;
        } catch (ReflectionException $exception) {
            $this->addUnknownControllerMethod($graph, $class, $method, $exception->getMessage());

            return $class.'::'.$method;
        }
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
    private function addParameterRelationships(
        Graph $graph,
        string $methodId,
        ReflectionParameter $parameter,
        array $routeParameters,
    ): void
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return;
        }

        $class = $type->getName();

        if (class_exists(FormRequest::class) && is_subclass_of($class, FormRequest::class)) {
            $requestedRequest = $class;

            if (in_array($parameter->getName(), $routeParameters, true)) {
                $this->addFormRequestWarning($graph, $methodId, $parameter, [
                    'reason' => 'route_parameter_shadows_form_request',
                    'requestedRequest' => $requestedRequest,
                    'message' => "Route parameter [{$parameter->getName()}] is supplied before Laravel considers the FormRequest type.",
                ]);

                return;
            }

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
                    $validRuntimeRequest = class_exists($runtimeRequest, false)
                        && is_subclass_of($runtimeRequest, FormRequest::class)
                        && (new ReflectionClass($runtimeRequest))->isInstantiable();
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
                        'parameter' => $parameter->getName(),
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
                            'parameter' => $parameter->getName(),
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
                    'parameter' => $parameter->getName(),
                    'requestedRequest' => $runtimeRequest !== $requestedRequest ? $requestedRequest : null,
                ],
            ]));

            $graph->addEdge(new Edge($methodId, $runtimeRequest, 'validates_with', $confidence, array_filter([
                'parameter' => $parameter->getName(),
                'requestedRequest' => $runtimeRequest !== $requestedRequest ? $requestedRequest : null,
                'runtimeRequest' => $runtimeRequest,
                'container_binding' => $bindingMetadata,
                'causalExecutionProven' => true,
            ], static fn (mixed $value): bool => $value !== null)));
        }

        if (is_subclass_of($class, Model::class)) {
            $graph->addNode(Node::make($class, 'model', class_basename($class), [
                'namespace' => str_contains($class, '\\') ? substr($class, 0, (int) strrpos($class, '\\')) : null,
                'class' => class_basename($class),
                'metadata' => [
                    'source' => 'controller_method_parameter',
                    'parameter' => $parameter->getName(),
                ],
            ]));

            $graph->addEdge(new Edge($methodId, $class, 'uses_model', 0.9, [
                'parameter' => $parameter->getName(),
                'inference' => 'typed_controller_parameter',
            ]));
        }
    }

    private function routeConfidence(string $class, string $method): float
    {
        return class_exists($class) && method_exists($class, $method) ? 1.0 : 0.75;
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
        ReflectionParameter $parameter,
        array $warning,
    ): void {
        $graph->addWarning(array_filter([
            'scanner' => 'routes',
            'kind' => 'form_request_resolution',
            'controllerMethod' => $methodId,
            'parameter' => $parameter->getName(),
            ...$warning,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
