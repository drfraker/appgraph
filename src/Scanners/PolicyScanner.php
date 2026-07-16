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
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

class PolicyScanner
{
    use InteractsWithPhpAst;

    private const AUTHORIZES_REQUESTS = 'Illuminate\Foundation\Auth\Access\AuthorizesRequests';

    private const GATE_FACADE = 'Illuminate\Support\Facades\Gate';

    private const GATE_CONTRACT = 'Illuminate\Contracts\Auth\Access\Gate';

    private const GATE_IMPLEMENTATION = 'Illuminate\Auth\Access\Gate';

    /** @var array<string, array<string, mixed>> */
    private array $classes = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $methods = [];

    /** @var array<string, array<int, string>> */
    private array $traits = [];

    /** @var array<string, string> */
    private array $policiesByModel = [];

    /** @var array<string, array<int, string>> */
    private array $modelsByShortName = [];

    public function __construct(
        private FileFinder $files,
        ?PhpFileFacts $phpFileFacts = null,
    ) {
        $this->initializePhpFileFacts($phpFileFacts);
    }

    protected function scannerName(): string
    {
        return 'policies';
    }

    protected function scannerSourceLabel(): string
    {
        return 'policy_scanner';
    }

    public function scan(Graph $graph): Graph
    {
        $this->classes = [];
        $this->methods = [];
        $this->traits = [];
        $this->policiesByModel = [];
        $this->modelsByShortName = [];
        $files = $this->files->findPhpFiles(['app', 'routes']);

        foreach ($files as $file) {
            $statements = $this->parseFile($file, $graph);

            if ($statements !== null) {
                $this->indexStatements($statements, $this->files->relativePath($file) ?? $file);
            }
        }

        $this->inferConventionPolicies();

        foreach ($files as $file) {
            $statements = $this->parseFile($file, $graph);

            if ($statements !== null) {
                $this->scanStatements($graph, $statements);
            }
        }

        return $graph;
    }

