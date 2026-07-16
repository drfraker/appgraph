<?php

namespace AppGraph\Scanners;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node as GraphNode;
use AppGraph\Scanners\Concerns\InteractsWithPhpAst;
use AppGraph\Scanners\Concerns\MatchesRouteTargets;
use AppGraph\Support\FileFinder;
use AppGraph\Support\PhpFileFacts;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

class TestScanner
{
    use InteractsWithPhpAst;
    use MatchesRouteTargets;

    private const MAKES_HTTP_REQUESTS = 'Illuminate\\Foundation\\Testing\\Concerns\\MakesHttpRequests';

    /** @var array<int, string> */
    private const LARAVEL_HTTP_TEST_CASES = [
        'Illuminate\\Foundation\\Testing\\TestCase',
        'Orchestra\\Testbench\\TestCase',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $classes = [];

    /** @var array<string, array{traits: array<int, string>}> */
    private array $traits = [];

    /** @var array<string, string> */
    private array $classNames = [];

    /** @var array<string, string> */
    private array $traitNames = [];

    /** @var array<string, true> */
    private array $localFunctions = [];

    /** @var array<string, array<int, string>> */
    private array $routesByName = [];

    /** @var array<int, array{id: string, methods: array<int, string>, uri: string, domain: string|null}> */
    private array $routes = [];

    public function __construct(
        private FileFinder $files,
        ?PhpFileFacts $phpFileFacts = null,
    ) {
        $this->initializePhpFileFacts($phpFileFacts);
    }

    protected function scannerName(): string
    {
        return 'tests';
    }

    protected function scannerSourceLabel(): string
    {
        return 'test_scanner';
    }

    public function scan(Graph $graph): Graph
    {
        $this->classes = [];
        $this->traits = [];
        $this->classNames = [];
        $this->traitNames = [];
        $this->localFunctions = [];
        $this->indexRoutes($graph);
        $parsedFiles = [];

        foreach ($this->files->findPhpFiles('tests') as $file) {
            $statements = $this->parseFile($file, $graph);

            if ($statements !== null) {
                $parsedFiles[] = [
                    'statements' => $statements,
                    'file' => $this->files->relativePath($file) ?? $file,
                ];
            }
        }

        foreach ($parsedFiles as $parsedFile) {
            $this->indexTestSymbols($parsedFile['statements']);
        }

        foreach ($parsedFiles as $parsedFile) {
            $this->scanStatements($graph, $parsedFile['statements'], $parsedFile['file']);
        }

        return $graph;
    }

    /** @param array<int, Node> $statements */
    private function indexTestSymbols(array $statements): void
    {
        $finder = new NodeFinder();

        foreach ($finder->findInstanceOf($statements, Stmt\Class_::class) as $class) {
            $name = $this->className($class);

            if ($name === null) {
                continue;
            }

            $this->classes[$name] = [
                'extends' => $this->resolvedName($class->extends),
                'traits' => $this->usedTraits($class),
            ];
            $this->classNames[strtolower($name)] = $name;
        }

        foreach ($finder->findInstanceOf($statements, Stmt\Trait_::class) as $trait) {
            $name = $this->className($trait);

            if ($name !== null) {
                $this->traits[$name] = ['traits' => $this->usedTraits($trait)];
                $this->traitNames[strtolower($name)] = $name;
            }
        }

        foreach ($finder->findInstanceOf($statements, Stmt\Function_::class) as $function) {
            $namespacedName = $function->namespacedName ?? null;
            $name = $namespacedName instanceof Name
                ? $namespacedName->toString()
                : $function->name->toString();
            $this->localFunctions[strtolower(ltrim($name, '\\'))] = true;
        }
    }

    /** @return array<int, string> */
    private function usedTraits(Stmt\Class_|Stmt\Trait_ $statement): array
    {
        $traits = [];

        foreach ($statement->stmts as $member) {
            if (! $member instanceof Stmt\TraitUse) {
                continue;
            }

            foreach ($member->traits as $trait) {
                $resolved = $this->resolvedName($trait);

                if (is_string($resolved)) {
                    $traits[$resolved] = $resolved;
                }
            }
        }

        ksort($traits);

        return array_values($traits);
    }

    private function indexRoutes(Graph $graph): void
    {
        $this->routesByName = [];
        $this->routes = [];

        foreach ($graph->nodes() as $node) {
            if ($node->type !== 'route') {
                continue;
            }

            $name = $node->metadata['name'] ?? null;

            if (is_string($name) && $name !== '') {
                $this->routesByName[$name][] = $node->id;
            }

            $uri = $node->metadata['uri'] ?? null;

            if (is_string($uri)) {
                $this->routes[] = [
                    'id' => $node->id,
                    'methods' => array_map('strtoupper', $node->metadata['methods'] ?? []),
                    'uri' => '/'.ltrim($uri, '/'),
                    'domain' => is_string($node->metadata['domain'] ?? null)
                        ? $node->metadata['domain']
                        : null,
                ];
            }
        }
    }

    /** @param array<int, Node> $statements */
    private function scanStatements(Graph $graph, array $statements, string $file): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $this->scanStatements($graph, $statement->stmts, $file);
                continue;
            }

