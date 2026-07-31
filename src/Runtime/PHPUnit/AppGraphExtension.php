<?php

namespace AppGraph\Runtime\PHPUnit;

use AppGraph\Runtime\JsonRuntimeEvidenceStore;
use AppGraph\Runtime\RuntimeEvidenceCollector;
use AppGraph\Runtime\RuntimeEvidenceRegistry;
use AppGraph\Runtime\RuntimeSourceScope;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use ReflectionProperty;
use Throwable;

/**
 * Opt-in PHPUnit/Pest adapter for AppGraph runtime evidence.
 *
 * Registering the extension is inert unless APPGRAPH_RUNTIME=1 (or the XML
 * parameter `enabled` is true), keeping ordinary test runs free of coverage
 * overhead and Laravel listeners. It records directly unless PHPUnit already
 * owns the coverage driver, in which case it safely piggybacks that session.
 */
final class AppGraphExtension implements Extension
{
    public function bootstrap(
        Configuration $configuration,
        Facade $facade,
        ParameterCollection $parameters,
    ): void {
        if (! $this->enabled($parameters)) {
            return;
        }

        $driver = CoverageDriver::detect();

        if ($this->pestTiaIsActive() || $driver === null) {
            return;
        }

        try {
            $root = $this->projectRoot($parameters, $configuration);
            $output = $this->outputPath($parameters, $root);
            $scope = new RuntimeSourceScope(
                $root,
                $this->sourceExclusions($configuration),
            );
            $collector = new RuntimeEvidenceCollector($root);
            $collector->primeSourceHashes($scope->phpFiles());
            $store = new JsonRuntimeEvidenceStore($output, $root);
            $session = new PhpUnitRuntimeSession(
                $collector,
                $store,
                new DirectCoverageRecorder($scope, $driver),
                ! $configuration->processIsolation(),
            );

            RuntimeEvidenceRegistry::activate($collector);
            $this->armAlreadyBootstrappedLaravel();
            $subscribers = [
                new TestPreparationStartedSubscriber($session),
                new TestPreparationFailedSubscriber($session),
            ];

            // PHPUnit 12 split preparation errors from preparation failures.
            // Keep the PHPUnit 12-only subscriber out of the autoload path on
            // PHPUnit 11, where this interface does not exist.
            if (interface_exists(\PHPUnit\Event\Test\PreparationErroredSubscriber::class)) {
                $subscribers[] = new TestPreparationErroredSubscriber($session);
            }

            $subscribers = [
                ...$subscribers,
                new TestSkippedSubscriber($session),
                new TestMarkedIncompleteSubscriber($session),
                new TestFinishedSubscriber($session),
                new ExecutionFinishedSubscriber($session),
            ];

            $facade->registerSubscribers(...$subscribers);

        } catch (Throwable) {
            RuntimeEvidenceRegistry::deactivate();
            // Evidence collection is auxiliary and must never block the suite.
        }
    }

    private function enabled(ParameterCollection $parameters): bool
    {
        $environment = getenv('APPGRAPH_RUNTIME');

        if (is_string($environment) && trim($environment) !== '') {
            return filter_var($environment, FILTER_VALIDATE_BOOL);
        }

        return $parameters->has('enabled')
            && filter_var($parameters->get('enabled'), FILTER_VALIDATE_BOOL);
    }

    private function projectRoot(
        ParameterCollection $parameters,
        Configuration $configuration,
    ): string
    {
        $environment = getenv('APPGRAPH_RUNTIME_ROOT');
        $configured = is_string($environment) && trim($environment) !== ''
            ? trim($environment)
            : ($parameters->has('root') ? trim($parameters->get('root')) : '');
        $workingDirectory = getcwd() ?: '.';

        if ($configured === '') {
            return $configuration->hasConfigurationFile()
                ? dirname($configuration->configurationFile())
                : $workingDirectory;
        }

        return $this->isAbsolutePath($configured)
            ? $configured
            : $workingDirectory.'/'.$configured;
    }

    private function pestTiaIsActive(): bool
    {
        if (filter_var(getenv('PEST_TIA'), FILTER_VALIDATE_BOOL)) {
            return true;
        }

        foreach ($_SERVER['argv'] ?? [] as $argument) {
            if ($argument === '--tia' || (is_string($argument) && str_starts_with($argument, '--tia='))) {
                return true;
            }
        }

        $containerClass = 'Pest\\Support\\Container';
        $recorderClass = 'Pest\\Plugins\\Tia\\Recorder';

        if (class_exists($containerClass, false)
            && class_exists($recorderClass, false)
            && method_exists($containerClass, 'getInstance')) {
            try {
                $container = $containerClass::getInstance();
                $recorder = is_object($container) && method_exists($container, 'get')
                    ? $container->get($recorderClass)
                    : null;

                if (is_object($recorder)
                    && method_exists($recorder, 'isActive')
                    && $recorder->isActive() === true) {
                    return true;
                }
            } catch (Throwable) {
                // Fall through: the command-line and environment checks above
                // remain the stable cross-version Pest integration contract.
            }
        }

        return false;
    }

    /** @return list<string> */
    private function sourceExclusions(Configuration $configuration): array
    {
        $exclusions = [];

        foreach ([
            $configuration->source()->excludeDirectories(),
            $configuration->source()->excludeFiles(),
        ] as $configuredExclusions) {
            foreach ($configuredExclusions as $exclusion) {
                if (is_object($exclusion) && method_exists($exclusion, 'path')) {
                    $path = $exclusion->path();

                    if (is_string($path) && $path !== '') {
                        $exclusions[$path] = true;
                    }
                }
            }
        }

        return array_keys($exclusions);
    }

    private function outputPath(ParameterCollection $parameters, string $root): string
    {
        $environment = getenv('APPGRAPH_RUNTIME_OUTPUT');
        $configured = is_string($environment) && trim($environment) !== ''
            ? trim($environment)
            : ($parameters->has('output')
                ? trim($parameters->get('output'))
                : 'storage/appgraph/runtime-evidence.json');

        if ($configured === '') {
            $configured = 'storage/appgraph/runtime-evidence.json';
        }

        return $this->isAbsolutePath($configured) ? $configured : rtrim($root, '/\\').'/'.$configured;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function armAlreadyBootstrappedLaravel(): void
    {
        $containerClass = 'Illuminate\\Container\\Container';

        // Do not autoload Laravel or call Container::getInstance(): that method
        // creates a blank global container when the application has not booted.
        if (! class_exists($containerClass, false)
            || ! property_exists($containerClass, 'instance')) {
            return;
        }

        $application = (new ReflectionProperty($containerClass, 'instance'))->getValue();

        if (is_object($application)) {
            RuntimeEvidenceRegistry::armLaravel($application);
        }
    }
}
