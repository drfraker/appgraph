<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Scanners\ContainerBindingScanner;
use AppGraph\Support\ContainerBindingRegistry;
use AppGraph\Tests\TestCase;
use Illuminate\Container\Container;

interface BindingContract
{
}

interface UnknownBindingContract
{
}

class DefaultBindingImplementation implements BindingContract
{
    public function run(): void
    {
    }
}

class ContextualBindingImplementation implements BindingContract
{
    public function run(): void
    {
    }
}

class UnknownBindingImplementation implements UnknownBindingContract
{
}

interface ChainedBindingContract
{
}

class IntermediateBindingImplementation implements ChainedBindingContract
{
}

class FinalBindingImplementation extends IntermediateBindingImplementation
{
}

class ScopedFactory
{
    public static function make(): \Closure
    {
        return static function (): self {
            throw new \RuntimeException('Factory execution is forbidden.');
        };
    }
}

class LateBoundFactory
{
    public static function make(): \Closure
    {
        return static function (): static {
            throw new \RuntimeException('Factory execution is forbidden.');
        };
    }
}

class ChildLateBoundFactory extends LateBoundFactory
{
}

class BindingConsumer
{
}

class AutoResolvedAliasService
{
    public static int $constructions = 0;

    public function __construct()
    {
        self::$constructions++;
    }
}

class ContainerBindingScannerTest extends TestCase
{
    public function test_it_snapshots_exact_and_contextual_bindings_without_executing_factories(): void
    {
        $container = new Container();
        $factoryInvoked = false;
        $container->singleton(BindingContract::class, DefaultBindingImplementation::class);
        $container->when(BindingConsumer::class)
            ->needs(BindingContract::class)
            ->give(ContextualBindingImplementation::class);
        $container->bind(UnknownBindingContract::class, function () use (&$factoryInvoked) {
            $factoryInvoked = true;

            return new UnknownBindingImplementation();
        });
        $concrete = DefaultBindingImplementation::class;
        $container->bind('misleading-factory', function () use ($concrete, &$factoryInvoked) {
            $factoryInvoked = true;

            return new $concrete();
        });
        $registry = new ContainerBindingRegistry($container, 'testing', 'AppGraph\\Tests\\Unit\\');

        $default = $registry->resolve(BindingContract::class);
        $contextual = $registry->resolve(BindingContract::class, BindingConsumer::class);

        $this->assertSame(DefaultBindingImplementation::class, $default['concrete']);
        $this->assertSame('singleton', $default['lifetime']);
        $this->assertSame(ContextualBindingImplementation::class, $contextual['concrete']);
        $this->assertSame('contextual', $contextual['scope']);
        $this->assertNull($registry->resolve('misleading-factory'));
        $this->assertFalse($factoryInvoked, 'Container factories must never run during a scan.');
        $this->assertContains('factory_return_unknown', array_column($registry->diagnostics(), 'reason'));

        $graph = new Graph();
        $graph->addNode(Node::make(DefaultBindingImplementation::class.'::run', 'method', 'DefaultBindingImplementation::run'));
        $graph->addNode(Node::make(ContextualBindingImplementation::class.'::run', 'method', 'ContextualBindingImplementation::run'));
        $graph->addNode(Node::make(BindingConsumer::class, 'class', 'BindingConsumer'));
        (new ContainerBindingScanner($registry))->scan($graph);
        $array = $graph->toArray();

        $this->assertGraphHasNode($array, BindingContract::class, 'interface');
        $this->assertGraphHasEdge($array, BindingContract::class, DefaultBindingImplementation::class, 'resolves_to');
        $this->assertGraphHasEdge($array, BindingContract::class, ContextualBindingImplementation::class, 'resolves_to');
        $edge = $this->graphEdge($array, BindingContract::class, ContextualBindingImplementation::class, 'resolves_to');
        $this->assertSame(BindingConsumer::class, $edge['metadata']['bindings']['contextual:'.BindingConsumer::class]['consumer']);
        $this->assertSame('testing', $array['meta']['analysis']['containerBindings']['environment']);
        $this->assertGreaterThanOrEqual(2, $array['meta']['analysis']['containerBindings']['resolvedCount']);
        $this->assertSame(1, $array['meta']['analysis']['containerBindings']['unresolvedCount']);
        $this->assertFalse($factoryInvoked);
    }