    /** @param array<int, Node> $statements */
    private function indexStatements(array $statements, string $file): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $this->indexStatements($statement->stmts, $file);
                continue;
            }

            if ($statement instanceof Stmt\Trait_ && ($trait = $this->className($statement)) !== null) {
                $this->traits[$trait] = $this->usedTraits($statement);
                continue;
            }

            if (! $statement instanceof Stmt\Class_ || $statement->name === null || ($class = $this->className($statement)) === null) {
                continue;
            }

            $this->classes[$class] = [
                'file' => $file,
                'line' => $statement->getStartLine(),
                'endLine' => $statement->getEndLine(),
                'extends' => $this->resolvedName($statement->extends),
                'traits' => $this->usedTraits($statement),
                'policy' => str_starts_with($file, 'app/Policies/') || str_contains('\\'.$class, '\\Policies\\'),
                'model' => str_starts_with($file, 'app/Models/') || str_contains('\\'.$class, '\\Models\\'),
            ];

            if ($this->classes[$class]['model']) {
                $this->modelsByShortName[class_basename($class)][] = $class;
            }

            foreach ($statement->getMethods() as $method) {
                $this->methods[$class][$method->name->toString()] = [
                    'node' => $method,
                    'file' => $file,
                    'line' => $method->getStartLine(),
                    'endLine' => $method->getEndLine(),
                    'signature' => $this->methodSignature($method),
                    'inputs' => $this->methodInputs($method),
                    'outputs' => $this->methodOutputs($method),
                    'visibility' => $this->methodVisibility($method),
                    'static' => $method->isStatic(),
                ];
            }

            $this->indexExplicitPolicyMap($statement);
        }
    }

    private function indexExplicitPolicyMap(Stmt\Class_ $class): void
    {
        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Stmt\Property) {
                continue;
            }

            foreach ($statement->props as $property) {
                if ($property->name->toString() !== 'policies' || ! $property->default instanceof Expr\Array_) {
                    continue;
                }

                foreach ($property->default->items as $item) {
                    $model = $item?->key instanceof Expr\ClassConstFetch ? $this->resolvedName($item->key->class) : null;
                    $policy = $item?->value instanceof Expr\ClassConstFetch ? $this->resolvedName($item->value->class) : null;

                    if ($model !== null && $policy !== null) {
                        $this->policiesByModel[$model] = $policy;
                    }
                }
            }
        }
    }

    private function inferConventionPolicies(): void
    {
        foreach ($this->classes as $policy => $record) {
            if (! $record['policy'] || ! str_ends_with(class_basename($policy), 'Policy')) {
                continue;
            }

            $modelName = substr(class_basename($policy), 0, -6);
            $models = $this->modelsByShortName[$modelName] ?? [];

            if (count($models) === 1) {
                $this->policiesByModel[$models[0]] ??= $policy;
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

            if (! $statement instanceof Stmt\Class_ || ($class = $this->className($statement)) === null) {
                continue;
            }

            foreach ($statement->getMethods() as $method) {
                $context = [
                    'class' => $class,
                    'method' => $method->name->toString(),
                    'localTypes' => $this->parameterTypes($method, $class),
                ];

                foreach ($method->stmts ?? [] as $methodStatement) {
                    $this->walk($graph, $methodStatement, $context);
                }
            }
        }
    }

    /** @param array{class: string, method: string, localTypes: array<string, array{class: string, confidence: float, inference: string}>} $context */
    private function walk(Graph $graph, Node $node, array &$context): void
    {
        if ($node instanceof Expr\Assign
            && $node->var instanceof Expr\Variable
            && is_string($node->var->name)
            && ($type = $this->typeFromExpression($node->expr, $context)) !== null) {
            $context['localTypes'][$node->var->name] = $type;
        }

        if (($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) && $node->name instanceof Identifier) {
            $operation = $node->name->toString();

            if (in_array($operation, ['authorize', 'authorizeForUser', 'allows', 'can', 'cannot', 'check', 'denies', 'inspect'], true)
                && $this->isLaravelAuthorizationCall($node, $operation, $context)) {
                $abilityPosition = $operation === 'authorizeForUser' ? 1 : 0;
                $targetPosition = $operation === 'authorizeForUser' ? 2 : 1;
                $abilityArg = $node->args[$abilityPosition] ?? null;
                $targetArg = $node->args[$targetPosition] ?? null;
                $ability = $abilityArg instanceof Arg && $abilityArg->value instanceof Scalar\String_ ? $abilityArg->value->value : null;
                $model = $targetArg instanceof Arg ? $this->modelFromExpression($targetArg->value, $context) : null;

                if ($ability !== null && $model !== null) {
                    $this->addAuthorizationEdge($graph, $context, $model, $ability, $operation, $node->getStartLine());
                }
            }
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
     * @param array{class: string, method: string, localTypes: array<string, array{class: string, confidence: float, inference: string}>} $context
     */
    private function isLaravelAuthorizationCall(
        Expr\MethodCall|Expr\StaticCall $call,
        string $operation,
        array $context,
    ): bool {
        if ($call instanceof Expr\StaticCall) {
            return $this->resolveStaticClass($call->class, $context['class']) === self::GATE_FACADE;
        }

        if ($call->var instanceof Expr\Variable && $call->var->name === 'this') {
            return in_array($operation, ['authorize', 'authorizeForUser'], true)
                && $this->classUsesAuthorizesRequests($context['class']);
        }

        return $this->isGateReceiver($call->var, $context);
    }

    /**
     * @param array{class: string, method: string, localTypes: array<string, array{class: string, confidence: float, inference: string}>} $context
     */
    private function isGateReceiver(Expr $expression, array $context): bool
    {
        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            return in_array(
                $context['localTypes'][$expression->name]['class'] ?? null,
                [self::GATE_CONTRACT, self::GATE_IMPLEMENTATION],
                true,
            );
        }

        if ($expression instanceof Expr\StaticCall) {
            return $expression->name instanceof Identifier
                && $expression->name->toString() === 'forUser'
                && $this->resolveStaticClass($expression->class, $context['class']) === self::GATE_FACADE;
        }

        return $expression instanceof Expr\MethodCall
            && $expression->name instanceof Identifier
            && $expression->name->toString() === 'forUser'
            && $this->isGateReceiver($expression->var, $context);
    }

    private function classUsesAuthorizesRequests(string $class, array $visited = []): bool
    {
        $class = ltrim($class, '\\');

        if (isset($visited[strtolower($class)])) {
            return false;
        }

        $visited[strtolower($class)] = true;
        $record = $this->classes[$class] ?? null;

        if ($record === null) {
            return false;
        }

        foreach ($record['traits'] ?? [] as $trait) {
            if ($this->traitUsesAuthorizesRequests($trait)) {
                return true;
            }
        }

        $parent = $record['extends'] ?? null;

        return is_string($parent) && $this->classUsesAuthorizesRequests($parent, $visited);
    }

    private function traitUsesAuthorizesRequests(string $trait, array $visited = []): bool
    {
        $trait = ltrim($trait, '\\');

        if ($trait === self::AUTHORIZES_REQUESTS) {
            return true;
        }

        if (isset($visited[strtolower($trait)])) {
            return false;
        }

        $visited[strtolower($trait)] = true;

        foreach ($this->traits[$trait] ?? [] as $nested) {
            if ($this->traitUsesAuthorizesRequests($nested, $visited)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function usedTraits(Stmt\Class_|Stmt\Trait_ $class): array
    {
        $traits = [];

        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Stmt\TraitUse) {
                continue;
            }

            foreach ($statement->traits as $trait) {
                $resolved = $this->resolvedName($trait);

                if ($resolved !== null) {
                    $traits[] = $resolved;
                }
            }
        }

        return array_values(array_unique($traits));
    }

    /** @param array{class: string, method: string, localTypes: array<string, array{class: string, confidence: float, inference: string}>} $context */
    private function modelFromExpression(Expr $expression, array $context): ?string
    {
        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            return $context['localTypes'][$expression->name]['class'] ?? null;
        }

        if ($expression instanceof Expr\ClassConstFetch) {
            return $this->resolvedName($expression->class);
        }

        if ($expression instanceof Expr\New_) {
            return $this->resolvedName($expression->class);
        }

        if ($expression instanceof Expr\Array_) {
            foreach ($expression->items as $item) {
                if ($item !== null && ($model = $this->modelFromExpression($item->value, $context)) !== null) {
                    return $model;
                }
            }
        }

        return null;
    }

    /**
     * @param array{class: string, method: string, localTypes: array<string, array{class: string, confidence: float, inference: string}>} $context
     * @return array{class: string, confidence: float, inference: string}|null
     */
    private function typeFromExpression(Expr $expression, array $context): ?array
    {
        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            return $context['localTypes'][$expression->name] ?? null;
        }

        if ($expression instanceof Expr\New_) {
            $class = $this->resolvedName($expression->class);

            return $class === null ? null : [
                'class' => $class,
                'confidence' => 0.95,
                'inference' => 'new_expression',
            ];
        }

        if ($expression instanceof Expr\StaticCall) {
            $class = $this->resolveStaticClass($expression->class, $context['class']);

            if ($class !== null && $expression->name instanceof Identifier && in_array($expression->name->toString(), [
                'create',
                'find',
                'findOrFail',
                'firstOrCreate',
                'make',
                'updateOrCreate',
            ], true)) {
                return [
                    'class' => $class,
                    'confidence' => 0.7,
                    'inference' => 'model_static_result',
                ];
            }
        }

        return null;
    }

    /** @param array{class: string, method: string, localTypes: array<string, array{class: string, confidence: float, inference: string}>} $context */
    private function addAuthorizationEdge(Graph $graph, array $context, string $model, string $ability, string $syntax, int $line): void
    {
        $policy = $this->policiesByModel[$model] ?? null;

        if ($policy === null || ! isset($this->methods[$policy][$ability])) {
            return;
        }

        $caller = $context['class'].'::'.$context['method'];
        $target = $policy.'::'.$ability;

        if (isset($this->methods[$context['class']][$context['method']])) {
            $graph->addNode($this->methodNode($context['class'], $context['method'], $this->methods[$context['class']][$context['method']]));
        }

        $graph->addNode(GraphNode::make($policy, 'policy', class_basename($policy), [
            'file' => $this->classes[$policy]['file'] ?? null,
            'line' => $this->classes[$policy]['line'] ?? null,
            'endLine' => $this->classes[$policy]['endLine'] ?? null,
        ]));
        $graph->addNode($this->methodNode($policy, $ability, $this->methods[$policy][$ability]));
        $graph->addEdge(new Edge($target, $policy, 'defined_in'));
        $graph->addEdge(new Edge($caller, $target, 'authorizes_via', 0.9, [
            'ability' => $ability,
            'model' => $model,
            'syntax' => $syntax,
            'line' => $line,
        ]));
    }
}
