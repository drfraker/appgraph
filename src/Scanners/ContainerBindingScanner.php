<?php

namespace AppGraph\Scanners;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Support\ContainerBindingRegistry;

class ContainerBindingScanner
{
    public function __construct(private ContainerBindingRegistry $bindings)
    {
    }

    public function scan(Graph $graph): Graph
    {
        $knownClasses = $this->knownClasses($graph);
        $emitted = 0;

        foreach ($this->bindings->bindings() as $binding) {
            if (! $this->isRelevant($binding, $knownClasses)) {
                continue;
            }

            $abstract = (string) $binding['abstract'];
            $concrete = (string) $binding['concrete'];

            if ($abstract === $concrete) {
                continue;
            }

            $abstractId = $this->nodeId($abstract);
            $concreteId = $this->nodeId($concrete);
            $this->addMissingNode($graph, $abstractId, $abstract, true);
            $this->addMissingNode($graph, $concreteId, $concrete, false);
            $occurrenceKey = $binding['scope'].':'.($binding['consumer'] ?? 'default');
            $graph->addEdge(new Edge($abstractId, $concreteId, 'resolves_to', (float) $binding['confidence'], [
                'source' => 'booted_container',
                'environment' => $binding['environment'],
                'bindings' => [
                    $occurrenceKey => array_filter([
                        'scope' => $binding['scope'],
                        'consumer' => $binding['consumer'] ?? null,
                        'shared' => $binding['shared'],
                        'lifetime' => $binding['lifetime'],
                        'effectiveLifetime' => $binding['effectiveLifetime'] ?? $binding['lifetime'],
                        'certainty' => $binding['certainty'],
                        'inference' => $binding['inference'],
                        'terminalInference' => $binding['terminalInference'] ?? null,
                        'resolutionPath' => $binding['resolutionPath'] ?? null,
                        'aliasTarget' => $binding['aliasTarget'] ?? null,
                        'aliasDirectTarget' => $binding['aliasDirectTarget'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null),
                ],
            ]));
            $emitted++;
        }

        $diagnostics = array_values(array_filter(
            $this->bindings->diagnostics(),
            fn (array $diagnostic): bool => $this->diagnosticIsRelevant($diagnostic, $knownClasses),
        ));
        $byReason = [];

        foreach ($diagnostics as $diagnostic) {
            $reason = (string) $diagnostic['reason'];
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
        }

        ksort($byReason);
        $graph->addMeta([
            'analysis' => [
                'containerBindings' => [
                    'environment' => $this->bindings->environment(),
                    'resolvedCount' => $emitted,
                    'unresolvedCount' => count($diagnostics),
                    'unresolvedByReason' => $byReason,
                    'samples' => array_slice($diagnostics, 0, 50),
                ],
            ],
        ]);

        return $graph;
    }

    /** @return array<string, true> */
    private function knownClasses(Graph $graph): array
    {
        $known = [];

        foreach ($graph->nodes() as $node) {
            if (str_contains($node->id, '::')) {
                $known[strstr($node->id, '::', true)] = true;
            }

            if (! str_contains($node->id, ':') && str_contains($node->id, '\\')) {
                $known[$node->id] = true;
            }
        }

        return $known;
    }

    /** @param array<string, mixed> $binding @param array<string, true> $known */
    private function isRelevant(array $binding, array $known): bool
    {
        $abstract = (string) $binding['abstract'];
        $concrete = (string) $binding['concrete'];
        $consumer = $binding['consumer'] ?? null;

        return isset($binding['aliasTarget'])
            || isset($known[$abstract], $known[$concrete])
            || isset($known[$abstract])
            || isset($known[$concrete])
            || (is_string($consumer) && isset($known[$consumer]))
            || $this->inApplicationNamespace($abstract)
            || $this->inApplicationNamespace($concrete);
    }

    /** @param array<string, mixed> $diagnostic @param array<string, true> $known */
    private function diagnosticIsRelevant(array $diagnostic, array $known): bool
    {
        $abstract = (string) $diagnostic['abstract'];
        $consumer = $diagnostic['consumer'] ?? null;

        return isset($diagnostic['aliasTarget'])
            || isset($diagnostic['aliasDirectTarget'])
            || isset($known[$abstract])
            || (is_string($consumer) && isset($known[$consumer]))
            || $this->inApplicationNamespace($abstract);
    }

    private function inApplicationNamespace(string $class): bool
    {
        $namespace = $this->bindings->applicationNamespace();

        return is_string($namespace) && $namespace !== '' && str_starts_with($class, $namespace);
    }

    private function nodeId(string $binding): string
    {
        return str_contains($binding, '\\') ? $binding : 'container:'.$binding;
    }

    private function addMissingNode(Graph $graph, string $id, string $binding, bool $abstract): void
    {
        if ($graph->hasNode($id)) {
            return;
        }

        $type = match (true) {
            // Classifying a binding must not autoload its file: application
            // files may contain top-level code. Already-loaded interfaces are
            // exact; otherwise retain the class-shaped declaration without
            // executing the autoloader merely to improve its label.
            interface_exists($binding, false) => 'interface',
            str_starts_with($id, 'container:') => 'container_binding',
            default => 'class',
        };
        $graph->addNode(Node::make($id, $type, str_starts_with($id, 'container:') ? $binding : class_basename($binding), [
            'namespace' => str_contains($binding, '\\') ? substr($binding, 0, (int) strrpos($binding, '\\')) : null,
            'metadata' => [
                'source' => 'booted_container',
                'bindingRole' => $abstract ? 'abstract' : 'concrete',
            ],
        ]));
    }
}