    public function test_typed_factory_returns_and_existing_instances_are_observed_without_invocation(): void
    {
        $container = new Container();
        $typedInvoked = false;
        $container->bind(BindingContract::class, function () use (&$typedInvoked): DefaultBindingImplementation {
            $typedInvoked = true;

            return new DefaultBindingImplementation();
        });
        $container->bind('nullable-factory', function () use (&$typedInvoked): ?DefaultBindingImplementation {
            $typedInvoked = true;

            return null;
        });
        $registry = new ContainerBindingRegistry($container, 'testing');

        $binding = $registry->resolve(BindingContract::class);

        $this->assertSame(DefaultBindingImplementation::class, $binding['concrete']);
        $this->assertSame('factory_declared_return_type', $binding['inference']);
        $this->assertSame(0.9, $binding['confidence']);
        $this->assertNull($registry->resolve('nullable-factory'));
        $this->assertFalse($typedInvoked);

        $container->instance(BindingContract::class, new ContextualBindingImplementation());
        $registry->refresh();
        $instance = $registry->resolve(BindingContract::class);

        $this->assertSame(ContextualBindingImplementation::class, $instance['concrete']);
        $this->assertSame('existing_instance', $instance['inference']);
        $this->assertInstanceOf(
            ContextualBindingImplementation::class,
            $registry->existingInstance(BindingContract::class),
        );
        $this->assertNull($registry->existingInstance('missing-service'));
        $this->assertFalse($typedInvoked);
    }

    public function test_binding_chains_are_followed_and_extenders_suppress_unproven_targets(): void
    {
        $container = new Container();
        $extenderInvoked = false;
        $container->bind(ChainedBindingContract::class, IntermediateBindingImplementation::class);
        $container->bind(IntermediateBindingImplementation::class, FinalBindingImplementation::class);
        $container->bind('extended-service', DefaultBindingImplementation::class);
        $container->extend('extended-service', function (object $service) use (&$extenderInvoked): object {
            $extenderInvoked = true;

            return $service;
        });
        $container->bind('scoped-factory', ScopedFactory::make());
        $registry = new ContainerBindingRegistry($container, 'testing');

        $chain = $registry->resolve(ChainedBindingContract::class);

        $this->assertSame(FinalBindingImplementation::class, $chain['concrete']);
        $this->assertSame([
            ChainedBindingContract::class,
            IntermediateBindingImplementation::class,
            FinalBindingImplementation::class,
        ], $chain['resolutionPath']);
        $this->assertNull($registry->resolve('extended-service'));
        $this->assertFalse($extenderInvoked);
        $this->assertContains('binding_extender_return_unknown', array_column($registry->diagnostics(), 'reason'));

        $scoped = $registry->resolve('scoped-factory');
        $this->assertSame(ScopedFactory::class, $scoped['concrete']);
        $this->assertSame('factory_declared_return_type', $scoped['inference']);
    }

    public function test_only_class_string_bindings_are_followed_through_other_bindings(): void
    {
        $container = new Container();
        $factoryInvoked = false;
        $container->bind(BindingContract::class, function () use (&$factoryInvoked): IntermediateBindingImplementation {
            $factoryInvoked = true;

            return new IntermediateBindingImplementation();
        });
        $container->bind(IntermediateBindingImplementation::class, FinalBindingImplementation::class);
        $registry = new ContainerBindingRegistry($container, 'testing');

        $factory = $registry->resolve(BindingContract::class);

        $this->assertSame(IntermediateBindingImplementation::class, $factory['concrete']);
        $this->assertSame('factory_declared_return_type', $factory['inference']);
        $this->assertArrayNotHasKey('resolutionPath', $factory);
        $this->assertFalse($factoryInvoked);

        $container->instance(BindingContract::class, new IntermediateBindingImplementation());
        $registry->refresh();
        $instance = $registry->resolve(BindingContract::class);

        $this->assertSame(IntermediateBindingImplementation::class, $instance['concrete']);
        $this->assertSame('existing_instance', $instance['inference']);
        $this->assertArrayNotHasKey('resolutionPath', $instance);
        $this->assertFalse($factoryInvoked);
    }

    public function test_contextual_chains_inherit_terminal_lifetime_and_unbound_extenders_are_unknown(): void
    {
        $container = new Container();
        $extenderInvoked = false;
        $container->singleton(IntermediateBindingImplementation::class, FinalBindingImplementation::class);
        $container->when(BindingConsumer::class)
            ->needs(BindingContract::class)
            ->give(IntermediateBindingImplementation::class);
        $container->bind(ChainedBindingContract::class, DefaultBindingImplementation::class);
        $container->extend(DefaultBindingImplementation::class, function (object $service) use (&$extenderInvoked): object {
            $extenderInvoked = true;

            return $service;
        });
        $registry = new ContainerBindingRegistry($container, 'testing');

        $contextual = $registry->resolve(BindingContract::class, BindingConsumer::class);

        $this->assertSame(FinalBindingImplementation::class, $contextual['concrete']);
        $this->assertSame('contextual', $contextual['lifetime']);
        $this->assertSame('singleton', $contextual['effectiveLifetime']);
        $this->assertNull($registry->resolve(ChainedBindingContract::class));
        $this->assertTrue($registry->hasDefaultDeclaration(DefaultBindingImplementation::class));
        $this->assertFalse($extenderInvoked);
    }

