<?php

namespace AppGraph\Scanners;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node as GraphNode;
use AppGraph\Scanners\Concerns\InteractsWithPhpAst;
use AppGraph\Support\FileFinder;
use PhpParser\Node as AstNode;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;

class FormRequestScanner
{
    use InteractsWithPhpAst;

    private const FORM_REQUEST_CLASS = 'Illuminate\Foundation\Http\FormRequest';

    private Parser $parser;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $classes = [];

    /**
     * @var array<string, Stmt\ClassMethod|null>
     */
    private array $rulesMethods = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $methods = [];

    public function __construct(private FileFinder $files)
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    protected function scannerName(): string
    {
        return 'form_requests';
    }

    protected function scannerSourceLabel(): string
    {
        return 'form_request_scanner';
    }

    public function scan(Graph $graph): Graph
    {
        $this->classes = [];
        $this->rulesMethods = [];
        $this->methods = [];
        $files = $this->files->findPhpFiles(['app']);

        foreach ($files as $file) {
            $statements = $this->parseFile($file, $graph);

            if ($statements === null) {
                continue;
            }

            $this->indexStatements($statements, $this->files->relativePath($file) ?? $file);
            unset($statements);
        }

        $memo = [];

        foreach ($this->classes as $class => $record) {
            if (! $this->isFormRequest($class, $memo)) {
                continue;
            }

            [$rules, $dynamic] = $this->extractRules($this->rulesMethods[$class] ?? null);

            $graph->addNode(GraphNode::make($class, 'form_request', $record['shortName'], [
                'namespace' => $record['namespace'],
                'class' => $record['shortName'],
                'file' => $record['file'],
                'line' => $record['line'],
                // No 'source' key: when RouteScanner already created this node from a
                // typed controller parameter, merging must not clobber that provenance.
                'metadata' => array_filter([
                    'rules' => $rules,
                    'rulesDynamic' => $dynamic ?: null,
                ], static fn ($value): bool => $value !== null),
            ]));
        }

        foreach ($files as $file) {
            $statements = $this->parseFile($file, $graph);

            if ($statements !== null) {
                $this->scanInlineValidationStatements($graph, $statements);
            }
        }

        return $graph;
    }

