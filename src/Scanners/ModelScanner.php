<?php

namespace AppGraph\Scanners;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node as GraphNode;
use AppGraph\Support\FileFinder;
use AppGraph\Support\PhpFileFacts;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\TraitUseAdaptation;
use PhpParser\NodeFinder;
use Throwable;

class ModelScanner
{
    private const MAX_ANCESTRY_WARNINGS = 100;

    private const MAX_EXACT_REFERENCE_CHARACTERS = 4096;

    private const MAX_EXACT_REFERENCE_BYTES = 16384;

    /** @var array<string, string> */
    private array $relationshipEdgeTypes = [
        'belongsto' => 'belongs_to',
        'belongstomany' => 'belongs_to_many',
        'hasmany' => 'has_many',
        'hasonethrough' => 'has_one_through',
        'hasmanythrough' => 'has_many_through',
        'hasone' => 'has_one',
        'morphedbymany' => 'morphed_by_many',
        'morphmany' => 'morph_many',
        'morphone' => 'morph_one',
        'morphto' => 'morph_to',
        'morphtomany' => 'morph_to_many',
    ];

    /**
     * Known framework model roots. Application-defined base models are resolved
     * transitively through the local class index below.
     *
     * @var array<string, true>
     */
    private array $modelRoots = [
        'Illuminate\\Database\\Eloquent\\Model' => true,
        'Illuminate\\Foundation\\Auth\\User' => true,
        'Illuminate\\Database\\Eloquent\\Relations\\Pivot' => true,
        'Illuminate\\Database\\Eloquent\\Relations\\MorphPivot' => true,
    ];

    /**
     * @var array<string, array{
     *     class: Stmt\Class_,
     *     file: string,
     *     extends: string|null,
     *     traitUses: array<int, array<string, mixed>>
     * }>
     */
    private array $classes = [];

    /**
     * @var array<string, array{
     *     trait: Stmt\Trait_,
     *     file: string,
     *     traitUses: array<int, array<string, mixed>>
     * }>
     */
    private array $traits = [];

    /** @var array<string, string> */
    private array $classNames = [];

    /** @var array<string, string> */
    private array $traitNames = [];

    /** @var array<string, true> */
    private array $applicationClasses = [];

    /** @var array<string, array{status: 'model'|'not_model'|'unknown', unresolved?: string, reason?: string}> */
    private array $modelAncestry = [];

    /** @var array<string, true> */
    private array $indexedFiles = [];

    /** @var array<string, string> */
    private array $sourceIndexFailures = [];

    /** @var array<string, array<string, array<string, mixed>|null>> */
    private array $classMethodCache = [];

    /** @var array<string, array<string, array<string, mixed>|null>> */
    private array $traitMethodCache = [];

    private int $ancestryWarningCount = 0;

    private int $suppressedAncestryWarnings = 0;

    private int $omittedAncestryReferenceWarnings = 0;

    private PhpFileFacts $phpFileFacts;

    public function __construct(
        private FileFinder $files,
        ?PhpFileFacts $phpFileFacts = null,
    ) {
        $this->phpFileFacts = $phpFileFacts ?? new PhpFileFacts();
    }

    public function scan(Graph $graph): Graph
    {
        $this->classes = [];
        $this->traits = [];
        $this->classNames = [];
        $this->traitNames = [];
        $this->applicationClasses = [];
        $this->modelAncestry = [];
        $this->indexedFiles = [];
        $this->sourceIndexFailures = [];
        $this->classMethodCache = [];
        $this->traitMethodCache = [];
        $this->ancestryWarningCount = 0;
        $this->suppressedAncestryWarnings = 0;
        $this->omittedAncestryReferenceWarnings = 0;

        foreach ($this->files->findPhpFiles(['app/Models', 'app']) as $file) {
            $this->indexFile($file, $graph);
        }

        $applicationClasses = array_keys($this->applicationClasses);
        sort($applicationClasses);

        foreach ($applicationClasses as $className) {
            $record = $this->classes[$className] ?? null;

            if ($record === null || $record['class']->isAbstract()) {
                continue;
            }

            $ancestry = $this->modelClassState($className);

            if ($ancestry['status'] === 'unknown') {
                if ($this->isModelShapedCandidate($className, $record, $ancestry)) {
                    $this->addAncestryWarning($graph, $className, $ancestry);
                }

                continue;
            }

            if ($ancestry['status'] !== 'model') {
                continue;
            }

            $this->addModel($graph, $className, $record);
        }

        if ($this->suppressedAncestryWarnings > 0) {
            $graph->addWarning([
                'scanner' => 'models',
                'inference' => 'unindexed_model_parent_summary',
                'suppressed' => $this->suppressedAncestryWarnings,
                'message' => sprintf(
                    'Suppressed %d additional model ancestry warnings after the bounded limit of %d.',
                    $this->suppressedAncestryWarnings,
                    self::MAX_ANCESTRY_WARNINGS,
                ),
            ]);
        }

        if ($this->omittedAncestryReferenceWarnings > 0) {
            $graph->addWarning([
                'scanner' => 'models',
                'inference' => 'unindexed_model_parent_reference_limit',
                'omitted' => $this->omittedAncestryReferenceWarnings,
                'message' => sprintf(
                    'Omitted %d model ancestry warnings because their class or parent could not be represented as a bounded exact reference.',
                    $this->omittedAncestryReferenceWarnings,
                ),
            ]);
        }

        return $graph;
    }

