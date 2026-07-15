<?php

namespace AppGraph\Scanners;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Scanners\Concerns\InteractsWithPhpAst;
use AppGraph\Support\FileFinder;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;

class CallScanner
{
    use InteractsWithPhpAst;

    private const COLLECTION_CLASS = 'Illuminate\\Support\\Collection';

    private const GATE_CONTRACT = 'Illuminate\\Contracts\\Auth\\Access\\Gate';

    private const PENDING_CHAIN_CLASS = 'Illuminate\\Foundation\\Bus\\PendingChain';

    private const PENDING_DISPATCH_CLASS = 'Illuminate\\Foundation\\Bus\\PendingDispatch';

    private const VALIDATOR_CONTRACT = 'Illuminate\\Contracts\\Validation\\Validator';

    /**
     * Framework calls are useful evidence, but the absence of their vendor
     * implementation from the application index does not imply a missing app
     * call edge. Keep this deliberately narrow: unknown application contracts
     * and third-party extension points should continue to produce warnings.
     *
     * @var array<int, string>
     */
    private const FRAMEWORK_CLASS_PREFIXES = [
        'Illuminate\\',
        'Laravel\\',
        'Symfony\\Component\\',
    ];

    /** @var array<int, string> */
    private const ELOQUENT_QUERY_PASSTHROUGH_METHODS = [
        'distinct',
        'latest',
        'limit',
        'newModelQuery',
        'newQuery',
        'oldest',
        'on',
        'orWhere',
        'orderBy',
        'query',
        'select',
        'take',
        'when',
        'where',
        'whereBelongsTo',
        'whereDate',
        'whereDoesntHave',
        'whereHas',
        'whereIn',
        'whereNotNull',
        'with',
        'without',
    ];

    /** @var array<int, string> */
    private const ELOQUENT_MODEL_RESULT_METHODS = [
        'create',
        'find',
        'findOrFail',
        'first',
        'firstOrCreate',
        'firstOrFail',
        'make',
        'sole',
        'updateOrCreate',
    ];

    /** @var array<int, string> */
    private const ELOQUENT_COLLECTION_RESULT_METHODS = [
        'all',
        'get',
    ];

    /** @var array<int, string> */
    private const ELOQUENT_UNTYPED_COLLECTION_RESULT_METHODS = [
        'pluck',
    ];

    /** @var array<int, string> */
    private const COLLECTION_PASSTHROUGH_METHODS = [
        'each',
        'filter',
        'flatten',
        'forget',
        'keyBy',
        'reject',
        'reverse',
        'sort',
        'sortBy',
        'sortByDesc',
        'unique',
        'values',
        'when',
    ];

    /** @var array<int, string> */
    private const COLLECTION_ELEMENT_RESULT_METHODS = [
        'first',
        'firstOrFail',
        'last',
        'sole',
    ];

    /** @var array<int, string> */
    private const COLLECTION_TRANSFORM_METHODS = [
        'chunk',
        'flatMap',
        'groupBy',
        'keys',
        'map',
        'mapInto',
        'mapSpread',
        'mapToGroups',
        'mapWithKeys',
        'partition',
        'pluck',
    ];

    /** @var array<int, string> */
    private const COLLECTION_CALLBACK_METHODS = [
        'each',
        'every',
        'filter',
        'first',
        'map',
        'partition',
        'reduce',
        'reject',
        'some',
    ];

    /** @var array<string, array<int, string>> */
    private const FRAMEWORK_FLUENT_METHODS = [
        self::GATE_CONTRACT => [
            'forUser',
        ],
        self::PENDING_CHAIN_CLASS => [
            'catch',
            'delay',
            'finally',
            'onConnection',
            'onQueue',
            'then',
        ],
        self::PENDING_DISPATCH_CLASS => [
            'afterCommit',
            'beforeCommit',
            'delay',
            'onConnection',
            'onQueue',
        ],
        self::VALIDATOR_CONTRACT => [
            'after',
            'sometimes',
            'stopOnFirstFailure',
        ],
    ];

    private Parser $parser;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $classes = [];

    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $methods = [];

    /**
     * @var array<string, string>
     */
    private array $relationshipReturnModels = [];

    /**
     * Calls that static analysis could not safely connect to a concrete method.
     * Samples are exported so query consumers can distinguish an absent edge
     * from evidence that no call exists.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $unresolvedCalls = [];

    /**
     * Calls known to terminate at a framework boundary. They are exported for
     * transparency, but excluded from unresolved warnings because another
     * semantic scanner may already represent their authorization, validation,
     * persistence, or queue effect.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $boundaryCalls = [];

    /**
     * @var array<int, string>
     */
    private array $relationshipCalls = [
        'belongsTo',
        'hasMany',
        'hasOne',
        'belongsToMany',
        'morphMany',
        'morphOne',
        'morphToMany',
    ];

    /**
     * @var array<int, string>
     */
    private array $modelEventCalls = [
        'creating',
        'created',
        'updating',
        'updated',
        'saving',
        'saved',
        'deleting',
        'deleted',
        'restoring',
        'restored',
    ];

