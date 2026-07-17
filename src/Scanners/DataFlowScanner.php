<?php

namespace AppGraph\Scanners;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node as GraphNode;
use AppGraph\Scanners\Concerns\InteractsWithPhpAst;
use AppGraph\Support\FileFinder;
use AppGraph\Support\PhpFileFacts;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

class DataFlowScanner
{
    use InteractsWithPhpAst;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $classes = [];

    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $methods = [];

    /**
     * @var array<string, array{table: string, confidence: float, metadata: array<string, mixed>}>
     */
    private array $modelTables = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $relationships = [];

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
        'morphedByMany',
        'hasManyThrough',
        'hasOneThrough',
    ];

    /**
     * @var array<int, string>
     */
    private array $readOperations = [
        'all',
        'avg',
        'count',
        'cursor',
        'cursorPaginate',
        'exists',
        'find',
        'findMany',
        'findOrFail',
        'first',
        'firstOr',
        'firstOrFail',
        'get',
        'load',
        'loadMissing',
        'max',
        'min',
        'paginate',
        'pluck',
        'simplePaginate',
        'sum',
        'value',
    ];

    /**
     * @var array<int, string>
     */
    private array $writeOperations = [
        'attach',
        'create',
        'decrement',
        'delete',
        'deleteQuietly',
        'destroy',
        'detach',
        'firstOrCreate',
        'forceCreate',
        'forceDelete',
        'increment',
        'insert',
        'restore',
        'restoreQuietly',
        'save',
        'saveMany',
        'saveQuietly',
        'sync',
        'syncWithoutDetaching',
        'toggle',
        'truncate',
        'update',
        'updateOrCreate',
        'updateQuietly',
        'upsert',
    ];

    /**
     * @var array<int, string>
     */
    private array $queryPassthroughOperations = [
        'crossJoin',
        'distinct',
        'from',
        'groupBy',
        'groupByRaw',
        'having',
        'havingRaw',
        'join',
        'latest',
        'leftJoin',
        'limit',
        'newQuery',
        'oldest',
        'on',
        'orWhere',
        'orWhereRaw',
        'orderBy',
        'orderByRaw',
        'query',
        'reorder',
        'rightJoin',
        'select',
        'addSelect',
        'selectRaw',
        'take',
        'tap',
        'unless',
        'when',
        'where',
        'whereBelongsTo',
        'whereBetween',
        'whereColumn',
        'whereDate',
        'whereDoesntHave',
        'whereHas',
        'whereIn',
        'whereKey',
        'whereKeyNot',
        'whereNotBetween',
        'whereNotIn',
        'whereNotNull',
        'whereNull',
        'whereRaw',
        'with',
        'withCount',
        'withWhereHas',
        'without',
        'withoutGlobalScope',
        'withoutGlobalScopes',
    ];

    /**
     * @var array<int, string>
     */
    private array $relationshipPivotWriteOperations = [
        'attach',
        'detach',
        'sync',
        'syncWithoutDetaching',
        'toggle',
    ];

    public function __construct(
        private FileFinder $files,
        ?PhpFileFacts $phpFileFacts = null,
    ) {
        $this->initializePhpFileFacts($phpFileFacts);
    }

    public function scan(Graph $graph): Graph
    {
        $this->classes = [];
        $this->methods = [];
        $this->modelTables = [];
        $this->relationships = [];

        $files = $this->files->findPhpFiles(['app', 'routes']);

        foreach ($files as $file) {
            $statements = $this->parseFile($file, $graph);

            if ($statements === null) {
                continue;
            }

            $this->indexStatements($statements, $file, $this->files->relativePath($file) ?? $file);
            unset($statements);
        }

        $this->hydrateModelTablesFromGraph($graph);
        $this->inferModelTablesFromClassHierarchy();
        $this->indexRelationships();

        gc_collect_cycles();

        foreach ($files as $file) {
            $statements = $this->parseFile($file, $graph);

            if ($statements === null) {
                continue;
            }

            $this->scanStatements($graph, $statements);
            unset($statements);
        }

        return $graph;
    }

    protected function scannerName(): string
    {
        return 'data_flow';
    }

    protected function scannerSourceLabel(): string
    {
        return 'data_flow_scanner';
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
                'abstract' => $statement instanceof Stmt\Class_ && $statement->isAbstract(),
                'type' => $statement instanceof Stmt\Trait_ ? 'trait' : 'class',
                'node' => $statement,
            ];

            foreach ($statement->getMethods() as $method) {
                $this->methods[$class][$method->name->toString()] = [
                    'node' => $method,
                    'file' => $relativeFile,
                    'line' => $method->getStartLine(),
                    'endLine' => $method->getEndLine(),
                    'signature' => $this->methodSignature($method),
                    'inputs' => $this->methodInputs($method),
                    'outputs' => $this->methodOutputs($method),
                    'visibility' => $this->methodVisibility($method),
                    'static' => $method->isStatic(),
                ];
            }

            $explicitTable = $this->explicitTableFromClass($statement);

            if ($explicitTable !== null) {
                $this->setModelTable($class, $explicitTable, 1.0, [
                    'source' => 'explicit_table_property_ast',
                ]);
            }
        }
    }

    private function hydrateModelTablesFromGraph(Graph $graph): void
    {
        foreach ($graph->nodes() as $node) {
            if ($node->type !== 'model') {
                continue;
            }

            $table = $node->metadata['table'] ?? null;

            if (! is_string($table) || $table === '') {
                continue;
            }

            $this->setModelTable($node->id, $table, 1.0, [
                'source' => 'model_scanner_graph_node',
            ]);
        }
    }

    private function inferModelTablesFromClassHierarchy(): void
    {
        $changed = true;

        while ($changed) {
            $changed = false;

            foreach ($this->classes as $class => $record) {
                if (isset($this->modelTables[$class])
                    || ($record['abstract'] ?? false)
                    || ! $this->classLooksLikeModel($class)) {
                    continue;
                }

                $this->setModelTable($class, Str::snake(Str::pluralStudly($record['shortName'])), 0.8, [
                    'source' => 'eloquent_table_convention_ast',
                    'reason' => 'No explicit $table property was found while indexing PHP source.',
                ]);

                $changed = true;
            }
        }
    }

    private function indexRelationships(): void
    {
        foreach ($this->methods as $class => $methods) {
            if (! isset($this->modelTables[$class])) {
                continue;
            }

            foreach ($methods as $method => $record) {
                $relationship = $this->relationshipFromMethod($class, $method, $record['node']);

                if ($relationship !== null) {
                    $this->relationships[$class.'::'.$method] = $relationship;
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
                    'localTypes' => $this->parameterTypes($method, $class),
                    'localAccess' => [],
                ];

                foreach ($method->stmts ?? [] as $methodStatement) {
                    $this->scanNode($graph, $methodStatement, $context);
                }
            }
        }
    }

    /**
     * @param array{
     *     class: string,
     *     method: string,
     *     localTypes: array<string, array{class: string, confidence: float, inference: string}>,
     *     localAccess: array<string, array<string, mixed>>
     * } $context
     */
    private function scanNode(Graph $graph, Node $node, array &$context): void
    {
        if ($node instanceof Expr\Assign && ! $node->var instanceof Expr\Variable) {
            $this->scanNode($graph, $node->expr, $context);
            $this->addAttributeAssignmentDataFlowEdges($graph, $node, $context);

            return;
        }

        if ($node instanceof Expr\Assign && $node->var instanceof Expr\Variable && is_string($node->var->name)) {
            $this->scanNode($graph, $node->expr, $context);

            $type = $this->inferExpressionType($node->expr, $context);
            $access = $this->inferAccessExpression($node->expr, $context);

            if ($type !== null) {
                $context['localTypes'][$node->var->name] = $type;
            }

            if ($access !== null) {
                $context['localAccess'][$node->var->name] = $access;
            }

            return;
        }

        if ($node instanceof Expr\MethodCall) {
            if ($node->var instanceof Node) {
                $this->scanNode($graph, $node->var, $context);
            }

            $this->addMethodCallDataFlowEdges($graph, $node, $context);

            foreach ($node->args as $arg) {
                // PHP first-class callable syntax uses a VariadicPlaceholder,
                // which intentionally has no value expression to scan.
                if ($arg instanceof Arg) {
                    $this->scanArg($graph, $arg, $context);
                }
            }

            return;
        }

        if ($node instanceof Expr\StaticCall) {
            $this->addStaticCallDataFlowEdges($graph, $node, $context);

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
     * @param array{
     *     class: string,
     *     method: string,
     *     localTypes: array<string, array{class: string, confidence: float, inference: string}>,
     *     localAccess: array<string, array<string, mixed>>
     * } $context
     */
    private function scanArg(Graph $graph, Arg $arg, array &$context): void
    {
        if ($arg->value instanceof Node) {
            $this->scanNode($graph, $arg->value, $context);
        }
    }

    /**
     * @param array{
     *     class: string,
     *     method: string,
     *     localTypes: array<string, array{class: string, confidence: float, inference: string}>,
     *     localAccess: array<string, array<string, mixed>>
     * } $context
     */
    private function addMethodCallDataFlowEdges(Graph $graph, Expr\MethodCall $call, array $context): void
    {
        if (! $call->name instanceof Identifier) {
            return;
        }

        $operation = $call->name->toString();
        $flowTypes = $this->flowTypesForOperation($operation);

        if ($flowTypes === []) {
            return;
        }

        $access = $this->inferAccessExpression($call->var, $context);

        if ($access === null) {
            return;
        }

        foreach ($flowTypes as $flowType) {
            foreach ($this->dataTargetsForAccess($access, $flowType, $operation) as $target) {
                $this->addDataFlowEdge($graph, $context, $target['table'], $flowType, round($target['confidence'] * $this->operationConfidence($operation, $flowType), 2), [
                    'line' => $call->getStartLine(),
                    'callsite' => $this->nodeCallsite($call),
                    'operation' => $operation,
                    'syntax' => 'method_call',
                    ...$this->fieldEvidenceForCall($call, $operation, $flowType, $access),
                ] + $target['metadata']);
            }
        }
    }

    /**
     * @param array{
     *     class: string,
     *     method: string,
     *     localTypes: array<string, array{class: string, confidence: float, inference: string}>,
     *     localAccess: array<string, array<string, mixed>>
     * } $context
     */
    private function addStaticCallDataFlowEdges(Graph $graph, Expr\StaticCall $call, array $context): void
    {
        if (! $call->name instanceof Identifier) {
            return;
        }

        $operation = $call->name->toString();
        $targetClass = $this->resolveStaticClass($call->class, $context['class']);

        if ($targetClass !== null && ($table = $this->tableForModel($targetClass)) !== null) {
            foreach ($this->flowTypesForOperation($operation) as $flowType) {
                $this->addDataFlowEdge($graph, $context, $table['table'], $flowType, round($table['confidence'] * $this->operationConfidence($operation, $flowType), 2), [
                    'line' => $call->getStartLine(),
                    'callsite' => $this->nodeCallsite($call),
                    'operation' => $operation,
                    'syntax' => 'static_call',
                    ...$this->fieldEvidenceForCall($call, $operation, $flowType),
                    'inference' => 'model_static_call',
                    'model' => $targetClass,
                    'targetRole' => 'model_table',
                ] + $table['metadata']);
            }

            return;
        }

        if (! $this->isDbFacadeClass($targetClass)) {
            return;
        }

        $flowType = match ($operation) {
            'select', 'selectOne', 'scalar' => 'reads',
            'delete', 'insert', 'statement', 'unprepared', 'update' => 'writes',
            default => null,
        };

        if ($flowType === null) {
            return;
        }

        $sql = $this->stringArg($call->args[0] ?? null);

        if ($sql === null) {
            return;
        }

        foreach ($this->tablesFromSql($sql, $flowType) as $table) {
            $this->addDataFlowEdge($graph, $context, $table, $flowType, 0.4, [
                'line' => $call->getStartLine(),
                'callsite' => $this->nodeCallsite($call),
                'operation' => $operation,
                'syntax' => 'db_facade_raw_sql',
                'inference' => 'raw_sql_table_name_regex',
                'targetRole' => 'raw_sql_table',
            ]);
        }
    }

    /**
     * @param array{
     *     class: string,
     *     method: string,
     *     localTypes: array<string, array{class: string, confidence: float, inference: string}>,
     *     localAccess: array<string, array<string, mixed>>
     * } $context
     * @param array<string, mixed> $metadata
     */
    private function addDataFlowEdge(Graph $graph, array $context, string $table, string $type, float $confidence, array $metadata): void
    {
        if ($table === '') {
            return;
        }

        $callerId = $context['class'].'::'.$context['method'];
        $existingTable = $graph->node('table:'.$table);

        if (($existingTable?->metadata['identityAmbiguous'] ?? false) === true) {
            $confidence = min($confidence, 0.5);
            $metadata['matchCertainty'] = 'possible';
            $metadata['ambiguity'] = 'database_scope_unresolved';
            $metadata['schemaIdentityCount'] = count(
                (array) ($existingTable->metadata['schemaIdentities'] ?? []),
            );
        }

        if (isset($this->methods[$context['class']][$context['method']])) {
            $graph->addNode($this->methodNode($context['class'], $context['method'], $this->methods[$context['class']][$context['method']]));
        }

        $graph->addNode(GraphNode::make('table:'.$table, 'table', $table, [
            'metadata' => [
                'inferredFromDataFlow' => true,
            ],
        ]));

        $operationKey = implode(':', array_filter([
            (string) ($metadata['line'] ?? 'unknown'),
            (string) ($metadata['callsite'] ?? 'unknown'),
            (string) ($metadata['operation'] ?? 'unknown'),
            (string) ($metadata['targetRole'] ?? 'table'),
        ]));

        // Compact per-call-site record: only the fields that actually vary between
        // operations on the same (caller, table, flow-type) edge. `inference` and
        // `targetRole` are kept once at the edge level (representative) rather than
        // repeated inside every op record.
        $operation = array_filter([
            'line' => $metadata['line'] ?? null,
            'operation' => $metadata['operation'] ?? null,
            'syntax' => $metadata['syntax'] ?? null,
            'model' => $metadata['model'] ?? null,
            'localScope' => $metadata['localScope'] ?? null,
            'relationshipMethod' => $metadata['relationshipMethod'] ?? null,
            'relationshipType' => $metadata['relationshipType'] ?? null,
            'fields' => $metadata['fields'] ?? null,
            'fieldCoverage' => $metadata['fieldCoverage'] ?? null,
            'fieldEvidence' => $metadata['fieldEvidence'] ?? null,
            'possibleModels' => $metadata['possibleModels'] ?? null,
        ], static fn ($value): bool => $value !== null);

        $graph->addEdge(new Edge($callerId, 'table:'.$table, $type, $confidence, array_filter([
            'inference' => $metadata['inference'] ?? null,
            'targetRole' => $metadata['targetRole'] ?? null,
            'matchCertainty' => $metadata['matchCertainty'] ?? null,
            'ambiguity' => $metadata['ambiguity'] ?? null,
            'schemaIdentityCount' => $metadata['schemaIdentityCount'] ?? null,
            'operations' => [
                $operationKey => $operation,
            ],
        ], static fn ($value): bool => $value !== null && $value !== [])));
    }

    /**
     * @param array<string, mixed> $access
     * @return array<int, array{table: string, confidence: float, metadata: array<string, mixed>}>
     */
    private function dataTargetsForAccess(array $access, string $flowType, string $operation): array
    {
        if (($access['kind'] ?? null) === 'table_query') {
            return [[
                'table' => $access['table'],
                'confidence' => $access['confidence'],
                'metadata' => [
                    'inference' => $access['inference'],
                    'targetRole' => 'query_table',
                ] + ($access['metadata'] ?? []),
            ]];
        }

        if (in_array($access['kind'] ?? null, ['model', 'model_query'], true)) {
            return [[
                'table' => $access['table'],
                'confidence' => $access['confidence'],
                'metadata' => [
                    'inference' => $access['inference'],
                    'model' => $access['class'] ?? null,
                    'targetRole' => 'model_table',
                ] + ($access['metadata'] ?? []),
            ]];
        }

        if (($access['kind'] ?? null) === 'polymorphic_model') {
            return array_map(static fn (array $target): array => [
                'table' => $target['table'],
                'confidence' => $target['confidence'],
                'metadata' => [
                    'inference' => 'abstract_model_descendants',
                    'model' => $access['class'],
                    'possibleModels' => $target['models'],
                    'targetRole' => 'possible_model_table',
                ],
            ], $access['targets']);
        }

        if (($access['kind'] ?? null) !== 'relationship') {
            return [];
        }

        $relationship = $access['relationship'];
        $baseMetadata = [
            'inference' => $access['inference'],
            'model' => $access['class'] ?? null,
            'relationshipMethod' => $relationship['method'] ?? null,
            'relationshipType' => $relationship['type'] ?? null,
        ] + ($access['metadata'] ?? []);

        if ($flowType === 'writes') {
            if (in_array($operation, $this->relationshipPivotWriteOperations, true)) {
                $pivotTable = $relationship['pivotTable'] ?? null;

                if (is_string($pivotTable) && $pivotTable !== '') {
                    return [[
                        'table' => $pivotTable,
                        'confidence' => $access['confidence'] * ($relationship['pivotConfidence'] ?? 0.75),
                        'metadata' => $baseMetadata + [
                            'targetRole' => 'relationship_pivot_table',
                        ],
                    ]];
                }
            }

            $targetTable = $relationship['targetTable'] ?? null;

            return is_string($targetTable) && $targetTable !== ''
                ? [[
                    'table' => $targetTable,
                    'confidence' => $access['confidence'],
                    'metadata' => $baseMetadata + [
                        'targetRole' => 'relationship_related_table',
                    ],
                ]]
                : [];
        }

        $targets = [];
        $targetTable = $relationship['targetTable'] ?? null;

        if (is_string($targetTable) && $targetTable !== '') {
            $targets[] = [
                'table' => $targetTable,
                'confidence' => $access['confidence'],
                'metadata' => $baseMetadata + [
                    'targetRole' => 'relationship_related_table',
                ],
            ];
        }

        $pivotTable = $relationship['pivotTable'] ?? null;

        if (is_string($pivotTable) && $pivotTable !== '') {
            $targets[] = [
                'table' => $pivotTable,
                'confidence' => $access['confidence'] * ($relationship['pivotConfidence'] ?? 0.75),
                'metadata' => $baseMetadata + [
                    'targetRole' => 'relationship_pivot_table',
                ],
            ];
        }

        return $targets;
    }

    /**
     * @param array{
     *     class: string,
     *     method: string,
     *     localTypes: array<string, array{class: string, confidence: float, inference: string}>,
     *     localAccess: array<string, array<string, mixed>>
     * } $context
     * @return array<string, mixed>|null
     */
    private function inferAccessExpression(Node|Expr $expression, array $context): ?array
    {
        if ($expression instanceof Expr\Variable) {
            if ($expression->name === 'this') {
                return $this->modelAccess($context['class'], 1.0, '$this');
            }

            if (is_string($expression->name)) {
                if (isset($context['localAccess'][$expression->name])) {
                    return $context['localAccess'][$expression->name];
                }

                if (isset($context['localTypes'][$expression->name])) {
                    $type = $context['localTypes'][$expression->name];

                    return $this->modelAccess($type['class'], $type['confidence'], $type['inference']);
                }
            }

            return null;
        }

        if ($expression instanceof Expr\New_) {
            $class = $this->resolvedName($expression->class);

            return $class === null ? null : $this->modelAccess($class, 0.95, 'new_expression');
        }

        if ($expression instanceof Expr\StaticCall) {
            if (! $expression->name instanceof Identifier) {
                return null;
            }

            $operation = $expression->name->toString();
            $class = $this->resolveStaticClass($expression->class, $context['class']);

            if ($this->isDbFacadeClass($class) && $operation === 'table') {
                $table = $this->stringArg($expression->args[0] ?? null);

                if ($table !== null) {
                    return [
                        'kind' => 'table_query',
                        'table' => $table,
                        'confidence' => 0.95,
                        'inference' => 'db_table_call',
                        'metadata' => [
                            'source' => 'db_facade_table_argument',
                        ],
                    ];
                }
            }

            $access = $class === null ? null : $this->modelAccess($class, 0.85, 'model_static_call');

            if ($access === null) {
                return null;
            }

            $localScope = $this->localScopeForAccess($access, $operation);

            if (in_array($operation, $this->queryPassthroughOperations, true) || $localScope !== null) {
                $fieldEvidence = $this->queryFieldEvidenceFromCall($expression, $operation);
                $access['kind'] = 'model_query';
                $access['confidence'] *= $localScope === null ? 1.0 : 0.95;
                $access['inference'] = $localScope === null
                    ? 'model_query_chain'
                    : 'model_local_scope';

                if ($localScope !== null) {
                    $access['metadata']['localScope'] = $localScope;
                }

                return $this->applyQueryFieldEvidence($access, $operation, $fieldEvidence);
            }

            if (in_array($operation, array_merge($this->readOperations, $this->writeOperations), true)) {
                return $access;
            }

            return null;
        }

        if ($expression instanceof Expr\MethodCall && $expression->name instanceof Identifier) {
            $receiver = $this->inferAccessExpression($expression->var, $context);

            if ($receiver === null) {
                return null;
            }

            $operation = $expression->name->toString();
            $relationship = $this->relationshipForAccess($receiver, $operation);

            if ($relationship !== null) {
                return [
                    'kind' => 'relationship',
                    'class' => $receiver['class'] ?? null,
                    'relationship' => $relationship,
                    'confidence' => round(($receiver['confidence'] ?? 0.7) * ($relationship['confidence'] ?? 0.7), 2),
                    'inference' => 'eloquent_relationship_chain',
                    'metadata' => [
                        'source' => 'relationship_method_return',
                    ],
                ];
            }

            $localScope = $this->localScopeForAccess($receiver, $operation);

            if (in_array($operation, $this->queryPassthroughOperations, true) || $localScope !== null) {
                $fieldEvidence = $this->queryFieldEvidenceFromCall($expression, $operation);
                $receiver['confidence'] = round(
                    ($receiver['confidence'] ?? 0.7) * ($localScope === null ? 1.0 : 0.95),
                    2,
                );
                $receiver['inference'] = $localScope === null
                    ? ($receiver['inference'] ?? 'query_chain')
                    : 'model_query_with_local_scope';

                if ($localScope !== null) {
                    $receiver['metadata']['localScope'] = $localScope;
                }

                if (($receiver['kind'] ?? null) === 'model') {
                    $receiver['kind'] = 'model_query';
                }

                return $this->applyQueryFieldEvidence($receiver, $operation, $fieldEvidence);
            }

            if (in_array($operation, array_merge($this->readOperations, $this->writeOperations), true)) {
                return $receiver;
            }
        }

        return null;
    }

    /**
     * Keep predicates separate from projections: `select()` replaces a prior
     * projection while `addSelect()` appends. Mixing the two would claim that
     * overwritten columns are still read by the final SQL query.
     *
     * @param array<string, mixed> $access
     * @param array{fields: array<int, string>, complete: bool} $evidence
     * @return array<string, mixed>
     */
    private function applyQueryFieldEvidence(array $access, string $operation, array $evidence): array
    {
        if ($operation === 'select') {
            $access['queryProjectionSeen'] = true;
            $access['queryProjectionFields'] = $evidence['fields'];
            $access['queryProjectionComplete'] = $evidence['complete'];

            return $access;
        }

        if ($operation === 'addSelect') {
            $projectionSeen = $access['queryProjectionSeen'] ?? false;
            $access['queryProjectionSeen'] = true;
            $access['queryProjectionFields'] = $this->mergeFields(
                $projectionSeen ? ($access['queryProjectionFields'] ?? []) : [],
                $evidence['fields'],
            );
            $access['queryProjectionComplete'] = ($projectionSeen
                ? ($access['queryProjectionComplete'] ?? false)
                : true) && $evidence['complete'];

            return $access;
        }

        $access['queryPredicateFields'] = $this->mergeFields(
            $access['queryPredicateFields'] ?? [],
            $evidence['fields'],
        );
        $access['queryPredicatesComplete'] = ($access['queryPredicatesComplete'] ?? true)
            && $evidence['complete'];

        return $access;
    }

    /**
     * @param array<string, mixed> $access
     * @return array<string, mixed>|null
     */
    private function relationshipForAccess(array $access, string $method): ?array
    {
        $class = $access['class'] ?? null;

        if (! is_string($class)) {
            return null;
        }

        return $this->relationships[$class.'::'.$method] ?? null;
    }

    /** @param array<string, mixed> $access */
    private function localScopeForAccess(array $access, string $operation): ?string
    {
        $class = $access['class'] ?? null;

        if (! is_string($class) || $class === '') {
            return null;
        }

        $scope = 'scope'.Str::studly($operation);

        return isset($this->methods[$class][$scope]) ? $class.'::'.$scope : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function modelAccess(string $class, float $confidence, string $inference): ?array
    {
        $table = $this->tableForModel($class);

        if ($table === null) {
            $targets = $this->descendantModelTables($class, $confidence);

            return $targets === [] ? null : [
                'kind' => 'polymorphic_model',
                'class' => $class,
                'targets' => $targets,
                'confidence' => max(array_column($targets, 'confidence')),
                'inference' => 'abstract_model_descendants',
            ];
        }

        return [
            'kind' => 'model',
            'class' => $class,
            'table' => $table['table'],
            'confidence' => round($confidence * $table['confidence'], 2),
            'inference' => $inference,
            'metadata' => $table['metadata'],
        ];
    }

    /**
     * @return array{table: string, confidence: float, metadata: array<string, mixed>}|null
     */
    private function tableForModel(string $class): ?array
    {
        if (isset($this->modelTables[$class])) {
            return $this->modelTables[$class];
        }

        if (($this->classes[$class]['abstract'] ?? false) === true) {
            return null;
        }

        if (! str_contains($class, '\\')) {
            return null;
        }

        $namespace = $this->namespaceFromClass($class);

        if ($namespace === null || ! str_contains($namespace, '\\Models')) {
            return null;
        }

        return [
            'table' => Str::snake(Str::pluralStudly(class_basename($class))),
            'confidence' => 0.45,
            'metadata' => [
                'source' => 'unindexed_model_namespace_convention',
            ],
        ];
    }

    /**
     * @param array{
     *     class: string,
     *     method: string,
     *     localTypes: array<string, array{class: string, confidence: float, inference: string}>,
     *     localAccess: array<string, array<string, mixed>>
     * } $context
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

            if ($class === null || ! $expression->name instanceof Identifier) {
                return null;
            }

            if (in_array($expression->name->toString(), array_merge($this->readOperations, $this->writeOperations), true)) {
                return [
                    'class' => $class,
                    'confidence' => 0.75,
                    'inference' => 'model_static_call_result',
                ];
            }

            return null;
        }

        if ($expression instanceof Expr\MethodCall && $expression->name instanceof Identifier) {
            $receiver = $this->inferAccessExpression($expression, $context);

            if ($receiver === null) {
                return null;
            }

            if (($receiver['kind'] ?? null) === 'relationship') {
                $targetClass = $receiver['relationship']['targetClass'] ?? null;

                return is_string($targetClass) ? [
                    'class' => $targetClass,
                    'confidence' => $receiver['confidence'] ?? 0.6,
                    'inference' => 'relationship_operation_result',
                ] : null;
            }

            $class = $receiver['class'] ?? null;

            return is_string($class) ? [
                'class' => $class,
                'confidence' => $receiver['confidence'] ?? 0.6,
                'inference' => 'model_operation_result',
            ] : null;
        }

        return null;
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

        if (str_starts_with((string) $record['file'], 'app/Models/')) {
            return true;
        }

        $extends = $record['extends'] ?? null;

        if (! is_string($extends)) {
            return false;
        }

        return in_array($extends, [
            'Illuminate\Database\Eloquent\Model',
            'Illuminate\Foundation\Auth\User',
        ], true) || isset($this->modelTables[$extends]) || $this->classLooksLikeModel($extends, $visited);
    }

    /**
     * Resolve an abstract/base model parameter to every indexed concrete
     * descendant table. Multiple possible tables are intentionally retained as
     * lower-confidence edges instead of inventing a table for the base class.
     *
     * @return array<int, array{table: string, confidence: float, models: array<int, string>}>
     */
    private function descendantModelTables(string $baseClass, float $confidence): array
    {
        $tables = [];

        foreach ($this->modelTables as $model => $table) {
            if ($model === $baseClass || ! $this->classExtends($model, $baseClass)) {
                continue;
            }

            $tableName = $table['table'];
            $tables[$tableName] ??= [
                'table' => $tableName,
                'confidence' => round($confidence * $table['confidence'] * 0.65, 2),
                'models' => [],
            ];
            $tables[$tableName]['models'][] = $model;
        }

        foreach ($tables as &$table) {
            sort($table['models']);
        }

        ksort($tables);

        return array_values($tables);
    }

    private function classExtends(string $class, string $baseClass, array $visited = []): bool
    {
        if (isset($visited[$class])) {
            return false;
        }

        $visited[$class] = true;
        $parent = $this->classes[$class]['extends'] ?? null;

        if (! is_string($parent) || $parent === '') {
            return false;
        }

        return $parent === $baseClass || $this->classExtends($parent, $baseClass, $visited);
    }

    /**
     * @param array{
     *     class: string,
     *     method: string,
     *     localTypes: array<string, array{class: string, confidence: float, inference: string}>,
     *     localAccess: array<string, array<string, mixed>>
     * } $context
     */
    private function addAttributeAssignmentDataFlowEdges(Graph $graph, Expr\Assign $assignment, array $context): void
    {
        $attribute = $this->attributeTarget($assignment->var, $context);

        if ($attribute === null) {
            return;
        }

        foreach ($this->dataTargetsForAccess($attribute['access'], 'writes', 'assign') as $target) {
            $this->addDataFlowEdge($graph, $context, $target['table'], 'writes', round($target['confidence'] * 0.85, 2), [
                'line' => $assignment->getStartLine(),
                'callsite' => $this->nodeCallsite($assignment),
                'operation' => 'assign',
                'syntax' => 'attribute_assignment',
                'fields' => [$attribute['field']],
                'fieldCoverage' => 'complete',
                'fieldEvidence' => 'attribute_assignment',
            ] + $target['metadata']);
        }
    }

    /**
     * @param array{
     *     class: string,
     *     method: string,
     *     localTypes: array<string, array{class: string, confidence: float, inference: string}>,
     *     localAccess: array<string, array<string, mixed>>
     * } $context
     * @return array{access: array<string, mixed>, field: string}|null
     */
    private function attributeTarget(Expr $expression, array $context): ?array
    {
        $segments = [];

        while ($expression instanceof Expr\ArrayDimFetch) {
            $segment = $this->arrayDimension($expression->dim);
            array_unshift($segments, $segment ?? '{dynamic}');
            $expression = $expression->var;
        }

        if (! $expression instanceof Expr\PropertyFetch || ! $expression->name instanceof Identifier) {
            return null;
        }

        $access = $this->inferAccessExpression($expression->var, $context);

        if ($access === null) {
            return null;
        }

        array_unshift($segments, $expression->name->toString());

        return [
            'access' => $access,
            'field' => implode('.', $segments),
        ];
    }

    private function arrayDimension(?Expr $dimension): ?string
    {
        return match (true) {
            $dimension instanceof Scalar\String_ => $dimension->value,
            $dimension instanceof Scalar\Int_ => (string) $dimension->value,
            default => null,
        };
    }

    private function nodeCallsite(Node $node): string
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        if ($start >= 0 && $end >= $start) {
            return $start.'-'.$end;
        }

        return $node->getStartTokenPos().'-'.$node->getEndTokenPos();
    }

    /**
     * @param array<string, mixed>|null $access
     * @return array{fields: array<int, string>, fieldCoverage: string, fieldEvidence?: string}
     */
    private function fieldEvidenceForCall(Expr\MethodCall|Expr\StaticCall $call, string $operation, string $flowType, ?array $access = null): array
    {
        if ($flowType === 'reads') {
            $predicateFields = $access['queryPredicateFields'] ?? [];
            $predicatesComplete = $access['queryPredicatesComplete'] ?? true;
            $projectionSeen = $access['queryProjectionSeen'] ?? false;
            $projectionFields = $access['queryProjectionFields'] ?? [];
            $projectionComplete = $access['queryProjectionComplete'] ?? false;

            if ($operation === 'find' && ($access['kind'] ?? null) === 'table_query') {
                // Query Builder::find() always adds `where id = ?` before its
                // terminal first() projection.
                $predicateFields = $this->mergeFields($predicateFields, ['id']);
            }

            if (in_array($operation, [
                'all', 'cursor', 'cursorPaginate', 'find', 'findMany', 'findOrFail',
                'first', 'firstOr', 'firstOrFail', 'get', 'paginate', 'simplePaginate',
            ], true)) {
                if (! $projectionSeen) {
                    $projection = $this->rowProjectionEvidence($call, $operation);
                    $projectionFields = $projection['fields'];
                    $projectionComplete = $projection['complete'];
                }

                $fields = $this->mergeFields($predicateFields, $projectionFields);
                $chainComplete = $predicatesComplete && $projectionComplete;
            } elseif (in_array($operation, ['pluck', 'value'], true)) {
                $terminal = $this->queryFieldEvidenceFromCall($call, $operation);

                if (! $projectionSeen) {
                    $projectionFields = $terminal['fields'];
                    $projectionComplete = $terminal['complete'];
                }

                $fields = $this->mergeFields($predicateFields, $projectionFields);
                $chainComplete = $predicatesComplete && $projectionComplete;
            } elseif (in_array($operation, ['avg', 'count', 'max', 'min', 'sum'], true)) {
                $terminal = $this->aggregateProjectionEvidence($call, $operation);
                $fields = $this->mergeFields($predicateFields, $terminal['fields']);
                $chainComplete = $predicatesComplete && $terminal['complete'];
            } elseif ($operation === 'exists') {
                $fields = $predicateFields;
                $chainComplete = $predicatesComplete;
            } else {
                $terminal = $this->queryFieldEvidenceFromCall($call, $operation);
                $fields = $this->mergeFields($predicateFields, $projectionFields, $terminal['fields']);
                $chainComplete = false;
            }

            // Eloquent relationships/models can add global scopes and implicit
            // key columns. Only a literal DB::table() query can completely
            // exclude other columns; known Eloquent fields still prove their
            // own access while all other columns remain possible.
            $coverageIsComplete = ($access['kind'] ?? null) === 'table_query'
                && $chainComplete;

            return [
                'fields' => $fields,
                'fieldCoverage' => $coverageIsComplete
                    ? ($this->fieldsContainWildcard($fields) ? 'whole_row' : 'complete')
                    : 'unknown',
                ...($fields === [] ? [] : ['fieldEvidence' => 'literal_query_field']),
            ];
        }

        if (in_array($operation, ['save', 'saveQuietly'], true)) {
            // The first argument contains save options (for example `touch`),
            // not attributes. Dirty attributes and model events are not known
            // from the call site.
            return ['fields' => [], 'fieldCoverage' => 'unknown'];
        }

        $positions = match ($operation) {
            'firstOrCreate', 'updateOrCreate' => [0, 1],
            default => [0],
        };
        $fields = [];
        $allLiteral = true;

        foreach ($positions as $position) {
            $argument = $call->args[$position] ?? null;

            if ($argument === null && $position > 0) {
                continue;
            }

            if (! $argument instanceof Arg || ! $argument->value instanceof Expr\Array_) {
                $allLiteral = false;
                continue;
            }

            $extraction = $this->fieldExtractionFromCall($call, $position);
            $fields = $this->mergeFields($fields, $extraction['fields']);
            $allLiteral = $allLiteral && $extraction['complete'];
        }

        if (in_array($operation, ['increment', 'decrement'], true)) {
            $field = $this->literalFieldArgument($call->args[0] ?? null);
            $extraArgument = $call->args[2] ?? null;
            $extra = ['fields' => [], 'complete' => true];

            if ($extraArgument !== null) {
                $extra = $this->fieldExtractionFromCall($call, 2);
            }

            $fields = $this->mergeFields($field === null ? [] : [$field], $extra['fields']);
            $complete = $field !== null
                && $extra['complete']
                && ($access['kind'] ?? null) === 'table_query';

            return [
                'fields' => $fields,
                'fieldCoverage' => $complete ? 'complete' : 'unknown',
                ...($fields === [] ? [] : ['fieldEvidence' => 'literal_field_argument']),
            ];
        }

        if (in_array($operation, ['forceDelete', 'truncate'], true)
            || ($operation === 'delete' && ($access['kind'] ?? null) === 'table_query')) {
            return ['fields' => [], 'fieldCoverage' => 'whole_row'];
        }

        if (in_array($operation, ['delete', 'deleteQuietly', 'destroy', 'restore', 'restoreQuietly'], true)) {
            // Eloquent deletes may be soft deletes, and restores update a
            // model-defined deleted-at column. Without model trait metadata the
            // affected fields are intentionally left open.
            return ['fields' => [], 'fieldCoverage' => 'unknown'];
        }

        $complete = $fields !== []
            && $allLiteral
            && ($access['kind'] ?? null) === 'table_query';

        return [
            'fields' => $fields,
            'fieldCoverage' => $complete ? 'complete' : 'unknown',
            ...($fields === [] ? [] : ['fieldEvidence' => 'literal_payload']),
        ];
    }

    /**
     * @return array{fields: array<int, string>, complete: bool}
     */
    private function queryFieldEvidenceFromCall(Expr\MethodCall|Expr\StaticCall $call, string $operation): array
    {
        if (in_array($operation, ['addSelect', 'select'], true)) {
            $fields = [];
            $complete = true;

            foreach ($call->args as $argument) {
                if (! $argument instanceof Arg) {
                    $complete = false;
                    continue;
                }

                if ($argument->unpack) {
                    $complete = false;
                }

                if ($argument->value instanceof Scalar\String_) {
                    $field = $this->normalizeLiteralField($argument->value->value);

                    if ($field !== null) {
                        $fields[] = $field;
                    } else {
                        $complete = false;
                    }
                } elseif ($argument->value instanceof Expr\Array_) {
                    foreach ($argument->value->items as $item) {
                        if ($item?->unpack) {
                            $complete = false;
                        }

                        if ($item?->value instanceof Scalar\String_) {
                            $field = $this->normalizeLiteralField($item->value->value);

                            if ($field !== null) {
                                $fields[] = $field;
                            } else {
                                $complete = false;
                            }
                        } else {
                            $complete = false;
                        }
                    }
                } else {
                    $complete = false;
                }
            }

            $fields = $this->mergeFields([], $fields);
            return [
                'fields' => $fields,
                'complete' => $complete,
            ];
        }

        if (! in_array($operation, [
            'avg', 'count', 'max', 'min', 'sum', 'value', 'pluck',
            'where', 'orWhere', 'whereDate', 'whereIn', 'whereNotNull', 'whereNull', 'orderBy',
        ], true)) {
            $fieldFree = in_array($operation, [
                'distinct', 'limit', 'newModelQuery', 'newQuery', 'on', 'query', 'take',
            ], true);

            return [
                'fields' => [],
                'complete' => $fieldFree,
            ];
        }

        $positions = $operation === 'pluck' ? [0, 1] : [0];
        $fields = [];
        $complete = true;

        foreach ($positions as $position) {
            $argument = $call->args[$position] ?? null;

            if ($argument === null && $position > 0) {
                continue;
            }

            $field = $this->literalFieldArgument($argument);

            if ($field === null) {
                $complete = false;
            } else {
                $fields[] = $field;
            }
        }

        $fields = $this->mergeFields([], $fields);
        return [
            'fields' => $fields,
            'complete' => $complete,
        ];
    }

    /**
     * @return array{fields: array<int, string>, complete: bool}
     */
    private function rowProjectionEvidence(Expr\MethodCall|Expr\StaticCall $call, string $operation): array
    {
        $position = match ($operation) {
            'cursorPaginate', 'find', 'findMany', 'findOrFail', 'paginate', 'simplePaginate' => 1,
            default => 0,
        };
        $argument = $call->args[$position] ?? null;

        if ($argument === null) {
            return ['fields' => ['*'], 'complete' => true];
        }

        return $this->fieldListFromArgument($argument);
    }

    /**
     * @return array{fields: array<int, string>, complete: bool}
     */
    private function aggregateProjectionEvidence(Expr\MethodCall|Expr\StaticCall $call, string $operation): array
    {
        $argument = $call->args[0] ?? null;

        if ($argument === null) {
            return [
                'fields' => [],
                'complete' => $operation === 'count',
            ];
        }

        $evidence = $this->fieldListFromArgument($argument);

        // COUNT(*) does not read every individual column. It is a complete,
        // column-free aggregate rather than a whole-row projection.
        if ($operation === 'count' && $evidence['complete'] && $evidence['fields'] === ['*']) {
            return ['fields' => [], 'complete' => true];
        }

        return $evidence;
    }

    /**
     * @return array{fields: array<int, string>, complete: bool}
     */
    private function fieldListFromArgument(Arg|Node\VariadicPlaceholder $argument): array
    {
        if (! $argument instanceof Arg || $argument->unpack) {
            return ['fields' => [], 'complete' => false];
        }

        if ($argument->value instanceof Scalar\String_) {
            $field = $this->normalizeLiteralField($argument->value->value);

            return [
                'fields' => $field === null ? [] : [$field],
                'complete' => $field !== null,
            ];
        }

        if (! $argument->value instanceof Expr\Array_) {
            return ['fields' => [], 'complete' => false];
        }

        $fields = [];
        $complete = true;

        foreach ($argument->value->items as $item) {
            if ($item === null || $item->unpack || ! $item->value instanceof Scalar\String_) {
                $complete = false;
                continue;
            }

            $field = $this->normalizeLiteralField($item->value->value);

            if ($field === null) {
                $complete = false;
            } else {
                $fields[] = $field;
            }
        }

        return [
            'fields' => $this->mergeFields([], $fields),
            'complete' => $complete,
        ];
    }

    private function literalFieldArgument(Arg|Node\VariadicPlaceholder|null $argument): ?string
    {
        return $argument instanceof Arg && $argument->value instanceof Scalar\String_
            ? $this->normalizeLiteralField($argument->value->value)
            : null;
    }

    private function normalizeLiteralField(string $field): ?string
    {
        $field = trim($field);

        if (! preg_match('/^([A-Za-z_*][A-Za-z0-9_.*]*(?:->[A-Za-z0-9_*]+)*)(?:\s+as\s+[A-Za-z_][A-Za-z0-9_]*)?$/i', $field, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /** @param array<int, string> $fields */
    private function fieldsContainWildcard(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($field === '*' || str_ends_with($field, '.*')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> ...$fieldSets
     * @return array<int, string>
     */
    private function mergeFields(array ...$fieldSets): array
    {
        $fields = [];

        foreach ($fieldSets as $fieldSet) {
            foreach ($fieldSet as $field) {
                if (is_string($field) && $field !== '') {
                    $fields[$field] = $field;
                }
            }
        }

        sort($fields);

        return array_values($fields);
    }

    /**
     * @return array<int, string>
     */
    /** @return array{fields: array<int, string>, complete: bool} */
    private function fieldExtractionFromCall(Expr\MethodCall|Expr\StaticCall $call, int $position): array
    {
        $argument = $call->args[$position] ?? null;

        if (! $argument instanceof Arg || ! $argument->value instanceof Expr\Array_) {
            return ['fields' => [], 'complete' => false];
        }

        $extraction = $this->fieldExtractionFromArray($argument->value);
        $extraction['fields'] = $this->mergeFields([], $extraction['fields']);

        return $extraction;
    }

    /** @return array{fields: array<int, string>, complete: bool} */
    private function fieldExtractionFromArray(Expr\Array_ $array, string $prefix = ''): array
    {
        $fields = [];
        $complete = true;

        foreach ($array->items as $item) {
            if ($item === null) {
                $complete = false;
                continue;
            }

            if ($item->unpack) {
                $complete = false;
            }

            if (($item->key === null || $item->key instanceof Scalar\Int_)
                && $prefix === ''
                && $item->value instanceof Expr\Array_) {
                $nested = $this->fieldExtractionFromArray($item->value);
                $fields = [...$fields, ...$nested['fields']];
                $complete = $complete && $nested['complete'];
                continue;
            }

            $key = $this->arrayDimension($item->key);

            if ($key === null) {
                if ($prefix !== '') {
                    $fields[] = $prefix.'.{dynamic}';
                }

                $complete = false;
                continue;
            }

            $path = $prefix === '' ? $key : $prefix.'.'.$key;

            if ($item->value instanceof Expr\Array_ && $item->value->items !== []) {
                $nested = $this->fieldExtractionFromArray($item->value, $path);
                $fields = [...$fields, ...($nested['fields'] === [] ? [$path] : $nested['fields'])];
                $complete = $complete && $nested['complete'];
            } else {
                $fields[] = $path;
            }
        }

        return ['fields' => $fields, 'complete' => $complete];
    }

    private function explicitTableFromClass(Stmt\Class_|Stmt\Trait_ $class): ?string
    {
        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Stmt\Property) {
                continue;
            }

            foreach ($statement->props as $property) {
                if ($property->name->toString() !== 'table' || ! $property->default instanceof Scalar\String_) {
                    continue;
                }

                return $property->default->value;
            }
        }

        return null;
    }

    private function setModelTable(string $class, string $table, float $confidence, array $metadata): void
    {
        if ($table === '') {
            return;
        }

        if (isset($this->modelTables[$class]) && $this->modelTables[$class]['confidence'] > $confidence) {
            return;
        }

        $this->modelTables[$class] = [
            'table' => $table,
            'confidence' => $confidence,
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function relationshipFromMethod(string $class, string $method, Stmt\ClassMethod $node): ?array
    {
        foreach ($node->stmts ?? [] as $statement) {
            $relationship = $this->relationshipFromNode($class, $method, $statement);

            if ($relationship !== null) {
                return $relationship;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function relationshipFromNode(string $class, string $method, Node $node): ?array
    {
        if ($node instanceof Expr\MethodCall && $node->name instanceof Identifier && in_array($node->name->toString(), $this->relationshipCalls, true)) {
            $relationshipType = $node->name->toString();
            $targetClass = $this->relationshipTargetClass($node);

            if ($targetClass === null) {
                return null;
            }

            $targetTable = $this->tableForModel($targetClass);
            $pivot = $this->relationshipPivotTable($relationshipType, $node, $class, $targetClass);

            return [
                'method' => $method,
                'type' => $relationshipType,
                'targetClass' => $targetClass,
                'targetTable' => $targetTable['table'] ?? null,
                'pivotTable' => $pivot['table'] ?? null,
                'pivotConfidence' => $pivot['confidence'] ?? null,
                'confidence' => $targetTable === null ? 0.55 : min(0.9, $targetTable['confidence']),
                'metadata' => [
                    'targetTableInference' => $targetTable['metadata'] ?? null,
                    'pivotTableInference' => $pivot['metadata'] ?? null,
                ],
            ];
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof Node) {
                $relationship = $this->relationshipFromNode($class, $method, $value);

                if ($relationship !== null) {
                    return $relationship;
                }

                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if (! $item instanceof Node) {
                        continue;
                    }

                    $relationship = $this->relationshipFromNode($class, $method, $item);

                    if ($relationship !== null) {
                        return $relationship;
                    }
                }
            }
        }

        return null;
    }

    private function relationshipTargetClass(Expr\MethodCall $call): ?string
    {
        $firstArg = $call->args[0]->value ?? null;

        if ($firstArg instanceof Expr\ClassConstFetch) {
            return $this->resolvedName($firstArg->class);
        }

        return null;
    }

    /**
     * @return array{table: string, confidence: float, metadata: array<string, mixed>}|null
     */
    private function relationshipPivotTable(string $type, Expr\MethodCall $call, string $ownerClass, string $targetClass): ?array
    {
        if (in_array($type, ['belongsToMany', 'morphedByMany'], true)) {
            $explicit = $this->stringArg($call->args[1] ?? null);

            if ($explicit !== null) {
                return [
                    'table' => $explicit,
                    'confidence' => 1.0,
                    'metadata' => [
                        'source' => 'explicit_relationship_pivot_argument',
                        'argument' => 2,
                    ],
                ];
            }

            $parts = [
                Str::snake(Str::singular(class_basename($ownerClass))),
                Str::snake(Str::singular(class_basename($targetClass))),
            ];
            sort($parts);

            return [
                'table' => implode('_', $parts),
                'confidence' => 0.45,
                'metadata' => [
                    'source' => 'belongs_to_many_pivot_convention',
                ],
            ];
        }

        if ($type === 'morphToMany') {
            $explicit = $this->stringArg($call->args[2] ?? null);

            if ($explicit !== null) {
                return [
                    'table' => $explicit,
                    'confidence' => 1.0,
                    'metadata' => [
                        'source' => 'explicit_morph_to_many_table_argument',
                        'argument' => 3,
                    ],
                ];
            }

            $name = $this->stringArg($call->args[1] ?? null);

            if ($name !== null) {
                return [
                    'table' => Str::snake(Str::plural($name)),
                    'confidence' => 0.45,
                    'metadata' => [
                        'source' => 'morph_to_many_name_convention',
                    ],
                ];
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function flowTypesForOperation(string $operation): array
    {
        $types = [];

        if (in_array($operation, $this->readOperations, true)) {
            $types[] = 'reads';
        }

        if (in_array($operation, $this->writeOperations, true)) {
            $types[] = 'writes';
        }

        return $types;
    }

    private function operationConfidence(string $operation, string $type): float
    {
        if (in_array($operation, ['firstOrCreate', 'updateOrCreate'], true) && $type === 'writes') {
            return 0.65;
        }

        if (in_array($operation, ['exists', 'count', 'pluck', 'value'], true)) {
            return 0.95;
        }

        return 0.9;
    }

    private function isDbFacadeClass(?string $class): bool
    {
        if ($class === null) {
            return false;
        }

        return $class === 'Illuminate\Support\Facades\DB'
            || $class === 'Illuminate\Database\DatabaseManager';
    }

    private function stringArg(?Arg $arg): ?string
    {
        if (! $arg?->value instanceof Scalar\String_) {
            return null;
        }

        return $arg->value->value;
    }

    /**
     * @return array<int, string>
     */
    private function tablesFromSql(string $sql, string $flowType): array
    {
        $patterns = $flowType === 'reads'
            ? ['/from\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?/i', '/join\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?/i']
            : ['/update\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?/i', '/insert\s+into\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?/i', '/delete\s+from\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?/i'];

        $tables = [];

        foreach ($patterns as $pattern) {
            if (! preg_match_all($pattern, $sql, $matches)) {
                continue;
            }

            foreach ($matches[1] as $table) {
                $tables[$table] = $table;
            }
        }

        sort($tables);

        return array_values($tables);
    }
}