    /**
     * @param array<int, \PhpParser\Node> $statements
     */
    private function indexStatements(array $statements, string $relativeFile): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $this->indexStatements($statement->stmts, $relativeFile);
                continue;
            }

            if (! $statement instanceof Stmt\Class_ || $statement->name === null) {
                continue;
            }

            $class = $this->className($statement);

            if ($class === null) {
                continue;
            }

            $this->classes[$class] = [
                'shortName' => $statement->name->toString(),
                'namespace' => $this->namespaceFromClass($class),
                'file' => $relativeFile,
                'line' => $statement->getStartLine(),
                'extends' => $this->resolvedName($statement->extends),
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

                if ($method->name->toString() === 'rules') {
                    $this->rulesMethods[$class] = $method;
                }
            }
        }
    }

    /**
     * @param array<string, bool> $memo
     */
    private function isFormRequest(string $class, array &$memo): bool
    {
        if (isset($memo[$class])) {
            return $memo[$class];
        }

        // Seed false so an inheritance cycle in broken code cannot recurse forever.
        $memo[$class] = false;

        $extends = $this->classes[$class]['extends'] ?? null;

        if (! is_string($extends)) {
            return false;
        }

        if ($extends === self::FORM_REQUEST_CLASS) {
            return $memo[$class] = true;
        }

        if (isset($this->classes[$extends])) {
            return $memo[$class] = $this->isFormRequest($extends, $memo);
        }

        return $memo[$class] = class_exists(self::FORM_REQUEST_CLASS)
            && class_exists($extends)
            && is_subclass_of($extends, self::FORM_REQUEST_CLASS);
    }

    /**
     * Extract a literal rules() array. Anything non-literal degrades gracefully:
     * unrenderable expressions become '{expr}' placeholders and the node is
     * flagged rulesDynamic so consumers know the extraction is partial.
     *
     * @return array{0: array<string, mixed>|null, 1: bool}
     */
    private function extractRules(?Stmt\ClassMethod $method): array
    {
        if ($method === null) {
            return [null, false];
        }

        $return = null;

        foreach ($method->stmts ?? [] as $statement) {
            if ($statement instanceof Stmt\Return_) {
                $return = $statement;
                break;
            }
        }

        if ($return === null) {
            return [null, true];
        }

        return $this->extractRulesFromExpression($return->expr);
    }

    /** @return array{0: array<string, mixed>|null, 1: bool} */
    private function extractRulesFromExpression(?Expr $expression): array
    {
        if (! $expression instanceof Expr\Array_) {
            return [null, true];
        }

        $rules = [];
        $dynamic = false;

        foreach ($expression->items as $item) {
            if ($item->key === null || ! $item->key instanceof Scalar\String_) {
                $dynamic = true;
                continue;
            }

            [$value, $valueDynamic] = $this->renderRuleValue($item->value);
            $rules[$item->key->value] = $value;
            $dynamic = $dynamic || $valueDynamic;
        }

        ksort($rules);

        return [$rules === [] ? null : $rules, $dynamic];
    }

    /** @param array<int, AstNode> $statements */
    private function scanInlineValidationStatements(Graph $graph, array $statements): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $this->scanInlineValidationStatements($graph, $statement->stmts);
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
                    $this->walkInlineValidation($graph, $methodStatement, $context);
                }
            }
        }
    }

    /**
     * @param array{class: string, method: string, localTypes: array<string, array{class: string, confidence: float, inference: string}>} $context
     */
    private function walkInlineValidation(Graph $graph, AstNode $node, array $context): void
    {
        [$rulesExpression, $syntax] = $this->inlineValidationRules($node, $context);

        if ($syntax !== null) {
            [$rules, $dynamic] = $this->extractRulesFromExpression($rulesExpression);
            $this->addInlineValidation($graph, $context, $node->getStartLine(), $syntax, $rules, $dynamic);
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof AstNode) {
                $this->walkInlineValidation($graph, $value, $context);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof AstNode) {
                        $this->walkInlineValidation($graph, $item, $context);
                    }
                }
            }
        }
    }

    /**
     * @param array{class: string, method: string, localTypes: array<string, array{class: string, confidence: float, inference: string}>} $context
     * @return array{0: Expr|null, 1: string|null}
     */
    private function inlineValidationRules(AstNode $node, array $context): array
    {
        if ($node instanceof Expr\MethodCall && $node->name instanceof Identifier) {
            $operation = $node->name->toString();

            if (! in_array($operation, ['validate', 'validateWithBag'], true)) {
                return [null, null];
            }

            if ($node->var instanceof Expr\Variable && $node->var->name === 'this') {
                if (! $this->classUsesControllerValidation($context['class'])) {
                    return [null, null];
                }

                $position = $operation === 'validate' ? 1 : 2;
                $arg = $node->args[$position] ?? null;

                return [$arg instanceof Arg ? $arg->value : null, 'controller_'.$operation];
            }

            if ($node->var instanceof Expr\Variable && is_string($node->var->name)) {
                $receiver = $context['localTypes'][$node->var->name]['class'] ?? null;

                if (! is_string($receiver) || ! $this->isHttpRequestClass($receiver)) {
                    return [null, null];
                }

                $position = $operation === 'validate' ? 0 : 1;
                $arg = $node->args[$position] ?? null;

                return [$arg instanceof Arg ? $arg->value : null, 'request_'.$operation];
            }
        }

        if ($node instanceof Expr\StaticCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'make'
            && class_basename($this->resolvedName($node->class) ?? '') === 'Validator') {
            $arg = $node->args[1] ?? null;

            return [$arg instanceof Arg ? $arg->value : null, 'validator_facade_make'];
        }

        if ($node instanceof Expr\FuncCall
            && $node->name instanceof Name
            && strtolower($node->name->toString()) === 'validator') {
            $arg = $node->args[1] ?? null;

            return [$arg instanceof Arg ? $arg->value : null, 'validator_helper'];
        }

        return [null, null];
    }

    private function isHttpRequestClass(string $class, array $visited = []): bool
    {
        if (isset($visited[$class])) {
            return false;
        }

        if (in_array($class, [
            'Illuminate\\Http\\Request',
            self::FORM_REQUEST_CLASS,
        ], true)) {
            return true;
        }

        $visited[$class] = true;
        $parent = $this->classes[$class]['extends'] ?? null;

        return is_string($parent) && $this->isHttpRequestClass($parent, $visited);
    }

    private function classUsesControllerValidation(string $class, array $visited = []): bool
    {
        if (isset($visited[$class])) {
            return false;
        }

        if ($class === 'Illuminate\\Routing\\Controller') {
            return true;
        }

        $visited[$class] = true;
        $parent = $this->classes[$class]['extends'] ?? null;

        return is_string($parent) && $this->classUsesControllerValidation($parent, $visited);
    }

    /**
     * @param array{class: string, method: string} $context
     * @param array<string, mixed>|null $rules
     */
    private function addInlineValidation(Graph $graph, array $context, int $line, string $syntax, ?array $rules, bool $dynamic): void
    {
        $caller = $context['class'].'::'.$context['method'];
        $validation = 'validation:'.$caller.':'.$line;
        $record = $this->methods[$context['class']][$context['method']] ?? null;

        if (is_array($record)) {
            $graph->addNode($this->methodNode($context['class'], $context['method'], $record));
        }

        $graph->addNode(GraphNode::make($validation, 'form_request', class_basename($context['class']).'::'.$context['method'].' inline validation', [
            'file' => $record['file'] ?? null,
            'line' => $line,
            'metadata' => array_filter([
                'source' => 'inline_validation',
                'syntax' => $syntax,
                'rules' => $rules,
                'rulesDynamic' => $dynamic ?: null,
            ], static fn ($value): bool => $value !== null),
        ]));
        $graph->addEdge(new Edge($caller, $validation, 'validates_with', $dynamic ? 0.75 : 0.95, [
            'syntax' => $syntax,
            'line' => $line,
        ]));
    }

    /**
     * @return array{0: mixed, 1: bool}
     */
    private function renderRuleValue(Expr $value): array
    {
        if ($value instanceof Scalar\String_) {
            return [$value->value, false];
        }

        if ($value instanceof Expr\ClassConstFetch && $value->name instanceof \PhpParser\Node\Identifier && $value->name->toString() === 'class') {
            $resolved = $this->resolvedName($value->class);

            return $resolved !== null ? [class_basename($resolved), false] : ['{expr}', true];
        }

        if ($value instanceof Expr\New_ && $value->class instanceof Name) {
            $resolved = $this->resolvedName($value->class);

            return $resolved !== null ? [class_basename($resolved), false] : ['{expr}', true];
        }

        if ($value instanceof Expr\Array_) {
            $items = [];
            $dynamic = false;

            foreach ($value->items as $item) {
                if ($item->key !== null) {
                    $dynamic = true;
                    continue;
                }

                [$rendered, $itemDynamic] = $this->renderRuleValue($item->value);
                $items[] = $rendered;
                $dynamic = $dynamic || $itemDynamic;
            }

            return [$items, $dynamic];
        }

        return ['{expr}', true];
    }
}
