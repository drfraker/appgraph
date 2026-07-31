<?php

namespace AppGraph\Support;

use Closure;
use Illuminate\Bus\Dispatcher as LaravelBusDispatcher;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher as LaravelEventDispatcher;
use Illuminate\Routing\CompiledRouteCollection as LaravelCompiledRouteCollection;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\RouteCollection as LaravelRouteCollection;
use Illuminate\Routing\Router as LaravelRouter;
use ReflectionClass;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

/**
 * A read-only snapshot of Laravel's already-booted container registrations.
 *
 * The registry deliberately never calls make(), build(), or a user factory.
 * Exact class-string wrappers, existing instances, and declared closure return
 * types are observable without executing application code.
 */
class ContainerBindingRegistry
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $records = null;

    /** @var array<int, array<string, mixed>> */
    private array $diagnostics = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $contextual = [];

    /** @var array<string, array<string, true>> */
    private array $contextualDeclared = [];

    /** @var array<string, array<string, mixed>> */
    private array $defaults = [];

    /** @var array<string, true> */
    private array $defaultDeclared = [];

    /** @var array<string, string>|null Alias name to its directly registered target. */
    private ?array $aliases = null;

    /** @var array<string, array<string, mixed>> */
    private array $aliasDefaults = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $aliasContextual = [];

    /** @var array<string, true> */
    private array $aliasDeclared = [];

    /** @var array<string, true> */
    private array $explicitDefaultDeclared = [];

    public function __construct(
        private Container $container,
        private string|Closure $environmentSource = 'unknown',
        private ?string $applicationNamespace = null,
    ) {
    }

    public function refresh(): void
    {
        $this->records = null;
        $this->diagnostics = [];
        $this->contextual = [];
        $this->contextualDeclared = [];
        $this->defaults = [];
        $this->defaultDeclared = [];
        $this->aliases = null;
        $this->aliasDefaults = [];
        $this->aliasContextual = [];
        $this->aliasDeclared = [];
        $this->explicitDefaultDeclared = [];
    }

    public function environment(): string
    {
        try {
            return $this->environmentSource instanceof Closure
                ? (string) ($this->environmentSource)()
                : $this->environmentSource;
        } catch (Throwable) {
            return 'unknown';
        }
    }

    public function applicationNamespace(): ?string
    {
        return $this->applicationNamespace;
    }

    /** @return array<int, array<string, mixed>> */
    public function bindings(): array
    {
        $this->capture();

        return $this->records ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function diagnostics(): array
    {
        $this->capture();

        return $this->diagnostics;
    }

    /** @return array<string, mixed>|null */
    public function resolve(string $abstract, ?string $consumer = null): ?array
    {
        $this->capture();
        $requested = ltrim($abstract, '\\');
        $canonical = $this->canonical($requested);
        $isAlias = isset($this->aliasDeclared[$requested]);

        if ($consumer !== null) {
            $consumer = ltrim($consumer, '\\');

            if ($isAlias && isset($this->aliasContextual[$consumer][$requested])) {
                return $this->aliasContextual[$consumer][$requested] + [
                    'requestedAbstract' => $requested,
                ];
            }

            if (isset($this->contextual[$consumer][$canonical])) {
                return $this->contextual[$consumer][$canonical] + [
                    'requestedAbstract' => $requested,
                ];
            }

            // A contextual binding wins at runtime even when its closure cannot
            // be understood statically; do not silently substitute the default.
            if (isset($this->contextualDeclared[$consumer][$canonical])) {
                return null;
            }
        }

        if ($isAlias) {
            return isset($this->aliasDefaults[$requested])
                ? $this->aliasDefaults[$requested] + ['requestedAbstract' => $requested]
                : null;
        }

        return isset($this->defaults[$canonical])
            ? $this->defaults[$canonical] + ['requestedAbstract' => $requested]
            : null;
    }

    public function hasDefaultDeclaration(string $abstract): bool
    {
        $this->capture();
        $requested = ltrim($abstract, '\\');

        return isset($this->aliasDeclared[$requested])
            || isset($this->defaultDeclared[$this->canonical($requested)]);
    }

    public function hasDeclaration(string $abstract, ?string $consumer = null): bool
    {
        $this->capture();
        $requested = ltrim($abstract, '\\');
        $canonical = $this->canonical($requested);

        if ($consumer !== null
            && isset($this->contextualDeclared[ltrim($consumer, '\\')][$canonical])) {
            return true;
        }

        return isset($this->aliasDeclared[$requested])
            || isset($this->defaultDeclared[$canonical]);
    }

    /**
     * Return an object only when it is already present in Laravel's instance
     * cache. This is intentionally narrower than make(): it is safe for
     * scanners that need to inspect booted framework state without running a
     * binding factory, extender, or application constructor.
     */
    public function existingInstance(string $abstract): ?object
    {
        $canonical = $this->canonical(ltrim($abstract, '\\'));

        foreach ($this->containerArray('instances') as $registered => $instance) {
            if (is_string($registered)
                && is_object($instance)
                && $this->canonical($registered) === $canonical) {
                return $instance;
            }
        }

        return null;
    }

    public function fingerprint(bool $refresh = false): string
    {
        if ($refresh) {
            $this->refresh();
        }

        $bindings = array_values(array_map(
            fn (array $binding): array => $this->fingerprintBinding($binding),
            array_filter(
                $this->bindings(),
                fn (array $binding): bool => $this->affectsGraphFingerprint($binding),
            ),
        ));
        $diagnostics = array_values(array_map(
            fn (array $diagnostic): array => $this->fingerprintDiagnostic($diagnostic),
            array_filter(
                $this->diagnostics(),
                fn (array $diagnostic): bool => $this->affectsGraphFingerprint($diagnostic),
            ),
        ));

        return hash('sha256', json_encode([
            'environment' => $this->environment(),
            // The raw alias registry is part of resolution semantics even when
            // its target is currently unknown. Sorting and hashing it directly
            // catches alias additions, removals, and chain changes without
            // resolving a service or autoloading a target class.
            'aliases' => $this->aliases ?? [],
            'bindings' => $bindings,
            'diagnostics' => $diagnostics,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Hash the framework-owned Event and Bus registries that authorize
     * executable listener and mapped-handler edges. Only already-instantiated
     * dispatchers are read; no service is resolved and no callback runs.
     */
    public function executionRegistryFingerprint(): string
    {
        return hash('sha256', json_encode([
            'router' => $this->routeRegistryIdentity(),
            'events' => $this->eventRegistryIdentity(),
            'bus' => $this->busRegistryIdentity(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function routeRegistryIdentity(): array
    {
        $router = $this->existingInstance('router')
            ?? $this->existingInstance(LaravelRouter::class);

        // Calling framework accessors on an application-defined Router subclass
        // could execute overridden code. Keep that state explicit but inert.
        if (! $router instanceof LaravelRouter || $router::class !== LaravelRouter::class) {
            return [
                'state' => $router === null ? 'unavailable' : 'unverified',
                'class' => $router !== null ? $router::class : null,
            ];
        }

        try {
            $collection = $router->getRoutes();
            $priority = (new ReflectionClass(LaravelRouter::class))
                ->getProperty('middlewarePriority')
                ->getValue($router);
            $middleware = [
                'aliases' => $this->registryValueDigest($router->getMiddleware()),
                'groups' => $this->registryValueDigest($router->getMiddlewareGroups()),
                'priority' => $this->registryValueDigest($priority),
            ];

            // Router::setRoutes() accepts RouteCollection subclasses. Do not
            // call an application override while computing a read-only
            // fingerprint; only Laravel's two concrete collection types are
            // safe to enumerate.
            if (! in_array($collection::class, [
                LaravelRouteCollection::class,
                LaravelCompiledRouteCollection::class,
            ], true)) {
                return [
                    'state' => 'partially_available',
                    'class' => $router::class,
                    'collectionClass' => $collection::class,
                    'collectionState' => 'unverified',
                    'middleware' => $middleware,
                ];
            }

            $routes = $collection->getRoutes();
            $routeHash = hash_init('sha256');
            hash_update($routeHash, "appgraph-router-routes-v1\0");
            $unverifiedRoutes = 0;

            foreach ($routes as $position => $route) {
                if (! $route instanceof LaravelRoute || $route::class !== LaravelRoute::class) {
                    $unverifiedRoutes++;
                    $identity = [
                        'position' => $position,
                        'state' => 'unverified',
                        'class' => is_object($route) ? $route::class : get_debug_type($route),
                    ];
                } else {
                    $methods = array_values(array_filter(
                        array_map('strtoupper', $route->methods()),
                        static fn (string $method): bool => $method !== 'HEAD',
                    ));
                    $identity = [
                        'position' => $position,
                        'methods' => $methods,
                        'uri' => $route->uri(),
                        'name' => $route->getName(),
                        'actionName' => $route->getActionName(),
                        // getAction() returns Laravel's raw array without
                        // resolving, invoking, or unserializing its values.
                        'action' => $this->registryValueDigest($route->getAction()),
                        'middleware' => $this->registryValueDigest($route->middleware()),
                        'excludedMiddleware' => $this->registryValueDigest($route->excludedMiddleware()),
                        'computedMiddleware' => $route->computedMiddleware === null
                            ? null
                            : $this->registryValueDigest($route->computedMiddleware),
                    ];
                }

                hash_update($routeHash, $this->registryValueDigest($identity));
            }

            return [
                'state' => 'available',
                'class' => $router::class,
                'collectionClass' => $collection::class,
                'routeCount' => count($routes),
                'unverifiedRoutes' => $unverifiedRoutes,
                'routes' => hash_final($routeHash),
                'middleware' => $middleware,
            ];
        } catch (Throwable) {
            return ['state' => 'unreadable', 'class' => $router::class];
        }
    }

    /**
     * Produce a deterministic, constant-size digest without serializing or
     * invoking closures and objects from the application. Associative map order
     * is normalized; list order remains significant for routes and middleware.
     */
    private function registryValueDigest(mixed $value, int $depth = 0): string
    {
        $hash = hash_init('sha256');

        if ($depth > 16) {
            hash_update($hash, 'depth-limit:'.get_debug_type($value));

            return hash_final($hash);
        }

        if ($value === null) {
            hash_update($hash, 'null');
        } elseif (is_bool($value)) {
            hash_update($hash, $value ? 'bool:1' : 'bool:0');
        } elseif (is_int($value)) {
            hash_update($hash, 'int:'.(string) $value);
        } elseif (is_float($value)) {
            hash_update($hash, 'float:'.pack('E', $value));
        } elseif (is_string($value)) {
            hash_update($hash, 'string:'.strlen($value).':'.$value);
        } elseif ($value instanceof Closure) {
            try {
                $reflection = new ReflectionFunction($value);
                $identity = [
                    'kind' => 'closure',
                    'file' => $reflection->getFileName() ?: null,
                    'start' => $reflection->getStartLine() ?: null,
                    'end' => $reflection->getEndLine() ?: null,
                    'scope' => $reflection->getClosureScopeClass()?->getName(),
                    'static' => $reflection->isStatic(),
                ];
            } catch (Throwable) {
                $identity = ['kind' => 'closure', 'state' => 'unreadable'];
            }

            hash_update($hash, 'closure:'.$this->registryValueDigest($identity, $depth + 1));
        } elseif (is_array($value)) {
            $list = array_is_list($value);
            $keys = array_keys($value);

            if (! $list) {
                usort($keys, static fn (int|string $left, int|string $right): int => [
                    get_debug_type($left),
                    (string) $left,
                ] <=> [
                    get_debug_type($right),
                    (string) $right,
                ]);
            }

            hash_update($hash, ($list ? 'list:' : 'map:').count($keys).':');

            foreach ($keys as $key) {
                hash_update($hash, $this->registryValueDigest($key, $depth + 1));
                hash_update($hash, $this->registryValueDigest($value[$key], $depth + 1));
            }
        } elseif (is_object($value)) {
            hash_update($hash, 'object:'.$value::class);
        } elseif (is_resource($value)) {
            hash_update($hash, 'resource:'.get_resource_type($value));
        } else {
            hash_update($hash, 'type:'.get_debug_type($value));
        }

        return hash_final($hash);
    }

    /** @return array<string, mixed> */
    private function eventRegistryIdentity(): array
    {
        $dispatcher = $this->existingInstance('events')
            ?? $this->existingInstance('Illuminate\\Contracts\\Events\\Dispatcher')
            ?? $this->existingInstance(LaravelEventDispatcher::class);

        if (! $dispatcher instanceof LaravelEventDispatcher || $dispatcher::class !== LaravelEventDispatcher::class) {
            return ['state' => $dispatcher === null ? 'unavailable' : 'unverified', 'class' => $dispatcher !== null ? $dispatcher::class : null];
        }

        try {
            $reflection = new ReflectionClass(LaravelEventDispatcher::class);
            $listeners = $reflection->getProperty('listeners')->getValue($dispatcher);
            $wildcards = $reflection->getProperty('wildcards')->getValue($dispatcher);
        } catch (Throwable) {
            return ['state' => 'unreadable'];
        }

        return [
            'state' => 'available',
            'listeners' => $this->normalizeEventRegistry(is_array($listeners) ? $listeners : []),
            'wildcards' => $this->normalizeEventRegistry(is_array($wildcards) ? $wildcards : []),
        ];
    }

    /** @return array<string, mixed> */
    private function busRegistryIdentity(): array
    {
        $dispatcher = $this->existingInstance('Illuminate\\Contracts\\Bus\\Dispatcher')
            ?? $this->existingInstance('Illuminate\\Contracts\\Bus\\QueueingDispatcher')
            ?? $this->existingInstance(LaravelBusDispatcher::class);

        if (! $dispatcher instanceof LaravelBusDispatcher || $dispatcher::class !== LaravelBusDispatcher::class) {
            return ['state' => $dispatcher === null ? 'unavailable' : 'unverified', 'class' => $dispatcher !== null ? $dispatcher::class : null];
        }

        try {
            $handlers = (new ReflectionClass(LaravelBusDispatcher::class))->getProperty('handlers')->getValue($dispatcher);
        } catch (Throwable) {
            return ['state' => 'unreadable'];
        }

        $handlers = is_array($handlers) ? array_filter(
            $handlers,
            fn (mixed $handler, mixed $job): bool => is_string($job)
                && is_string($handler)
                && ($this->applicationNamespace === null
                    || $this->isApplicationClass($job)
                    || $this->isApplicationClass($handler)),
            ARRAY_FILTER_USE_BOTH,
        ) : [];
        ksort($handlers);

        return ['state' => 'available', 'handlers' => $handlers];
    }

    /** @param array<string, mixed> $registry */
    private function normalizeEventRegistry(array $registry): array
    {
        $normalized = [];

        foreach ($registry as $event => $listeners) {
            if (! is_string($event) || ! is_array($listeners)) {
                continue;
            }

            $records = [];

            foreach ($listeners as $listener) {
                $record = $this->normalizeRuntimeListener($listener);

                if ($record !== null) {
                    $records[] = $record;
                }
            }

            if ($records !== [] && ($this->applicationNamespace === null
                || $this->isApplicationClass($event)
                || array_any($records, fn (array $record): bool => $this->isApplicationClass((string) ($record['class'] ?? $record['scope'] ?? ''))))) {
                $normalized[$event] = $records;
            }
        }

        ksort($normalized);

        return $normalized;
    }

    /** @return array<string, mixed>|null */
    private function normalizeRuntimeListener(mixed $listener): ?array
    {
        if (is_string($listener)) {
            [$class, $method] = array_pad(explode('@', $listener, 2), 2, 'handle');

            return ['kind' => 'class', 'class' => ltrim($class, '\\'), 'method' => $method];
        }

        if (is_array($listener) && count($listener) === 2 && is_string($listener[1] ?? null)) {
            $target = $listener[0] ?? null;

            return is_string($target) || is_object($target)
                ? ['kind' => is_object($target) ? 'object' : 'class', 'class' => is_object($target) ? $target::class : ltrim($target, '\\'), 'method' => $listener[1]]
                : null;
        }

        if ($listener instanceof Closure) {
            try {
                $reflection = new ReflectionFunction($listener);

                return array_filter([
                    'kind' => 'closure',
                    'file' => $reflection->getFileName() ?: null,
                    'start' => $reflection->getStartLine() ?: null,
                    'end' => $reflection->getEndLine() ?: null,
                    'scope' => $reflection->getClosureScopeClass()?->getName(),
                ], static fn (mixed $value): bool => $value !== null);
            } catch (Throwable) {
                return ['kind' => 'closure'];
            }
        }

        return is_object($listener) ? ['kind' => 'object', 'class' => $listener::class] : null;
    }

    private function isApplicationClass(string $class): bool
    {
        if (! is_string($this->applicationNamespace) || $this->applicationNamespace === '') {
            return true;
        }

        return str_starts_with(
            ltrim($class, '\\'),
            rtrim(ltrim($this->applicationNamespace, '\\'), '\\').'\\',
        );
    }

    /** @param array<string, mixed> $record */
    private function isApplicationRelevant(array $record): bool
    {
        if (! is_string($this->applicationNamespace) || $this->applicationNamespace === '') {
            return true;
        }

        $namespace = rtrim(ltrim($this->applicationNamespace, '\\'), '\\').'\\';

        foreach (['abstract', 'concrete', 'consumer'] as $field) {
            $value = $record[$field] ?? null;

            if (is_string($value) && str_starts_with(ltrim($value, '\\'), $namespace)) {
                return true;
            }
        }

        return false;
    }

    private function capture(): void
    {
        if ($this->records !== null) {
            return;
        }

        $this->aliases = $this->snapshotAliases();

        foreach (array_keys($this->aliases) as $alias) {
            $this->aliasDeclared[$alias] = true;
        }

        $scoped = [];

        foreach ($this->containerArray('scopedInstances') as $abstract) {
            if (is_string($abstract)) {
                $scoped[$this->canonical($abstract)] = true;
            }
        }

        $extended = [];

        foreach ($this->containerArray('extenders') as $abstract => $extenders) {
            if (is_string($abstract) && is_array($extenders) && $extenders !== []) {
                $canonical = $this->canonical($abstract);
                $extended[$canonical] = true;
                $this->defaultDeclared[$canonical] = true;
                $this->explicitDefaultDeclared[$canonical] = true;
            }
        }

        /** @var array<string, array<string, mixed>> $directDefaults */
        $directDefaults = [];
        /** @var array<string, string> $unknownDefaults */
        $unknownDefaults = array_fill_keys(array_keys($extended), 'binding_extender_return_unknown');

        foreach ($this->container->getBindings() as $abstract => $binding) {
            if (! is_string($abstract) || ! is_array($binding)) {
                continue;
            }

            $canonical = $this->canonical($abstract);
            $this->defaultDeclared[$canonical] = true;
            $this->explicitDefaultDeclared[$canonical] = true;

            if (isset($extended[$canonical])) {
                $unknownDefaults[$canonical] = 'binding_extender_return_unknown';
                $this->diagnostics[] = $this->diagnostic($canonical, 'binding_extender_return_unknown', 'default');

                continue;
            }

            $resolved = $this->inspectImplementation($binding['concrete'] ?? null);

            if ($resolved === null) {
                $unknownDefaults[$canonical] = 'factory_return_unknown';
                $this->diagnostics[] = $this->diagnostic($canonical, 'factory_return_unknown', 'default');
                continue;
            }

            $record = $this->record(
                abstract: $canonical,
                concrete: $resolved['concrete'],
                scope: 'default',
                shared: (bool) ($binding['shared'] ?? false),
                lifetime: isset($scoped[$canonical]) ? 'scoped' : ((bool) ($binding['shared'] ?? false) ? 'singleton' : 'transient'),
                inference: $resolved['inference'],
                confidence: $resolved['confidence'],
            );
            $directDefaults[$canonical] = $record;
        }

        // Existing instances are the strongest observation of what the current
        // booted application will return, and safely override a factory record.
        foreach ($this->containerArray('instances') as $abstract => $instance) {
            if (! is_string($abstract) || ! is_object($instance)) {
                continue;
            }

            $canonical = $this->canonical($abstract);
            $this->defaultDeclared[$canonical] = true;
            unset($unknownDefaults[$canonical]);
            $this->diagnostics = array_values(array_filter(
                $this->diagnostics,
                static fn (array $diagnostic): bool => ! (
                    ($diagnostic['scope'] ?? null) === 'default'
                    && ($diagnostic['abstract'] ?? null) === $canonical
                ),
            ));
            $record = $this->record(
                abstract: $canonical,
                concrete: $instance::class,
                scope: 'default',
                shared: true,
                lifetime: isset($scoped[$canonical]) ? 'scoped' : 'singleton',
                inference: 'existing_instance',
                confidence: 1.0,
            );
            $directDefaults[$canonical] = $record;
        }

        $contextual = property_exists($this->container, 'contextual') && is_array($this->container->contextual)
            ? $this->container->contextual
            : [];
        /** @var array<string, array<string, array<string, mixed>>> $directContextual */
        $directContextual = [];
        /** @var array<string, array<string, string>> $unknownContextual */
        $unknownContextual = [];

        foreach ($contextual as $consumer => $bindings) {
            if (! is_string($consumer) || ! is_array($bindings)) {
                continue;
            }

            $consumer = ltrim($consumer, '\\');

            foreach ($bindings as $abstract => $implementation) {
                if (! is_string($abstract)) {
                    continue;
                }

                $canonical = $this->canonical($abstract);
                $this->contextualDeclared[$consumer][$canonical] = true;

                if (isset($extended[$canonical])) {
                    $unknownContextual[$consumer][$canonical] = 'contextual_extender_return_unknown';
                    $this->diagnostics[] = $this->diagnostic($canonical, 'contextual_extender_return_unknown', 'contextual', $consumer);

                    continue;
                }

                $resolved = $this->inspectImplementation($implementation);

                if ($resolved === null) {
                    $unknownContextual[$consumer][$canonical] = 'contextual_factory_return_unknown';
                    $this->diagnostics[] = $this->diagnostic($canonical, 'contextual_factory_return_unknown', 'contextual', $consumer);
                    continue;
                }

                $record = $this->record(
                    abstract: $canonical,
                    concrete: $resolved['concrete'],
                    scope: 'contextual',
                    shared: false,
                    lifetime: 'contextual',
                    inference: $resolved['inference'],
                    confidence: $resolved['confidence'],
                    consumer: $consumer,
                );
                $directContextual[$consumer][$canonical] = $record;
            }
        }

        $records = [];

        foreach ($directDefaults as $abstract => $record) {
            $effective = $this->effectiveRecord($record, $directDefaults, $unknownDefaults);

            if ($effective === null) {
                $this->diagnostics[] = $this->diagnostic($abstract, 'binding_target_unknown', 'default');
                continue;
            }

            $this->defaults[$abstract] = $effective;
            $records[] = $effective;
        }

        foreach (array_keys($this->contextualDeclared) as $consumer) {
            $bindings = $directContextual[$consumer] ?? [];
            $available = $directDefaults;
            $unknown = $unknownDefaults;

            foreach (array_keys($this->contextualDeclared[$consumer] ?? []) as $abstract) {
                if (isset($bindings[$abstract])) {
                    $available[$abstract] = $bindings[$abstract];
                    unset($unknown[$abstract]);
                } else {
                    unset($available[$abstract]);
                    $unknown[$abstract] = $unknownContextual[$consumer][$abstract] ?? 'contextual_target_unknown';
                }
            }

            foreach ($available as $abstract => $record) {
                $effective = $this->effectiveRecord($record, $available, $unknown);

                if ($effective === null) {
                    // A contextual override at an intermediate hop also changes
                    // every abstraction that resolves through that hop. Record
                    // the derived declaration so resolve(A, consumer) cannot
                    // silently fall back to A's default when contextual B is
                    // unknown in an A -> B chain.
                    $this->contextualDeclared[$consumer][$abstract] = true;
                    $this->diagnostics[] = $this->diagnostic(
                        $abstract,
                        'contextual_target_unknown',
                        'contextual',
                        $consumer,
                    );
                    continue;
                }

                $default = $this->defaults[$abstract] ?? null;
                $contextSpecific = isset($this->contextualDeclared[$consumer][$abstract])
                    || $default === null
                    || ($default['concrete'] ?? null) !== ($effective['concrete'] ?? null)
                    || ($default['effectiveLifetime'] ?? null) !== ($effective['effectiveLifetime'] ?? null)
                    || ($default['terminalInference'] ?? $default['inference'] ?? null)
                        !== ($effective['terminalInference'] ?? $effective['inference'] ?? null);

                if (! $contextSpecific) {
                    continue;
                }

                $effective['scope'] = 'contextual';
                $effective['consumer'] = $consumer;

                $this->contextual[$consumer][$abstract] = $effective;
                $records[] = $effective;
            }
        }

        $this->appendAliasRecords($records);

        // Prefer the observed instance record when duplicate default records exist.
        $unique = [];

        foreach ($records as $record) {
            $key = implode("\0", [$record['scope'], $record['consumer'] ?? '', $record['abstract'], $record['concrete']]);
            $unique[$key] = $record;
        }

        $records = array_values($unique);
        usort($records, static fn (array $a, array $b): int => [
            $a['abstract'], $a['scope'], $a['consumer'] ?? '', $a['concrete'],
        ] <=> [
            $b['abstract'], $b['scope'], $b['consumer'] ?? '', $b['concrete'],
        ]);
        usort($this->diagnostics, static fn (array $a, array $b): int => [
            $a['abstract'], $a['scope'], $a['consumer'] ?? '',
        ] <=> [
            $b['abstract'], $b['scope'], $b['consumer'] ?? '',
        ]);
        $this->records = $records;
    }

    /**
     * Materialize the effective, observable meaning of Laravel aliases without
     * resolving a service or invoking an autoloader.
     *
     * @param array<int, array<string, mixed>> $records
     */
    private function appendAliasRecords(array &$records): void
    {
        foreach ($this->aliases ?? [] as $alias => $directTarget) {
            $resolution = $this->aliasResolution($alias);

            if ($resolution['cycle']) {
                $this->diagnostics[] = $this->diagnostic(
                    $alias,
                    'alias_cycle',
                    'default',
                    metadata: [
                        'aliasDirectTarget' => $directTarget,
                        'resolutionPath' => $resolution['path'],
                    ],
                );

                continue;
            }

            $target = $resolution['canonical'];
            $default = $this->defaults[$target] ?? null;

            if ($default === null && ! isset($this->defaultDeclared[$target])) {
                $default = $this->autoConcreteRecord($target);
            }

            if ($default !== null) {
                $aliased = $this->aliasRecord($alias, $directTarget, $resolution['path'], $default);
                $this->aliasDefaults[$alias] = $aliased;
                $records[] = $aliased;
            } else {
                $this->diagnostics[] = $this->diagnostic(
                    $alias,
                    'alias_target_unknown',
                    'default',
                    metadata: [
                        'aliasTarget' => $target,
                        'aliasDirectTarget' => $directTarget,
                        'resolutionPath' => $resolution['path'],
                    ],
                );
            }

            $consumers = array_values(array_unique([
                ...array_keys($this->contextualDeclared),
                ...array_keys($this->contextual),
            ]));

            foreach ($consumers as $consumer) {
                $contextual = $this->contextual[$consumer][$target] ?? null;
                $declared = isset($this->contextualDeclared[$consumer][$target]);

                if ($contextual === null && ! $declared) {
                    continue;
                }

                if ($contextual === null) {
                    $this->diagnostics[] = $this->diagnostic(
                        $alias,
                        'alias_contextual_target_unknown',
                        'contextual',
                        $consumer,
                        [
                            'aliasTarget' => $target,
                            'aliasDirectTarget' => $directTarget,
                            'resolutionPath' => $resolution['path'],
                        ],
                    );

                    continue;
                }

                $aliased = $this->aliasRecord($alias, $directTarget, $resolution['path'], $contextual);
                $this->aliasContextual[$consumer][$alias] = $aliased;
                $records[] = $aliased;
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function autoConcreteRecord(string $target): ?array
    {
        if (! class_exists($target, false)) {
            return null;
        }

        try {
            $reflection = new ReflectionClass($target);

            if (! $reflection->isInstantiable()) {
                return null;
            }

            $lifetime = match (true) {
                $reflection->getAttributes(Singleton::class) !== [] => 'singleton',
                $reflection->getAttributes(Scoped::class) !== [] => 'scoped',
                default => 'transient',
            };

            return $this->record(
                abstract: $target,
                concrete: $target,
                scope: 'default',
                shared: $lifetime !== 'transient',
                lifetime: $lifetime,
                inference: 'auto_concrete',
                confidence: 1.0,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, string> $aliasPath
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function aliasRecord(
        string $alias,
        string $directTarget,
        array $aliasPath,
        array $target,
    ): array {
        $terminalInference = (string) ($target['terminalInference'] ?? $target['inference']);
        $targetPath = $target['resolutionPath'] ?? [
            (string) $target['abstract'],
            (string) $target['concrete'],
        ];

        return [
            ...$target,
            'abstract' => $alias,
            'inference' => 'container_alias',
            'terminalInference' => $terminalInference,
            'resolutionPath' => array_values(array_unique([
                ...$aliasPath,
                ...$targetPath,
            ])),
            'aliasTarget' => (string) $target['abstract'],
            'aliasDirectTarget' => $directTarget,
        ];
    }

    /**
     * Follow Laravel class-string binding chains without resolving an object.
     * Unknown factories, extenders, and cycles make the effective target
     * unknowable and are intentionally not guessed.
     *
     * @param array<string, mixed> $record
     * @param array<string, array<string, mixed>> $defaults
     * @param array<string, string> $unknown
     * @param array<string, true> $visited
     * @return array<string, mixed>|null
     */
    private function effectiveRecord(array $record, array $defaults, array $unknown, array $visited = []): ?array
    {
        $abstract = (string) $record['abstract'];

        if (isset($visited[$abstract])) {
            return null;
        }

        $visited[$abstract] = true;
        $target = $this->canonical((string) $record['concrete']);
        $containerResolvesTarget = in_array($record['terminalInference'] ?? $record['inference'], [
            'class_string_binding',
            'laravel_class_string_wrapper',
        ], true);

        if (! $containerResolvesTarget
            || $target === $abstract
            || (! isset($defaults[$target]) && ! isset($unknown[$target]))) {
            $record['concrete'] = $target;

            return $record;
        }

        if (isset($unknown[$target])) {
            return null;
        }

        $terminal = $this->effectiveRecord($defaults[$target], $defaults, $unknown, $visited);

        if ($terminal === null) {
            return null;
        }

        $path = [$abstract, ...($terminal['resolutionPath'] ?? [$target, $terminal['concrete']])];
        $record['concrete'] = $terminal['concrete'];
        $record['confidence'] = round((float) $record['confidence'] * (float) $terminal['confidence'], 2);
        $record['certainty'] = $record['confidence'] === 1.0 ? 'observed' : 'declared';
        $record['resolutionPath'] = array_values(array_unique($path));
        $record['terminalInference'] = $terminal['terminalInference'] ?? $terminal['inference'];
        $record['effectiveLifetime'] = $this->composeLifetime(
            (string) $record['lifetime'],
            (string) ($terminal['effectiveLifetime'] ?? $terminal['lifetime']),
        );

        return $record;
    }

    /** @return array{concrete: string, inference: string, confidence: float}|null */
    private function inspectImplementation(mixed $implementation): ?array
    {
        if (is_object($implementation) && ! $implementation instanceof Closure) {
            return [
                'concrete' => $implementation::class,
                'inference' => 'existing_instance',
                'confidence' => 1.0,
            ];
        }

        if (is_string($implementation) && $this->looksLikeClass($implementation)) {
            return [
                'concrete' => ltrim($implementation, '\\'),
                'inference' => 'class_string_binding',
                'confidence' => 1.0,
            ];
        }

        if (! $implementation instanceof Closure) {
            return null;
        }

        try {
            $reflection = new ReflectionFunction($implementation);
            $variables = $reflection->getStaticVariables();
            $wrapped = $variables['concrete'] ?? null;

            if ($this->isLaravelClassStringWrapper($reflection, $variables)
                && is_string($wrapped)
                && $this->looksLikeClass($wrapped)) {
                return [
                    'concrete' => ltrim($wrapped, '\\'),
                    'inference' => 'laravel_class_string_wrapper',
                    'confidence' => 1.0,
                ];
            }

            $return = $this->classFromReflectionType(
                $reflection->getReturnType(),
                $reflection->getClosureScopeClass(),
                $reflection->getClosureCalledClass(),
            );

            return $return === null ? null : [
                'concrete' => $return,
                'inference' => 'factory_declared_return_type',
                'confidence' => 0.9,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $variables */
    private function isLaravelClassStringWrapper(ReflectionFunction $reflection, array $variables): bool
    {
        $scope = $reflection->getClosureScopeClass()?->getName();

        if (! is_string($scope) || ! is_a($scope, Container::class, true)) {
            return false;
        }

        $factory = new ReflectionMethod($scope, 'getClosure');
        $start = $reflection->getStartLine();

        return is_string($variables['abstract'] ?? null)
            && is_string($variables['concrete'] ?? null)
            && $reflection->getClosureThis() === $this->container
            && $reflection->getFileName() === $factory->getFileName()
            && is_int($start)
            && $start >= $factory->getStartLine()
            && $start <= $factory->getEndLine();
    }

    private function classFromReflectionType(
        ?ReflectionType $type,
        ?ReflectionClass $scope = null,
        ?ReflectionClass $calledClass = null,
    ): ?string
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->allowsNull() ? null : $this->classFromNamedType($type, $scope, $calledClass);
        }

        if ($type instanceof ReflectionUnionType) {
            $classes = [];

            foreach ($type->getTypes() as $inner) {
                if (! $inner instanceof ReflectionNamedType
                    || $inner->isBuiltin()
                    || ($class = $this->classFromNamedType($inner, $scope, $calledClass)) === null) {
                    return null;
                }

                $classes[] = $class;
            }

            $classes = array_values(array_unique($classes));

            return count($classes) === 1 ? $classes[0] : null;
        }

        if ($type instanceof ReflectionIntersectionType) {
            $classes = array_values(array_unique(array_filter(array_map(
                fn (ReflectionNamedType $inner): ?string => $this->classFromNamedType($inner, $scope, $calledClass),
                $type->getTypes(),
            ))));

            return count($classes) === 1 ? $classes[0] : null;
        }

        return null;
    }

    private function classFromNamedType(
        ReflectionNamedType $type,
        ?ReflectionClass $scope,
        ?ReflectionClass $calledClass,
    ): ?string
    {
        if ($type->isBuiltin()) {
            return null;
        }

        return match (strtolower($type->getName())) {
            'self' => $scope?->getName(),
            'static' => $calledClass?->getName() ?? $scope?->getName(),
            'parent' => $scope?->getParentClass()?->getName(),
            default => ltrim($type->getName(), '\\'),
        };
    }

    /** @return array<string, mixed> */
    private function record(
        string $abstract,
        string $concrete,
        string $scope,
        bool $shared,
        string $lifetime,
        string $inference,
        float $confidence,
        ?string $consumer = null,
    ): array {
        return array_filter([
            'abstract' => $abstract,
            'concrete' => ltrim($concrete, '\\'),
            'scope' => $scope,
            'consumer' => $consumer,
            'shared' => $shared,
            'lifetime' => $lifetime,
            'effectiveLifetime' => $lifetime === 'contextual' ? 'transient' : $lifetime,
            'source' => 'booted_container',
            'certainty' => $confidence === 1.0 ? 'observed' : 'declared',
            'inference' => $inference,
            'confidence' => $confidence,
            'environment' => $this->environment(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private function diagnostic(
        string $abstract,
        string $reason,
        string $scope,
        ?string $consumer = null,
        array $metadata = [],
    ): array {
        return array_filter([
            'abstract' => $abstract,
            'reason' => $reason,
            'scope' => $scope,
            'consumer' => $consumer,
            'source' => 'booted_container',
            'environment' => $this->environment(),
            ...$metadata,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function composeLifetime(string $outer, string $terminal): string
    {
        if ($outer === 'singleton' || $terminal === 'singleton') {
            return 'singleton';
        }

        if ($outer === 'scoped' || $terminal === 'scoped') {
            return 'scoped';
        }

        return $terminal === 'contextual' ? 'transient' : $terminal;
    }

    private function canonical(string $abstract): string
    {
        $resolution = $this->aliasResolution(ltrim($abstract, '\\'));

        return $resolution['cycle'] ? ltrim($abstract, '\\') : $resolution['canonical'];
    }

    /** @return array{canonical: string, path: array<int, string>, cycle: bool} */
    private function aliasResolution(string $abstract): array
    {
        $current = ltrim($abstract, '\\');
        $path = [$current];
        $visited = [];
        $aliases = $this->aliases ?? $this->snapshotAliases();

        while (isset($aliases[$current])) {
            if (isset($visited[$current])) {
                return ['canonical' => $current, 'path' => $path, 'cycle' => true];
            }

            $visited[$current] = true;
            $current = $aliases[$current];
            $path[] = $current;
        }

        return ['canonical' => $current, 'path' => $path, 'cycle' => false];
    }

    /** @return array<string, string> */
    private function snapshotAliases(): array
    {
        $aliases = [];

        foreach ($this->containerArray('aliases') as $alias => $target) {
            if (! is_string($alias) || ! is_string($target)) {
                continue;
            }

            $alias = ltrim($alias, '\\');
            $target = ltrim($target, '\\');

            if ($alias !== '' && $target !== '') {
                $aliases[$alias] = $target;
            }
        }

        ksort($aliases);

        return $aliases;
    }

    private function looksLikeClass(string $value): bool
    {
        $value = ltrim($value, '\\');

        return $value !== '' && (
            str_contains($value, '\\')
            || class_exists($value, false)
            || interface_exists($value, false)
        );
    }

    /** @param array<string, mixed> $record */
    private function affectsGraphFingerprint(array $record): bool
    {
        // Alias topology is hashed independently from the raw, sorted alias
        // registry. Derived alias records can change merely because a target
        // singleton was resolved between scans, so including them twice would
        // make the source fingerprint process-history dependent.
        if (isset($record['aliasTarget'])) {
            return false;
        }

        if ($this->isApplicationRelevant($record)) {
            return true;
        }

        $abstract = ltrim((string) ($record['abstract'] ?? ''), '\\');

        if ($abstract === '') {
            return false;
        }

        // Laravel's deprecation handler resolves the framework-owned `log`
        // singleton lazily. Its transition from an opaque factory diagnostic
        // to a canonical self-observation is process history, not application
        // architecture. Concrete application and vendor targets still flow
        // through the normal relevance rules below.
        if ($abstract === 'log' && (
            isset($record['reason'])
            || ($record['concrete'] ?? null) === $abstract
        )) {
            return false;
        }

        if (isset($this->explicitDefaultDeclared[$this->canonical($abstract)])) {
            if (! str_contains($abstract, '\\')) {
                return true;
            }

            $concrete = ltrim((string) ($record['concrete'] ?? ''), '\\');

            // Package/framework services are commonly materialized lazily as
            // different commands run in the same process. They are not
            // application architecture unless an application class appears
            // in the binding (handled above). Keep explicit third-party
            // integration bindings and user string keys, while excluding this
            // process-history noise from freshness identity.
            return ! $this->isInfrastructureClass($abstract)
                || ($concrete !== '' && ! $this->isInfrastructureClass($concrete));
        }

        // Remaining instance-only observations are populated opportunistically
        // as a long-lived process handles commands (for example Laravel's
        // `date` service). Application-relevant and explicitly declared
        // bindings already returned above, so none of this fallback is stable
        // scan identity.
        return false;
    }

    private function isInfrastructureClass(string $class): bool
    {
        $class = ltrim($class, '\\');

        foreach (['Illuminate\\', 'Laravel\\', 'Symfony\\', 'Psr\\', 'AppGraph\\'] as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $binding @return array<string, mixed> */
    private function fingerprintBinding(array $binding): array
    {
        return array_filter([
            'abstract' => $binding['abstract'] ?? null,
            'concrete' => $binding['concrete'] ?? null,
            'scope' => $binding['scope'] ?? null,
            'consumer' => $binding['consumer'] ?? null,
            'shared' => $binding['shared'] ?? null,
            'lifetime' => $binding['lifetime'] ?? null,
            'effectiveLifetime' => $binding['effectiveLifetime'] ?? null,
            'aliasTarget' => $binding['aliasTarget'] ?? null,
            'aliasDirectTarget' => $binding['aliasDirectTarget'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $diagnostic @return array<string, mixed> */
    private function fingerprintDiagnostic(array $diagnostic): array
    {
        return array_filter([
            'abstract' => $diagnostic['abstract'] ?? null,
            'reason' => $diagnostic['reason'] ?? null,
            'scope' => $diagnostic['scope'] ?? null,
            'consumer' => $diagnostic['consumer'] ?? null,
            'resolutionPath' => $diagnostic['resolutionPath'] ?? null,
            'aliasTarget' => $diagnostic['aliasTarget'] ?? null,
            'aliasDirectTarget' => $diagnostic['aliasDirectTarget'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<mixed> */
    private function containerArray(string $property): array
    {
        try {
            $reflection = new ReflectionObject($this->container);

            while ($reflection !== false) {
                if ($reflection->hasProperty($property)) {
                    $value = $reflection->getProperty($property)->getValue($this->container);

                    return is_array($value) ? $value : [];
                }

                $reflection = $reflection->getParentClass();
            }
        } catch (Throwable) {
            // Missing internals degrade to the public binding map.
        }

        return [];
    }
}
