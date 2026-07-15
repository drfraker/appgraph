<?php

namespace AppGraph\Scanners;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node as GraphNode;
use AppGraph\Scanners\Concerns\InteractsWithPhpAst;
use AppGraph\Support\FileFinder;
use AppGraph\Support\PhpFileFacts;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

class SideEffectScanner
{
    use InteractsWithPhpAst;

    /** @var array<string, array<string, mixed>> */
    private array $classes = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $methods = [];

    /** @var array<int, string> */
    private array $cacheReads = ['get', 'getMultiple', 'has', 'many', 'remember', 'rememberForever'];

    /** @var array<int, string> */
    private array $cacheWrites = ['add', 'decrement', 'flush', 'forget', 'forever', 'increment', 'put', 'putMany', 'pull', 'remember', 'rememberForever'];

    /** @var array<int, string> */
    private array $filesystemReads = ['download', 'exists', 'get', 'lastModified', 'missing', 'readStream', 'size', 'temporaryUrl', 'url'];

    /** @var array<int, string> */
    private array $filesystemWrites = ['append', 'copy', 'delete', 'deleteDirectory', 'makeDirectory', 'move', 'prepend', 'put', 'putFile', 'putFileAs', 'writeStream'];

    /** @var array<int, string> */
    private array $httpCalls = ['delete', 'get', 'head', 'patch', 'post', 'put', 'send'];

    public function __construct(
        private FileFinder $files,
        ?PhpFileFacts $phpFileFacts = null,
    ) {
        $this->initializePhpFileFacts($phpFileFacts);
    }

    protected function scannerName(): string
    {
        return 'side_effects';
    }

    protected function scannerSourceLabel(): string
    {
        return 'side_effect_scanner';
    }

    public function scan(Graph $graph): Graph
    {
        $this->classes = [];
        $this->methods = [];
        $files = $this->files->findPhpFiles(['app', 'routes']);

        foreach ($files as $file) {
            $statements = $this->parseFile($file, $graph);

            if ($statements !== null) {
                $this->indexStatements($statements, $this->files->relativePath($file) ?? $file);
            }
        }

        foreach ($files as $file) {
            $statements = $this->parseFile($file, $graph);

            if ($statements !== null) {
                $this->scanStatements($graph, $statements);
            }
        }

        return $graph;
    }

