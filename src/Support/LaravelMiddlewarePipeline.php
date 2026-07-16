<?php

namespace AppGraph\Support;

use Closure;
use Composer\Autoload\ClassLoader;
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
    private const SOURCE_MAX_ANCESTRY_DEPTH = 64;

    private const SOURCE_MAX_DECLARATIONS = 256;

    private const SOURCE_MAX_FILES = 64;

    private const SOURCE_MAX_SYMBOLS = 512;

    private const SOURCE_MAX_LITERAL_DEPTH = 32;

    private PhpFileFacts $phpFileFacts;

    private FileFinder $files;

    public function __construct(
        private ContainerBindingRegistry $containerBindings,
        ?PhpFileFacts $phpFileFacts = null,
        ?FileFinder $files = null,
    ) {
        $this->phpFileFacts = $phpFileFacts ?? new PhpFileFacts();
        $this->files = $files ?? new FileFinder();
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
            $sourceMiddleware = $this->sourceControllerMiddleware(
                $controllerClass,
                $controllerMethod,
                $diagnostics,
            );

            if ($sourceMiddleware !== null) {
                return $sourceMiddleware;
            }

            $diagnostics[] = $this->controllerDiagnostic(
                'controller_middleware_class_unloaded',
                $controllerClass,
                $controllerMethod,
                'Controller middleware source was not Composer-discoverable inside the project and the controller was not autoloaded.',
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
     * Recover modern controller middleware from current project source without
     * loading the controller file or invoking HasMiddleware::middleware().
     *
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, mixed>|null
     */
    private function sourceControllerMiddleware(
        string $controllerClass,
        string $controllerMethod,
        array &$diagnostics,
    ): ?array {
        $index = [
            'classes' => [],
            'traits' => [],
            'interfaces' => [],
            'classNames' => [],
            'traitNames' => [],
            'interfaceNames' => [],
            'files' => [],
            'discovered' => [],
            'parseErrors' => [],
            'limit' => null,
            'symbols' => 0,
        ];

        $record = $this->sourceSymbolRecord($controllerClass, false, $index);

        if ($record === null) {
            $lookupKey = strtolower(ltrim($controllerClass, '\\'));

            if (isset($index['discovered'][$lookupKey])) {
                $error = $index['parseErrors'][$lookupKey] ?? null;
                $diagnostics[] = $this->controllerDiagnostic(
                    'controller_middleware_source_unavailable',
                    $controllerClass,
                    $controllerMethod,
                    'Composer found current project controller source, but it could not be indexed safely.',
                    array_filter([
                        'detail' => is_string($error) ? substr($error, 0, 512) : null,
                    ]),
                );

                return [];
            }

            return null;
        }

        $controllerClass = $index['classNames'][strtolower(ltrim($controllerClass, '\\'))]
            ?? ltrim($controllerClass, '\\');
        $middleware = [];
        $interfaceVisited = [];
        $implementsModernContract = $this->sourceClassImplements(
            $controllerClass,
            HasMiddleware::class,
            $index,
            $interfaceVisited,
        );

        if ($implementsModernContract === true) {
            $methodVisited = [];
            $method = $this->sourceControllerMethod(
                $controllerClass,
                'middleware',
                $controllerClass,
                $index,
                $methodVisited,
            );

            if (($method['status'] ?? null) !== 'found') {
                $diagnostics[] = $this->controllerDiagnostic(
                    ($method['status'] ?? null) === 'missing'
                        ? 'controller_middleware_method_not_found'
                        : 'controller_middleware_source_unavailable',
                    $controllerClass,
                    $controllerMethod,
                    ($method['status'] ?? null) === 'missing'
                        ? 'The controller implements HasMiddleware but current source has no middleware() method.'
                        : 'The composed middleware() source crosses an unindexed boundary, so its declarations were omitted.',
                );
            } elseif (($method['visibility'] ?? null) !== 'public' || ! $method['node']->isStatic()) {
                $diagnostics[] = $this->controllerDiagnostic(
                    'controller_middleware_method_invalid',
                    $controllerClass,
                    $controllerMethod,
                    'HasMiddleware::middleware() is not a public static method, so Laravel cannot invoke it safely.',
                );
            } else {
                $middleware = $this->sourceStaticControllerMiddleware(
                    $method,
                    $controllerClass,
                    $controllerMethod,
                    $index,
                    $diagnostics,
                );
            }
        } elseif ($implementsModernContract === null) {
            $diagnostics[] = $this->controllerDiagnostic(
                'controller_middleware_ancestry_unavailable',
                $controllerClass,
                $controllerMethod,
                'Controller ancestry crosses unindexed source, so modern static middleware declarations could not be proven.',
            );
        } else {
            $legacyVisited = [];
            $legacy = $this->sourceClassExtends(
                $controllerClass,
                Controller::class,
                $index,
                $legacyVisited,
            );

            if ($legacy === true) {
                $diagnostics[] = $this->controllerDiagnostic(
                    'legacy_controller_middleware_omitted',
                    $controllerClass,
                    $controllerMethod,
                    'Legacy controller middleware requires a controller instance and was omitted because graph scans never construct controllers.',
                );
            }
        }

        $middleware = [
            ...$middleware,
            ...$this->sourceAttributeControllerMiddleware(
                $controllerClass,
                $controllerMethod,
                $index,
                $diagnostics,
            ),
        ];

        if (is_string($index['limit'] ?? null)) {
            $diagnostics[] = $this->controllerDiagnostic(
                'controller_middleware_source_limit',
                $controllerClass,
                $controllerMethod,
                'Controller middleware source recovery reached a deterministic inspection limit; additional declarations were omitted.',
                [
                    'limit' => $index['limit'],
                    'maxFiles' => self::SOURCE_MAX_FILES,
                    'maxSymbols' => self::SOURCE_MAX_SYMBOLS,
                    'maxAncestryDepth' => self::SOURCE_MAX_ANCESTRY_DEPTH,
                ],
            );
        }

        if (count($middleware) > self::SOURCE_MAX_DECLARATIONS) {
            $middleware = array_slice($middleware, 0, self::SOURCE_MAX_DECLARATIONS);
            $diagnostics[] = $this->controllerDiagnostic(
                'controller_middleware_declaration_limit',
                $controllerClass,
                $controllerMethod,
                'Controller middleware recovery reached its deterministic declaration limit; remaining declarations were omitted.',
                ['limit' => self::SOURCE_MAX_DECLARATIONS],
            );
        }

        return $middleware;
    }

    /**
     * @param array<string, mixed> $index
     * @return array<string, mixed>|null
     */
    private function sourceSymbolRecord(string $symbol, bool $trait, array &$index): ?array
    {
        $symbol = ltrim($symbol, '\\');
        $nameMap = $trait ? 'traitNames' : 'classNames';
        $records = $trait ? 'traits' : 'classes';
        $canonical = $index[$nameMap][strtolower($symbol)] ?? null;

        if (is_string($canonical)) {
            return $index[$records][$canonical];
        }

        $file = $this->sourceSymbolFile($symbol);

        if ($file === null) {
            return null;
        }

        $lookupKey = strtolower($symbol);
        $index['discovered'][$lookupKey] = true;

        if (! isset($index['files'][$file])) {
            if (count($index['files']) >= self::SOURCE_MAX_FILES) {
                $index['limit'] ??= 'files';

                return null;
            }

            $index['files'][$file] = true;

            try {
                $statements = $this->phpFileFacts->statements($file);
            } catch (Throwable $throwable) {
                $index['parseErrors'][$lookupKey] = $throwable->getMessage();

                return null;
            }

            $finder = new NodeFinder();

            foreach ($finder->findInstanceOf($statements, Stmt\Class_::class) as $classNode) {
                if ($index['symbols'] >= self::SOURCE_MAX_SYMBOLS) {
                    $index['limit'] ??= 'symbols';
                    break;
                }

                $className = $this->sourceStatementName($classNode);

                if ($className === null) {
                    continue;
                }

                $index['classes'][$className] = [
                    'node' => $classNode,
                    'extends' => $this->sourceAstName($classNode->extends),
                    'implements' => array_values(array_filter(array_map(
                        fn (Name $name): ?string => $this->sourceAstName($name),
                        $classNode->implements,
                    ))),
                    'traitUses' => $this->sourceUsedTraits($classNode),
                ];
                $index['classNames'][strtolower($className)] = $className;
                $index['symbols']++;
            }

            foreach ($finder->findInstanceOf($statements, Stmt\Trait_::class) as $traitNode) {
                if ($index['symbols'] >= self::SOURCE_MAX_SYMBOLS) {
                    $index['limit'] ??= 'symbols';
                    break;
                }

                $traitName = $this->sourceStatementName($traitNode);

                if ($traitName === null) {
                    continue;
                }

                $index['traits'][$traitName] = [
                    'node' => $traitNode,
                    'traitUses' => $this->sourceUsedTraits($traitNode),
                ];
                $index['traitNames'][strtolower($traitName)] = $traitName;
                $index['symbols']++;
            }

            foreach ($finder->findInstanceOf($statements, Stmt\Interface_::class) as $interfaceNode) {
                if ($index['symbols'] >= self::SOURCE_MAX_SYMBOLS) {
                    $index['limit'] ??= 'symbols';
                    break;
                }

                $interfaceName = $this->sourceStatementName($interfaceNode);

                if ($interfaceName === null) {
                    continue;
                }

                $index['interfaces'][$interfaceName] = [
                    'node' => $interfaceNode,
                    'extends' => array_values(array_filter(array_map(
                        fn (Name $name): ?string => $this->sourceAstName($name),
                        $interfaceNode->extends,
                    ))),
                ];
                $index['interfaceNames'][strtolower($interfaceName)] = $interfaceName;
                $index['symbols']++;
            }
        }

        $canonical = $index[$nameMap][strtolower($symbol)] ?? null;

        return is_string($canonical) ? $index[$records][$canonical] : null;
    }

    private function sourceSymbolFile(string $symbol): ?string
    {
        foreach (array_slice(spl_autoload_functions(), 0, self::SOURCE_MAX_FILES) as $autoload) {
            if (! is_array($autoload) || ! ($autoload[0] ?? null) instanceof ClassLoader) {
                continue;
            }

            try {
                $file = $autoload[0]->findFile($symbol);
            } catch (Throwable) {
                continue;
            }

            if (! is_string($file) || $file === '' || ! is_file($file)) {
                continue;
            }

            $file = str_replace('\\', '/', realpath($file) ?: $file);
            $relative = $this->files->relativePath($file);

            if (is_string($relative)
                && ! str_starts_with($relative, '/')
                && preg_match('/^(?:app|routes|database\/migrations|tests)\//D', $relative) === 1) {
                return $file;
            }
        }

        return null;
    }

    private function sourceStatementName(Stmt\Class_|Stmt\Trait_|Stmt\Interface_ $statement): ?string
    {
        $name = $statement->namespacedName ?? null;

        return $name instanceof Name ? $name->toString() : $statement->name?->toString();
    }

    private function sourceAstName(?Name $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $resolved = $name->getAttribute('resolvedName');

        return $resolved instanceof Name ? $resolved->toString() : ltrim($name->toString(), '\\');
    }

    /**
     * @return array<int, array{
     *     traits: array<int, string>,
     *     precedences: array<int, array{trait: string|null, method: string, insteadOf: array<int, string>}>,
     *     aliases: array<int, array{trait: string|null, method: string, alias: string|null, visibility: string|null}>
     * }>
     */
    private function sourceUsedTraits(Stmt\Class_|Stmt\Trait_ $statement): array
    {
        $uses = [];

        foreach ($statement->stmts as $member) {
            if (! $member instanceof Stmt\TraitUse) {
                continue;
            }

            $use = [
                'traits' => array_values(array_filter(array_map(
                    fn (Name $name): ?string => $this->sourceAstName($name),
                    $member->traits,
                ))),
                'precedences' => [],
                'aliases' => [],
            ];

            foreach ($member->adaptations as $adaptation) {
                if ($adaptation instanceof Stmt\TraitUseAdaptation\Precedence) {
                    $use['precedences'][] = [
                        'trait' => $this->sourceAstName($adaptation->trait),
                        'method' => $adaptation->method->toString(),
                        'insteadOf' => array_values(array_filter(array_map(
                            fn (Name $name): ?string => $this->sourceAstName($name),
                            $adaptation->insteadof,
                        ))),
                    ];

                    continue;
                }

                if ($adaptation instanceof Stmt\TraitUseAdaptation\Alias) {
                    $use['aliases'][] = [
                        'trait' => $this->sourceAstName($adaptation->trait),
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

    /**
     * @param array<string, mixed> $index
     * @param array<string, true> $visited
     */
    private function sourceClassImplements(
        string $class,
        string $interface,
        array &$index,
        array &$visited,
    ): ?bool {
        $class = $index['classNames'][strtolower(ltrim($class, '\\'))] ?? ltrim($class, '\\');

        if (strcasecmp($class, ltrim(Controller::class, '\\')) === 0
            && strcasecmp(ltrim($interface, '\\'), ltrim(HasMiddleware::class, '\\')) === 0) {
            return false;
        }

        $visitKey = strtolower($class);

        if (isset($visited[$visitKey])) {
            return null;
        }

        if (count($visited) >= self::SOURCE_MAX_ANCESTRY_DEPTH) {
            $index['limit'] ??= 'ancestry';

            return null;
        }

        $visited[$visitKey] = true;
        $record = $this->sourceSymbolRecord($class, false, $index);

        if ($record === null) {
            if (! class_exists($class, false)) {
                return null;
            }

            try {
                return (new ReflectionClass($class))->implementsInterface($interface);
            } catch (Throwable) {
                return null;
            }
        }

        $interfaceUnknown = false;

        foreach ($record['implements'] as $implemented) {
            if (strcasecmp(ltrim($implemented, '\\'), ltrim($interface, '\\')) === 0) {
                return true;
            }

            $interfaceVisited = [];
            $extends = $this->sourceInterfaceExtends(
                $implemented,
                $interface,
                $index,
                $interfaceVisited,
            );

            if ($extends === true) {
                return true;
            }

            $interfaceUnknown = $interfaceUnknown || $extends === null;
        }

        $parent = $record['extends'];
        $parentState = is_string($parent)
            ? $this->sourceClassImplements($parent, $interface, $index, $visited)
            : false;

        if ($parentState === true) {
            return true;
        }

        return $interfaceUnknown || $parentState === null ? null : false;
    }

    /**
     * @param array<string, mixed> $index
     * @param array<string, true> $visited
     */
    private function sourceInterfaceExtends(
        string $interface,
        string $base,
        array &$index,
        array &$visited,
    ): ?bool {
        $interface = ltrim($interface, '\\');

        if (strcasecmp($interface, ltrim($base, '\\')) === 0) {
            return true;
        }

        $interface = $index['interfaceNames'][strtolower($interface)] ?? $interface;
        $visitKey = strtolower($interface);

        if (isset($visited[$visitKey])) {
            return null;
        }

        if (count($visited) >= self::SOURCE_MAX_ANCESTRY_DEPTH) {
            $index['limit'] ??= 'ancestry';

            return null;
        }

        $visited[$visitKey] = true;
        $record = $this->sourceInterfaceRecord($interface, $index);

        if ($record === null) {
            if (! interface_exists($interface, false)) {
                return null;
            }

            try {
                return (new ReflectionClass($interface))->isSubclassOf($base);
            } catch (Throwable) {
                return null;
            }
        }

        $unknown = false;

        foreach ($record['extends'] as $parent) {
            $branchVisited = $visited;
            $state = $this->sourceInterfaceExtends($parent, $base, $index, $branchVisited);

            if ($state === true) {
                return true;
            }

            $unknown = $unknown || $state === null;
        }

        return $unknown ? null : false;
    }

    /**
     * @param array<string, mixed> $index
     * @return array<string, mixed>|null
     */
    private function sourceInterfaceRecord(string $interface, array &$index): ?array
    {
        $interface = ltrim($interface, '\\');
        $canonical = $index['interfaceNames'][strtolower($interface)] ?? null;

        if (is_string($canonical)) {
            return $index['interfaces'][$canonical];
        }

        $file = $this->sourceSymbolFile($interface);

        if ($file === null) {
            return null;
        }

        if (! isset($index['files'][$file])) {
            // Index every declaration in this source file through the existing
            // loader, regardless of which symbol led us to the file.
            $this->sourceSymbolRecord($interface, false, $index);
        }

        $canonical = $index['interfaceNames'][strtolower($interface)] ?? null;

        return is_string($canonical) ? $index['interfaces'][$canonical] : null;
    }

    /**
     * @param array<string, mixed> $index
     * @param array<string, true> $visited
     */
    private function sourceClassExtends(
        string $class,
        string $base,
        array &$index,
        array &$visited,
    ): ?bool {
        if (strcasecmp(ltrim($class, '\\'), ltrim($base, '\\')) === 0) {
            return true;
        }

        $class = $index['classNames'][strtolower(ltrim($class, '\\'))] ?? ltrim($class, '\\');
        $visitKey = strtolower($class);

        if (isset($visited[$visitKey])) {
            return null;
        }

        if (count($visited) >= self::SOURCE_MAX_ANCESTRY_DEPTH) {
            $index['limit'] ??= 'ancestry';

            return null;
        }

        $visited[$visitKey] = true;
        $record = $this->sourceSymbolRecord($class, false, $index);

        if ($record === null) {
            if (! class_exists($class, false)) {
                return null;
            }

            try {
                $reflection = new ReflectionClass($class);

                return $reflection->getName() === $base || $reflection->isSubclassOf($base);
            } catch (Throwable) {
                return null;
            }
        }

        $parent = $record['extends'];

        return is_string($parent)
            ? $this->sourceClassExtends($parent, $base, $index, $visited)
            : false;
    }

    /**
     * @param array<string, mixed> $index
     * @param array<string, true> $visited
     * @return array<string, mixed>
     */
    private function sourceControllerMethod(
        string $class,
        string $method,
        string $runtimeClass,
        array &$index,
        array &$visited,
    ): array {
        $class = $index['classNames'][strtolower(ltrim($class, '\\'))] ?? ltrim($class, '\\');

        if ($this->sameSourcePhpName($class, Controller::class)) {
            return ['status' => 'missing'];
        }

        $visitKey = 'class:'.strtolower($class);

        if (isset($visited[$visitKey])) {
            return ['status' => 'unavailable'];
        }

        if (count($visited) >= self::SOURCE_MAX_ANCESTRY_DEPTH) {
            $index['limit'] ??= 'ancestry';

            return ['status' => 'unavailable'];
        }

        $visited[$visitKey] = true;
        $record = $this->sourceSymbolRecord($class, false, $index);

        if ($record === null) {
            return ['status' => 'unavailable'];
        }

        foreach ($record['node']->getMethods() as $candidate) {
            if (strcasecmp($candidate->name->toString(), $method) === 0) {
                return [
                    'status' => 'found',
                    'node' => $candidate,
                    'declaring' => $class,
                    'resolutionClass' => $class,
                    'runtimeClass' => $runtimeClass,
                    'visibility' => $this->sourceMethodVisibility($candidate),
                ];
            }
        }

        $traitState = $this->sourceControllerTraitUsesMethod(
            $record['traitUses'],
            $method,
            $class,
            $runtimeClass,
            $index,
            $visited,
        );

        if (($traitState['status'] ?? null) !== 'missing') {
            return $traitState;
        }

        $parent = $record['extends'];

        return is_string($parent)
            ? $this->sourceControllerMethod($parent, $method, $runtimeClass, $index, $visited)
            : ['status' => 'missing'];
    }

    /**
     * @param array<string, mixed> $index
     * @param array<string, true> $visited
     * @return array<string, mixed>
     */
    private function sourceTraitControllerMethod(
        string $trait,
        string $method,
        string $resolutionClass,
        string $runtimeClass,
        array &$index,
        array &$visited,
    ): array {
        $trait = $index['traitNames'][strtolower(ltrim($trait, '\\'))] ?? ltrim($trait, '\\');
        $visitKey = 'trait:'.strtolower($trait);

        if (isset($visited[$visitKey])) {
            return ['status' => 'unavailable'];
        }

        if (count($visited) >= self::SOURCE_MAX_ANCESTRY_DEPTH) {
            $index['limit'] ??= 'ancestry';

            return ['status' => 'unavailable'];
        }

        $visited[$visitKey] = true;
        $record = $this->sourceSymbolRecord($trait, true, $index);

        if ($record === null) {
            return ['status' => 'unavailable'];
        }

        foreach ($record['node']->getMethods() as $candidate) {
            if (strcasecmp($candidate->name->toString(), $method) === 0) {
                return [
                    'status' => 'found',
                    'node' => $candidate,
                    'declaring' => $trait,
                    'resolutionClass' => $resolutionClass,
                    'runtimeClass' => $runtimeClass,
                    'visibility' => $this->sourceMethodVisibility($candidate),
                ];
            }
        }

        return $this->sourceControllerTraitUsesMethod(
            $record['traitUses'],
            $method,
            $resolutionClass,
            $runtimeClass,
            $index,
            $visited,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $uses
     * @param array<string, mixed> $index
     * @param array<string, true> $visited
     * @return array<string, mixed>
     */
    private function sourceControllerTraitUsesMethod(
        array $uses,
        string $method,
        string $resolutionClass,
        string $runtimeClass,
        array &$index,
        array &$visited,
    ): array {
        $candidates = [];
        $unavailable = [];
        $invalid = false;

        foreach ($uses as $use) {
            [$groupCandidates, $groupUnavailable, $groupInvalid] = $this->sourceControllerTraitUseCandidates(
                $use,
                $method,
                $resolutionClass,
                $runtimeClass,
                $index,
                $visited,
            );
            array_push($candidates, ...$groupCandidates);
            array_push($unavailable, ...$groupUnavailable);
            $invalid = $invalid || $groupInvalid;
        }

        if ($unavailable !== []) {
            return ['status' => 'unavailable'];
        }

        $candidates = $this->uniqueSourceControllerTraitCandidates($candidates);

        if ($invalid || count($candidates) > 1) {
            return ['status' => 'unavailable'];
        }

        return $candidates[0] ?? ['status' => 'missing'];
    }

    /**
     * @param array<string, mixed> $use
     * @param array<string, mixed> $index
     * @param array<string, true> $visited
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: bool}
     */
    private function sourceControllerTraitUseCandidates(
        array $use,
        string $method,
        string $resolutionClass,
        string $runtimeClass,
        array &$index,
        array &$visited,
    ): array {
        [$candidates, $unavailable, $invalid] = $this->sourceControllerTraitCandidatesForOriginal(
            $use,
            $method,
            $resolutionClass,
            $runtimeClass,
            $index,
            $visited,
        );

        foreach ($use['aliases'] ?? [] as $alias) {
            if (! $this->sameSourcePhpName($alias['alias'] ?? null, $method)) {
                continue;
            }

            $qualifiedTrait = is_string($alias['trait'] ?? null) ? $alias['trait'] : null;

            [$aliasCandidates, $aliasUnavailable, $aliasInvalid] = $this->sourceControllerTraitCandidatesForOriginal(
                $use,
                (string) ($alias['method'] ?? ''),
                $resolutionClass,
                $runtimeClass,
                $index,
                $visited,
                $qualifiedTrait,
                $qualifiedTrait === null,
            );

            foreach ($aliasCandidates as &$candidate) {
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
        // A renamed alias changes only the additional alias.
        foreach ($use['aliases'] ?? [] as $alias) {
            if (($alias['alias'] ?? null) !== null
                || ! is_string($alias['visibility'] ?? null)
                || ! $this->sameSourcePhpName($alias['method'] ?? null, $method)) {
                continue;
            }

            foreach ($candidates as &$candidate) {
                if (($alias['trait'] ?? null) === null
                    || $this->sameSourcePhpName($candidate['topTrait'] ?? null, $alias['trait'])) {
                    $candidate['visibility'] = $alias['visibility'];
                }
            }
            unset($candidate);
        }

        return [$candidates, $unavailable, $invalid];
    }

    /**
     * @param array<string, mixed> $use
     * @param array<string, mixed> $index
     * @param array<string, true> $visited
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: bool}
     */
    private function sourceControllerTraitCandidatesForOriginal(
        array $use,
        string $method,
        string $resolutionClass,
        string $runtimeClass,
        array &$index,
        array &$visited,
        ?string $onlyTrait = null,
        bool $applyPrecedence = true,
    ): array {
        $candidates = [];
        $unavailable = [];
        $invalid = false;

        foreach ($use['traits'] ?? [] as $trait) {
            if (! is_string($trait)
                || ($onlyTrait !== null && ! $this->sameSourcePhpName($trait, $onlyTrait))) {
                continue;
            }

            $topTrait = $index['traitNames'][strtolower(ltrim($trait, '\\'))] ?? ltrim($trait, '\\');
            $branchVisited = $visited;
            $state = $this->sourceTraitControllerMethod(
                $topTrait,
                $method,
                $resolutionClass,
                $runtimeClass,
                $index,
                $branchVisited,
            );
            $state['topTrait'] = $topTrait;

            if (($state['status'] ?? null) === 'found') {
                $candidates[] = $state;
            } elseif (($state['status'] ?? null) === 'unavailable') {
                $unavailable[] = $state;
            }
        }

        foreach ($applyPrecedence ? ($use['precedences'] ?? []) : [] as $precedence) {
            if (! $this->sameSourcePhpName($precedence['method'] ?? null, $method)) {
                continue;
            }

            $selected = $precedence['trait'] ?? null;

            if (is_string($selected) && ! (bool) array_filter(
                [...$candidates, ...$unavailable],
                fn (array $candidate): bool => $this->sameSourcePhpName(
                    $candidate['topTrait'] ?? null,
                    $selected,
                ),
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
                    fn (string $trait): bool => $this->sameSourcePhpName(
                        $candidate['topTrait'] ?? null,
                        $trait,
                    ),
                ),
            ));
            $unavailable = array_values(array_filter(
                $unavailable,
                fn (array $candidate): bool => ! (bool) array_filter(
                    $excluded,
                    fn (string $trait): bool => $this->sameSourcePhpName(
                        $candidate['topTrait'] ?? null,
                        $trait,
                    ),
                ),
            ));
        }

        return [$candidates, $unavailable, $invalid];
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function uniqueSourceControllerTraitCandidates(array $candidates): array
    {
        $unique = [];

        foreach ($candidates as $candidate) {
            $key = strtolower(implode('|', [
                $candidate['topTrait'] ?? '',
                $candidate['declaring'] ?? '',
                $candidate['node'] instanceof Stmt\ClassMethod
                    ? $candidate['node']->name->toString().':'.$candidate['node']->getStartLine()
                    : '',
                $candidate['visibility'] ?? '',
            ]));
            $unique[$key] = $candidate;
        }

        return array_values($unique);
    }

    private function sourceMethodVisibility(Stmt\ClassMethod $method): string
    {
        return $method->isPrivate()
            ? 'private'
            : ($method->isProtected() ? 'protected' : 'public');
    }

    private function sameSourcePhpName(mixed $left, mixed $right): bool
    {
        return is_string($left)
            && is_string($right)
            && strcasecmp(ltrim($left, '\\'), ltrim($right, '\\')) === 0;
    }

    /**
     * @param array<string, mixed> $method
     * @param array<string, mixed> $index
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, mixed>
     */
    private function sourceStaticControllerMiddleware(
        array $method,
        string $controllerClass,
        string $controllerMethod,
        array &$index,
        array &$diagnostics,
    ): array {
        $returns = array_values(array_filter(
            $method['node']->stmts ?? [],
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
        $flattenTruncated = false;
        $items = $returns[0]->expr->items;

        foreach (array_slice($items, 0, self::SOURCE_MAX_DECLARATIONS) as $item) {
            if ($item === null || $item->unpack) {
                $diagnostics[] = $this->unsupportedLiteralDiagnostic(
                    $controllerClass,
                    $controllerMethod,
                    $item?->getStartLine(),
                );

                continue;
            }

            $literal = $this->sourceControllerMiddlewareLiteral($item->value, $method, $index);

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

                $flattenTruncated = $this->flattenSourceMiddlewareValue(
                    $definition['middleware'],
                    $middleware,
                ) || $flattenTruncated;

                continue;
            }

            $flattenTruncated = $this->flattenSourceMiddlewareValue(
                $definition,
                $middleware,
            ) || $flattenTruncated;
        }

        if (count($items) > self::SOURCE_MAX_DECLARATIONS || $flattenTruncated) {
            $diagnostics[] = $this->controllerDiagnostic(
                'controller_middleware_declaration_limit',
                $controllerClass,
                $controllerMethod,
                'Controller middleware recovery reached its deterministic declaration limit; remaining declarations were omitted.',
                ['limit' => self::SOURCE_MAX_DECLARATIONS],
            );
        }

        return array_slice($middleware, 0, self::SOURCE_MAX_DECLARATIONS);
    }

    /** @param array<int, string> $middleware */
    private function flattenSourceMiddlewareValue(mixed $value, array &$middleware): bool
    {
        if (count($middleware) >= self::SOURCE_MAX_DECLARATIONS) {
            return true;
        }

        if (is_array($value)) {
            $position = 0;
            $count = count($value);

            foreach ($value as $nested) {
                $position++;

                if ($this->flattenSourceMiddlewareValue($nested, $middleware)) {
                    return true;
                }

                if (count($middleware) >= self::SOURCE_MAX_DECLARATIONS && $position < $count) {
                    return true;
                }
            }

            return false;
        }

        if (is_string($value)) {
            $middleware[] = $value;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $method
     * @param array<string, mixed> $index
     * @return array{ok: bool, value?: mixed}
     */
    private function sourceControllerMiddlewareLiteral(Expr $expression, array $method, array &$index): array
    {
        if (! $expression instanceof Expr\New_) {
            return $this->sourceLiteralValue($expression, $method, $index);
        }

        if (! $expression->class instanceof Name
            || ! $this->sameSourcePhpName(
                $this->sourceResolvedClassName($expression->class, $method, $index),
                ControllerMiddleware::class,
            )) {
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

            if ($name === null
                || ! in_array($name, ['middleware', 'only', 'except'], true)
                || array_key_exists($name, $arguments)) {
                return ['ok' => false];
            }

            $literal = $this->sourceLiteralValue($argument->value, $method, $index);

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
     * @param array<string, mixed> $context
     * @param array<string, mixed> $index
     * @return array{ok: bool, value?: mixed}
     */
    private function sourceLiteralValue(
        Expr $expression,
        array $context,
        array &$index,
        int $depth = 0,
    ): array
    {
        if ($depth >= self::SOURCE_MAX_LITERAL_DEPTH) {
            $index['limit'] ??= 'literalDepth';

            return ['ok' => false];
        }

        if ($expression instanceof Scalar\String_) {
            return ['ok' => true, 'value' => $expression->value];
        }

        if ($expression instanceof Expr\ClassConstFetch
            && $expression->class instanceof Name
            && $expression->name instanceof Node\Identifier
            && strtolower($expression->name->toString()) === 'class') {
            $resolved = $this->sourceResolvedClassName($expression->class, $context, $index);

            return is_string($resolved)
                ? ['ok' => true, 'value' => $resolved]
                : ['ok' => false];
        }

        if ($expression instanceof Expr\ConstFetch
            && strtolower($expression->name->toString()) === 'null') {
            return ['ok' => true, 'value' => null];
        }

        if ($expression instanceof Expr\Array_) {
            if (count($expression->items) > self::SOURCE_MAX_DECLARATIONS) {
                $index['limit'] ??= 'declarations';

                return ['ok' => false];
            }

            $values = [];

            foreach ($expression->items as $item) {
                if ($item === null || $item->unpack) {
                    return ['ok' => false];
                }

                $literal = $this->sourceLiteralValue($item->value, $context, $index, $depth + 1);

                if (! $literal['ok']) {
                    return ['ok' => false];
                }

                $values[] = $literal['value'];
            }

            return ['ok' => true, 'value' => $values];
        }

        return ['ok' => false];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $index
     */
    private function sourceResolvedClassName(Name $name, array $context, array &$index): ?string
    {
        $raw = strtolower($name->toString());

        if ($raw === 'static') {
            return $context['runtimeClass'];
        }

        if ($raw === 'self') {
            return $context['resolutionClass'];
        }

        if ($raw === 'parent') {
            $record = $this->sourceSymbolRecord($context['resolutionClass'], false, $index);

            return is_string($record['extends'] ?? null) ? $record['extends'] : null;
        }

        return $this->sourceAstName($name);
    }

    /**
     * @param array<string, mixed> $index
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, string>
     */
    private function sourceAttributeControllerMiddleware(
        string $controllerClass,
        string $controllerMethod,
        array &$index,
        array &$diagnostics,
    ): array {
        $hierarchy = [];
        $current = $controllerClass;
        $visited = [];

        while (! isset($visited[strtolower($current)])) {
            if (count($visited) >= self::SOURCE_MAX_ANCESTRY_DEPTH) {
                $index['limit'] ??= 'ancestry';
                break;
            }

            $visited[strtolower($current)] = true;
            $record = $this->sourceSymbolRecord($current, false, $index);

            if ($record === null) {
                if (! $this->sameSourcePhpName($current, Controller::class)) {
                    $diagnostics[] = $this->controllerDiagnostic(
                        'controller_middleware_attribute_ancestry_unavailable',
                        $controllerClass,
                        $controllerMethod,
                        'Controller ancestry crosses source outside the project, so inherited middleware attributes may be incomplete.',
                        ['symbol' => $current],
                    );
                }

                break;
            }

            array_unshift($hierarchy, [$current, $record]);
            $parent = $record['extends'];

            if (! is_string($parent)) {
                break;
            }

            $current = $index['classNames'][strtolower($parent)] ?? $parent;
        }

        $middleware = [];
        $inspectedAttributes = 0;

        foreach ($hierarchy as [$class, $record]) {
            $context = [
                'runtimeClass' => $controllerClass,
                'resolutionClass' => $class,
            ];
            $this->appendSourceMiddlewareAttributes(
                $record['node']->attrGroups,
                $context,
                $controllerClass,
                $controllerMethod,
                $index,
                $diagnostics,
                $middleware,
                $inspectedAttributes,
            );
        }

        $methodVisited = [];
        $method = $this->sourceControllerMethod(
            $controllerClass,
            $controllerMethod,
            $controllerClass,
            $index,
            $methodVisited,
        );

        if (($method['status'] ?? null) === 'found') {
            $this->appendSourceMiddlewareAttributes(
                $method['node']->attrGroups,
                $method,
                $controllerClass,
                $controllerMethod,
                $index,
                $diagnostics,
                $middleware,
                $inspectedAttributes,
            );
        } elseif (($method['status'] ?? null) === 'unavailable') {
            $diagnostics[] = $this->controllerDiagnostic(
                'controller_middleware_action_source_unavailable',
                $controllerClass,
                $controllerMethod,
                'The route action crosses unindexed source, so method-level middleware attributes may be incomplete.',
            );
        }

        return $middleware;
    }

    /**
     * @param array<int, Node\AttributeGroup> $groups
     * @param array<string, mixed> $context
     * @param array<string, mixed> $index
     * @param array<int, array<string, mixed>> $diagnostics
     * @param array<int, string> $middleware
     */
    private function appendSourceMiddlewareAttributes(
        array $groups,
        array $context,
        string $controllerClass,
        string $controllerMethod,
        array &$index,
        array &$diagnostics,
        array &$middleware,
        int &$inspectedAttributes,
    ): void {
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($inspectedAttributes >= self::SOURCE_MAX_DECLARATIONS) {
                    $index['limit'] ??= 'declarations';

                    return;
                }

                $inspectedAttributes++;

                if (! $this->sameSourcePhpName(
                    $this->sourceAstName($attribute->name),
                    MiddlewareAttribute::class,
                )) {
                    continue;
                }

                $arguments = [];

                foreach ($attribute->args as $position => $argument) {
                    if ($argument->unpack) {
                        $arguments = [];
                        break;
                    }

                    $name = $argument->name?->toString() ?? match ($position) {
                        0 => 'middleware',
                        1 => 'only',
                        2 => 'except',
                        default => null,
                    };

                    if ($name === null
                        || ! in_array($name, ['middleware', 'only', 'except'], true)
                        || array_key_exists($name, $arguments)) {
                        $arguments = [];
                        break;
                    }

                    $literal = $this->sourceLiteralValue($argument->value, $context, $index);

                    if (! $literal['ok']) {
                        $arguments = [];
                        break;
                    }

                    $arguments[$name] = $literal['value'];
                }

                $definition = [
                    'middleware' => $arguments['middleware'] ?? null,
                    'only' => $arguments['only'] ?? null,
                    'except' => $arguments['except'] ?? null,
                ];

                if (! is_string($definition['middleware'])
                    || (! is_array($definition['only']) && $definition['only'] !== null)
                    || (! is_array($definition['except']) && $definition['except'] !== null)) {
                    $diagnostics[] = $this->controllerDiagnostic(
                        'controller_middleware_attribute_unsupported',
                        $controllerClass,
                        $controllerMethod,
                        'An exact Laravel controller middleware attribute had arguments that could not be represented without constructing it.',
                    );

                    continue;
                }

                if (! $this->methodExcluded(
                    $controllerMethod,
                    $definition['only'],
                    $definition['except'],
                )) {
                    $middleware[] = $definition['middleware'];
                }
            }
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
