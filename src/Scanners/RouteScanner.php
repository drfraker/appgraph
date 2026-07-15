<?php

namespace AppGraph\Scanners;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Support\LaravelIntrospection;
use AppGraph\Support\PhpReflection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;

class RouteScanner
{
    public function __construct(
        private LaravelIntrospection $laravel,
        private PhpReflection $reflection,
    ) {
    }

    public function scan(Graph $graph): Graph
    {
        foreach ($this->laravel->routes() as $route) {
            $uri = $this->laravel->routeUri($route);
            $action = $this->laravel->controllerAction($route);

            foreach ($this->laravel->routeMethods($route) as $method) {
                $routeNodeId = 'route:'.strtoupper($method).':'.$uri;

                $graph->addNode(Node::make($routeNodeId, 'route', strtoupper($method).' '.$uri, [
                    'metadata' => [
                        'name' => $route->getName(),
                        'uri' => $uri,
                        'methods' => $this->laravel->routeMethods($route),
                        'middleware' => $route->gatherMiddleware(),
                        'action' => $route->getActionName(),
                    ],
                ]));

                if ($action === null) {
                    continue;
                }

                $controllerMethodId = $action['class'].'::'.$action['method'];
                $this->addControllerMethod($graph, $action['class'], $action['method']);

                $graph->addEdge(new Edge($routeNodeId, $controllerMethodId, 'routes_to', $this->routeConfidence($action['class'], $action['method']), [
                    'controller' => $action['class'],
                    'method' => $action['method'],
                ]));
            }
        }

        return $graph;
    }

    private function addControllerMethod(Graph $graph, string $class, string $method): void
    {
        try {
            if (! class_exists($class)) {
                $this->addUnknownControllerMethod($graph, $class, $method, 'controller_class_not_autoloadable');

                return;
            }

            $controller = new ReflectionClass($class);

            $graph->addNode($this->reflection->classNode($controller));

            if (! $controller->hasMethod($method)) {
                $this->addUnknownControllerMethod($graph, $class, $method, 'controller_method_not_found');

                return;
            }

            $controllerMethod = $controller->getMethod($method);

            $graph->addNode($this->reflection->methodNode($controllerMethod));
            $graph->addEdge(new Edge($class.'::'.$method, $class, 'defined_in'));

            foreach ($controllerMethod->getParameters() as $parameter) {
                $this->addParameterRelationships($graph, $class.'::'.$method, $parameter);
            }
        } catch (ReflectionException $exception) {
            $this->addUnknownControllerMethod($graph, $class, $method, $exception->getMessage());
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

    private function addParameterRelationships(Graph $graph, string $methodId, ReflectionParameter $parameter): void
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return;
        }

        $class = $type->getName();

        if (class_exists(FormRequest::class) && is_subclass_of($class, FormRequest::class)) {
            $graph->addNode(Node::make($class, 'form_request', class_basename($class), [
                'namespace' => str_contains($class, '\\') ? substr($class, 0, (int) strrpos($class, '\\')) : null,
                'class' => class_basename($class),
                'metadata' => [
                    'source' => 'controller_method_parameter',
                    'parameter' => $parameter->getName(),
                ],
            ]));

            $graph->addEdge(new Edge($methodId, $class, 'validates_with', 1.0, [
                'parameter' => $parameter->getName(),
            ]));
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
}