    private function indexFile(string $file, ?Graph $graph = null): bool
    {
        $identity = str_replace('\\', '/', realpath($file) ?: $file);

        if (isset($this->indexedFiles[$identity])) {
            return ! isset($this->sourceIndexFailures[$identity]);
        }

        $this->indexedFiles[$identity] = true;

        try {
            $statements = $this->phpFileFacts->statements($file);
        } catch (Throwable $throwable) {
            $this->sourceIndexFailures[$identity] = $throwable::class;

            if ($graph !== null) {
                $graph->addWarning([
                    'scanner' => 'models',
                    'file' => $this->files->relativePath($file) ?? $file,
                    'message' => $throwable->getMessage(),
                    'class' => $throwable::class,
                ]);
            }

            return false;
        }

        $finder = new NodeFinder();

        foreach ($finder->findInstanceOf($statements, Stmt\Class_::class) as $class) {
            $name = $this->className($class);

            if ($name === null) {
                continue;
            }

            $this->classes[$name] = [
                'class' => $class,
                'file' => $file,
                'extends' => $this->resolvedName($class->extends, $name),
                'traitUses' => $this->traitUses($class, $name),
            ];
            $this->classNames[strtolower($name)] = $name;
            $this->applicationClasses[$name] = true;
        }

        foreach ($finder->findInstanceOf($statements, Stmt\Trait_::class) as $trait) {
            $name = $this->traitName($trait);

            if ($name === null) {
                continue;
            }

            $this->traits[$name] = [
                'trait' => $trait,
                'file' => $file,
                'traitUses' => $this->traitUses($trait, $name),
            ];
            $this->traitNames[strtolower($name)] = $name;
        }

        return true;
    }

    /**
     * @return array<int, array{
     *     traits: array<int, string>,
     *     precedences: array<int, array{trait: string|null, method: string, insteadOf: array<int, string>}>,
     *     aliases: array<int, array{trait: string|null, method: string, alias: string|null, visibility: string|null}>
     * }>
     */
    private function traitUses(Stmt\Class_|Stmt\Trait_ $statement, string $currentSymbol): array
    {
        $uses = [];

        foreach ($statement->stmts as $member) {
            if (! $member instanceof Stmt\TraitUse) {
                continue;
            }

            $use = [
                'traits' => array_values(array_filter(array_map(
                    fn (Name $trait): ?string => $this->resolvedName($trait, $currentSymbol),
                    $member->traits,
                ))),
                'precedences' => [],
                'aliases' => [],
            ];

            foreach ($member->adaptations as $adaptation) {
                if ($adaptation instanceof TraitUseAdaptation\Precedence) {
                    $use['precedences'][] = [
                        'trait' => $this->resolvedName($adaptation->trait, $currentSymbol),
                        'method' => $adaptation->method->toString(),
                        'insteadOf' => array_values(array_filter(array_map(
                            fn (Name $trait): ?string => $this->resolvedName($trait, $currentSymbol),
                            $adaptation->insteadof,
                        ))),
                    ];

                    continue;
                }

                if ($adaptation instanceof TraitUseAdaptation\Alias) {
                    $use['aliases'][] = [
                        'trait' => $this->resolvedName($adaptation->trait, $currentSymbol),
                        'method' => $adaptation->method->toString(),
                        'alias' => $adaptation->newName?->toString(),
                        'visibility' => $this->traitAliasVisibility($adaptation->newModifier),
                    ];
                }
            }

            $uses[] = $use;
        }

        return $uses;
    }

    private function traitAliasVisibility(?int $modifier): ?string
    {
        return match ($modifier) {
            Stmt\Class_::MODIFIER_PUBLIC => 'public',
            Stmt\Class_::MODIFIER_PROTECTED => 'protected',
            Stmt\Class_::MODIFIER_PRIVATE => 'private',
            default => null,
        };
    }

    /**
     * @param array{
     *     class: Stmt\Class_,
     *     file: string,
     *     extends: string|null,
     *     traitUses: array<int, array<string, mixed>>
     * } $record
     */
    private function addModel(Graph $graph, string $className, array $record): void
    {
        $class = $record['class'];
        $shortName = $class->name?->toString() ?? class_basename($className);
        [$table, $confidence, $metadata] = $this->inferTableName($className, $shortName);

        $graph->addNode(GraphNode::make($className, 'model', $shortName, [
            'namespace' => $this->namespace($className),
            'class' => $shortName,
            'file' => $this->files->relativePath($record['file']) ?? $record['file'],
            'line' => $class->getStartLine(),
            'endLine' => $class->getEndLine(),
            'metadata' => array_filter([
                'table' => $table,
                'tableInference' => $metadata,
            ], static fn (mixed $value): bool => $value !== null),
        ]));

        if ($table !== null) {
            $existingTable = $graph->node('table:'.$table);

            if (($existingTable?->metadata['identityAmbiguous'] ?? false) === true) {
                $confidence = min($confidence, 0.5);
                $metadata['matchCertainty'] = 'possible';
                $metadata['ambiguity'] = 'database_scope_unresolved';
                $metadata['schemaIdentityCount'] = count(
                    (array) ($existingTable->metadata['schemaIdentities'] ?? []),
                );
            }

            $graph->addNode(GraphNode::make('table:'.$table, 'table', $table, [
                'metadata' => [
                    'inferredFromModel' => $className,
                ],
            ]));

            $graph->addEdge(new Edge($className, 'table:'.$table, 'uses_table', $confidence, $metadata));
        } else {
            $graph->addWarning([
                'scanner' => 'models',
                'class' => $className,
                'inference' => 'unresolved_model_table',
                'reason' => $metadata['uncertainty'] ?? 'unresolved_table_property_expression',
                'message' => 'No table fact was emitted because an explicit model table declaration could not be resolved to an exact usable string safely.',
            ]);
        }

        $this->addRelationshipEdges($graph, $className);
    }

