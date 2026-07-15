<?php

namespace AppGraph\Scanners;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node as GraphNode;
use AppGraph\Scanners\Concerns\InteractsWithPhpAst;
use AppGraph\Support\FileFinder;
use AppGraph\Support\PhpFileFacts;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

class TestScanner
{
    use InteractsWithPhpAst;

    /** @var array<string, array<string, mixed>> */
    private array $classes = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $methods = [];

    /** @var array<string, array<int, string>> */
    private array $routesByName = [];

    /** @var array<int, array{id: string, methods: array<int, string>, uri: string}> */
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
        $this->methods = [];
        $this->indexRoutes($graph);

        foreach ($this->files->findPhpFiles('tests') as $file) {
            $statements = $this->parseFile($file, $graph);

            if ($statements !== null) {
                $this->scanStatements($graph, $statements, $this->files->relativePath($file) ?? $file);
            }
        }

        return $graph;
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

            $this->classes[$class] = ['extends' => $this->resolvedName($statement->extends)];

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
                ]));

                foreach ($method->stmts ?? [] as $methodStatement) {
                    $this->walk($graph, $methodStatement, $nodeId);
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

    private function walk(Graph $graph, Node $node, string $testId): void
    {
        if ($node instanceof Expr\FuncCall
            && $node->name instanceof Name
            && $this->looksLikeRouteHelper($node->name->toString())) {
            $name = $node->args[0]->value ?? null;

            if ($name instanceof Scalar\String_) {
                foreach ($this->routesByName[$name->value] ?? [] as $routeId) {
                    $graph->addEdge(new Edge($testId, $routeId, 'tests_route', 1.0, [
                        'routeName' => $name->value,
                        'helper' => $node->name->toString(),
                        'line' => $node->getStartLine(),
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

            if ($httpMethod !== null && $uri instanceof Scalar\String_) {
                foreach ($this->routes as $route) {
                    if (in_array($httpMethod, $route['methods'], true) && $this->uriMatches($route['uri'], $uri->value)) {
                        $graph->addEdge(new Edge($testId, $route['id'], 'tests_route', 0.9, [
                            'httpMethod' => $httpMethod,
                            'uri' => $uri->value,
                            'line' => $node->getStartLine(),
                        ]));
                    }
                }
            }
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof Node) {
                $this->walk($graph, $value, $testId);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $this->walk($graph, $item, $testId);
                    }
                }
            }
        }
    }

    private function looksLikeRouteHelper(string $function): bool
    {
        $position = strrpos($function, '\\');
        $basename = strtolower($position === false ? $function : substr($function, $position + 1));

        return str_contains($basename, 'route');
    }

    private function uriMatches(string $routeUri, string $requestedUri): bool
    {
        $path = parse_url($requestedUri, PHP_URL_PATH);

        if (! is_string($path)) {
            return false;
        }

        $quoted = preg_quote('/'.ltrim($routeUri, '/'), '~');
        $pattern = preg_replace('~\\\\\{[^}]+\\\\\}~', '[^/]+', $quoted);

        return is_string($pattern) && preg_match('~^'.$pattern.'/?$~', '/'.ltrim($path, '/')) === 1;
    }
}