    /** @param array<int, Node> $statements */
    private function indexStatements(array $statements, string $relativeFile): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $this->indexStatements($statement->stmts, $relativeFile);
                continue;
            }

            if ((! $statement instanceof Stmt\Class_ && ! $statement instanceof Stmt\Trait_) || $statement->name === null) {
                continue;
            }

            $class = $this->className($statement);

            if ($class === null) {
                continue;
            }

            $this->classes[$class] = [
                'extends' => $statement instanceof Stmt\Class_ ? $this->resolvedName($statement->extends) : null,
            ];

            foreach ($statement->getMethods() as $method) {
                $this->methods[$class][$method->name->toString()] = [
                    'node' => $method,
                    'file' => $relativeFile,
                    'line' => $method->getStartLine(),
                    'signature' => $this->methodSignature($method),
                    'inputs' => $this->methodInputs($method),
                    'outputs' => $this->methodOutputs($method),
                    'visibility' => $this->methodVisibility($method),
                    'static' => $method->isStatic(),
                ];
            }
        }
    }

    /** @param array<int, Node> $statements */
    private function scanStatements(Graph $graph, array $statements): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $this->scanStatements($graph, $statement->stmts);
                continue;
            }

            if (! $statement instanceof Stmt\Class_ && ! $statement instanceof Stmt\Trait_) {
                continue;
            }

            $class = $this->className($statement);

            if ($class === null) {
                continue;
            }

            foreach ($statement->getMethods() as $method) {
                $context = ['class' => $class, 'method' => $method->name->toString()];

                foreach ($method->stmts ?? [] as $methodStatement) {
                    $this->walk($graph, $methodStatement, $context);
                }
            }
        }
    }

    /** @param array{class: string, method: string} $context */
    private function walk(Graph $graph, Node $node, array $context): void
    {
        if ($node instanceof Expr\StaticCall && $node->name instanceof Identifier) {
            $this->inspectCall($graph, $node, $node->name->toString(), $this->resolvedName($node->class), $context);
        } elseif ($node instanceof Expr\MethodCall && $node->name instanceof Identifier) {
            $this->inspectCall($graph, $node, $node->name->toString(), $this->facadeRoot($node->var), $context);
        } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
            $this->inspectCacheHelper($graph, $node, $context);
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof Node) {
                $this->walk($graph, $value, $context);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $this->walk($graph, $item, $context);
                    }
                }
            }
        }
    }

    /**
     * @param Expr\StaticCall|Expr\MethodCall $call
     * @param array{class: string, method: string} $context
     */
    private function inspectCall(Graph $graph, Expr\StaticCall|Expr\MethodCall $call, string $operation, ?string $root, array $context): void
    {
        $facade = class_basename((string) $root);

        if ($facade === 'Cache') {
            $key = $operation === 'flush' ? '*' : $this->literalArg($call->args[0] ?? null);

            if (in_array($operation, $this->cacheReads, true)) {
                $this->addEffect($graph, $context, 'cache', $this->cacheStore($call), $key, 'reads_cache', $operation, $call->getStartLine());
            }

            if (in_array($operation, $this->cacheWrites, true)) {
                $this->addEffect($graph, $context, 'cache', $this->cacheStore($call), $key, 'writes_cache', $operation, $call->getStartLine());
            }

            return;
        }

        if ($facade === 'Storage') {
            $path = $this->literalArg($call->args[0] ?? null);

            if (in_array($operation, $this->filesystemReads, true)) {
                $this->addEffect($graph, $context, 'filesystem', $this->storageDisk($call), $path, 'reads_filesystem', $operation, $call->getStartLine());
            }

            if (in_array($operation, $this->filesystemWrites, true)) {
                $this->addEffect($graph, $context, 'filesystem', $this->storageDisk($call), $path, 'writes_filesystem', $operation, $call->getStartLine());
            }

            return;
        }

        if ($facade === 'Http' && in_array($operation, $this->httpCalls, true)) {
            $argumentIndex = $operation === 'send' ? 1 : 0;
            $url = $this->literalArg($call->args[$argumentIndex] ?? null);
            $this->addEffect($graph, $context, 'external_service', 'http', $url, 'calls_external', $operation, $call->getStartLine());
        }
    }

    /** @param array{class: string, method: string} $context */
    private function inspectCacheHelper(Graph $graph, Expr\FuncCall $call, array $context): void
    {
        if (strtolower($call->name->toString()) !== 'cache' || $call->args === []) {
            return;
        }

        $first = $call->args[0] instanceof Arg ? $call->args[0]->value : null;

        if ($first instanceof Expr\Array_) {
            foreach ($first->items as $item) {
                $key = $item?->key instanceof Scalar\String_ ? $item->key->value : null;
                $this->addEffect($graph, $context, 'cache', 'default', $key, 'writes_cache', 'put', $call->getStartLine());
            }

            return;
        }

        $this->addEffect($graph, $context, 'cache', 'default', $this->literalExpr($first), 'reads_cache', 'get', $call->getStartLine());
    }

    /** @param array{class: string, method: string} $context */
    private function addEffect(Graph $graph, array $context, string $nodeType, string $scope, ?string $target, string $edgeType, string $operation, int $line): void
    {
        $target ??= '{dynamic}';
        $id = 'side-effect:'.$nodeType.':'.$scope.':'.$target;
        $caller = $context['class'].'::'.$context['method'];

        if (isset($this->methods[$context['class']][$context['method']])) {
            $graph->addNode($this->methodNode($context['class'], $context['method'], $this->methods[$context['class']][$context['method']]));
        }

        $graph->addNode(GraphNode::make($id, $nodeType, $scope.':'.$target, [
            'metadata' => array_filter([
                'scope' => $scope,
                'target' => $target,
                'dynamic' => $target === '{dynamic}' ? true : null,
            ], static fn ($value): bool => $value !== null),
        ]));
        $graph->addEdge(new Edge($caller, $id, $edgeType, $target === '{dynamic}' ? 0.45 : 0.95, [
            'operation' => $operation,
            'line' => $line,
        ]));
    }

    private function facadeRoot(Expr $expression): ?string
    {
        while ($expression instanceof Expr\MethodCall) {
            $expression = $expression->var;
        }

        return $expression instanceof Expr\StaticCall ? $this->resolvedName($expression->class) : null;
    }

    private function cacheStore(Expr\StaticCall|Expr\MethodCall $call): string
    {
        return $call instanceof Expr\MethodCall ? $this->chainScope($call->var, 'store') ?? 'default' : 'default';
    }

    private function storageDisk(Expr\StaticCall|Expr\MethodCall $call): string
    {
        return $call instanceof Expr\MethodCall ? $this->chainScope($call->var, 'disk') ?? 'default' : 'default';
    }

    private function chainScope(Expr $expression, string $method): ?string
    {
        while ($expression instanceof Expr\MethodCall) {
            if ($expression->name instanceof Identifier && $expression->name->toString() === $method) {
                return $this->literalArg($expression->args[0] ?? null) ?? '{dynamic}';
            }

            $expression = $expression->var;
        }

        if ($expression instanceof Expr\StaticCall
            && $expression->name instanceof Identifier
            && $expression->name->toString() === $method) {
            return $this->literalArg($expression->args[0] ?? null) ?? '{dynamic}';
        }

        return null;
    }

    private function literalArg(Arg|Node\VariadicPlaceholder|null $arg): ?string
    {
        return $arg instanceof Arg ? $this->literalExpr($arg->value) : null;
    }

    private function literalExpr(?Expr $expression): ?string
    {
        return match (true) {
            $expression instanceof Scalar\String_ => $expression->value,
            $expression instanceof Scalar\Int_ => (string) $expression->value,
            default => null,
        };
    }
}