    /** @return array{0: string|null, 1: float, 2: array<string, mixed>} */
    private function inferTableName(string $className, string $shortName): array
    {
        $override = $this->getTableOverrideState($className);

        if ($override['status'] === 'found') {
            return [$override['value'], 1.0, array_filter([
                'source' => 'explicit_get_table_return',
                'declaredOn' => $override['declaredOn'] ?? null,
                'viaTrait' => $override['viaTrait'] ?? null,
            ], static fn (mixed $value): bool => $value !== null)];
        }

        if ($override['status'] === 'unknown') {
            return [null, 0.0, array_filter([
                'source' => 'unresolved_get_table_override',
                'reason' => 'An application getTable() override could not be resolved to one exact table without executing code.',
                'uncertainty' => $override['reason'] ?? 'dynamic_get_table_override',
                'declaredOn' => $override['declaredOn'] ?? null,
            ], static fn (mixed $value): bool => $value !== null)];
        }

        $state = $this->classTableState($className);

        if ($state['status'] === 'found') {
            return [$state['value'], 1.0, array_filter([
                'source' => 'explicit_table_property',
                'declaredOn' => $state['declaredOn'] ?? null,
                'viaTrait' => $state['viaTrait'] ?? null,
                'declarations' => $state['declarations'] ?? null,
            ], static fn (mixed $value): bool => $value !== null)];
        }

        if ($state['status'] === 'unknown') {
            return [null, 0.0, array_filter([
                'source' => 'unresolved_table_property',
                'reason' => 'A non-empty $table declaration exists, but its exact string value could not be resolved safely.',
                'uncertainty' => $state['reason'] ?? 'unresolved_trait_table_property',
                'traits' => $state['traits'] ?? null,
                'declaredOn' => $state['declaredOn'] ?? null,
            ], static fn (mixed $value): bool => $value !== null)];
        }

        return [Str::snake(Str::pluralStudly($shortName)), 0.85, [
            'source' => 'eloquent_table_convention',
            'reason' => $state['status'] === 'conventional'
                ? 'The model or a composed trait explicitly restores Eloquent conventional table lookup.'
                : 'Model does not define a non-empty $table property.',
        ]];
    }

    /** @return array<string, mixed> */
    private function getTableOverrideState(string $className): array
    {
        $visited = [];
        $methods = $this->effectiveClassMethods($className, $visited);

        if (! array_key_exists('gettable', $methods)) {
            return ['status' => 'missing'];
        }

        $method = $methods['gettable'];

        if (! is_array($method) || ! ($method['node'] ?? null) instanceof Stmt\ClassMethod) {
            return [
                'status' => 'unknown',
                'reason' => 'ambiguous_get_table_override',
            ];
        }

        /** @var Stmt\ClassMethod $node */
        $node = $method['node'];
        $statements = $node->stmts ?? [];
        $base = [
            'declaredOn' => $method['declaredOn'] ?? $className,
            'viaTrait' => ($method['kind'] ?? null) === 'trait' ?: null,
        ];

        if (($method['visibility'] ?? null) !== 'public'
            || $node->isStatic()
            || count($statements) !== 1
            || ! $statements[0] instanceof Stmt\Return_) {
            return $base + [
                'status' => 'unknown',
                'reason' => 'dynamic_get_table_override',
            ];
        }

        $expression = $statements[0]->expr;

        if ($expression instanceof Node\Scalar\String_ && $expression->value !== '') {
            return $base + [
                'status' => 'found',
                'value' => $expression->value,
            ];
        }

        return $base + [
            'status' => 'unknown',
            'reason' => $expression instanceof Node\Scalar\String_
                ? 'explicit_empty_get_table_return'
                : 'dynamic_get_table_override',
        ];
    }

    /** @return array<string, mixed> */
    private function classTableState(string $className, array $visited = []): array
    {
        $className = $this->indexedClassName($className) ?? $className;

        if (isset($visited['class:'.strtolower($className)])) {
            return [
                'status' => 'unknown',
                'reason' => 'table_property_inheritance_cycle',
            ];
        }

        if (! isset($this->classes[$className])) {
            return ['status' => 'missing'];
        }

        $visited['class:'.strtolower($className)] = true;
        $record = $this->classes[$className];
        $local = $this->localTableState($record['class'], $className, false);

        if ($local['status'] !== 'missing') {
            return $local;
        }

        $traits = $this->composedTraitTableState($record['traitUses'], $visited);

        if ($traits['status'] !== 'missing') {
            return $traits;
        }

        $parent = $record['extends'];

        if ($parent === null || $this->isModelRoot($parent)) {
            return ['status' => 'missing'];
        }

        return $this->classTableState($parent, $visited);
    }

    /** @return array<string, mixed> */
    private function traitTableState(string $trait, array $visited): array
    {
        $trait = $this->indexedTraitName($trait) ?? $trait;
        $visitKey = 'trait:'.strtolower($trait);

        if (isset($visited[$visitKey])) {
            return [
                'status' => 'unknown',
                'reason' => 'trait_composition_cycle',
                'traits' => [$trait],
            ];
        }

        if (! isset($this->traits[$trait])) {
            return [
                'status' => 'unknown',
                'reason' => 'unindexed_trait',
                'traits' => [$trait],
            ];
        }

        $visited[$visitKey] = true;
        $record = $this->traits[$trait];
        $local = $this->localTableState($record['trait'], $trait, true);

        if ($local['status'] !== 'missing') {
            return $local;
        }

        return $this->composedTraitTableState($record['traitUses'], $visited);
    }

    /**
     * @param array<int, array<string, mixed>> $uses
     * @return array<string, mixed>
     */
    private function composedTraitTableState(array $uses, array $visited): array
    {
        $states = [];

        foreach ($uses as $use) {
            foreach ($use['traits'] ?? [] as $trait) {
                if (! is_string($trait)) {
                    continue;
                }

                $states[] = $this->traitTableState($trait, $visited);
            }
        }

        $states = array_values(array_filter(
            $states,
            static fn (array $state): bool => ($state['status'] ?? 'missing') !== 'missing',
        ));

        if ($states === []) {
            return ['status' => 'missing'];
        }

        $traits = [];

        foreach ($states as $state) {
            foreach ($state['traits'] ?? [$state['declaredOn'] ?? null] as $trait) {
                if (is_string($trait) && $trait !== '') {
                    $traits[$trait] = $trait;
                }
            }
        }

        sort($traits);
        $traits = array_slice(array_values($traits), 0, 16);

        if ((bool) array_filter(
            $states,
            static fn (array $state): bool => ($state['status'] ?? null) === 'unknown',
        )) {
            return [
                'status' => 'unknown',
                'reason' => 'unresolved_trait_table_property',
                'traits' => $traits,
            ];
        }

        $signatures = [];

        foreach ($states as $state) {
            $signature = ($state['status'] ?? 'missing').'\0'.($state['value'] ?? '');
            $signatures[$signature] = true;
        }

        if (count($signatures) !== 1) {
            return [
                'status' => 'unknown',
                'reason' => 'conflicting_trait_table_properties',
                'traits' => $traits,
            ];
        }

        $state = $states[0];
        $state['viaTrait'] = true;
        $state['declarations'] = $traits;

        return $state;
    }