    public function __construct(private FileFinder $files)
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function scan(Graph $graph): Graph
    {
        $this->classes = [];
        $this->methods = [];
        $this->relationshipReturnModels = [];
        $this->unresolvedCalls = [];
        $this->boundaryCalls = [];

        $files = $this->files->findPhpFiles(['app', 'routes']);

        foreach ($files as $file) {
            $statements = $this->parseFile($file, $graph);

            if ($statements === null) {
                continue;
            }

            $this->indexStatements($statements, $file, $this->files->relativePath($file) ?? $file);
            unset($statements);
        }

        foreach ($this->methods as $class => $methods) {
            foreach ($methods as $method => $record) {
                $graph->addNode($this->methodNode($class, $method, $record));
            }
        }

        gc_collect_cycles();

        foreach ($files as $file) {
            $statements = $this->parseFile($file, $graph);

            if ($statements === null) {
                continue;
            }

            $this->scanStatements($graph, $statements);
            unset($statements);
        }

        $this->exportResolutionDiagnostics($graph);

        return $graph;
    }

    protected function scannerName(): string
    {
        return 'calls';
    }

    protected function scannerSourceLabel(): string
    {
        return 'call_scanner';
    }

    /**
     * @param array<int, Node> $statements
     */
    private function indexStatements(array $statements, string $file, string $relativeFile): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $this->indexStatements($statement->stmts, $file, $relativeFile);
                continue;
            }

            if (! $statement instanceof Stmt\Class_ && ! $statement instanceof Stmt\Trait_) {
                continue;
            }

            if ($statement->name === null) {
                continue;
            }

            $class = $this->className($statement);

            if ($class === null) {
                continue;
            }

            $this->classes[$class] = [
                'name' => $class,
                'shortName' => $statement->name->toString(),
                'namespace' => $this->namespaceFromClass($class),
                'file' => $relativeFile,
                'line' => $statement->getStartLine(),
                'extends' => $statement instanceof Stmt\Class_ ? $this->resolvedName($statement->extends) : null,
                'implements' => $statement instanceof Stmt\Class_
                    ? array_values(array_filter(array_map(fn (Node\Name $interface): ?string => $this->resolvedName($interface), $statement->implements)))
                    : [],
                'traits' => $this->usedTraits($statement),
                'type' => $statement instanceof Stmt\Trait_ ? 'trait' : 'class',
                'propertyTypes' => $this->propertyTypes($statement, $class),
            ];

            foreach ($statement->getMethods() as $method) {
                $this->methods[$class][$method->name->toString()] = [
                    'node' => $method,
                    'file' => $relativeFile,
                    'line' => $method->getStartLine(),
                    'signature' => $this->methodSignature($method),
                    'inputs' => $this->methodInputs($method),
                    'outputs' => $this->methodOutputs($method),
                    'returnType' => $this->resolveType($method->returnType, $class),
                    'visibility' => $this->methodVisibility($method),
                    'static' => $method->isStatic(),
                ];
            }

            foreach ($statement->getMethods() as $method) {
                $returnModel = $this->relationshipReturnModel($method);

                if ($returnModel !== null) {
                    $this->relationshipReturnModels[$class.'::'.$method->name->toString()] = $returnModel;
                }
            }
        }
    }

    /**
     * @param array<int, Node> $statements
     */
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
                $context = [
                    'class' => $class,
                    'method' => $method->name->toString(),
                    'file' => $this->methods[$class][$method->name->toString()]['file'] ?? null,
                    'localTypes' => $this->parameterTypes($method, $class),
                ];

                $this->scanMethodStatements($graph, $method->stmts ?? [], $context);
            }
        }
    }

    /**
     * @param array<int, Node> $statements
     * @param array{class: string, method: string, localTypes: array<string, array{class: string, confidence: float, inference: string}>} $context
     */
    private function scanMethodStatements(Graph $graph, array $statements, array &$context): void
    {
        foreach ($statements as $statement) {
            $this->scanNode($graph, $statement, $context);
        }
    }

    /**
     * @param array{class: string, method: string, localTypes: array<string, array{class: string, confidence: float, inference: string}>} $context
     */
    private function scanNode(Graph $graph, Node $node, array &$context): void
    {
        if ($node instanceof Expr\Assign && $node->var instanceof Expr\Variable && is_string($node->var->name)) {
            $this->scanNode($graph, $node->expr, $context);
            $type = $this->inferExpressionType($node->expr, $context);

            if ($type !== null) {
                $context['localTypes'][$node->var->name] = $type;
            }

            return;
        }

        if ($node instanceof Expr\MethodCall) {
            if ($node->var instanceof Node) {
                $this->scanNode($graph, $node->var, $context);
            }

            $this->addMethodCallEdge($graph, $node, $context);

            $receiver = $this->inferExpressionType($node->var, $context);

            foreach ($node->args as $position => $arg) {
                // First-class callables such as $service->handle(...) store a
                // VariadicPlaceholder in args; there is no argument expression
                // to traverse in that case.
                if ($arg instanceof Arg) {
                    $this->scanMethodCallArg($graph, $node, $arg, $position, $receiver, $context);
                }
            }

            return;
        }

        if ($node instanceof Expr\StaticCall) {
            $this->addStaticCallEdge($graph, $node, $context);
            $this->scanModelEventClosure($graph, $node, $context);

            foreach ($node->args as $arg) {
                if ($arg instanceof Arg) {
                    $this->scanArg($graph, $arg, $context);
                }
            }

            return;
        }

        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $closureContext = $context;

            foreach ($node->params as $param) {
                $this->addParameterType($closureContext, $param, $context['class']);
            }

            if ($node instanceof Expr\Closure) {
                foreach ($node->stmts as $statement) {
                    $this->scanNode($graph, $statement, $closureContext);
                }
            } elseif ($node->expr instanceof Node) {
                $this->scanNode($graph, $node->expr, $closureContext);
            }

            return;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof Node) {
                $this->scanNode($graph, $value, $context);
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $this->scanNode($graph, $item, $context);
                    }
                }
            }
        }
    }

    /**
     * @param array{class: string, method: string, localTypes: array<string, array{class: string, confidence: float, inference: string}>} $context
     */
    private function scanArg(Graph $graph, Arg $arg, array &$context): void
    {
        if ($arg->value instanceof Node) {
            $this->scanNode($graph, $arg->value, $context);
        }
    }

    /**
     * Infer the item passed to common collection callbacks. This recovers app
     * calls in untyped closures such as Note::query()->get()->each(fn ($note)
     * => $note->sign()) without pretending to resolve arbitrary callback APIs.
     *
     * @param array{class: string, confidence: float, inference: string, elementClass?: string}|null $receiver
     * @param array{class: string, method: string, localTypes: array<string, array{class: string, confidence: float, inference: string}>} $context
     */
    private function scanMethodCallArg(Graph $graph, Expr\MethodCall $call, Arg $arg, int $position, ?array $receiver, array &$context): void
    {
        $method = $call->name instanceof Identifier ? $call->name->toString() : null;
        $callback = $arg->value;

        if ($position === 0
            && $method !== null
            && in_array($method, self::COLLECTION_CALLBACK_METHODS, true)
            && ($receiver['class'] ?? null) === self::COLLECTION_CLASS
            && isset($receiver['elementClass'])
            && ($callback instanceof Expr\Closure || $callback instanceof Expr\ArrowFunction)
            && isset($callback->params[0])
            && $callback->params[0]->var instanceof Expr\Variable
            && is_string($callback->params[0]->var->name)) {
            $callbackContext = $context;
            $callbackContext['localTypes'][$callback->params[0]->var->name] = [
                'class' => $receiver['elementClass'],
                'confidence' => round($receiver['confidence'] * 0.8, 2),
                'inference' => 'collection_element_callback',
            ];
            $this->scanNode($graph, $callback, $callbackContext);

            return;
        }

        $this->scanArg($graph, $arg, $context);
    }

    /**
     * @param array{class: string, method: string, localTypes: array<string, array{class: string, confidence: float, inference: string}>} $context
     */
    private function addMethodCallEdge(Graph $graph, Expr\MethodCall $call, array $context): void
    {
        if (! $call->name instanceof Identifier) {
            $this->recordUnresolvedCall($context, $call, 'dynamic_method_name');

            return;
        }

        $receiver = $this->inferExpressionType($call->var, $context);

        if ($receiver === null) {
            $this->recordUnresolvedCall($context, $call, 'receiver_type_unknown', [
                'method' => $call->name->toString(),
            ]);

            return;
        }

        $this->addCallEdge(
            $graph,
            $context,
            $receiver['class'],
            $call->name->toString(),
            $receiver['confidence'],
            $call->getStartLine(),
            [
                'inference' => $receiver['inference'],
                'syntax' => 'method_call',
            ]
        );
    }

    /**
     * @param array{class: string, method: string, localTypes: array<string, array{class: string, confidence: float, inference: string}>} $context
     */
    private function addStaticCallEdge(Graph $graph, Expr\StaticCall $call, array $context): void
    {
        if (! $call->name instanceof Identifier) {
            $this->recordUnresolvedCall($context, $call, 'dynamic_static_method_name');

            return;
        }

        $targetClass = $this->resolveStaticClass($call->class, $context['class']);

        if ($targetClass === null) {
            $this->recordUnresolvedCall($context, $call, 'static_class_unknown', [
                'method' => $call->name->toString(),
            ]);

            return;
        }

        $this->addCallEdge(
            $graph,
            $context,
            $targetClass,
            $call->name->toString(),
            1.0,
            $call->getStartLine(),
            [
                'inference' => 'static_call',
                'syntax' => 'static_call',
            ]
        );
    }

    /**
     * @param array{class: string, method: string, localTypes: array<string, array{class: string, confidence: float, inference: string}>} $context
     */
    private function scanModelEventClosure(Graph $graph, Expr\StaticCall $call, array $context): void
    {
        if (! $call->name instanceof Identifier || ! in_array($call->name->toString(), $this->modelEventCalls, true)) {
            return;
        }

        $targetClass = $this->resolveStaticClass($call->class, $context['class']);

        if ($targetClass !== $context['class']) {
            return;
        }

        $firstArg = $call->args[0]->value ?? null;

        if (! $firstArg instanceof Expr\Closure || $firstArg->params === []) {
            return;
        }

        $firstParam = $firstArg->params[0];

        if (! $firstParam->var instanceof Expr\Variable || ! is_string($firstParam->var->name)) {
            return;
        }

        $closureContext = $context;
        $closureContext['localTypes'][$firstParam->var->name] = [
            'class' => $context['class'],
            'confidence' => 0.8,
            'inference' => 'eloquent_model_event_closure',
        ];

        foreach ($firstArg->params as $param) {
            $this->addParameterType($closureContext, $param, $context['class']);
        }

        foreach ($firstArg->stmts as $statement) {
            $this->scanNode($graph, $statement, $closureContext);
        }
    }

    /**
     * @param array{class: string, method: string, localTypes: array<string, array{class: string, confidence: float, inference: string}>} $context
     * @param array<string, mixed> $metadata
     */
    private function addCallEdge(Graph $graph, array $context, string $targetClass, string $calledMethod, float $confidence, int $line, array $metadata): void
    {
        $resolvedMethod = $this->resolveTargetMethod($targetClass, $calledMethod);

        if ($resolvedMethod === null) {
            if (($boundaryClass = $this->frameworkBoundaryClass($targetClass)) !== null) {
                $this->recordBoundaryCall($context, $targetClass, $calledMethod, $line, $boundaryClass);

                return;
            }

            $this->recordUnresolvedCall($context, null, 'target_method_not_indexed', [
                'target' => $targetClass.'::'.$calledMethod,
                'line' => $line,
            ]);

            return;
        }

        [$declaringClass, $targetMethod, $targetMetadata, $targetConfidence] = $resolvedMethod;
        $callerId = $context['class'].'::'.$context['method'];
        $targetId = $declaringClass.'::'.$targetMethod;

        if (isset($this->methods[$context['class']][$context['method']])) {
            $graph->addNode($this->methodNode($context['class'], $context['method'], $this->methods[$context['class']][$context['method']]));
        }

        if (isset($this->methods[$declaringClass][$targetMethod])) {
            $graph->addNode($this->methodNode($declaringClass, $targetMethod, $this->methods[$declaringClass][$targetMethod]));
        }

        $graph->addEdge(new Edge($callerId, $targetId, 'calls', round($confidence * $targetConfidence, 2), [
            'line' => $line,
            'calledMethod' => $calledMethod,
        ] + $targetMetadata + $metadata));
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>, 3: float}|null
     */
    private function resolveTargetMethod(string $class, string $method): ?array
    {
        return $this->resolveTargetMethodFrom($class, $method, []);
    }

    /**
     * @param array<string, true> $visited
     * @return array{0: string, 1: string, 2: array<string, mixed>, 3: float}|null
     */
    private function resolveTargetMethodFrom(string $class, string $method, array $visited): ?array
    {
        if (isset($visited[$class])) {
            return null;
        }

        $visited[$class] = true;

        if (isset($this->methods[$class][$method])) {
            return [$class, $method, [], 1.0];
        }

        $scopeMethod = 'scope'.Str::studly($method);

        if (isset($this->methods[$class][$scopeMethod])) {
            return [$class, $scopeMethod, [
                'inferenceDetail' => 'eloquent_scope_call',
            ], 0.75];
        }

        foreach ($this->classes[$class]['traits'] ?? [] as $trait) {
            $resolved = $this->resolveTargetMethodFrom($trait, $method, $visited);

            if ($resolved !== null) {
                $resolved[2] += [
                    'declaringClass' => $resolved[0],
                    'inferenceDetail' => 'trait_method',
                ];

                return $resolved;
            }
        }

        $parent = $this->classes[$class]['extends'] ?? null;

        if (is_string($parent) && $parent !== '') {
            $resolved = $this->resolveTargetMethodFrom($parent, $method, $visited);

            if ($resolved !== null) {
                $resolved[2] += [
                    'declaringClass' => $resolved[0],
                    'inferenceDetail' => 'inherited_method',
                ];

                return $resolved;
            }
        }

        return null;
    }

    /**
     * @param array{class: string, method: string, localTypes: array<string, array{class: string, confidence: float, inference: string}>} $context
     * @return array{class: string, confidence: float, inference: string}|null
     */
    private function inferExpressionType(Node|Expr $expression, array $context): ?array
    {
        if ($expression instanceof Expr\Variable) {
            if ($expression->name === 'this') {
                return [
                    'class' => $context['class'],
                    'confidence' => 1.0,
                    'inference' => '$this',
                ];
            }

            if (is_string($expression->name) && isset($context['localTypes'][$expression->name])) {
                return $context['localTypes'][$expression->name];
            }

            return null;
        }

        if ($expression instanceof Expr\PropertyFetch
            && $expression->var instanceof Expr\Variable
            && $expression->var->name === 'this'
            && $expression->name instanceof Identifier) {
            return $this->propertyType($context['class'], $expression->name->toString());
        }

        if ($expression instanceof Expr\New_) {
            $class = $this->resolvedName($expression->class);

            return $class === null ? null : [
                'class' => $class,
                'confidence' => 0.95,
                'inference' => 'new_expression',
            ];
        }

        if ($expression instanceof Expr\FuncCall && $expression->name instanceof Node\Name) {
            $helper = strtolower($expression->name->toString());
            $firstArg = $expression->args[0] ?? null;
            $classExpression = $firstArg instanceof Arg ? $firstArg->value : null;

            if (in_array($helper, ['app', 'resolve'], true)
                && ($class = $this->classFromContainerArgument($classExpression)) !== null) {

                return [
                    'class' => $class,
                    'confidence' => 0.9,
                    'inference' => 'container_helper',
                ];
            }

            if ($helper === 'collect') {
                $elementType = $classExpression instanceof Expr\Array_
                    ? $this->arrayElementType($classExpression, $context)
                    : null;

                return array_filter([
                    'class' => self::COLLECTION_CLASS,
                    'elementClass' => $elementType['class'] ?? null,
                    'confidence' => $elementType === null ? 0.9 : round($elementType['confidence'] * 0.9, 2),
                    'inference' => $elementType === null ? 'collection_helper' : 'collection_helper_array_element',
                ], static fn ($value): bool => $value !== null);
            }

            if ($helper === 'validator') {
                return [
                    'class' => self::VALIDATOR_CONTRACT,
                    'confidence' => 0.9,
                    'inference' => 'validator_helper',
                ];
            }
        }

        if ($expression instanceof Expr\StaticCall) {
            $class = $this->resolveStaticClass($expression->class, $context['class']);

            if ($class === null || ! $expression->name instanceof Identifier) {
                return null;
            }

            $operation = $expression->name->toString();
            $method = $this->resolveTargetMethod($class, $operation);

            if ($method !== null) {
                [$declaringClass, $targetMethod, $targetMetadata, $targetConfidence] = $method;
                $returnType = $this->methods[$declaringClass][$targetMethod]['returnType'] ?? null;

                if (is_string($returnType)) {
                    return [
                        'class' => $returnType,
                        'confidence' => round($targetConfidence * 0.9, 2),
                        'inference' => 'declared_static_return_type',
                    ];
                }

                if (($targetMetadata['inferenceDetail'] ?? null) === 'eloquent_scope_call' && $this->classLooksLikeModel($class)) {
                    return [
                        'class' => $class,
                        'confidence' => round($targetConfidence * 0.75, 2),
                        'inference' => 'eloquent_scope_query',
                    ];
                }
            }

            if (($frameworkReturn = $this->frameworkStaticReturnType($class, $operation)) !== null) {
                return $frameworkReturn;
            }

            if ($this->classLooksLikeModel($class) && in_array($operation, self::ELOQUENT_COLLECTION_RESULT_METHODS, true)) {
                return [
                    'class' => self::COLLECTION_CLASS,
                    'elementClass' => $class,
                    'confidence' => 0.65,
                    'inference' => 'eloquent_collection_result',
                ];
            }

            if ($this->classLooksLikeModel($class) && in_array($operation, self::ELOQUENT_UNTYPED_COLLECTION_RESULT_METHODS, true)) {
                return [
                    'class' => self::COLLECTION_CLASS,
                    'confidence' => 0.6,
                    'inference' => 'eloquent_untyped_collection_result',
                ];
            }

            if ($this->classLooksLikeModel($class) && in_array($operation, self::ELOQUENT_QUERY_PASSTHROUGH_METHODS, true)) {
                return [
                    'class' => $class,
                    'confidence' => 0.7,
                    'inference' => 'eloquent_query_chain',
                ];
            }

            if ($this->classLooksLikeModel($class) && in_array($operation, self::ELOQUENT_MODEL_RESULT_METHODS, true)) {
                return [
                    'class' => $class,
                    'confidence' => 0.65,
                    'inference' => 'model_static_factory_call',
                ];
            }

            return null;
        }

        if ($expression instanceof Expr\MethodCall && $expression->name instanceof Identifier) {
            $receiver = $this->inferExpressionType($expression->var, $context);

            if ($receiver === null) {
                return null;
            }

            $operation = $expression->name->toString();
            $method = $this->resolveTargetMethod($receiver['class'], $operation);

            if ($method === null) {
                if (isset(self::FRAMEWORK_FLUENT_METHODS[$receiver['class']])
                    && in_array($operation, self::FRAMEWORK_FLUENT_METHODS[$receiver['class']], true)) {
                    return [
                        ...$receiver,
                        'confidence' => round($receiver['confidence'] * 0.95, 2),
                        'inference' => 'framework_fluent_operation',
                    ];
                }

                if ($receiver['class'] === self::COLLECTION_CLASS) {
                    if (in_array($operation, self::COLLECTION_PASSTHROUGH_METHODS, true)) {
                        return [
                            ...$receiver,
                            'confidence' => round($receiver['confidence'] * 0.95, 2),
                            'inference' => 'collection_fluent_operation',
                        ];
                    }

                    if (in_array($operation, self::COLLECTION_TRANSFORM_METHODS, true)) {
                        return [
                            'class' => self::COLLECTION_CLASS,
                            'confidence' => round($receiver['confidence'] * 0.85, 2),
                            'inference' => 'collection_transform_result',
                        ];
                    }

                    if (in_array($operation, self::COLLECTION_ELEMENT_RESULT_METHODS, true)
                        && isset($receiver['elementClass'])) {
                        return [
                            'class' => $receiver['elementClass'],
                            'confidence' => round($receiver['confidence'] * 0.8, 2),
                            'inference' => 'collection_element_result',
                        ];
                    }
                }

                if ($this->classLooksLikeModel($receiver['class'])) {
                    if (in_array($operation, self::ELOQUENT_COLLECTION_RESULT_METHODS, true)) {
                        return [
                            'class' => self::COLLECTION_CLASS,
                            'elementClass' => $receiver['class'],
                            'confidence' => round($receiver['confidence'] * 0.85, 2),
                            'inference' => 'eloquent_collection_result',
                        ];
                    }

                    if (in_array($operation, self::ELOQUENT_UNTYPED_COLLECTION_RESULT_METHODS, true)) {
                        return [
                            'class' => self::COLLECTION_CLASS,
                            'confidence' => round($receiver['confidence'] * 0.8, 2),
                            'inference' => 'eloquent_untyped_collection_result',
                        ];
                    }

                    if (in_array($operation, self::ELOQUENT_QUERY_PASSTHROUGH_METHODS, true)) {
                        return [
                            ...$receiver,
                            'confidence' => round($receiver['confidence'] * 0.95, 2),
                            'inference' => 'eloquent_query_chain',
                        ];
                    }

                    if (in_array($operation, self::ELOQUENT_MODEL_RESULT_METHODS, true)) {
                        return [
                            ...$receiver,
                            'confidence' => round($receiver['confidence'] * 0.85, 2),
                            'inference' => 'eloquent_model_result',
                        ];
                    }
                }

                return null;
            }

            [$declaringClass, $targetMethod, , $targetConfidence] = $method;
            $returnModel = $this->relationshipReturnModels[$declaringClass.'::'.$targetMethod] ?? null;

            if ($returnModel !== null) {
                return [
                    'class' => $returnModel,
                    'confidence' => round($receiver['confidence'] * $targetConfidence * 0.75, 2),
                    'inference' => 'eloquent_relationship_return',
                ];
            }

            $returnType = $this->methods[$declaringClass][$targetMethod]['returnType'] ?? null;

            if (is_string($returnType)) {
                return [
                    'class' => $returnType,
                    'confidence' => round($receiver['confidence'] * $targetConfidence * 0.9, 2),
                    'inference' => 'declared_method_return_type',
                ];
            }

            $targetMetadata = $method[2];

            if (($targetMetadata['inferenceDetail'] ?? null) === 'eloquent_scope_call' && $this->classLooksLikeModel($receiver['class'])) {
                return [
                    ...$receiver,
                    'confidence' => round($receiver['confidence'] * $targetConfidence * 0.75, 2),
                    'inference' => 'eloquent_scope_query',
                ];
            }

            return null;
        }

        return null;
    }

    /** @return array{class: string, confidence: float, inference: string}|null */
    private function frameworkStaticReturnType(string $class, string $operation): ?array
    {
        $basename = class_basename($class);

        if ($basename === 'Bus' && $operation === 'chain') {
            return [
                'class' => self::PENDING_CHAIN_CLASS,
                'confidence' => 0.9,
                'inference' => 'bus_chain_pending_result',
            ];
        }

        if ($basename === 'Gate' && $operation === 'forUser') {
            return [
                'class' => self::GATE_CONTRACT,
                'confidence' => 0.9,
                'inference' => 'gate_for_user_result',
            ];
        }

        if ($basename === 'Validator' && $operation === 'make') {
            return [
                'class' => self::VALIDATOR_CONTRACT,
                'confidence' => 0.9,
                'inference' => 'validator_factory_result',
            ];
        }

        if ($operation === 'dispatch' && $this->classLooksLikeJob($class)) {
            return [
                'class' => self::PENDING_DISPATCH_CLASS,
                'confidence' => 0.85,
                'inference' => 'job_pending_dispatch_result',
            ];
        }

        return null;
    }

    /**
     * @param array{class: string, method: string, localTypes: array<string, array{class: string, confidence: float, inference: string}>} $context
     * @return array{class: string, confidence: float, inference: string}|null
     */
    private function arrayElementType(Expr\Array_ $array, array $context): ?array
    {
        $elementType = null;

        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }

            $type = $this->inferExpressionType($item->value, $context);

            if ($type === null) {
                return null;
            }

            if ($elementType !== null && $elementType['class'] !== $type['class']) {
                return null;
            }

            $elementType = $elementType === null ? $type : [
                'class' => $type['class'],
                'confidence' => min($elementType['confidence'], $type['confidence']),
                'inference' => 'homogeneous_array_element',
            ];
        }

        return $elementType;
    }

    private function classLooksLikeModel(string $class, array $visited = []): bool
    {
        if (isset($visited[$class])) {
            return false;
        }

        $visited[$class] = true;
        $record = $this->classes[$class] ?? null;

        if ($record === null || ($record['type'] ?? null) !== 'class') {
            return false;
        }

        if (str_starts_with((string) ($record['file'] ?? ''), 'app/Models/')) {
            return true;
        }

        $parent = $record['extends'] ?? null;

        if (! is_string($parent) || $parent === '') {
            return false;
        }

        return in_array($parent, [
            'Illuminate\\Database\\Eloquent\\Model',
            'Illuminate\\Foundation\\Auth\\User',
        ], true) || $this->classLooksLikeModel($parent, $visited);
    }

    private function classLooksLikeJob(string $class): bool
    {
        $record = $this->classes[$class] ?? null;

        if ($record === null || ($record['type'] ?? null) !== 'class') {
            return false;
        }

        if (str_starts_with((string) ($record['file'] ?? ''), 'app/Jobs/')) {
            return true;
        }

        if (array_intersect($record['implements'] ?? [], [
            'Illuminate\\Contracts\\Queue\\ShouldQueue',
            'Illuminate\\Contracts\\Queue\\ShouldQueueAfterCommit',
        ]) !== []) {
            return true;
        }

        return in_array('Illuminate\\Foundation\\Bus\\Dispatchable', $record['traits'] ?? [], true);
    }

    /**
     * @return array<string, array{class: string, confidence: float, inference: string}>
     */
    private function propertyTypes(Stmt\Class_|Stmt\Trait_ $statement, string $class): array
    {
        $types = [];

        foreach ($statement->stmts as $member) {
            if ($member instanceof Stmt\Property) {
                $type = $this->resolveType($member->type, $class);

                if ($type === null) {
                    continue;
                }

                foreach ($member->props as $property) {
                    $types[$property->name->toString()] = [
                        'class' => $type,
                        'confidence' => 0.95,
                        'inference' => 'typed_property',
                    ];
                }

                continue;
            }

            if (! $member instanceof Stmt\ClassMethod || $member->name->toString() !== '__construct') {
                continue;
            }

            $parameters = $this->parameterTypes($member, $class);

            foreach ($member->params as $param) {
                if (! $param->isPromoted()
                    || ! $param->var instanceof Expr\Variable
                    || ! is_string($param->var->name)
                    || ! isset($parameters[$param->var->name])) {
                    continue;
                }

                $types[$param->var->name] = [
                    ...$parameters[$param->var->name],
                    'confidence' => 0.98,
                    'inference' => 'constructor_promoted_property',
                ];
            }

            foreach ($member->stmts ?? [] as $constructorStatement) {
                $this->collectConstructorPropertyAssignments($constructorStatement, $parameters, $types);
            }
        }

        return $types;
    }

    /**
     * @param array<string, array{class: string, confidence: float, inference: string}> $parameters
     * @param array<string, array{class: string, confidence: float, inference: string}> $types
     */
    private function collectConstructorPropertyAssignments(Node $node, array $parameters, array &$types): void
    {
        if ($node instanceof Expr\Assign
            && $node->var instanceof Expr\PropertyFetch
            && $node->var->var instanceof Expr\Variable
            && $node->var->var->name === 'this'
            && $node->var->name instanceof Identifier
            && $node->expr instanceof Expr\Variable
            && is_string($node->expr->name)
            && isset($parameters[$node->expr->name])) {
            $types[$node->var->name->toString()] = [
                ...$parameters[$node->expr->name],
                'confidence' => 0.95,
                'inference' => 'constructor_property_assignment',
            ];
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof Node) {
                $this->collectConstructorPropertyAssignments($value, $parameters, $types);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $this->collectConstructorPropertyAssignments($item, $parameters, $types);
                    }
                }
            }
        }
    }

    /**
     * @return array{class: string, confidence: float, inference: string}|null
     */
    private function propertyType(string $class, string $property, array $visited = []): ?array
    {
        if (isset($visited[$class])) {
            return null;
        }

        $visited[$class] = true;
        $type = $this->classes[$class]['propertyTypes'][$property] ?? null;

        if (is_array($type)) {
            return $type;
        }

        foreach ($this->classes[$class]['traits'] ?? [] as $trait) {
            if (($type = $this->propertyType($trait, $property, $visited)) !== null) {
                return $type;
            }
        }

        $parent = $this->classes[$class]['extends'] ?? null;

        return is_string($parent) ? $this->propertyType($parent, $property, $visited) : null;
    }

    private function classFromContainerArgument(?Node $expression): ?string
    {
        if ($expression instanceof Expr\ClassConstFetch
            && $expression->name instanceof Identifier
            && strtolower($expression->name->toString()) === 'class') {
            return $this->resolvedName($expression->class);
        }

        if ($expression instanceof Scalar\String_ && str_contains($expression->value, '\\')) {
            return ltrim($expression->value, '\\');
        }

        return null;
    }

    private function frameworkBoundaryClass(string $class, array $visited = []): ?string
    {
        if (isset($visited[$class])) {
            return null;
        }

        $visited[$class] = true;

        foreach (self::FRAMEWORK_CLASS_PREFIXES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return $class;
            }
        }

        foreach ($this->classes[$class]['traits'] ?? [] as $trait) {
            if (($boundary = $this->frameworkBoundaryClass($trait, $visited)) !== null) {
                return $boundary;
            }
        }

        $parent = $this->classes[$class]['extends'] ?? null;

        return is_string($parent) && $parent !== ''
            ? $this->frameworkBoundaryClass($parent, $visited)
            : null;
    }

    /** @param array<string, mixed> $context */
    private function recordBoundaryCall(array $context, string $targetClass, string $method, int $line, string $boundaryClass): void
    {
        $this->boundaryCalls[] = array_filter([
            'caller' => ($context['class'] ?? 'unknown').'::'.($context['method'] ?? 'unknown'),
            'file' => $context['file'] ?? null,
            'line' => $line,
            'reason' => 'framework_boundary',
            'target' => $targetClass.'::'.$method,
            'boundaryClass' => $boundaryClass,
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra
     */
    private function recordUnresolvedCall(array $context, ?Node $node, string $reason, array $extra = []): void
    {
        $this->unresolvedCalls[] = array_filter([
            'caller' => ($context['class'] ?? 'unknown').'::'.($context['method'] ?? 'unknown'),
            'file' => $context['file'] ?? null,
            'line' => $extra['line'] ?? $node?->getStartLine(),
            'reason' => $reason,
        ] + $extra, static fn ($value): bool => $value !== null);
    }

    private function exportResolutionDiagnostics(Graph $graph): void
    {
        if ($this->unresolvedCalls === [] && $this->boundaryCalls === []) {
            return;
        }

        [$byReason, $byCaller] = $this->summarizeDiagnostics($this->unresolvedCalls);
        [$boundaryByReason, $boundaryByCaller] = $this->summarizeDiagnostics($this->boundaryCalls);

        $graph->addMeta([
            'analysis' => [
                'callResolution' => [
                    'unresolvedCount' => count($this->unresolvedCalls),
                    'byReason' => $byReason,
                    'byCaller' => $byCaller,
                    'samples' => array_slice($this->unresolvedCalls, 0, 50),
                    'boundaryCount' => count($this->boundaryCalls),
                    'boundaryByReason' => $boundaryByReason,
                    'boundaryByCaller' => $boundaryByCaller,
                    'boundarySamples' => array_slice($this->boundaryCalls, 0, 50),
                ],
            ],
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $calls
     * @return array{0: array<string, int>, 1: array<string, array<string, mixed>>}
     */
    private function summarizeDiagnostics(array $calls): array
    {
        $byReason = [];
        $byCaller = [];

        foreach ($calls as $call) {
            $reason = (string) $call['reason'];
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
            $caller = (string) $call['caller'];
            $byCaller[$caller] ??= ['count' => 0, 'byReason' => [], 'samples' => []];
            $byCaller[$caller]['count']++;
            $byCaller[$caller]['byReason'][$reason] = ($byCaller[$caller]['byReason'][$reason] ?? 0) + 1;

            if (count($byCaller[$caller]['samples']) < 3) {
                $byCaller[$caller]['samples'][] = $call;
            }
        }

        ksort($byReason);
        ksort($byCaller);

        foreach ($byCaller as &$caller) {
            ksort($caller['byReason']);
        }
        unset($caller);

        return [$byReason, $byCaller];
    }

    /**
     * @return array<int, string>
     */
    private function usedTraits(Stmt\Class_|Stmt\Trait_ $statement): array
    {
        $traits = [];

        foreach ($statement->stmts as $member) {
            if (! $member instanceof Stmt\TraitUse) {
                continue;
            }

            foreach ($member->traits as $trait) {
                $resolved = $this->resolvedName($trait);

                if ($resolved !== null) {
                    $traits[$resolved] = $resolved;
                }
            }
        }

        sort($traits);

        return array_values($traits);
    }

    private function relationshipReturnModel(Stmt\ClassMethod $method): ?string
    {
        foreach ($method->stmts ?? [] as $statement) {
            $model = $this->relationshipReturnModelFromNode($statement);

            if ($model !== null) {
                return $model;
            }
        }

        return null;
    }

    private function relationshipReturnModelFromNode(Node $node): ?string
    {
        if ($node instanceof Expr\MethodCall && $node->name instanceof Identifier && in_array($node->name->toString(), $this->relationshipCalls, true)) {
            $firstArg = $node->args[0]->value ?? null;

            if ($firstArg instanceof Expr\ClassConstFetch) {
                return $this->resolvedName($firstArg->class);
            }
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof Node) {
                $model = $this->relationshipReturnModelFromNode($value);

                if ($model !== null) {
                    return $model;
                }

                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if (! $item instanceof Node) {
                        continue;
                    }

                    $model = $this->relationshipReturnModelFromNode($item);

                    if ($model !== null) {
                        return $model;
                    }
                }
            }
        }

        return null;
    }
}