    public function test_contextual_bindings_apply_to_every_hop_for_the_same_consumer(): void
    {
        $container = new Container();
        $container->bind(BindingContract::class, IntermediateBindingImplementation::class);
        $container->when(BindingConsumer::class)
            ->needs(IntermediateBindingImplementation::class)
            ->give(FinalBindingImplementation::class);
        $registry = new ContainerBindingRegistry($container, 'testing');

        $nested = $registry->resolve(BindingContract::class, BindingConsumer::class);

        $this->assertSame(FinalBindingImplementation::class, $nested['concrete']);
        $this->assertSame('contextual', $nested['scope']);
        $this->assertSame(BindingConsumer::class, $nested['consumer']);
        $this->assertSame([
            BindingContract::class,
            IntermediateBindingImplementation::class,
            FinalBindingImplementation::class,
        ], $nested['resolutionPath']);

        $container = new Container();
        $container->when(BindingConsumer::class)
            ->needs(BindingContract::class)
            ->give(IntermediateBindingImplementation::class);
        $container->when(BindingConsumer::class)
            ->needs(IntermediateBindingImplementation::class)
            ->give(FinalBindingImplementation::class);
        $registry = new ContainerBindingRegistry($container, 'testing');

        $direct = $registry->resolve(BindingContract::class, BindingConsumer::class);

        $this->assertSame(FinalBindingImplementation::class, $direct['concrete']);
        $this->assertSame('contextual', $direct['scope']);
    }

    public function test_an_unknown_contextual_intermediate_never_falls_back_to_the_default_chain(): void
    {
        $container = new Container();
        $factoryInvoked = false;
        $container->bind(BindingContract::class, IntermediateBindingImplementation::class);
        $container->when(BindingConsumer::class)
            ->needs(IntermediateBindingImplementation::class)
            ->give(function () use (&$factoryInvoked) {
                $factoryInvoked = true;

                return new FinalBindingImplementation();
            });
        $registry = new ContainerBindingRegistry($container, 'testing');

        $this->assertNull($registry->resolve(BindingContract::class, BindingConsumer::class));
        $this->assertTrue($registry->hasDeclaration(BindingContract::class, BindingConsumer::class));
        $this->assertFalse($factoryInvoked);
        $this->assertContains('contextual_target_unknown', array_column($registry->diagnostics(), 'reason'));
    }

    public function test_scoped_aliases_inherit_a_singleton_terminal_lifetime(): void
    {
        $container = new Container();
        $container->singleton(IntermediateBindingImplementation::class, FinalBindingImplementation::class);
        $container->scoped(BindingContract::class, IntermediateBindingImplementation::class);
        $registry = new ContainerBindingRegistry($container, 'testing');

        $binding = $registry->resolve(BindingContract::class);

        $this->assertSame(FinalBindingImplementation::class, $binding['concrete']);
        $this->assertSame('scoped', $binding['lifetime']);
        $this->assertSame('singleton', $binding['effectiveLifetime']);
    }

    public function test_environment_metadata_is_refreshed_from_a_dynamic_source(): void
    {
        $environment = 'testing';
        $container = new Container();
        $container->bind(BindingContract::class, DefaultBindingImplementation::class);
        $registry = new ContainerBindingRegistry($container, static function () use (&$environment): string {
            return $environment;
        });

        $this->assertSame('testing', $registry->resolve(BindingContract::class)['environment']);

        $environment = 'production';
        $registry->refresh();

        $this->assertSame('production', $registry->environment());
        $this->assertSame('production', $registry->resolve(BindingContract::class)['environment']);
    }

    public function test_static_factory_return_types_use_the_late_bound_called_class(): void
    {
        $container = new Container();
        $container->bind('late-bound-factory', ChildLateBoundFactory::make());
        $registry = new ContainerBindingRegistry($container, 'testing');

        $binding = $registry->resolve('late-bound-factory');

        $this->assertSame(ChildLateBoundFactory::class, $binding['concrete']);
        $this->assertSame('factory_declared_return_type', $binding['inference']);
    }