    /** @return array<string, mixed> */
    private function localTableState(Stmt\Class_|Stmt\Trait_ $statement, string $declaredOn, bool $viaTrait): array
    {
        foreach ($statement->getProperties() as $property) {
            foreach ($property->props as $item) {
                if ($item->name->toString() !== 'table') {
                    continue;
                }

                // A local declaration overrides an inherited value even when
                // it deliberately restores Eloquent's conventional lookup.
                $resolved = $this->resolveTablePropertyValue(
                    $item->default,
                    $statement,
                    $declaredOn,
                );

                if (! $resolved['resolved']) {
                    return [
                        'status' => 'unknown',
                        'reason' => $resolved['reason'],
                        'declaredOn' => $declaredOn,
                        'viaTrait' => $viaTrait,
                    ];
                }

                if (is_string($resolved['value']) && $resolved['value'] !== '') {
                    return [
                        'status' => 'found',
                        'value' => $resolved['value'],
                        'declaredOn' => $declaredOn,
                        'viaTrait' => $viaTrait,
                    ];
                }

                if ($resolved['value'] === '') {
                    return [
                        'status' => 'unknown',
                        'reason' => 'explicit_empty_table_property',
                        'declaredOn' => $declaredOn,
                        'viaTrait' => $viaTrait,
                    ];
                }

                if ($resolved['value'] !== null) {
                    return [
                        'status' => 'unknown',
                        'reason' => 'non_string_table_property_value',
                        'declaredOn' => $declaredOn,
                        'viaTrait' => $viaTrait,
                    ];
                }

                return [
                    'status' => 'conventional',
                    'declaredOn' => $declaredOn,
                    'viaTrait' => $viaTrait,
                ];
            }
        }

        return ['status' => 'missing'];
    }

    /**
     * Resolve only expressions whose value is fixed by local syntax. In
     * particular, `self::TABLE` is safe when TABLE is a local literal (or a
     * cycle-free chain of local self constants). Late-static, inherited,
     * concatenated, and executable expressions remain unknown.
     *
     * @param array<string, true> $visited
     * @return array{resolved: bool, value?: mixed, reason?: string}
     */
    private function resolveTablePropertyValue(
        ?Expr $expression,
        Stmt\Class_|Stmt\Trait_ $statement,
        string $declaredOn,
        array $visited = [],
    ): array {
        if ($expression === null) {
            return ['resolved' => true, 'value' => null];
        }

        if ($expression instanceof Node\Scalar\String_) {
            return ['resolved' => true, 'value' => $expression->value];
        }

        if ($expression instanceof Expr\ConstFetch
            && strtolower($expression->name->toString()) === 'null') {
            return ['resolved' => true, 'value' => null];
        }

        if (! $expression instanceof Expr\ClassConstFetch
            || ! $expression->class instanceof Name
            || strtolower($expression->class->toString()) !== 'self'
            || ! $expression->name instanceof Node\Identifier) {
            return [
                'resolved' => false,
                'reason' => 'unresolved_table_property_expression',
            ];
        }

        $constantName = $expression->name->toString();
        $visitKey = strtolower($declaredOn).'::'.$constantName;

        if (isset($visited[$visitKey])) {
            return [
                'resolved' => false,
                'reason' => 'table_property_constant_cycle',
            ];
        }

        $visited[$visitKey] = true;

        foreach ($statement->stmts as $member) {
            if (! $member instanceof Stmt\ClassConst) {
                continue;
            }

            foreach ($member->consts as $constant) {
                if ($constant->name->toString() !== $constantName) {
                    continue;
                }

                return $this->resolveTablePropertyValue(
                    $constant->value,
                    $statement,
                    $declaredOn,
                    $visited,
                );
            }
        }

        return [
            'resolved' => false,
            'reason' => 'unresolved_table_property_constant',
        ];
    }