            if (! $statement instanceof Stmt\Class_ || ($class = $this->className($statement)) === null) {
                continue;
            }

            foreach ($statement->getMethods() as $method) {
                if (! $this->isTestMethod($method)) {
                    continue;
                }

                $methodName = $method->name->toString();
                $nodeId = 'test:'.$class.'::'.$methodName;
                $graph->addNode(GraphNode::make($nodeId, 'test', class_basename($class).'::'.$methodName, [
                    'class' => class_basename($class),
                    'method' => $methodName,
                    'file' => $file,
                    'line' => $method->getStartLine(),
                    'endLine' => $method->getEndLine(),
                ]));

                foreach ($method->stmts ?? [] as $methodStatement) {
                    $this->walk($graph, $methodStatement, $nodeId, $class);
                }
            }
        }
    }

    private function isTestMethod(Stmt\ClassMethod $method): bool
    {
        if (str_starts_with($method->name->toString(), 'test')) {
            return true;
        }

        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (class_basename((string) $this->resolvedName($attribute->name)) === 'Test') {
                    return true;
                }
            }
        }

        return false;
    }

    private function walk(Graph $graph, Node $node, string $testId, string $class): void
    {
        if ($node instanceof Expr\FuncCall
            && $this->isSupportedRouteHelper($node, $class)) {
            $name = $node->args[0]->value ?? null;

            if ($name instanceof Scalar\String_) {
                foreach ($this->routesByName[$name->value] ?? [] as $routeId) {
                    $graph->addEdge(new Edge($testId, $routeId, 'tests_route', 1.0, [
                        'routeName' => $name->value,
                        'helper' => $node->name->toString(),
                        'resolvedHelper' => 'route',
                        'line' => $node->getStartLine(),
                        'matchCertainty' => 'exact',
                    ]));
                }
            }
        }

        if ($node instanceof Expr\MethodCall && $node->name instanceof Identifier) {
            $method = strtolower($node->name->toString());
            $httpMethod = match ($method) {
                'get', 'getjson' => 'GET',
                'post', 'postjson' => 'POST',
                'put', 'putjson' => 'PUT',
                'patch', 'patchjson' => 'PATCH',
                'delete', 'deletejson' => 'DELETE',
                default => null,
            };
            $uri = $node->args[0]->value ?? null;

            if ($httpMethod !== null
                && $uri instanceof Scalar\String_
                && $this->isProvenLaravelHttpReceiver($node->var, $class)) {
                foreach ($this->literalRouteMatches($this->routes, $httpMethod, $uri->value) as $match) {
                    $route = $match['route'];
                    $graph->addEdge(new Edge(
                        $testId,
                        $route['id'],
                        'tests_route',
                        $match['certainty'] === 'exact' ? 0.9 : 0.55,
                        array_filter([
                            'httpMethod' => $httpMethod,
                            'uri' => $uri->value,
                            'requestedHost' => $match['requestedHost'],
                            'routeDomain' => $route['domain'],
                            'matchCertainty' => $match['certainty'],
                            'ambiguity' => $match['ambiguity'],
                            'candidateCount' => $match['candidateCount'],
                            'line' => $node->getStartLine(),
                            'receiver' => '$this',
                            'receiverProven' => true,
                        ], static fn (mixed $value): bool => $value !== null),
                    ));
                }
            }
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof Node) {
                $this->walk($graph, $value, $testId, $class);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $this->walk($graph, $item, $testId, $class);
                    }
                }
            }
        }
    }

    private function isSupportedRouteHelper(Expr\FuncCall $call, string $class): bool
    {
        if (! $call->name instanceof Name) {
            return false;
        }

        if ($call->name->isFullyQualified()) {
            return strcasecmp(ltrim($call->name->toString(), '\\'), 'route') === 0
                && ! isset($this->localFunctions['route']);
        }

        $resolvedName = $call->name->getAttribute('resolvedName');

        if ($resolvedName instanceof Name) {
            return strcasecmp(ltrim($resolvedName->toString(), '\\'), 'route') === 0
                && ! isset($this->localFunctions['route']);
        }

        if (! $call->name->isUnqualified() || strcasecmp($call->name->toString(), 'route') !== 0) {
            return false;
        }

        $namespace = $this->namespaceFromClass($class);
        $localFunction = strtolower(($namespace === null ? '' : $namespace.'\\').'route');

        return ! isset($this->localFunctions[$localFunction])
            && ! isset($this->localFunctions['route']);
    }

    private function isProvenLaravelHttpReceiver(Expr $receiver, string $class): bool
    {
        return $receiver instanceof Expr\Variable
            && $receiver->name === 'this'
            && $this->classSupportsLaravelHttpRequests($class);
    }

    /** @param array<string, true> $visited */
    private function classSupportsLaravelHttpRequests(string $class, array $visited = []): bool
    {
        foreach (self::LARAVEL_HTTP_TEST_CASES as $testCase) {
            if (strcasecmp(ltrim($class, '\\'), $testCase) === 0) {
                return true;
            }
        }

        $visitKey = 'class:'.strtolower(ltrim($class, '\\'));

        if (isset($visited[$visitKey])) {
            return false;
        }

        $visited[$visitKey] = true;
        $class = $this->classNames[strtolower(ltrim($class, '\\'))] ?? $class;
        $record = $this->classes[$class] ?? null;

        if (! is_array($record)) {
            return false;
        }

        foreach ($record['traits'] ?? [] as $trait) {
            if ($this->traitSupportsLaravelHttpRequests($trait, $visited)) {
                return true;
            }
        }

        $parent = $record['extends'] ?? null;

        return is_string($parent)
            && $this->classSupportsLaravelHttpRequests($parent, $visited);
    }

    /** @param array<string, true> $visited */
    private function traitSupportsLaravelHttpRequests(string $trait, array $visited): bool
    {
        if (strcasecmp(ltrim($trait, '\\'), self::MAKES_HTTP_REQUESTS) === 0) {
            return true;
        }

        $visitKey = 'trait:'.strtolower(ltrim($trait, '\\'));

        if (isset($visited[$visitKey])) {
            return false;
        }

        $visited[$visitKey] = true;
        $trait = $this->traitNames[strtolower(ltrim($trait, '\\'))] ?? $trait;
        $record = $this->traits[$trait] ?? null;

        if (! is_array($record)) {
            return false;
        }

        foreach ($record['traits'] ?? [] as $nestedTrait) {
            if ($this->traitSupportsLaravelHttpRequests($nestedTrait, $visited)) {
                return true;
            }
        }

        return false;
    }
}
