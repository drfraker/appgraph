<?php

namespace AppGraph\Support;

use Closure;
use Illuminate\Routing\Attributes\Controllers\Middleware as MiddlewareAttribute;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware as ControllerMiddleware;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Throwable;

class LaravelMiddlewarePipeline
{
    private PhpFileFacts $phpFileFacts;

    public function __construct(
        private ContainerBindingRegistry $containerBindings,
        ?PhpFileFacts $phpFileFacts = null,
    ) {
        $this->phpFileFacts = $phpFileFacts ?? new PhpFileFacts();
    }

    /**
     * Resolve the executable route middleware pipeline while retaining the
     * declaration that produced every resolved occurrence.
     *
     * @return array{
     *     declared: array<int, mixed>,
     *     resolved: array<int, array<string, mixed>>,
     *     occurrences: array<int, array<string, mixed>>,
     *     diagnostics: array<int, array<string, mixed>>
     * }
     */
    public function inspect(Route $route): array
    {
        $diagnostics = [];
        $declarations = $this->safeDeclarations($route, $diagnostics);
        $declared = array_column($declarations, 'value');

        $router = $this->containerBindings->existingInstance('router')
            ?? $this->containerBindings->existingInstance(Router::class);

        if (! $router instanceof Router || $router::class !== Router::class) {
            return [
                'declared' => $declared,
                'resolved' => [],
                'occurrences' => [],
                'diagnostics' => [...$diagnostics, [
                    'reason' => $router === null ? 'router_state_unavailable' : 'router_state_unverified',
                    'class' => $router !== null ? $router::class : null,
                    'message' => 'An exact already-instantiated Laravel router was not available, so middleware registries were not inspected.',
                ]],
            ];
        }

        try {
            $candidates = $this->provenanceCandidates($router, $declarations, $diagnostics);
        } catch (Throwable $throwable) {
            $candidates = [];
            $diagnostics[] = [
                'reason' => 'middleware_provenance_failed',
                'message' => $throwable->getMessage(),
                'class' => $throwable::class,
            ];
        }

        try {
            $resolved = $this->resolvedMiddleware($router, $route, $candidates, $diagnostics);
        } catch (Throwable $throwable) {
            return [
                'declared' => $declared,
                'resolved' => [],
                'occurrences' => [],
                'diagnostics' => [...$diagnostics, [
                    'reason' => 'middleware_resolution_failed',
                    'message' => $throwable->getMessage(),
                    'class' => $throwable::class,
                ]],
            ];
        }

        $candidateQueues = [];

        foreach ($candidates as $candidate) {
            $candidateQueues[$this->middlewareKey($candidate['value'])][] = $candidate;
        }

        $occurrences = [];
        $resolvedMetadata = [];

        foreach ($resolved as $position => $middleware) {
            $descriptor = $this->resolvedDescriptor($middleware, $position);
            $resolvedMetadata[] = $descriptor;
            $key = $this->middlewareKey($middleware);
            $candidate = null;

            if (($candidateQueues[$key] ?? []) !== []) {
                $candidate = array_shift($candidateQueues[$key]);
            }

            if ($candidate === null) {
                $candidate = [
                    'scope' => 'unknown',
                    'declared' => null,
                    'declared_alias' => null,
                    'declared_position' => null,
                    'expanded' => null,
                    'expanded_alias' => null,
                    'groups' => [],
                ];

                $diagnostics[] = [
                    'reason' => 'middleware_provenance_unresolved',
                    'position' => $position,
                    'resolved' => $descriptor['resolved'],
                    'message' => 'Laravel resolved middleware that could not be matched back to its declaration.',
                ];
            } else {
                unset($candidate['value']);
            }

            $occurrence = array_merge($candidate, $descriptor);

            if (($descriptor['kind'] ?? null) === 'closure') {
                $diagnostics[] = [
                    'reason' => 'middleware_closure',
                    'position' => $position,
                    'scope' => $occurrence['scope'],
                    'declared' => $occurrence['declared'],
                    'message' => 'Closure middleware executes at runtime but has no stable method node.',
                ];

                continue;
            }

            if (($descriptor['kind'] ?? null) !== 'class') {
                $diagnostics[] = [
                    'reason' => 'unsupported_middleware',
                    'position' => $position,
                    'scope' => $occurrence['scope'],
                    'declared' => $occurrence['declared'],
                    'message' => 'Resolved middleware is neither a class string nor a Closure.',
                ];

                continue;
            }

            $occurrences[] = $occurrence;
        }

        return [
            'declared' => $declared,
            'resolved' => $resolvedMetadata,
            'occurrences' => $occurrences,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, mixed>
     */
    private function resolvedMiddleware(
        Router $router,
        Route $route,
        array $candidates,
        array &$diagnostics,
    ): array
    {
        $resolved = array_column($candidates, 'value');
        $excludedDeclarations = array_map(
            static fn (mixed $middleware): array => ['scope' => 'excluded', 'value' => $middleware],
            method_exists($route, 'excludedMiddleware') ? $route->excludedMiddleware() : [],
        );
        $excludedCandidates = $this->provenanceCandidates($router, $excludedDeclarations, $diagnostics);
        $excluded = array_column($excludedCandidates, 'value');
        $unresolvedExclusions = [];

        $resolved = array_values(array_filter($resolved, function (mixed $middleware) use ($excluded, &$unresolvedExclusions): bool {
            if ($middleware instanceof Closure || ! is_string($middleware)) {
                return true;
            }

            if (in_array($middleware, $excluded, true)) {
                return false;
            }

            foreach ($excluded as $exclude) {
                if (! is_string($exclude) || str_contains($middleware, ':') || str_contains($exclude, ':')) {
                    continue;
                }

                if (class_exists($middleware, false) && class_exists($exclude, false)) {
                    if ((new ReflectionClass($middleware))->isSubclassOf($exclude)) {
                        return false;
                    }

                    continue;
                }

                $unresolvedExclusions[$middleware] = true;
            }

            return true;
        }));

        if ($unresolvedExclusions !== []) {
            $diagnostics[] = [
                'reason' => 'middleware_exclusion_unresolved',
                'middleware' => array_keys($unresolvedExclusions),
                'message' => 'Middleware ancestry was not loaded, so only exact exclusions were applied without invoking the application autoloader.',
            ];
        }

        return $this->sortMiddlewareWithoutAutoload($router, $resolved, $diagnostics);
    }

    /**
     * Mirror Laravel's stable middleware-priority pass without calling
     * class_implements(), class_parents(), or class_exists() with autoloading
     * enabled. Exact class names remain exact; already-loaded ancestry is safe
     * to inspect; unknown ancestry retains declaration-relative order.
     *
     * @param array<int, mixed> $middleware
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, mixed>
     */
    private function sortMiddlewareWithoutAutoload(
        Router $router,
        array $middleware,
        array &$diagnostics,
    ): array {
        try {
            $priority = (new ReflectionClass(Router::class))
                ->getProperty('middlewarePriority')
                ->getValue($router);
            $priority = is_array($priority) ? $priority : [];
        } catch (Throwable) {
            $priority = [];
            $diagnostics[] = [
                'reason' => 'middleware_priority_unreadable',
                'message' => 'Laravel middleware priority state could not be read without executing application code.',
            ];
        }

        $unresolved = [];
        $guard = max(1, count($middleware) * count($middleware) + 1);

        do {
            $moved = false;
            $lastIndex = 0;
            $lastPriorityIndex = null;

            foreach ($middleware as $index => $value) {
                if (! is_string($value)) {
                    continue;
                }

                [$priorityIndex, $ancestryKnown] = $this->middlewarePriorityIndex($priority, $value);

                if (! $ancestryKnown) {
                    $unresolved[explode(':', $value, 2)[0]] = true;
                }

                if ($priorityIndex === null) {
                    continue;
                }

                if ($lastPriorityIndex !== null && $priorityIndex < $lastPriorityIndex) {
                    $movedValue = $middleware[$index];
                    array_splice($middleware, $index, 1);
                    array_splice($middleware, $lastIndex, 0, [$movedValue]);
                    $moved = true;
                    break;
                }

                $lastIndex = $index;
                $lastPriorityIndex = $priorityIndex;
            }
        } while ($moved && --$guard > 0);

        if ($unresolved !== []) {
            $diagnostics[] = [
                'reason' => 'middleware_priority_unresolved',
                'middleware' => array_keys($unresolved),
                'message' => 'Unloaded middleware ancestry was not inspected, so inherited priority matches remain in declaration-relative order.',
            ];
        }

        return Router::uniqueMiddleware(array_values($middleware));
    }

    /**
     * @param array<int, string> $priority
     * @return array{0: int|null, 1: bool}
     */
    private function middlewarePriorityIndex(array $priority, string $middleware): array
    {
        $class = explode(':', $middleware, 2)[0];
        $exact = array_search($class, $priority, true);

        if ($exact !== false) {
            return [$exact, true];
        }

        if (! class_exists($class, false) && ! interface_exists($class, false)) {
            return [null, false];
        }

        try {
            $reflection = new ReflectionClass($class);
            $names = [
                ...array_keys($reflection->getInterfaces()),
                ...array_values(class_parents($class, false) ?: []),
            ];

            foreach ($names as $name) {
                $index = array_search($name, $priority, true);

                if ($index !== false) {
                    return [$index, true];
                }
            }

            return [null, true];
        } catch (Throwable) {
            return [null, false];
        }
    }

    /**
     * Build the declarations Laravel would gather without asking the route to
     * instantiate a legacy controller or execute HasMiddleware::middleware().
     *
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array{scope: string, value: mixed}>
     */
    private function safeDeclarations(Route $route, array &$diagnostics): array
    {
        $routeDeclarations = array_map(
            static fn (mixed $middleware): array => ['scope' => 'route', 'value' => $middleware],
            array_values($route->middleware()),
        );

        if (property_exists($route, 'computedMiddleware') && $route->computedMiddleware !== null) {
            return $this->classifyCachedDeclarations(
                array_values((array) $route->computedMiddleware),
                $routeDeclarations,
            );
        }

        $controllerDeclarations = array_map(
            static fn (mixed $middleware): array => ['scope' => 'controller', 'value' => $middleware],
            $this->safeControllerMiddleware($route, $diagnostics),
        );

        return $this->uniqueDeclarations([...$routeDeclarations, ...$controllerDeclarations]);
    }

    /**
     * @param array<int, mixed> $cached
     * @param array<int, array{scope: string, value: mixed}> $routeDeclarations
     * @return array<int, array{scope: string, value: mixed}>
     */
    private function classifyCachedDeclarations(array $cached, array $routeDeclarations): array
    {
        $remainingRouteDeclarations = [];

        foreach ($routeDeclarations as $declaration) {
            $remainingRouteDeclarations[$this->middlewareKey($declaration['value'])] =
                ($remainingRouteDeclarations[$this->middlewareKey($declaration['value'])] ?? 0) + 1;
        }

        $classified = [];

        foreach ($cached as $middleware) {
            $key = $this->middlewareKey($middleware);
            $scope = ($remainingRouteDeclarations[$key] ?? 0) > 0 ? 'route' : 'controller';

            if ($scope === 'route') {
                $remainingRouteDeclarations[$key]--;
            }

            $classified[] = ['scope' => $scope, 'value' => $middleware];
        }

        return $classified;
    }

    /**
     * Match Router::uniqueMiddleware() while retaining the scope of the first
     * declaration, which is the one Laravel keeps at runtime.
     *
     * @param array<int, array{scope: string, value: mixed}> $declarations
     * @return array<int, array{scope: string, value: mixed}>
     */
    private function uniqueDeclarations(array $declarations): array
    {
        $seen = [];
        $unique = [];

        foreach ($declarations as $declaration) {
            $key = $this->middlewareKey($declaration['value']);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $declaration;
        }

        return $unique;
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, mixed>
     */
    private function safeControllerMiddleware(Route $route, array &$diagnostics): array
    {
        $controllerClass = $route->getControllerClass();
        $controllerMethod = $route->getActionMethod();

        if (! is_string($controllerClass) || $controllerClass === '') {
            return [];
        }

        if (! class_exists($controllerClass, false)) {
            $diagnostics[] = $this->controllerDiagnostic(
                'controller_middleware_class_unloaded',
                $controllerClass,
                $controllerMethod,
                'Controller middleware was not reflected because doing so would autoload application code; literal source recovery requires an already-loaded controller.',
            );

            return [];
        }

        try {
            $controller = new ReflectionClass($controllerClass);
            $middleware = [];

            if ($controller->implementsInterface(HasMiddleware::class)) {
                $middleware = $this->staticControllerMiddleware(
                    $controller,
                    $controllerMethod,
                    $diagnostics,
                );
            } elseif ($controller->isSubclassOf(Controller::class) || $controller->getName() === Controller::class) {
                $diagnostics[] = $this->controllerDiagnostic(
                    'legacy_controller_middleware_omitted',
                    $controllerClass,
                    $controllerMethod,
                    'Legacy controller middleware requires a controller instance and was omitted because graph scans never construct controllers.',
                );
            }

            return [
                ...$middleware,
                ...$this->attributeControllerMiddleware($controller, $controllerMethod, $diagnostics),
            ];
        } catch (Throwable $throwable) {
            $diagnostics[] = $this->controllerDiagnostic(
                'controller_middleware_reflection_failed',
                $controllerClass,
                $controllerMethod,
                $throwable->getMessage(),
                ['class' => $throwable::class],
            );

            return [];
        }
    }

    /**
     * Recover literal HasMiddleware declarations from source without calling
     * the static middleware() method.
     *
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, mixed>
     */
    private function staticControllerMiddleware(
        ReflectionClass $controller,
        string $controllerMethod,
        array &$diagnostics,
    ): array {
        $controllerClass = $controller->getName();

        if (! $controller->hasMethod('middleware')) {
            $diagnostics[] = $this->controllerDiagnostic(
                'controller_middleware_method_not_found',
                $controllerClass,
                $controllerMethod,
                'The controller implements HasMiddleware but has no middleware() method to inspect.',
            );

            return [];
        }

        $method = $controller->getMethod('middleware');

        if (! $method->isPublic() || ! $method->isStatic()) {
            $diagnostics[] = $this->controllerDiagnostic(
                'controller_middleware_method_invalid',
                $controllerClass,
                $controllerMethod,
                'HasMiddleware::middleware() is not a public static method, so Laravel cannot invoke it safely.',
            );

            return [];
        }

        $astMethod = $this->methodAst($method);

        if ($astMethod === null) {
            $diagnostics[] = $this->controllerDiagnostic(
                'controller_middleware_source_unavailable',
                $controllerClass,
                $controllerMethod,
                'The middleware() source could not be inspected, so its declarations were omitted.',
            );

            return [];
        }

        $returns = array_values(array_filter(
            $astMethod->stmts ?? [],
            static fn (Stmt $statement): bool => $statement instanceof Stmt\Return_,
        ));

        if (count($returns) !== 1 || ! $returns[0]->expr instanceof Expr\Array_) {
            $diagnostics[] = $this->controllerDiagnostic(
                'controller_middleware_return_not_literal',
                $controllerClass,
                $controllerMethod,
                'The middleware() method does not have one direct literal array return, so graph scanning did not execute or guess its result.',
            );

            return [];
        }

        $middleware = [];

        foreach ($returns[0]->expr->items as $item) {
            if ($item === null || $item->unpack) {
                $diagnostics[] = $this->unsupportedLiteralDiagnostic(
                    $controllerClass,
                    $controllerMethod,
                    $item?->getStartLine(),
                );

                continue;
            }

            $literal = $this->controllerMiddlewareLiteral(
                $item->value,
                $controller,
                $method->getDeclaringClass(),
            );

            if (! $literal['ok']) {
                $diagnostics[] = $this->unsupportedLiteralDiagnostic(
                    $controllerClass,
                    $controllerMethod,
                    $item->getStartLine(),
                );

                continue;
            }

            $definition = $literal['value'];

            if (is_array($definition) && ($definition['__controller_middleware'] ?? false) === true) {
                if ($this->methodExcluded($controllerMethod, $definition['only'], $definition['except'])) {
                    continue;
                }

                $this->flattenMiddlewareValue($definition['middleware'], $middleware);

                continue;
            }

            $this->flattenMiddlewareValue($definition, $middleware);
        }

        return $middleware;
    }

    private function methodAst(ReflectionMethod $method): ?Stmt\ClassMethod
    {
        $file = $method->getFileName();

        if (! is_string($file) || $file === '' || ! is_file($file)) {
            return null;
        }

        try {
            $statements = $this->phpFileFacts->statements($file);
            $finder = new NodeFinder();

            /** @var Stmt\ClassMethod|null $astMethod */
            $astMethod = $finder->findFirst(
                $statements,
                static fn (Node $node): bool => $node instanceof Stmt\ClassMethod
                    && $node->name->toString() === $method->getName()
                    && $node->getStartLine() === $method->getStartLine()
                    && $node->getEndLine() === $method->getEndLine(),
            );

            return $astMethod;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{ok: bool, value?: mixed}
     */
    private function controllerMiddlewareLiteral(
        Expr $expression,
        ReflectionClass $controller,
        ReflectionClass $scope,
    ): array
    {
        if ($expression instanceof Expr\New_) {
            return $this->controllerMiddlewareObjectLiteral($expression, $controller, $scope);
        }

        return $this->literalValue($expression, $controller, $scope);
    }

    /**
     * @return array{ok: bool, value?: mixed}
     */
    private function controllerMiddlewareObjectLiteral(
        Expr\New_ $expression,
        ReflectionClass $controller,
        ReflectionClass $scope,
    ): array
    {
        if (! $expression->class instanceof Name
            || $this->resolvedName($expression->class, $controller, $scope) !== ControllerMiddleware::class) {
            return ['ok' => false];
        }

        $arguments = [];

        foreach ($expression->args as $position => $argument) {
            if (! $argument instanceof Arg || $argument->unpack) {
                return ['ok' => false];
            }

            $name = $argument->name?->toString() ?? match ($position) {
                0 => 'middleware',
                1 => 'only',
                2 => 'except',
                default => null,
            };

            if ($name === null || array_key_exists($name, $arguments)) {
                return ['ok' => false];
            }

            $literal = $this->literalValue($argument->value, $controller, $scope);

            if (! $literal['ok']) {
                return ['ok' => false];
            }

            $arguments[$name] = $literal['value'];
        }

        $middleware = $arguments['middleware'] ?? null;
        $only = $arguments['only'] ?? null;
        $except = $arguments['except'] ?? null;

        if ((! is_string($middleware) && ! is_array($middleware))
            || (! is_array($only) && $only !== null)
            || (! is_array($except) && $except !== null)) {
            return ['ok' => false];
        }

        return [
            'ok' => true,
            'value' => [
                '__controller_middleware' => true,
                'middleware' => $middleware,
                'only' => $only,
                'except' => $except,
            ],
        ];
    }

    /**
     * @return array{ok: bool, value?: mixed}
     */
    private function literalValue(
        Expr $expression,
        ReflectionClass $controller,
        ReflectionClass $scope,
    ): array
    {
        if ($expression instanceof Scalar\String_) {
            return ['ok' => true, 'value' => $expression->value];
        }

        if ($expression instanceof Expr\ClassConstFetch
            && $expression->class instanceof Name
            && $expression->name instanceof Node\Identifier
            && strtolower($expression->name->toString()) === 'class') {
            return ['ok' => true, 'value' => $this->resolvedName($expression->class, $controller, $scope)];
        }

        if ($expression instanceof Expr\ConstFetch
            && strtolower($expression->name->toString()) === 'null') {
            return ['ok' => true, 'value' => null];
        }

        if ($expression instanceof Expr\Array_) {
            $values = [];

            foreach ($expression->items as $item) {
                if ($item === null || $item->unpack) {
                    return ['ok' => false];
                }

                $literal = $this->literalValue($item->value, $controller, $scope);

                if (! $literal['ok']) {
                    return ['ok' => false];
                }

                $values[] = $literal['value'];
            }

            return ['ok' => true, 'value' => $values];
        }

        return ['ok' => false];
    }

    private function resolvedName(Name $name, ReflectionClass $controller, ReflectionClass $scope): string
    {
        $raw = strtolower($name->toString());

        if ($raw === 'static') {
            return $controller->getName();
        }

        if ($raw === 'self') {
            return $scope->getName();
        }

        if ($raw === 'parent') {
            return $scope->getParentClass()?->getName() ?? $name->toString();
        }

        $resolved = $name->getAttribute('resolvedName');

        return $resolved instanceof Name ? $resolved->toString() : $name->toString();
    }

    /**
     * @param array<int, mixed> $middleware
     */
    private function flattenMiddlewareValue(mixed $value, array &$middleware): void
    {
        if (is_array($value)) {
            foreach ($value as $nested) {
                $this->flattenMiddlewareValue($nested, $middleware);
            }

            return;
        }

        if (is_string($value)) {
            $middleware[] = $value;
        }
    }

    /**
     * Read only the exact framework middleware attribute. Calling
     * ReflectionAttribute::newInstance() could execute a custom constructor.
     *
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, string>
     */
    private function attributeControllerMiddleware(
        ReflectionClass $controller,
        string $controllerMethod,
        array &$diagnostics,
    ): array {
        if (! class_exists(MiddlewareAttribute::class)) {
            return [];
        }

        $attributes = [];
        $hierarchy = [];
        $current = $controller;

        do {
            array_unshift($hierarchy, $current);
        } while ($current = $current->getParentClass());

        foreach ($hierarchy as $class) {
            array_push($attributes, ...$class->getAttributes(MiddlewareAttribute::class));
        }

        try {
            if ($controller->hasMethod($controllerMethod)) {
                array_push(
                    $attributes,
                    ...$controller->getMethod($controllerMethod)->getAttributes(MiddlewareAttribute::class),
                );
            }
        } catch (ReflectionException) {
            // The route scanner will separately report an unresolved action.
        }

        $middleware = [];

        foreach ($attributes as $attribute) {
            $definition = $this->attributeDefinition($attribute);

            if ($definition === null) {
                $diagnostics[] = $this->controllerDiagnostic(
                    'controller_middleware_attribute_unsupported',
                    $controller->getName(),
                    $controllerMethod,
                    'An exact Laravel controller middleware attribute had arguments that could not be represented without constructing it.',
                );

                continue;
            }

            if ($this->methodExcluded($controllerMethod, $definition['only'], $definition['except'])) {
                continue;
            }

            $middleware[] = $definition['middleware'];
        }

        return $middleware;
    }

    /**
     * @return array{middleware: string, only: array<int, mixed>|null, except: array<int, mixed>|null}|null
     */
    private function attributeDefinition(ReflectionAttribute $attribute): ?array
    {
        $arguments = $attribute->getArguments();
        $middleware = $arguments['middleware'] ?? $arguments[0] ?? null;
        $only = $arguments['only'] ?? $arguments[1] ?? null;
        $except = $arguments['except'] ?? $arguments[2] ?? null;

        if (! is_string($middleware)
            || (! is_array($only) && $only !== null)
            || (! is_array($except) && $except !== null)) {
            return null;
        }

        return compact('middleware', 'only', 'except');
    }

    /**
     * @param array<int, mixed>|null $only
     * @param array<int, mixed>|null $except
     */
    private function methodExcluded(string $method, ?array $only, ?array $except): bool
    {
        return ($only !== null && ! in_array($method, $only, true))
            || ($except !== null && $except !== [] && in_array($method, $except, true));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function controllerDiagnostic(
        string $reason,
        string $controller,
        string $method,
        string $message,
        array $extra = [],
    ): array {
        return [
            'reason' => $reason,
            'scope' => 'controller',
            'controller' => $controller,
            'method' => $method,
            'message' => $message,
            ...$extra,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unsupportedLiteralDiagnostic(
        string $controller,
        string $method,
        ?int $line,
    ): array {
        return $this->controllerDiagnostic(
            'controller_middleware_literal_unsupported',
            $controller,
            $method,
            'A controller middleware declaration was omitted because it is not a supported string, class constant, literal array, or literal Laravel Middleware object.',
            ['line' => $line],
        );
    }

    /**
     * @param array<int, array{scope: string, value: mixed}> $declarations
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function provenanceCandidates(Router $router, array $declarations, array &$diagnostics): array
    {
        $aliases = method_exists($router, 'getMiddleware') ? $router->getMiddleware() : [];
        $groups = method_exists($router, 'getMiddlewareGroups') ? $router->getMiddlewareGroups() : [];
        $candidates = [];

        foreach ($declarations as $position => $declaration) {
            $value = $this->stringableValue($declaration['value']);
            $base = [
                'scope' => $declaration['scope'],
                'declared' => $this->displayValue($value),
                'declared_alias' => is_string($value) ? $this->middlewareName($value) : null,
                'declared_position' => $position,
                'groups' => [],
            ];

            $this->expandDeclaration(
                $value,
                $base,
                $aliases,
                $groups,
                $candidates,
                $diagnostics,
            );
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $aliases
     * @param array<string, array<int, mixed>> $groups
     * @param array<int, array<string, mixed>> $candidates
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function expandDeclaration(
        mixed $value,
        array $base,
        array $aliases,
        array $groups,
        array &$candidates,
        array &$diagnostics,
    ): void {
        $value = $this->stringableValue($value);

        if ($value instanceof Closure) {
            $candidates[] = $base + [
                'expanded' => 'closure',
                'expanded_alias' => null,
                'value' => $value,
            ];

            return;
        }

        if (! is_string($value)) {
            $diagnostics[] = [
                'reason' => 'unsupported_middleware_declaration',
                'scope' => $base['scope'],
                'declared' => $base['declared'],
                'message' => 'Middleware declarations must already be strings or Closures; graph scanning does not invoke arbitrary Stringable objects.',
            ];

            return;
        }

        if (isset($aliases[$value]) && $aliases[$value] instanceof Closure) {
            $candidates[] = $base + [
                'expanded' => $value,
                'expanded_alias' => $value,
                'value' => $aliases[$value],
            ];

            return;
        }

        if (isset($groups[$value])) {
            if (in_array($value, $base['groups'], true)) {
                $diagnostics[] = [
                    'reason' => 'recursive_middleware_group',
                    'scope' => $base['scope'],
                    'declared' => $base['declared'],
                    'group' => $value,
                    'message' => "Middleware group [{$value}] recursively references itself.",
                ];

                return;
            }

            foreach ($groups[$value] as $member) {
                $this->expandDeclaration(
                    $member,
                    [...$base, 'groups' => [...$base['groups'], $value]],
                    $aliases,
                    $groups,
                    $candidates,
                    $diagnostics,
                );
            }

            return;
        }

        [$name, $parameters] = array_pad(explode(':', $value, 2), 2, null);
        $resolved = $aliases[$name] ?? $name;

        if ($resolved instanceof Closure) {
            $candidates[] = $base + [
                'expanded' => $value,
                'expanded_alias' => $name,
                'value' => $resolved,
            ];

            return;
        }

        if (! is_string($resolved)) {
            $diagnostics[] = [
                'reason' => 'unsupported_middleware_alias',
                'scope' => $base['scope'],
                'declared' => $base['declared'],
                'alias' => $name,
                'message' => "Middleware alias [{$name}] did not resolve to a class string or Closure.",
            ];

            return;
        }

        $candidates[] = $base + [
            'expanded' => $value,
            'expanded_alias' => $name,
            'value' => $resolved.($parameters !== null ? ':'.$parameters : ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvedDescriptor(mixed $middleware, int $position): array
    {
        if ($middleware instanceof Closure) {
            return [
                'position' => $position,
                'kind' => 'closure',
                'resolved' => 'closure',
                'resolved_alias' => null,
                'resolved_class' => null,
                'parameters' => [],
            ];
        }

        $middleware = $this->stringableValue($middleware);

        if (! is_string($middleware)) {
            return [
                'position' => $position,
                'kind' => 'unsupported',
                'resolved' => get_debug_type($middleware),
                'resolved_alias' => null,
                'resolved_class' => null,
                'parameters' => [],
            ];
        }

        [$class, $parameters] = array_pad(explode(':', $middleware, 2), 2, null);

        return [
            'position' => $position,
            'kind' => 'class',
            'resolved' => $middleware,
            'resolved_alias' => $class,
            'resolved_class' => $class,
            'parameters' => $parameters === null ? [] : explode(',', $parameters),
        ];
    }

    private function middlewareKey(mixed $middleware): string
    {
        return match (true) {
            is_object($middleware) => 'object:'.spl_object_id($middleware),
            is_array($middleware) => 'array:'.hash('sha256', json_encode(
                $this->safeIdentityValue($middleware),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            )),
            is_string($middleware) => 'string:'.$middleware,
            is_int($middleware), is_float($middleware), is_bool($middleware), $middleware === null =>
                get_debug_type($middleware).':'.json_encode($middleware, JSON_THROW_ON_ERROR),
            default => get_debug_type($middleware),
        };
    }

    private function stringableValue(mixed $value): mixed
    {
        return $value;
    }

    private function safeIdentityValue(mixed $value): mixed
    {
        if (is_object($value)) {
            return ['object' => $value::class, 'id' => spl_object_id($value)];
        }

        if (! is_array($value)) {
            return is_resource($value) ? ['resource' => get_resource_type($value)] : $value;
        }

        $identity = [];

        foreach ($value as $key => $nested) {
            $identity[(string) $key] = $this->safeIdentityValue($nested);
        }

        return $identity;
    }

    private function displayValue(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            $value instanceof Closure => 'closure',
            default => null,
        };
    }

    private function middlewareName(string $middleware): string
    {
        return explode(':', $middleware, 2)[0];
    }
}