    private function addRelationshipEdges(Graph $graph, string $className): void
    {
        $finder = new NodeFinder();
        $visited = [];
        $methods = $this->effectiveClassMethods($className, $visited);
        $relationships = [];
        ksort($methods);

        foreach ($methods as $method) {
            if (! is_array($method)
                || ($method['static'] ?? true)
                || ($method['visibility'] ?? null) !== 'public'
                || ! ($method['node'] ?? null) instanceof Stmt\ClassMethod) {
                continue;
            }

            /** @var Stmt\ClassMethod $methodNode */
            $methodNode = $method['node'];
            $methodName = (string) ($method['name'] ?? $methodNode->name->toString());
            $resolutionClass = (string) ($method['resolutionClass'] ?? $className);

            foreach ($finder->findInstanceOf($methodNode->stmts ?? [], Expr\MethodCall::class) as $call) {
                if (! $call->var instanceof Expr\Variable
                    || $call->var->name !== 'this'
                    || ! $call->name instanceof Node\Identifier) {
                    continue;
                }

                $callName = $call->name->toString();
                $callKey = strtolower($callName);
                $edgeType = $this->relationshipEdgeTypes[$callKey] ?? null;

                if ($edgeType === null) {
                    continue;
                }

                $target = $callKey === 'morphto'
                    ? null
                    : $this->relationshipTarget($call, $resolutionClass, $className);
                $through = in_array($callKey, ['hasonethrough', 'hasmanythrough'], true)
                    ? $this->relationshipTarget($call, $resolutionClass, $className, 1)
                    : null;
                $targetResolution = $callKey === 'morphto'
                    ? 'runtime_polymorphic'
                    : ($target !== null ? 'exact_class_reference' : 'unresolved_class_argument');

                $this->recordRelationship($relationships, $className, $methodName, $edgeType, $target, array_filter([
                    'relationshipMethod' => $methodName,
                    'inference' => 'eloquent_relationship_call',
                    'call' => $callName,
                    'declaredOn' => $method['declaredOn'] ?? $resolutionClass,
                    'viaTrait' => ($method['kind'] ?? null) === 'trait' ?: null,
                    'line' => $call->getStartLine(),
                    'through' => $through,
                    'targetResolution' => $targetResolution,
                ], static fn (mixed $value): bool => $value !== null), $callKey === 'morphto'
                    ? 'Polymorphic morphTo target is resolved at runtime; no concrete model was inferred.'
                    : 'Relationship target class could not be resolved deterministically.');
            }

            foreach ($finder->findInstanceOf($methodNode->stmts ?? [], Stmt\Return_::class) as $return) {
                if (! $return->expr instanceof Expr\MethodCall) {
                    continue;
                }

                $fluent = $this->fluentThroughRelationship($return->expr, $className, $methods);

                if ($fluent === null) {
                    continue;
                }

                $this->recordRelationship(
                    $relationships,
                    $className,
                    $methodName,
                    $fluent['edgeType'],
                    $fluent['target'],
                    array_filter([
                        'relationshipMethod' => $methodName,
                        'inference' => 'eloquent_fluent_through_relationship',
                        'call' => $fluent['call'],
                        'declaredOn' => $method['declaredOn'] ?? $resolutionClass,
                        'viaTrait' => ($method['kind'] ?? null) === 'trait' ?: null,
                        'line' => $return->expr->getStartLine(),
                        'through' => $fluent['through'],
                        'throughRelationship' => $fluent['throughRelationship'],
                        'distantRelationship' => $fluent['distantRelationship'],
                        'syntax' => 'fluent_through',
                        'targetResolution' => 'exact_composed_relationships',
                    ], static fn (mixed $value): bool => $value !== null),
                    'Fluent through relationship could not be resolved deterministically.',
                );
            }
        }

        ksort($relationships);

        foreach ($relationships as $relationship) {
            $declarations = $relationship['declarations'];
            ksort($declarations);
            $relationshipMethods = array_values(array_unique(array_map(
                static fn (array $declaration): string => $declaration['relationshipMethod'],
                $declarations,
            )));
            sort($relationshipMethods);

            foreach ($declarations as $reference => $declaration) {
                $through = $declaration['through'] ?? null;

                if (! is_string($through) || $through === '') {
                    continue;
                }

                $graph->addNode(GraphNode::make($through, 'model', class_basename($through), [
                    'namespace' => $this->namespace($through),
                    'class' => class_basename($through),
                    'metadata' => [
                        'inferredAsThroughForRelationships' => [$reference => true],
                    ],
                ]));
            }

            if ($relationship['target'] !== null) {
                $nodeMetadata = [
                    'inferredFromRelationships' => array_fill_keys(array_keys($declarations), true),
                ];

                if (count($declarations) === 1) {
                    $nodeMetadata['inferredFromRelationship'] = array_key_first($declarations);
                }

                $graph->addNode(GraphNode::make(
                    $relationship['target'],
                    'model',
                    class_basename($relationship['target']),
                    [
                        'namespace' => $this->namespace($relationship['target']),
                        'class' => class_basename($relationship['target']),
                        'metadata' => $nodeMetadata,
                    ],
                ));
            } else {
                $graph->addNode(GraphNode::make(
                    $relationship['targetId'],
                    'unknown',
                    $relationshipMethods[0],
                    [
                        'metadata' => [
                            'reason' => $relationship['unknownReason'],
                            'relationshipMethods' => $relationshipMethods,
                            'relationshipDeclarations' => $declarations,
                        ],
                    ],
                ));
            }

            $metadata = [
                'inference' => 'eloquent_relationship_call',
                'relationshipMethods' => $relationshipMethods,
                'relationshipDeclarations' => $declarations,
            ];

            if (count($declarations) === 1) {
                $metadata = array_replace($metadata, reset($declarations));
            }

            $graph->addEdge(new Edge(
                $className,
                $relationship['targetId'],
                $relationship['edgeType'],
                $relationship['confidence'],
                $metadata,
            ));
        }
    }