    public function test_binding_node_classification_never_autoloads_application_files(): void
    {
        $autoloaded = [];
        $loader = static function (string $class) use (&$autoloaded): void {
            if (str_starts_with($class, 'LazySafety\\')) {
                $autoloaded[] = $class;
            }
        };
        spl_autoload_register($loader, true, true);

        try {
            $container = new Container();
            $container->bind('LazySafety\\Contract', 'LazySafety\\Implementation');
            $registry = new ContainerBindingRegistry($container, 'testing', 'LazySafety\\');
            $graph = new Graph();

            (new ContainerBindingScanner($registry))->scan($graph);

            $array = $graph->toArray();
            $this->assertSame([], $autoloaded);
            $this->assertGraphHasNode($array, 'LazySafety\\Contract', 'class');
            $this->assertGraphHasNode($array, 'LazySafety\\Implementation', 'class');
            $this->assertGraphHasEdge(
                $array,
                'LazySafety\\Contract',
                'LazySafety\\Implementation',
                'resolves_to',
            );
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public function test_aliases_and_alias_chains_emit_exact_resolution_evidence(): void
    {
        $container = new Container();
        $container->bind(BindingContract::class, DefaultBindingImplementation::class);
        $container->when(BindingConsumer::class)
            ->needs(BindingContract::class)
            ->give(ContextualBindingImplementation::class);
        $container->alias(BindingContract::class, 'billing');
        $container->alias('billing', 'billing.secondary');
        $registry = new ContainerBindingRegistry($container, 'testing', 'AppGraph\\Tests\\Unit\\');

        $alias = $registry->resolve('billing');
        $chain = $registry->resolve('billing.secondary');
        $contextual = $registry->resolve('billing', BindingConsumer::class);

        $this->assertSame(DefaultBindingImplementation::class, $alias['concrete']);
        $this->assertSame('container_alias', $alias['inference']);
        $this->assertSame(BindingContract::class, $alias['aliasTarget']);
        $this->assertSame(ContextualBindingImplementation::class, $contextual['concrete']);
        $this->assertSame('container_alias', $contextual['inference']);
        $this->assertSame('contextual', $contextual['scope']);
        $this->assertSame([
            'billing.secondary',
            'billing',
            BindingContract::class,
            DefaultBindingImplementation::class,
        ], $chain['resolutionPath']);
        $this->assertTrue($registry->hasDefaultDeclaration('billing'));
        $this->assertTrue($registry->hasDeclaration('billing.secondary'));

        $graph = new Graph();
        (new ContainerBindingScanner($registry))->scan($graph);
        $array = $graph->toArray();

        $this->assertGraphHasNode($array, 'container:billing', 'container_binding');
        $this->assertGraphHasNode($array, 'container:billing.secondary', 'container_binding');
        $this->assertGraphHasEdge(
            $array,
            'container:billing.secondary',
            DefaultBindingImplementation::class,
            'resolves_to',
        );
        $this->assertGraphHasEdge(
            $array,
            'container:billing',
            ContextualBindingImplementation::class,
            'resolves_to',
        );
        $edge = $this->graphEdge(
            $array,
            'container:billing.secondary',
            DefaultBindingImplementation::class,
            'resolves_to',
        );
        $evidence = $edge['metadata']['bindings']['default:default'];
        $this->assertSame('container_alias', $evidence['inference']);
        $this->assertSame(BindingContract::class, $evidence['aliasTarget']);
        $this->assertSame('billing', $evidence['aliasDirectTarget']);
    }

    public function test_aliases_to_loaded_auto_concretes_are_proven_without_factories_or_autoloaders(): void
    {
        AutoResolvedAliasService::$constructions = 0;
        $factoryInvoked = false;
        $autoloaded = [];
        $loader = static function (string $class) use (&$autoloaded): void {
            if (str_starts_with($class, 'LazyAlias\\')) {
                $autoloaded[] = $class;
            }
        };
        spl_autoload_register($loader, true, true);

        try {
            $container = new Container();
            $container->alias(AutoResolvedAliasService::class, 'auto-service');
            $container->alias('LazyAlias\\UnloadedService', 'lazy-service');
            $container->bind(UnknownBindingContract::class, function () use (&$factoryInvoked) {
                $factoryInvoked = true;

                return new UnknownBindingImplementation();
            });
            $container->alias(UnknownBindingContract::class, 'unknown-service');
            $registry = new ContainerBindingRegistry($container, 'testing', 'AppGraph\\Tests\\Unit\\');

            $auto = $registry->resolve('auto-service');

            $this->assertSame(AutoResolvedAliasService::class, $auto['concrete']);
            $this->assertSame('container_alias', $auto['inference']);
            $this->assertSame('auto_concrete', $auto['terminalInference']);
            $this->assertNull($registry->resolve('lazy-service'));
            $this->assertNull($registry->resolve('unknown-service'));
            $this->assertTrue($registry->hasDeclaration('lazy-service'));

            $graph = new Graph();
            (new ContainerBindingScanner($registry))->scan($graph);
            $registry->fingerprint(true);

            $this->assertSame(0, AutoResolvedAliasService::$constructions);
            $this->assertFalse($factoryInvoked);
            $this->assertSame([], $autoloaded);
            $this->assertContains('alias_target_unknown', array_column($registry->diagnostics(), 'reason'));
            $this->assertGraphHasEdge(
                $graph->toArray(),
                'container:auto-service',
                AutoResolvedAliasService::class,
                'resolves_to',
            );
        } finally {
            spl_autoload_unregister($loader);
        }
    }
}