    /**
     * @param array<string, array<string, mixed>> $relationships
     * @param array<string, mixed> $declaration
     */
    private function recordRelationship(
        array &$relationships,
        string $className,
        string $methodName,
        string $edgeType,
        ?string $target,
        array $declaration,
        string $unknownReason,
    ): void {
        $targetId = $target ?? 'unknown:relationship:'.$className.'::'.$methodName;
        $edgeKey = $edgeType."\0".$targetId;
        $methodReference = $className.'::'.$methodName;
        $declarationKey = $methodReference;

        if (isset($relationships[$edgeKey]['declarations'][$declarationKey])
            && $relationships[$edgeKey]['declarations'][$declarationKey] !== $declaration) {
            $declarationKey .= '#'.substr(hash(
                'sha256',
                json_encode($declaration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ), 0, 8);
        }

        $relationships[$edgeKey] ??= [
            'target' => $target,
            'targetId' => $targetId,
            'edgeType' => $edgeType,
            'confidence' => $target !== null ? 0.75 : 0.45,
            'unknownReason' => $unknownReason,
            'declarations' => [],
        ];
        $relationships[$edgeKey]['declarations'][$declarationKey] = $declaration;
    }

    /**
     * Resolve Laravel's two common fluent forms only when both composing
     * relationship methods are local-source facts with exact class targets:
     * `through('cars')->has('owner')` and `throughCars()->hasOwner()`.
     *
     * @param array<string, array<string, mixed>|null> $methods
     * @return array{
     *     edgeType: 'has_one_through'|'has_many_through',
     *     target: string,
     *     through: string,
     *     throughRelationship: string,
     *     distantRelationship: string,
     *     call: string
     * }|null
     */
    private function fluentThroughRelationship(
        Expr\MethodCall $call,
        string $className,
        array $methods,
    ): ?array {
        if (! $call->name instanceof Node\Identifier
            || ! $call->var instanceof Expr\MethodCall
            || ! $call->var->name instanceof Node\Identifier
            || ! $call->var->var instanceof Expr\Variable
            || $call->var->var->name !== 'this') {
            return null;
        }

        $distantRelationship = $this->fluentRelationshipName($call, 'has');
        $throughRelationship = $this->fluentRelationshipName($call->var, 'through');

        if ($distantRelationship === null || $throughRelationship === null) {
            return null;
        }

        $local = $this->provenRelationshipMethod(
            $methods[strtolower($throughRelationship)] ?? null,
            $className,
        );

        if ($local === null) {
            return null;
        }

        $visited = [];
        $distantMethods = $this->effectiveClassMethods($local['target'], $visited);
        $distant = $this->provenRelationshipMethod(
            $distantMethods[strtolower($distantRelationship)] ?? null,
            $local['target'],
        );

        if ($distant === null) {
            return null;
        }

        return [
            'edgeType' => in_array('hasmany', [$local['callKey'], $distant['callKey']], true)
                ? 'has_many_through'
                : 'has_one_through',
            'target' => $distant['target'],
            'through' => $local['target'],
            'throughRelationship' => $throughRelationship,
            'distantRelationship' => $distantRelationship,
            'call' => $call->var->name->toString().'()->'.$call->name->toString().'()',
        ];
    }

    private function fluentRelationshipName(Expr\MethodCall $call, string $prefix): ?string
    {
        if (! $call->name instanceof Node\Identifier) {
            return null;
        }

        $method = $call->name->toString();

        if (strcasecmp($method, $prefix) === 0) {
            if (count($call->args) !== 1) {
                return null;
            }

            $argument = $call->args[0]->value ?? null;

            return $argument instanceof Node\Scalar\String_ && $argument->value !== ''
                ? $argument->value
                : null;
        }

        if ($call->args !== [] || ! str_starts_with(strtolower($method), strtolower($prefix))) {
            return null;
        }

        $suffix = substr($method, strlen($prefix));

        return $suffix !== '' ? lcfirst($suffix) : null;
    }

    /**
     * @param array<string, mixed>|null $method
     * @return array{callKey: 'hasone'|'hasmany', target: string}|null
     */
    private function provenRelationshipMethod(?array $method, string $lateStaticClass): ?array
    {
        if ($method === null
            || ($method['static'] ?? true)
            || ($method['visibility'] ?? null) !== 'public'
            || ! ($method['node'] ?? null) instanceof Stmt\ClassMethod) {
            return null;
        }

        $resolutionClass = (string) ($method['resolutionClass'] ?? $lateStaticClass);
        $candidates = [];
        $finder = new NodeFinder();

        foreach ($finder->findInstanceOf($method['node']->stmts ?? [], Stmt\Return_::class) as $return) {
            $call = $return->expr;

            if (! $call instanceof Expr\MethodCall
                || ! $call->var instanceof Expr\Variable
                || $call->var->name !== 'this'
                || ! $call->name instanceof Node\Identifier) {
                continue;
            }

            $callKey = strtolower($call->name->toString());

            if (! in_array($callKey, ['hasone', 'hasmany'], true)) {
                continue;
            }

            $target = $this->relationshipTarget($call, $resolutionClass, $lateStaticClass);

            if ($target === null) {
                return null;
            }

            $candidates[] = [
                'callKey' => $callKey,
                'target' => $target,
            ];
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * @param array<string, true> $visited
     * @return array<string, array<string, mixed>|null>
     */
    private function effectiveClassMethods(string $className, array &$visited): array
    {
        $className = $this->indexedClassName($className) ?? $className;

        if (isset($this->classMethodCache[$className])) {
            return $this->classMethodCache[$className];
        }

        $visitKey = 'class:'.strtolower($className);

        if (isset($visited[$visitKey]) || ! isset($this->classes[$className])) {
            return [];
        }

        $visited[$visitKey] = true;
        $record = $this->classes[$className];
        $methods = [];
        $parent = $record['extends'];

        if (is_string($parent) && ! $this->isModelRoot($parent)) {
            $branchVisited = $visited;

            foreach ($this->effectiveClassMethods($parent, $branchVisited) as $name => $method) {
                if (is_array($method) && ($method['visibility'] ?? null) !== 'private') {
                    $methods[$name] = $method;
                }
            }
        }

        $traitVisited = [];

        foreach ($this->composedTraitMethods($record['traitUses'], $className, $traitVisited) as $name => $method) {
            // Even an ambiguous trait method masks an inherited method; valid
            // PHP must resolve it with an adaptation or a class declaration.
            $methods[$name] = $method;
        }

        foreach ($record['class']->getMethods() as $method) {
            $name = strtolower($method->name->toString());
            $methods[$name] = $this->methodRecord($method, $method->name->toString(), $className, $className, 'class');
        }

        unset($visited[$visitKey]);
        ksort($methods);

        return $this->classMethodCache[$className] = $methods;
    }

    /**
     * @param array<string, true> $visited
     * @return array<string, array<string, mixed>|null>
     */
    private function effectiveTraitMethods(string $trait, array &$visited): array
    {
        $trait = $this->indexedTraitName($trait) ?? $trait;

        if (isset($this->traitMethodCache[$trait])) {
            return $this->traitMethodCache[$trait];
        }

        $visitKey = 'trait:'.strtolower($trait);

        if (isset($visited[$visitKey]) || ! isset($this->traits[$trait])) {
            return [];
        }

        $visited[$visitKey] = true;
        $record = $this->traits[$trait];
        $methods = $this->composedTraitMethods($record['traitUses'], $trait, $visited);

        foreach ($record['trait']->getMethods() as $method) {
            $name = strtolower($method->name->toString());
            $methods[$name] = $this->methodRecord($method, $method->name->toString(), $trait, $trait, 'trait');
        }

        unset($visited[$visitKey]);
        ksort($methods);

        return $this->traitMethodCache[$trait] = $methods;
    }

    /**
     * @param array<int, array<string, mixed>> $uses
     * @param array<string, true> $visited
     * @return array<string, array<string, mixed>|null>
     */
    private function composedTraitMethods(array $uses, string $resolutionClass, array &$visited): array
    {
        $methods = [];

        foreach ($uses as $use) {
            /** @var array<string, array<int, array{topTrait: string, method: array<string, mixed>|null}>> $raw */
            $raw = [];

            foreach ($use['traits'] ?? [] as $topTrait) {
                if (! is_string($topTrait)) {
                    continue;
                }

                $indexedTrait = $this->indexedTraitName($topTrait) ?? $topTrait;
                $branchVisited = $visited;

                foreach ($this->effectiveTraitMethods($indexedTrait, $branchVisited) as $name => $method) {
                    if (is_array($method)) {
                        $method['resolutionClass'] = $resolutionClass;
                    }

                    $raw[$name][] = [
                        'topTrait' => $indexedTrait,
                        'method' => $method,
                    ];
                }
            }

            $selected = $raw;
            $invalidAdaptations = [];

            foreach ($use['precedences'] ?? [] as $precedence) {
                $methodName = strtolower((string) ($precedence['method'] ?? ''));

                if ($methodName === '') {
                    continue;
                }

                $selectedTrait = $precedence['trait'] ?? null;

                if (is_string($selectedTrait) && ! (bool) array_filter(
                    $raw[$methodName] ?? [],
                    fn (array $candidate): bool => $this->samePhpName($candidate['topTrait'], $selectedTrait),
                )) {
                    $invalidAdaptations[$methodName] = true;
                }

                $excluded = array_values(array_filter(
                    $precedence['insteadOf'] ?? [],
                    static fn (mixed $trait): bool => is_string($trait),
                ));
                $selected[$methodName] = array_values(array_filter(
                    $selected[$methodName] ?? [],
                    fn (array $candidate): bool => ! (bool) array_filter(
                        $excluded,
                        fn (string $trait): bool => $this->samePhpName($candidate['topTrait'], $trait),
                    ),
                ));
            }

            $group = [];

            foreach ($selected as $name => $candidates) {
                $group[$name] = ! isset($invalidAdaptations[$name]) && count($candidates) === 1
                    ? $candidates[0]['method']
                    : null;
            }

            foreach ($use['aliases'] ?? [] as $alias) {
                $sourceName = strtolower((string) ($alias['method'] ?? ''));
                $qualifiedTrait = $alias['trait'] ?? null;
                $candidates = is_string($qualifiedTrait)
                    ? array_values(array_filter(
                        $raw[$sourceName] ?? [],
                        fn (array $candidate): bool => $this->samePhpName($candidate['topTrait'], $qualifiedTrait),
                    ))
                    : ($selected[$sourceName] ?? []);

                if (count($candidates) !== 1 || ! is_array($candidates[0]['method'])) {
                    $aliasName = $alias['alias'] ?? null;

                    if (is_string($aliasName) && $aliasName !== '') {
                        $group[strtolower($aliasName)] = null;
                    }

                    continue;
                }

                $candidate = $candidates[0]['method'];
                $visibility = $alias['visibility'] ?? null;

                if (is_string($visibility)) {
                    $candidate['visibility'] = $visibility;
                }

                $aliasName = $alias['alias'] ?? null;

                if (is_string($aliasName) && $aliasName !== '') {
                    $candidate['name'] = $aliasName;
                    $aliasKey = strtolower($aliasName);
                    $group[$aliasKey] = array_key_exists($aliasKey, $group)
                        ? null
                        : $candidate;

                    continue;
                }

                // A visibility-only alias changes the original imported method
                // only when that exact candidate is the selected implementation.
                if (count($selected[$sourceName] ?? []) === 1
                    && $this->samePhpName($selected[$sourceName][0]['topTrait'], $candidates[0]['topTrait'])) {
                    $group[$sourceName] = $candidate;
                }
            }

            foreach ($group as $name => $method) {
                $methods[$name] = array_key_exists($name, $methods)
                    ? null
                    : $method;
            }
        }

        ksort($methods);

        return $methods;
    }

    /** @return array<string, mixed> */
    private function methodRecord(
        Stmt\ClassMethod $method,
        string $name,
        string $declaredOn,
        string $resolutionClass,
        string $kind,
    ): array {
        return [
            'node' => $method,
            'name' => $name,
            'declaredOn' => $declaredOn,
            'resolutionClass' => $resolutionClass,
            'kind' => $kind,
            'visibility' => $method->isPrivate()
                ? 'private'
                : ($method->isProtected() ? 'protected' : 'public'),
            'static' => $method->isStatic(),
        ];
    }

    private function relationshipTarget(
        Expr\MethodCall $call,
        string $lexicalClass,
        string $lateStaticClass,
        int $argumentIndex = 0,
    ): ?string
    {
        $argument = $call->args[$argumentIndex]->value ?? null;

        if (! $argument instanceof Expr\ClassConstFetch
            || ! $argument->name instanceof Node\Identifier
            || strtolower($argument->name->toString()) !== 'class'
            || ! $argument->class instanceof Name) {
            return null;
        }

        if (strtolower($argument->class->toString()) === 'static') {
            return $this->indexedClassName($lateStaticClass) ?? $lateStaticClass;
        }

        // self::class and parent::class are lexical. For trait methods the
        // method record carries the class use-site, including aliases; for an
        // inherited class method it carries its declaring class. static::class
        // alone is late-bound to the concrete model currently being scanned.
        return $this->resolvedName($argument->class, $lexicalClass);
    }

    /** @return array{status: 'model'|'not_model'|'unknown', unresolved?: string, reason?: string} */
    private function modelClassState(string $className, array $visiting = []): array
    {
        if ($this->isModelRoot($className)) {
            return ['status' => 'model'];
        }

        $className = $this->indexedClassName($className) ?? $className;

        if (isset($this->modelAncestry[$className])) {
            return $this->modelAncestry[$className];
        }

        $visitKey = strtolower($className);

        if (isset($visiting[$visitKey])) {
            return $this->modelAncestry[$className] = [
                'status' => 'unknown',
                'unresolved' => $className,
                'reason' => 'model_inheritance_cycle',
            ];
        }

        if (! isset($this->classes[$className])) {
            return $this->modelAncestry[$className] = [
                'status' => 'unknown',
                'unresolved' => $className,
                'reason' => 'unindexed_parent_source',
            ];
        }

        $visiting[$visitKey] = true;
        $parent = $this->classes[$className]['extends'];

        if ($parent === null) {
            return $this->modelAncestry[$className] = ['status' => 'not_model'];
        }

        if ($this->isModelRoot($parent)) {
            return $this->modelAncestry[$className] = ['status' => 'model'];
        }

        $state = $this->modelClassState($parent, $visiting);

        if ($state['status'] === 'unknown' && ! isset($state['unresolved'])) {
            $state['unresolved'] = $parent;
        }

        return $this->modelAncestry[$className] = $state;
    }

    private function className(Stmt\Class_ $class): ?string
    {
        if ($class->name === null) {
            return null;
        }

        $namespaced = $class->namespacedName ?? null;

        return $namespaced instanceof Name
            ? $namespaced->toString()
            : $class->name->toString();
    }

    private function traitName(Stmt\Trait_ $trait): ?string
    {
        if ($trait->name === null) {
            return null;
        }

        $namespaced = $trait->namespacedName ?? null;

        return $namespaced instanceof Name
            ? $namespaced->toString()
            : $trait->name->toString();
    }

    private function indexedClassName(string $class): ?string
    {
        return $this->classNames[strtolower(ltrim($class, '\\'))] ?? null;
    }

    private function indexedTraitName(string $trait): ?string
    {
        return $this->traitNames[strtolower(ltrim($trait, '\\'))] ?? null;
    }

    private function samePhpName(?string $left, ?string $right): bool
    {
        return is_string($left)
            && is_string($right)
            && strcasecmp(ltrim($left, '\\'), ltrim($right, '\\')) === 0;
    }

    private function isModelRoot(string $class): bool
    {
        foreach ($this->modelRoots as $root => $_) {
            if ($this->samePhpName($root, $class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * External ancestry is common for controllers, requests, commands, and
     * other non-model framework types. Warn only when local source gives an
     * agent a concrete reason to expect model facts; otherwise the uncertainty
     * would drown real model gaps in routine application structure.
     *
     * @param array{class: Stmt\Class_, file: string, extends: string|null, traitUses: array<int, array<string, mixed>>} $record
     * @param array{status: 'unknown', unresolved?: string, reason?: string} $state
     */
    private function isModelShapedCandidate(string $className, array $record, array $state): bool
    {
        $relative = str_replace('\\', '/', $this->files->relativePath($record['file']) ?? $record['file']);

        if (preg_match('#(?:^|/)Models(?:/|$)#i', $relative) === 1) {
            return true;
        }

        foreach ([class_basename($className), class_basename((string) ($state['unresolved'] ?? ''))] as $shortName) {
            if (preg_match('/(?:Model|Record|Entity)$/i', $shortName) === 1) {
                return true;
            }
        }

        foreach ($record['class']->getProperties() as $property) {
            foreach ($property->props as $item) {
                if ($item->name->toString() === 'table') {
                    return true;
                }
            }
        }

        $finder = new NodeFinder();

        foreach ($finder->findInstanceOf($record['class']->getMethods(), Expr\MethodCall::class) as $call) {
            if ($call->var instanceof Expr\Variable
                && $call->var->name === 'this'
                && $call->name instanceof Node\Identifier
                && isset($this->relationshipEdgeTypes[strtolower($call->name->toString())])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{status: 'unknown', unresolved?: string, reason?: string} $state
     */
    private function addAncestryWarning(Graph $graph, string $className, array $state): void
    {
        if ($this->ancestryWarningCount >= self::MAX_ANCESTRY_WARNINGS) {
            $this->suppressedAncestryWarnings++;

            return;
        }

        $parent = (string) ($state['unresolved'] ?? 'unknown');

        if (! $this->isBoundedExactReference($className)
            || ! $this->isBoundedExactReference($parent)) {
            $this->omittedAncestryReferenceWarnings++;

            return;
        }

        $this->ancestryWarningCount++;

        $graph->addWarning([
            'scanner' => 'models',
            'class' => $className,
            'parent' => $parent,
            'inference' => 'unindexed_model_parent',
            'reason' => $state['reason'] ?? 'unindexed_parent_source',
            'message' => 'Could not determine whether the reported class is an Eloquent model because its parent is outside the indexed application source and known framework roots; no model facts were emitted. Inspect the exact class and parent fields.',
        ]);
    }

    private function isBoundedExactReference(string $value): bool
    {
        return $value !== ''
            && ! str_contains($value, "\0")
            && strlen($value) <= self::MAX_EXACT_REFERENCE_BYTES
            && mb_strlen($value) <= self::MAX_EXACT_REFERENCE_CHARACTERS
            && preg_match('//u', $value) === 1;
    }

    private function resolvedName(?Name $name, string $currentClass): ?string
    {
        if ($name === null) {
            return null;
        }

        $raw = strtolower($name->toString());

        if (in_array($raw, ['self', 'static'], true)) {
            return $currentClass;
        }

        if ($raw === 'parent') {
            $currentClass = $this->indexedClassName($currentClass) ?? $currentClass;

            return $this->classes[$currentClass]['extends'] ?? null;
        }

        $resolved = $name->getAttribute('resolvedName');

        if ($resolved instanceof Name) {
            return ltrim($resolved->toString(), '\\');
        }

        if ($name instanceof Name\FullyQualified) {
            return ltrim($name->toString(), '\\');
        }

        return ltrim($name->toString(), '\\');
    }

    private function namespace(string $class): ?string
    {
        $position = strrpos($class, '\\');

        return $position === false ? null : substr($class, 0, $position);
    }
}
