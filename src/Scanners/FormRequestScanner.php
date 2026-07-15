<?php

namespace AppGraph\Scanners;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node as GraphNode;
use AppGraph\Scanners\Concerns\InteractsWithPhpAst;
use AppGraph\Support\ContainerBindingRegistry;
use AppGraph\Support\FileFinder;
use AppGraph\Support\PhpFileFacts;
use PhpParser\Node as AstNode;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\TraitUseAdaptation;

class FormRequestScanner
{
    use InteractsWithPhpAst;

    private const FORM_REQUEST_CLASS = 'Illuminate\Foundation\Http\FormRequest';

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $classes = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $methods = [];

    /** @var array<string, array<string, mixed>> */
    private array $traits = [];

    /** @var array<string, string> */
    private array $classNames = [];

    /** @var array<string, string> */
    private array $traitNames = [];

    /** @var array<string, array<string, mixed>> */
    private array $methodResolutionCache = [];

    /** @var array<string, true> */
    private array $resolutionWarnings = [];

    public function __construct(
        private FileFinder $files,
        private ?ContainerBindingRegistry $containerBindings = null,
        ?PhpFileFacts $phpFileFacts = null,
    ) {
        $this->initializePhpFileFacts($phpFileFacts);
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
        $this->methods = [];
        $this->traits = [];
        $this->classNames = [];
        $this->traitNames = [];
        $this->methodResolutionCache = [];
        $this->resolutionWarnings = [];
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
        $boundRequests = [];

        foreach ($this->classes as $class => $record) {
            if (! $this->isFormRequest($class, $memo, $graph)) {
                continue;
            }

            // A custom validator() bypasses rules() during validator creation. In
            // Laravel 12+, the framework can still call rules() conditionally from
            // validateNoUnknownFields(), which is modeled separately below.
            $customValidator = $this->resolveApplicationMethod($class, 'validator');
            $defaultRulesActive = ($this->resolveApplicationMethodState($class, 'validator')['status'] ?? null) === 'missing'
                && $this->unindexedParentOverride($class, 'validator') === null
                && $this->frameworkValidationCanReachValidator($class)
                && ! $this->hasApplicationOverride($class, 'validateResolved')
                && ! $this->hasApplicationOverride($class, 'getValidatorInstance')
                && ! $this->hasApplicationOverride($class, 'createDefaultValidator')
                && ! $this->hasApplicationOverride($class, 'validationRules');
            $conditionalUnknownFieldRules = ! ($record['abstract'] ?? false)
                && $this->conditionalUnknownFieldRulesActive($class, $customValidator);
            $rulesMethod = $defaultRulesActive || $conditionalUnknownFieldRules
                ? $this->resolveApplicationMethod($class, 'rules')
                : null;
            [$rules, $dynamic] = $this->extractRules($rulesMethod['record']['node'] ?? null);

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
                    'rulesConditional' => $conditionalUnknownFieldRules ?: null,
                    'rulesCondition' => $conditionalUnknownFieldRules
                        ? 'unknown_field_validation_enabled_and_validator_after_callbacks_run'
                        : null,
                ], static fn ($value): bool => $value !== null),
            ]));

            $abstract = ($record['abstract'] ?? false) === true;
            $hasBinding = $this->containerBindings?->hasDefaultDeclaration($class) === true;

            if ($hasBinding) {
                $binding = $this->containerBindings?->resolve($class);

                if ($binding === null) {
                    $this->addRequestBindingWarning(
                        $graph,
                        $class,
                        $abstract ? 'abstract_form_request_binding_unknown' : 'form_request_binding_unknown',
                    );

                    continue;
                }

                $concrete = $this->indexedClassName((string) ($binding['concrete'] ?? ''));

                if ($concrete === null
                    || ($this->classes[$concrete]['abstract'] ?? true) === true
                    || ! $this->isFormRequest($concrete, $memo, $graph)) {
                    $this->addRequestBindingWarning(
                        $graph,
                        $class,
                        $abstract ? 'abstract_form_request_binding_not_concrete' : 'form_request_binding_not_concrete',
                        (string) ($binding['concrete'] ?? ''),
                    );

                    continue;
                }

                if (($binding['terminalInference'] ?? $binding['inference'] ?? null) === 'existing_instance') {
                    $this->addRequestResolution($graph, $class, $concrete, $binding);
                    $this->addRequestBindingWarning(
                        $graph,
                        $class,
                        'form_request_existing_instance_bypasses_lifecycle',
                        $concrete,
                    );

                    continue;
                }

                if ($concrete !== $class) {
                    $boundRequests[] = [$class, $concrete, $binding];

                    continue;
                }
            }

            if ($abstract) {
                $this->addRequestBindingWarning($graph, $class, 'abstract_form_request_unbound');

                continue;
            }

            $this->addLifecycleExecutionBridges($graph, $class, $customValidator);
        }

        foreach ($boundRequests as [$requestedRequest, $concrete, $binding]) {
            $this->addBoundRequestLifecycleBridges($graph, $requestedRequest, $concrete, $binding);
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
     * A FormRequest is itself resolved through the container. When that
     * resolution is proven to return another concrete request, the requested
     * type is only an entrypoint and the runtime class owns every lifecycle
     * hook.
     *
     * @param array<string, mixed> $binding
     */
    private function addBoundRequestLifecycleBridges(
        Graph $graph,
        string $requestedRequest,
        string $concrete,
        array $binding,
    ): void
    {
        [$bindingConfidence, $bindingMetadata] = $this->addRequestResolution(
            $graph,
            $requestedRequest,
            $concrete,
            $binding,
        );

        $copied = 0;

        foreach ($graph->edges() as $edge) {
            if ($edge->from !== $concrete || $edge->type !== 'framework_invokes') {
                continue;
            }

            $graph->addEdge(new Edge(
                $requestedRequest,
                $edge->to,
                $edge->type,
                min($edge->confidence, $bindingConfidence),
                $edge->metadata + [
                    'requestedRequest' => $requestedRequest,
                    'runtimeRequest' => $concrete,
                    'containerBinding' => $bindingMetadata,
                ],
            ));
            $copied++;
        }

        if ($copied === 0) {
            $this->addRequestBindingWarning(
                $graph,
                $requestedRequest,
                ($this->classes[$requestedRequest]['abstract'] ?? false)
                    ? 'abstract_form_request_runtime_lifecycle_unknown'
                    : 'form_request_runtime_lifecycle_unknown',
                $concrete,
            );
        }
    }

    /**
     * @param array<string, mixed> $binding
     * @return array{float, array<string, mixed>}
     */
    private function addRequestResolution(Graph $graph, string $requestedRequest, string $concrete, array $binding): array
    {
        $bindingConfidence = (float) ($binding['confidence'] ?? 0.0);
        $bindingMetadata = array_filter([
            'requested' => $requestedRequest,
            'abstract' => ($this->classes[$requestedRequest]['abstract'] ?? false) ? $requestedRequest : null,
            'concrete' => $concrete,
            'scope' => $binding['scope'] ?? null,
            'lifetime' => $binding['lifetime'] ?? null,
            'effectiveLifetime' => $binding['effectiveLifetime'] ?? null,
            'certainty' => $binding['certainty'] ?? null,
            'inference' => $binding['inference'] ?? null,
            'terminalInference' => $binding['terminalInference'] ?? null,
            'resolutionPath' => $binding['resolutionPath'] ?? null,
            'environment' => $binding['environment'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        $record = $this->classes[$requestedRequest];
        $graph->addNode(GraphNode::make($requestedRequest, 'form_request', $record['shortName'], [
            'metadata' => [
                'runtimeRequest' => $concrete,
                'containerBinding' => $bindingMetadata,
            ],
        ]));
        if ($requestedRequest !== $concrete) {
            $graph->addEdge(new Edge($requestedRequest, $concrete, 'resolves_to', $bindingConfidence, [
                'source' => 'booted_container',
                'environment' => $binding['environment'] ?? null,
                'bindings' => [
                    'default' => $bindingMetadata,
                ],
            ]));
        }

        return [$bindingConfidence, $bindingMetadata];
    }

    private function addRequestBindingWarning(
        Graph $graph,
        string $requestClass,
        string $reason,
        ?string $concrete = null,
    ): void {
        $key = implode('|', [$requestClass, $reason, $concrete ?? '']);

        if (isset($this->resolutionWarnings[$key])) {
            return;
        }

        $this->resolutionWarnings[$key] = true;
        $graph->addWarning(array_filter([
            'scanner' => $this->scannerName(),
            'class' => $requestClass,
            'concrete' => $concrete,
            'inference' => $reason,
            'message' => match ($reason) {
                'abstract_form_request_unbound' => sprintf(
                    'Suppressed lifecycle edges for abstract FormRequest %s because the booted container has no concrete binding.',
                    $requestClass,
                ),
                'abstract_form_request_binding_unknown' => sprintf(
                    'Suppressed lifecycle edges for abstract FormRequest %s because its container binding target is not statically known.',
                    $requestClass,
                ),
                'abstract_form_request_binding_not_concrete' => sprintf(
                    'Suppressed lifecycle edges for abstract FormRequest %s because binding target %s is not an indexed concrete FormRequest.',
                    $requestClass,
                    $concrete ?? 'unknown',
                ),
                'form_request_binding_unknown' => sprintf(
                    'Suppressed lifecycle edges for FormRequest %s because its declared container binding target is not statically known.',
                    $requestClass,
                ),
                'form_request_binding_not_concrete' => sprintf(
                    'Suppressed lifecycle edges for FormRequest %s because binding target %s is not an indexed concrete FormRequest.',
                    $requestClass,
                    $concrete ?? 'unknown',
                ),
                'form_request_existing_instance_bypasses_lifecycle' => sprintf(
                    'Suppressed automatic lifecycle edges for FormRequest %s because its existing-instance binding resolves to %s without firing container resolving callbacks.',
                    $requestClass,
                    $concrete ?? 'unknown',
                ),
                'form_request_runtime_lifecycle_unknown' => sprintf(
                    'Suppressed lifecycle edges for FormRequest %s because runtime lifecycle evidence for %s is unavailable.',
                    $requestClass,
                    $concrete ?? 'its binding target',
                ),
                default => sprintf(
                    'Suppressed lifecycle edges for abstract FormRequest %s because runtime lifecycle evidence for %s is unavailable.',
                    $requestClass,
                    $concrete ?? 'its binding target',
                ),
            },
        ], static fn (mixed $value): bool => $value !== null));
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

            if ((! $statement instanceof Stmt\Class_ && ! $statement instanceof Stmt\Trait_)
                || $statement->name === null) {
                continue;
            }

            $class = $this->className($statement);

            if ($class === null) {
                continue;
            }

            $record = [
                'shortName' => $statement->name->toString(),
                'namespace' => $this->namespaceFromClass($class),
                'file' => $relativeFile,
                'line' => $statement->getStartLine(),
                'traits' => $this->usedTraits($statement),
                'traitUses' => $this->traitUses($statement),
            ];

            if ($statement instanceof Stmt\Class_) {
                $record['extends'] = $this->resolvedName($statement->extends);
                $record['abstract'] = $statement->isAbstract();
                $this->classes[$class] = $record;
                $this->classNames[strtolower($class)] = $class;
            } else {
                $this->traits[$class] = $record;
                $this->traitNames[strtolower($class)] = $class;
            }

            foreach ($statement->getMethods() as $method) {
                $declaredMethod = $method->name->toString();
                $this->methods[$class][strtolower($declaredMethod)] = [
                    'name' => $declaredMethod,
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
                    $traits[] = $resolved;
                }
            }
        }

        return array_values(array_unique($traits));
    }

    /**
     * Preserve each PHP trait-composition group and its adaptations. Flattening
     * this to a list loses the method selected by `insteadof` and can turn an
     * alias or visibility change into a false confidence-1 lifecycle edge.
     *
     * @return array<int, array{
     *     traits: array<int, string>,
     *     precedences: array<int, array{trait: string|null, method: string, insteadOf: array<int, string>}>,
     *     aliases: array<int, array{trait: string|null, method: string, alias: string|null, visibility: string|null}>
     * }>
     */
    private function traitUses(Stmt\Class_|Stmt\Trait_ $statement): array
    {
        $uses = [];

        foreach ($statement->stmts as $member) {
            if (! $member instanceof Stmt\TraitUse) {
                continue;
            }

            $use = [
                'traits' => array_values(array_filter(array_map(
                    fn (Name $trait): ?string => $this->resolvedName($trait),
                    $member->traits,
                ))),
                'precedences' => [],
                'aliases' => [],
            ];

            foreach ($member->adaptations as $adaptation) {
                if ($adaptation instanceof TraitUseAdaptation\Precedence) {
                    $use['precedences'][] = [
                        'trait' => $this->resolvedName($adaptation->trait),
                        'method' => $adaptation->method->toString(),
                        'insteadOf' => array_values(array_filter(array_map(
                            fn (Name $trait): ?string => $this->resolvedName($trait),
                            $adaptation->insteadof,
                        ))),
                    ];

                    continue;
                }

                if ($adaptation instanceof TraitUseAdaptation\Alias) {
                    $use['aliases'][] = [
                        'trait' => $this->resolvedName($adaptation->trait),
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
     * @param array<string, bool> $memo
     */
    private function isFormRequest(string $class, array &$memo, Graph $graph): bool
    {
        $class = $this->indexedClassName($class) ?? $class;

        if (isset($memo[$class])) {
            return $memo[$class];
        }

        // Seed false so an inheritance cycle in broken code cannot recurse forever.
        $memo[$class] = false;

        $extends = $this->classes[$class]['extends'] ?? null;

        if (! is_string($extends)) {
            return false;
        }

        if ($this->samePhpName($extends, self::FORM_REQUEST_CLASS)) {
            return $memo[$class] = true;
        }

        $indexedParent = $this->indexedClassName($extends);

        if ($indexedParent !== null) {
            return $memo[$class] = $this->isFormRequest($indexedParent, $memo, $graph);
        }

        // A scan must never autoload an application/package parent just to ask
        // whether it extends FormRequest. Already-loaded types are safe to
        // reflect; otherwise skip with explicit uncertainty instead of running
        // arbitrary file-level code through an autoloader.
        if (! class_exists($extends, false)) {
            $warningKey = 'unloaded-form-request-parent|'.$class.'|'.strtolower($extends);

            if (! isset($this->resolutionWarnings[$warningKey])) {
                $this->resolutionWarnings[$warningKey] = true;
                $graph->addWarning([
                    'scanner' => $this->scannerName(),
                    'class' => $class,
                    'parent' => $extends,
                    'inference' => 'unloaded_form_request_parent',
                    'message' => sprintf(
                        'Skipped %s because unindexed parent %s was not already loaded and was not autoloaded during the scan.',
                        $class,
                        $extends,
                    ),
                ]);
            }

            return $memo[$class] = false;
        }

        try {
            $reflection = new \ReflectionClass($extends);

            do {
                if ($this->samePhpName($reflection->getName(), self::FORM_REQUEST_CLASS)) {
                    return $memo[$class] = true;
                }

                $reflection = $reflection->getParentClass();
            } while ($reflection instanceof \ReflectionClass);

            return $memo[$class] = false;
        } catch (\Throwable) {
            return $memo[$class] = false;
        }
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

    /**
     * @return array{class: string, record: array<string, mixed>, declaredMethod?: string}|null
     */
    private function resolveApplicationMethod(string $class, string $method): ?array
    {
        $state = $this->resolveApplicationMethodState($class, $method);

        return ($state['status'] ?? null) === 'resolved' ? $state : null;
    }

    /**
     * Resolve the exact method PHP's trait composition exposes, without loading
     * source files merely to inspect them. The non-resolved states are important:
     * `hazard` means Laravel will encounter an inaccessible private hook, while
     * `unknown` means an unindexed trait or ambiguous adaptation could own it.
     *
     * @return array<string, mixed>
     */
    private function resolveApplicationMethodState(string $class, string $method): array
    {
        $class = $this->indexedClassName($class) ?? $class;
        $cacheKey = strtolower($class.'::'.$method);

        if (isset($this->methodResolutionCache[$cacheKey])) {
            return $this->methodResolutionCache[$cacheKey];
        }

        $visited = [];
        $state = $this->resolveClassMethodState($class, $method, $visited);

        return $this->methodResolutionCache[$cacheKey] = $state;
    }

    /** @param array<string, true> $visited */
    private function resolveClassMethodState(string $class, string $method, array &$visited): array
    {
        $class = $this->indexedClassName($class) ?? $class;

        if (! isset($this->classes[$class])) {
            return ['status' => 'missing'];
        }

        $visitKey = 'class:'.strtolower($class);

        if (isset($visited[$visitKey])) {
            return [
                'status' => 'unknown',
                'reason' => 'inheritance_cycle',
                'class' => $class,
            ];
        }

        $visited[$visitKey] = true;
        $record = $this->methods[$class][strtolower($method)] ?? null;

        if (is_array($record)) {
            return $this->methodState($class, $method, $record);
        }

        $traitState = $this->resolveTraitUsesState(
            $this->classes[$class]['traitUses'] ?? [],
            $method,
            $visited,
        );

        if (($traitState['status'] ?? null) !== 'missing') {
            return $this->inaccessibleStateIfNeeded($traitState, $method);
        }

        $parent = $this->classes[$class]['extends'] ?? null;

        return is_string($parent)
            ? $this->resolveClassMethodState($parent, $method, $visited)
            : ['status' => 'missing'];
    }

    /** @param array<string, mixed> $record */
    private function methodState(string $class, string $method, array $record): array
    {
        return $this->inaccessibleStateIfNeeded([
            'status' => 'resolved',
            'class' => $class,
            'declaredMethod' => $record['name'] ?? $method,
            'record' => $record,
        ], $method);
    }

    /** @param array<string, mixed> $state */
    private function inaccessibleStateIfNeeded(array $state, string $method): array
    {
        if (($state['status'] ?? null) !== 'resolved') {
            return $state;
        }

        $visibility = $state['record']['visibility'] ?? null;

        if ($visibility === 'private') {
            $state['status'] = 'hazard';
            $state['reason'] = 'private_lifecycle_hook';

            return $state;
        }

        if ($visibility === 'protected'
            && in_array(strtolower($method), ['authorize', 'rules'], true)) {
            $state['status'] = 'hazard';
            $state['reason'] = 'protected_container_call_hook';
        }

        return $state;
    }

    /**
     * @param array<int, array<string, mixed>> $uses
     * @param array<string, true> $visited
     */
    private function resolveTraitUsesState(array $uses, string $method, array &$visited): array
    {
        $candidates = [];
        $unknown = [];

        foreach ($uses as $use) {
            [$groupCandidates, $groupUnknown] = $this->traitUseCandidates($use, $method, $visited);
            array_push($candidates, ...$groupCandidates);
            array_push($unknown, ...$groupUnknown);
        }

        if ($unknown !== []) {
            return [
                'status' => 'unknown',
                'reason' => $unknown[0]['reason'] ?? 'unresolved_trait_composition',
                'trait' => $unknown[0]['topTrait'] ?? $unknown[0]['trait'] ?? null,
                'details' => $unknown,
            ];
        }

        $candidates = $this->uniqueTraitCandidates($candidates);

        if (count($candidates) > 1) {
            return [
                'status' => 'unknown',
                'reason' => 'ambiguous_trait_adaptation',
                'traits' => array_values(array_unique(array_column($candidates, 'topTrait'))),
            ];
        }

        return $candidates[0] ?? ['status' => 'missing'];
    }

    /**
     * @param array<string, mixed> $use
     * @param array<string, true> $visited
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function traitUseCandidates(array $use, string $method, array &$visited): array
    {
        [$candidates, $unknown] = $this->traitCandidatesForOriginalMethod($use, $method, $visited);

        foreach ($use['aliases'] ?? [] as $alias) {
            if (! $this->samePhpName($alias['alias'] ?? null, $method)) {
                continue;
            }

            [$aliasCandidates, $aliasUnknown] = $this->traitCandidatesForOriginalMethod(
                $use,
                (string) $alias['method'],
                $visited,
                $alias['trait'] ?? null,
            );

            foreach ($aliasCandidates as &$candidate) {
                if (is_string($alias['visibility'] ?? null)) {
                    $candidate['record']['visibility'] = $alias['visibility'];
                }

                $candidate['aliasedAs'] = $alias['alias'];
            }
            unset($candidate);

            array_push($candidates, ...$aliasCandidates);
            array_push($unknown, ...$aliasUnknown);
        }

        // `Trait::method as private;` changes the visibility of the original
        // imported method. A renamed alias changes only the alias visibility.
        foreach ($use['aliases'] ?? [] as $alias) {
            if (! $this->samePhpName($alias['method'] ?? null, $method)
                || ($alias['alias'] ?? null) !== null
                || ! is_string($alias['visibility'] ?? null)) {
                continue;
            }

            foreach ($candidates as &$candidate) {
                if (($alias['trait'] ?? null) === null
                    || $this->samePhpName($candidate['topTrait'] ?? null, $alias['trait'])) {
                    $candidate['record']['visibility'] = $alias['visibility'];
                }
            }
            unset($candidate);
        }

        return [$candidates, $unknown];
    }

    /**
     * @param array<string, mixed> $use
     * @param array<string, true> $visited
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function traitCandidatesForOriginalMethod(
        array $use,
        string $method,
        array &$visited,
        ?string $onlyTrait = null,
    ): array {
        $candidates = [];
        $unknown = [];

        foreach ($use['traits'] ?? [] as $trait) {
            if ($onlyTrait !== null && ! $this->samePhpName($trait, $onlyTrait)) {
                continue;
            }

            $trait = $this->indexedTraitName($trait) ?? $trait;
            $branchVisited = $visited;
            $state = $this->resolveTraitMethodState($trait, $method, $branchVisited);
            $state['topTrait'] = $trait;

            if (($state['status'] ?? null) === 'resolved') {
                $candidates[] = $state;
            } elseif (($state['status'] ?? null) === 'unknown') {
                $unknown[] = $state;
            }
        }

        foreach ($use['precedences'] ?? [] as $precedence) {
            if (! $this->samePhpName($precedence['method'] ?? null, $method)) {
                continue;
            }

            $selected = $precedence['trait'] ?? null;

            if (is_string($selected)
                && ! (bool) array_filter(
                    [...$candidates, ...$unknown],
                    fn (array $candidate): bool => $this->samePhpName($candidate['topTrait'] ?? null, $selected),
                )) {
                $unknown[] = [
                    'status' => 'unknown',
                    'reason' => 'invalid_trait_adaptation',
                    'topTrait' => $selected,
                ];
            }

            $excluded = $precedence['insteadOf'] ?? [];
            $candidates = array_values(array_filter(
                $candidates,
                fn (array $candidate): bool => ! (bool) array_filter(
                    $excluded,
                    fn (string $trait): bool => $this->samePhpName($candidate['topTrait'] ?? null, $trait),
                ),
            ));
            $unknown = array_values(array_filter(
                $unknown,
                fn (array $candidate): bool => ! (bool) array_filter(
                    $excluded,
                    fn (string $trait): bool => $this->samePhpName($candidate['topTrait'] ?? null, $trait),
                ),
            ));
        }

        return [$candidates, $unknown];
    }

    /** @param array<string, true> $visited */
    private function resolveTraitMethodState(string $trait, string $method, array &$visited): array
    {
        $trait = $this->indexedTraitName($trait) ?? $trait;
        $visitKey = 'trait:'.strtolower($trait);

        if (isset($visited[$visitKey])) {
            return [
                'status' => 'unknown',
                'reason' => 'trait_composition_cycle',
                'trait' => $trait,
            ];
        }

        $visited[$visitKey] = true;

        if (isset($this->traits[$trait])) {
            $record = $this->methods[$trait][strtolower($method)] ?? null;

            if (is_array($record)) {
                return [
                    'status' => 'resolved',
                    'class' => $trait,
                    'declaredMethod' => $record['name'] ?? $method,
                    'record' => $record,
                ];
            }

            return $this->resolveTraitUsesState(
                $this->traits[$trait]['traitUses'] ?? [],
                $method,
                $visited,
            );
        }

        return $this->resolveReflectedTraitMethodState($trait, $method);
    }

    /** @return array<string, mixed> */
    private function resolveReflectedTraitMethodState(string $trait, string $method): array
    {
        // Do not autoload an arbitrary application/package file during a scan.
        // If Composer or the test/runtime has already loaded it, reflection is a
        // side-effect-free way to inspect the composed trait method table.
        if (! trait_exists($trait, false)) {
            return [
                'status' => 'unknown',
                'reason' => 'unindexed_trait',
                'trait' => $trait,
            ];
        }

        try {
            $reflection = new \ReflectionClass($trait);

            if (! $reflection->hasMethod($method)) {
                return ['status' => 'missing'];
            }

            $reflectionMethod = $reflection->getMethod($method);
            $declaringTrait = $reflectionMethod->getDeclaringClass()->getName();

            return [
                'status' => 'resolved',
                'class' => $declaringTrait,
                'declaredMethod' => $reflectionMethod->getName(),
                'record' => $this->reflectedMethodRecord($reflectionMethod),
                'reflected' => true,
            ];
        } catch (\Throwable) {
            return [
                'status' => 'unknown',
                'reason' => 'unindexed_trait_reflection_failed',
                'trait' => $trait,
            ];
        }
    }

    /** @return array<string, mixed> */
    private function reflectedMethodRecord(\ReflectionMethod $method): array
    {
        $file = $method->getFileName();
        $inputs = [];

        foreach ($method->getParameters() as $parameter) {
            $inputs[] = [
                'name' => $parameter->getName(),
                'type' => $this->reflectionTypeName($parameter->getType()),
                'allowsNull' => $parameter->allowsNull(),
                'optional' => $parameter->isOptional(),
                'variadic' => $parameter->isVariadic(),
                'byReference' => $parameter->isPassedByReference(),
            ];
        }

        $returnType = $this->reflectionTypeName($method->getReturnType());
        $signatureParameters = array_map(
            static fn (array $input): string => trim(($input['type'] !== null ? $input['type'].' ' : '').'$'.$input['name']),
            $inputs,
        );

        return [
            'node' => null,
            'file' => is_string($file) ? ($this->files->relativePath($file) ?? $file) : null,
            'line' => $method->getStartLine() ?: null,
            'signature' => $method->getName().'('.implode(', ', $signatureParameters).')'.($returnType !== null ? ': '.$returnType : ''),
            'inputs' => $inputs,
            'outputs' => $returnType !== null ? [['type' => $returnType]] : [],
            'visibility' => $method->isPrivate() ? 'private' : ($method->isProtected() ? 'protected' : 'public'),
            'static' => $method->isStatic(),
        ];
    }

    private function reflectionTypeName(?\ReflectionType $type): ?string
    {
        return $type === null ? null : (string) $type;
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function uniqueTraitCandidates(array $candidates): array
    {
        $unique = [];

        foreach ($candidates as $candidate) {
            $key = strtolower(implode('|', [
                $candidate['topTrait'] ?? '',
                $candidate['class'] ?? '',
                $candidate['declaredMethod'] ?? '',
                $candidate['aliasedAs'] ?? '',
                $candidate['record']['visibility'] ?? '',
            ]));
            $unique[$key] = $candidate;
        }

        return array_values($unique);
    }

    private function hasApplicationOverride(string $class, string $method): bool
    {
        return ($this->resolveApplicationMethodState($class, $method)['status'] ?? null) !== 'missing'
            || $this->unindexedParentOverride($class, $method) !== null;
    }

    /** @param array{class: string, record: array<string, mixed>}|null $customValidator */
    private function conditionalUnknownFieldRulesActive(string $class, ?array $customValidator): bool
    {
        return $this->conditionalUnknownFieldRulesPath($class, $customValidator)
            && ($this->resolveApplicationMethodState($class, 'rules')['status'] ?? null) === 'resolved';
    }

    /** @param array{class: string, record: array<string, mixed>}|null $customValidator */
    private function conditionalUnknownFieldRulesPath(string $class, ?array $customValidator): bool
    {
        if ($customValidator === null
            || ! $this->frameworkHasFormRequestMethod('validateNoUnknownFields')
            || ! $this->frameworkValidationCanReachValidator($class)) {
            return false;
        }

        foreach (['validateResolved', 'getValidatorInstance', 'validateNoUnknownFields', 'validationRules'] as $method) {
            if (($this->resolveApplicationMethodState($class, $method)['status'] ?? null) !== 'missing'
                || $this->unindexedParentOverride($class, $method) !== null) {
                return false;
            }
        }

        return ($this->resolveApplicationMethodState($class, 'rules')['status'] ?? null) !== 'missing'
            || $this->unindexedParentOverride($class, 'rules') !== null;
    }

    private function frameworkValidationCanReachValidator(string $class): bool
    {
        $prepare = $this->resolveApplicationMethodState($class, 'prepareForValidation');

        if ($this->lifecycleStateBlocks($prepare)) {
            return false;
        }

        $passes = $this->resolveApplicationMethodState($class, 'passesAuthorization');

        if ($this->lifecycleStateBlocks($passes)) {
            return false;
        }

        if (($passes['status'] ?? null) === 'resolved') {
            return true;
        }

        $unindexedPasses = $this->unindexedParentOverride($class, 'passesAuthorization');

        if ($unindexedPasses !== null) {
            return ($unindexedPasses['visibility'] ?? null) !== 'private';
        }

        $authorize = $this->resolveApplicationMethodState($class, 'authorize');

        if ($this->lifecycleStateBlocks($authorize)) {
            return false;
        }

        $unindexedAuthorize = ($authorize['status'] ?? null) === 'missing'
            ? $this->unindexedParentOverride($class, 'authorize')
            : null;

        return $unindexedAuthorize === null
            || ! in_array($unindexedAuthorize['visibility'] ?? null, ['private', 'protected'], true);
    }

    private function frameworkHasFormRequestMethod(string $method): bool
    {
        return class_exists(self::FORM_REQUEST_CLASS, false)
            && method_exists(self::FORM_REQUEST_CLASS, $method);
    }

    /**
     * Inspect only the first unindexed parent boundary. Reflection reads method
     * declarations without constructing the request or invoking application code.
     * A reflection failure is treated as an override so graph claims remain safe.
     *
     * @return array{class: string, reflected: bool, visibility?: string}|null
     */
    private function unindexedParentOverride(string $class, string $method): ?array
    {
        $visited = [];
        $class = $this->indexedClassName($class) ?? $class;

        while (isset($this->classes[$class]) && ! isset($visited[strtolower($class)])) {
            $visited[strtolower($class)] = true;
            $parent = $this->classes[$class]['extends'] ?? null;

            if (! is_string($parent) || $this->samePhpName($parent, self::FORM_REQUEST_CLASS)) {
                return null;
            }

            $indexedParent = $this->indexedClassName($parent);

            if ($indexedParent !== null) {
                $class = $indexedParent;
                continue;
            }

            try {
                // isFormRequest() already resolved this boundary. Avoid causing a
                // second autoload attempt while deriving lifecycle evidence.
                if (! class_exists($parent, false)) {
                    return [
                        'class' => $parent,
                        'reflected' => false,
                    ];
                }

                $reflection = new \ReflectionClass($parent);

                if (! $reflection->hasMethod($method)) {
                    return null;
                }

                $reflectionMethod = $reflection->getMethod($method);
                $declaringClass = $reflectionMethod->getDeclaringClass()->getName();

                if ($this->samePhpName($declaringClass, self::FORM_REQUEST_CLASS)) {
                    return null;
                }

                return [
                    'class' => $declaringClass,
                    'reflected' => true,
                    'visibility' => $reflectionMethod->isPrivate()
                        ? 'private'
                        : ($reflectionMethod->isProtected() ? 'protected' : 'public'),
                ];
            } catch (\Throwable) {
                return [
                    'class' => $parent,
                    'reflected' => false,
                ];
            }
        }

        return null;
    }

    /** @param array{class: string, reflected: bool, visibility?: string} $override */
    private function addUnindexedOverrideWarning(
        Graph $graph,
        string $requestClass,
        string $method,
        array $override,
    ): void {
        $graph->addWarning([
            'scanner' => $this->scannerName(),
            'class' => $requestClass,
            'method' => $method,
            'declaredOn' => $override['class'],
            'inference' => 'unindexed_parent_override',
            'message' => sprintf(
                'Skipped default FormRequest lifecycle claims because %s may override %s().',
                $override['class'],
                $method,
            ),
        ]);
    }

    /** @param array{class: string, reflected: bool, visibility?: string} $override */
    private function unindexedOverrideBlocks(
        Graph $graph,
        string $requestClass,
        string $method,
        array $override,
    ): bool {
        $visibility = $override['visibility'] ?? null;
        $reason = match (true) {
            $visibility === 'private' => 'private_lifecycle_hook',
            $visibility === 'protected'
                && in_array(strtolower($method), ['authorize', 'rules'], true) => 'protected_container_call_hook',
            default => null,
        };

        if ($reason === null) {
            return false;
        }

        $this->addMethodResolutionWarning($graph, $requestClass, $method, [
            'status' => 'hazard',
            'reason' => $reason,
            'class' => $override['class'],
        ]);

        return true;
    }

    /** @param array<string, mixed> $state */
    private function addMethodResolutionWarning(
        Graph $graph,
        string $requestClass,
        string $method,
        array $state,
    ): void {
        $reason = (string) ($state['reason'] ?? 'unresolved_lifecycle_hook');
        $declaredOn = $state['class'] ?? $state['trait'] ?? null;
        $key = implode('|', [$requestClass, $method, $reason, (string) $declaredOn]);

        if (isset($this->resolutionWarnings[$key])) {
            return;
        }

        $this->resolutionWarnings[$key] = true;
        $message = match ($reason) {
            'private_lifecycle_hook' => sprintf(
                'Stopped FormRequest lifecycle claims at %s() because %s declares it private and Laravel cannot invoke it.',
                $method,
                $declaredOn ?? $requestClass,
            ),
            'protected_container_call_hook' => sprintf(
                'Stopped FormRequest lifecycle claims at %s() because %s declares it protected but Laravel Container::call requires that hook to be public.',
                $method,
                $declaredOn ?? $requestClass,
            ),
            'unindexed_trait', 'unindexed_trait_reflection_failed' => sprintf(
                'Stopped FormRequest lifecycle claims at %s() because unindexed trait %s could supply or adapt that hook.',
                $method,
                $state['trait'] ?? 'unknown',
            ),
            'ambiguous_trait_adaptation' => sprintf(
                'Stopped FormRequest lifecycle claims at %s() because its trait adaptation could not be resolved uniquely.',
                $method,
            ),
            default => sprintf(
                'Stopped FormRequest lifecycle claims at %s() because method resolution is uncertain (%s).',
                $method,
                $reason,
            ),
        };

        $graph->addWarning(array_filter([
            'scanner' => $this->scannerName(),
            'class' => $requestClass,
            'method' => $method,
            'declaredOn' => $declaredOn,
            'trait' => $state['trait'] ?? null,
            'traits' => $state['traits'] ?? null,
            'inference' => $reason,
            'terminal' => true,
            'message' => $message,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /** @return array<string, mixed> */
    private function lifecycleMethodState(Graph $graph, string $requestClass, string $method): array
    {
        $state = $this->resolveApplicationMethodState($requestClass, $method);

        if (in_array($state['status'] ?? null, ['hazard', 'unknown'], true)) {
            $this->addMethodResolutionWarning($graph, $requestClass, $method, $state);
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function lifecycleStateBlocks(array $state): bool
    {
        return in_array($state['status'] ?? null, ['hazard', 'unknown'], true);
    }

    /**
     * Add the Laravel 12/13 FormRequest execution convention as semantic edges.
     * The edge source is the request node because Laravel resolves the request and
     * invokes these hooks before entering the controller method.
     *
     * @param array{class: string, record: array<string, mixed>}|null $customValidator
     */
    private function addLifecycleExecutionBridges(Graph $graph, string $requestClass, ?array $customValidator): void
    {
        $validateResolvedState = $this->lifecycleMethodState($graph, $requestClass, 'validateResolved');

        if ($this->lifecycleStateBlocks($validateResolvedState)) {
            return;
        }

        $validateResolved = ($validateResolvedState['status'] ?? null) === 'resolved'
            ? $validateResolvedState
            : null;
        $unindexedValidateResolved = $validateResolved === null
            ? $this->unindexedParentOverride($requestClass, 'validateResolved')
            : null;

        if ($validateResolved !== null) {
            $this->addResolvedLifecycleHook(
                $graph,
                $requestClass,
                'validateResolved',
                $validateResolved,
                0,
                'validation_lifecycle',
                branch: 'application_override',
            );

            return;
        }

        if ($unindexedValidateResolved !== null) {
            $this->addUnindexedOverrideWarning(
                $graph,
                $requestClass,
                'validateResolved',
                $unindexedValidateResolved,
            );
            $this->unindexedOverrideBlocks(
                $graph,
                $requestClass,
                'validateResolved',
                $unindexedValidateResolved,
            );

            return;
        }

        if (! $this->addLifecycleHook($graph, $requestClass, 'prepareForValidation', 10, 'preparation')) {
            return;
        }

        $passesAuthorizationState = $this->lifecycleMethodState($graph, $requestClass, 'passesAuthorization');

        if ($this->lifecycleStateBlocks($passesAuthorizationState)) {
            return;
        }

        $passesAuthorization = ($passesAuthorizationState['status'] ?? null) === 'resolved'
            ? $passesAuthorizationState
            : null;
        $unindexedPassesAuthorization = $passesAuthorization === null
            ? $this->unindexedParentOverride($requestClass, 'passesAuthorization')
            : null;
        $authorize = null;
        $unindexedAuthorize = null;

        if ($passesAuthorization === null && $unindexedPassesAuthorization === null) {
            $authorizeState = $this->lifecycleMethodState($graph, $requestClass, 'authorize');

            if ($this->lifecycleStateBlocks($authorizeState)) {
                return;
            }

            $authorize = ($authorizeState['status'] ?? null) === 'resolved'
                ? $authorizeState
                : null;
            $unindexedAuthorize = $authorize === null
                ? $this->unindexedParentOverride($requestClass, 'authorize')
                : null;

            if ($unindexedAuthorize !== null) {
                $this->addUnindexedOverrideWarning(
                    $graph,
                    $requestClass,
                    'authorize',
                    $unindexedAuthorize,
                );

                if ($this->unindexedOverrideBlocks(
                    $graph,
                    $requestClass,
                    'authorize',
                    $unindexedAuthorize,
                )) {
                    return;
                }
            }
        }

        if ($passesAuthorization !== null) {
            $this->addResolvedLifecycleHook(
                $graph,
                $requestClass,
                'passesAuthorization',
                $passesAuthorization,
                20,
                'authorization',
                branch: 'application_override',
            );
        } elseif ($unindexedPassesAuthorization !== null) {
            $this->addUnindexedOverrideWarning(
                $graph,
                $requestClass,
                'passesAuthorization',
                $unindexedPassesAuthorization,
            );

            if ($this->unindexedOverrideBlocks(
                $graph,
                $requestClass,
                'passesAuthorization',
                $unindexedPassesAuthorization,
            )) {
                return;
            }
        } elseif ($authorize !== null) {
            $this->addResolvedLifecycleHook($graph, $requestClass, 'authorize', $authorize, 20, 'authorization');
        }

        if ($passesAuthorization !== null
            || $unindexedPassesAuthorization !== null
            || $authorize !== null
            || $unindexedAuthorize !== null) {
            if (! $this->addLifecycleHook(
                $graph,
                $requestClass,
                'failedAuthorization',
                30,
                'authorization',
                conditional: true,
                condition: 'authorization_failed',
                expectedTerminal: true,
            )) {
                return;
            }
        }

        $getValidatorState = $this->lifecycleMethodState($graph, $requestClass, 'getValidatorInstance');

        if ($this->lifecycleStateBlocks($getValidatorState)) {
            return;
        }

        $getValidator = ($getValidatorState['status'] ?? null) === 'resolved'
            ? $getValidatorState
            : null;
        $unindexedGetValidator = $getValidator === null
            ? $this->unindexedParentOverride($requestClass, 'getValidatorInstance')
            : null;

        if ($getValidator !== null) {
            $this->addResolvedLifecycleHook(
                $graph,
                $requestClass,
                'getValidatorInstance',
                $getValidator,
                40,
                'validator_creation',
                branch: 'application_override',
            );
        } elseif ($unindexedGetValidator !== null) {
            $this->addUnindexedOverrideWarning(
                $graph,
                $requestClass,
                'getValidatorInstance',
                $unindexedGetValidator,
            );

            if ($this->unindexedOverrideBlocks(
                $graph,
                $requestClass,
                'getValidatorInstance',
                $unindexedGetValidator,
            )) {
                return;
            }
        } else {
            if ($this->frameworkHasFormRequestMethod('configureFromAttributes')) {
                if (! $this->addLifecycleHook(
                    $graph,
                    $requestClass,
                    'configureFromAttributes',
                    35,
                    'validator_configuration',
                )) {
                    return;
                }
            }

            if (! $this->addValidatorCreationHooks($graph, $requestClass, $customValidator)) {
                return;
            }

            if (! $this->addLifecycleHook(
                $graph,
                $requestClass,
                'withValidator',
                50,
                'validator_configuration',
            )) {
                return;
            }

            if (! $this->addLifecycleHook($graph, $requestClass, 'after', 60, 'validator_configuration')) {
                return;
            }

            if ($this->frameworkHasFormRequestMethod('shouldFailOnUnknownFields')) {
                if (! $this->addLifecycleHook(
                    $graph,
                    $requestClass,
                    'shouldFailOnUnknownFields',
                    61,
                    'unknown_fields',
                )) {
                    return;
                }

                if (! $this->addLifecycleHook(
                    $graph,
                    $requestClass,
                    'validateNoUnknownFields',
                    62,
                    'unknown_fields',
                    conditional: true,
                    condition: 'unknown_field_validation_enabled',
                )) {
                    return;
                }

                if ($this->conditionalUnknownFieldRulesPath($requestClass, $customValidator)
                    && ! $this->addConditionalUnknownFieldRulesHook($graph, $requestClass)) {
                    return;
                }
            }
        }

        if (! $this->addLifecycleHook(
            $graph,
            $requestClass,
            'failedValidation',
            70,
            'validation_result',
            conditional: true,
            condition: 'validation_failed',
            expectedTerminal: true,
        )) {
            return;
        }

        $this->addLifecycleHook(
            $graph,
            $requestClass,
            'passedValidation',
            80,
            'validation_result',
            conditional: true,
            condition: 'validation_passed_or_failure_handler_returned',
            beforeController: true,
        );
    }

    private function addConditionalUnknownFieldRulesHook(Graph $graph, string $requestClass): bool
    {
        $state = $this->lifecycleMethodState($graph, $requestClass, 'rules');

        if ($this->lifecycleStateBlocks($state)) {
            return false;
        }

        if (($state['status'] ?? null) !== 'resolved') {
            $unindexed = $this->unindexedParentOverride($requestClass, 'rules');

            if ($unindexed !== null && $this->unindexedOverrideBlocks(
                $graph,
                $requestClass,
                'rules',
                $unindexed,
            )) {
                $this->addUnindexedOverrideWarning($graph, $requestClass, 'rules', $unindexed);

                return false;
            }

            return true;
        }

        $this->addResolvedLifecycleHook(
            $graph,
            $requestClass,
            'rules',
            $state,
            63,
            'unknown_fields',
            conditional: true,
            condition: 'unknown_field_validation_enabled_and_validator_after_callbacks_run',
            branch: 'custom_validator_unknown_fields',
            invokedBy: 'validateNoUnknownFields',
        );

        return true;
    }

    /** @param array{class: string, record: array<string, mixed>}|null $customValidator */
    private function addValidatorCreationHooks(Graph $graph, string $requestClass, ?array $customValidator): bool
    {
        $customValidatorState = $this->lifecycleMethodState($graph, $requestClass, 'validator');

        if ($this->lifecycleStateBlocks($customValidatorState)) {
            return false;
        }

        if ($customValidator !== null) {
            $this->addResolvedLifecycleHook(
                $graph,
                $requestClass,
                'validator',
                $customValidator,
                40,
                'validator_creation',
                branch: 'custom_validator',
            );

            return true;
        }

        $unindexedValidator = $this->unindexedParentOverride($requestClass, 'validator');

        if ($unindexedValidator !== null) {
            $this->addUnindexedOverrideWarning($graph, $requestClass, 'validator', $unindexedValidator);

            return ! $this->unindexedOverrideBlocks(
                $graph,
                $requestClass,
                'validator',
                $unindexedValidator,
            );
        }

        $createDefaultState = $this->lifecycleMethodState($graph, $requestClass, 'createDefaultValidator');

        if ($this->lifecycleStateBlocks($createDefaultState)) {
            return false;
        }

        $createDefault = ($createDefaultState['status'] ?? null) === 'resolved'
            ? $createDefaultState
            : null;
        $unindexedCreateDefault = $createDefault === null
            ? $this->unindexedParentOverride($requestClass, 'createDefaultValidator')
            : null;

        if ($createDefault !== null) {
            $this->addResolvedLifecycleHook(
                $graph,
                $requestClass,
                'createDefaultValidator',
                $createDefault,
                40,
                'validator_creation',
                branch: 'application_override',
            );

            return true;
        }

        if ($unindexedCreateDefault !== null) {
            $this->addUnindexedOverrideWarning(
                $graph,
                $requestClass,
                'createDefaultValidator',
                $unindexedCreateDefault,
            );

            return ! $this->unindexedOverrideBlocks(
                $graph,
                $requestClass,
                'createDefaultValidator',
                $unindexedCreateDefault,
            );
        }

        $validationRulesState = $this->lifecycleMethodState($graph, $requestClass, 'validationRules');

        if ($this->lifecycleStateBlocks($validationRulesState)) {
            return false;
        }

        $validationRules = ($validationRulesState['status'] ?? null) === 'resolved'
            ? $validationRulesState
            : null;
        $unindexedValidationRules = $validationRules === null
            ? $this->unindexedParentOverride($requestClass, 'validationRules')
            : null;

        if ($validationRules !== null) {
            $this->addResolvedLifecycleHook(
                $graph,
                $requestClass,
                'validationRules',
                $validationRules,
                40,
                'validator_creation',
                branch: 'application_override',
            );
        } elseif ($unindexedValidationRules !== null) {
            $this->addUnindexedOverrideWarning(
                $graph,
                $requestClass,
                'validationRules',
                $unindexedValidationRules,
            );

            if ($this->unindexedOverrideBlocks(
                $graph,
                $requestClass,
                'validationRules',
                $unindexedValidationRules,
            )) {
                return false;
            }
        } else {
            if (! $this->addLifecycleHook(
                $graph,
                $requestClass,
                'rules',
                40,
                'validator_creation',
                branch: 'default_validator',
            )) {
                return false;
            }
        }

        // PHP evaluates make() arguments left-to-right after validationRules().
        foreach (['validationData' => 41, 'messages' => 42, 'attributes' => 43] as $hook => $order) {
            if (! $this->addLifecycleHook(
                $graph,
                $requestClass,
                $hook,
                $order,
                'validator_creation',
                branch: 'default_validator',
            )) {
                return false;
            }
        }

        return true;
    }

    private function addLifecycleHook(
        Graph $graph,
        string $requestClass,
        string $hook,
        int $order,
        string $phase,
        bool $conditional = false,
        bool $terminal = false,
        ?string $condition = null,
        ?string $branch = null,
        bool $beforeController = false,
        bool $expectedTerminal = false,
    ): bool {
        $state = $this->lifecycleMethodState($graph, $requestClass, $hook);

        if ($this->lifecycleStateBlocks($state)) {
            return false;
        }

        if (($state['status'] ?? null) !== 'resolved') {
            if (in_array(strtolower($hook), ['authorize', 'rules'], true)) {
                $unindexed = $this->unindexedParentOverride($requestClass, $hook);

                if ($unindexed !== null && $this->unindexedOverrideBlocks(
                    $graph,
                    $requestClass,
                    $hook,
                    $unindexed,
                )) {
                    $this->addUnindexedOverrideWarning($graph, $requestClass, $hook, $unindexed);

                    return false;
                }
            }

            return true;
        }

        $this->addResolvedLifecycleHook(
            $graph,
            $requestClass,
            $hook,
            $state,
            $order,
            $phase,
            $conditional,
            $terminal,
            $condition,
            $branch,
            $beforeController,
            $expectedTerminal,
        );

        return true;
    }

    /**
     * @param array{class: string, record: array<string, mixed>} $resolved
     */
    private function addResolvedLifecycleHook(
        Graph $graph,
        string $requestClass,
        string $hook,
        array $resolved,
        int $order,
        string $phase,
        bool $conditional = false,
        bool $terminal = false,
        ?string $condition = null,
        ?string $branch = null,
        bool $beforeController = false,
        bool $expectedTerminal = false,
        ?string $invokedBy = null,
    ): void {
        $declaringClass = $resolved['class'];
        $record = $resolved['record'];
        $declaredMethod = $resolved['declaredMethod'] ?? $hook;
        $target = $declaringClass.'::'.$declaredMethod;

        $graph->addNode($this->methodNode($declaringClass, $declaredMethod, $record));
        $graph->addEdge(new Edge($requestClass, $target, 'framework_invokes', 1.0, array_filter([
            'hook' => $hook,
            'declaredMethod' => $declaredMethod !== $hook ? $declaredMethod : null,
            'composedAs' => $resolved['aliasedAs'] ?? null,
            'phase' => $phase,
            'order' => $order,
            'conditional' => $conditional,
            'terminal' => $terminal,
            'condition' => $condition,
            'branch' => $branch,
            'invokedBy' => $invokedBy,
            'beforeController' => $beforeController,
            'expectedTerminal' => $expectedTerminal,
            'declaredOn' => $declaringClass,
            'inherited' => $declaringClass !== $requestClass,
            'viaTrait' => isset($this->traits[$declaringClass]) ?: null,
            'source' => $this->scannerSourceLabel(),
            'rule' => 'laravel_form_request_lifecycle',
        ], static fn (mixed $value): bool => $value !== null)));
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
            $operation = strtolower($node->name->toString());

            if (! in_array($operation, ['validate', 'validatewithbag'], true)) {
                return [null, null];
            }

            if ($node->var instanceof Expr\Variable && $node->var->name === 'this') {
                if (! $this->classUsesControllerValidation($context['class'])) {
                    return [null, null];
                }

                $position = $operation === 'validate' ? 1 : 2;
                $arg = $node->args[$position] ?? null;

                return [
                    $arg instanceof Arg ? $arg->value : null,
                    $operation === 'validate' ? 'controller_validate' : 'controller_validateWithBag',
                ];
            }

            if ($node->var instanceof Expr\Variable && is_string($node->var->name)) {
                $receiver = $context['localTypes'][$node->var->name]['class'] ?? null;

                if (! is_string($receiver) || ! $this->isHttpRequestClass($receiver)) {
                    return [null, null];
                }

                $position = $operation === 'validate' ? 0 : 1;
                $arg = $node->args[$position] ?? null;

                return [
                    $arg instanceof Arg ? $arg->value : null,
                    $operation === 'validate' ? 'request_validate' : 'request_validateWithBag',
                ];
            }
        }

        if ($node instanceof Expr\StaticCall
            && $node->name instanceof Identifier
            && strtolower($node->name->toString()) === 'make'
            && strcasecmp(class_basename($this->resolvedName($node->class) ?? ''), 'Validator') === 0) {
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
        $class = $this->indexedClassName($class) ?? $class;
        $visitKey = strtolower($class);

        if (isset($visited[$visitKey])) {
            return false;
        }

        if ($this->samePhpName($class, 'Illuminate\\Http\\Request')
            || $this->samePhpName($class, self::FORM_REQUEST_CLASS)) {
            return true;
        }

        $visited[$visitKey] = true;
        $parent = $this->classes[$class]['extends'] ?? null;

        return is_string($parent) && $this->isHttpRequestClass($parent, $visited);
    }

    private function classUsesControllerValidation(string $class, array $visited = []): bool
    {
        $class = $this->indexedClassName($class) ?? $class;
        $visitKey = strtolower($class);

        if (isset($visited[$visitKey])) {
            return false;
        }

        if ($this->samePhpName($class, 'Illuminate\\Routing\\Controller')) {
            return true;
        }

        $visited[$visitKey] = true;
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
        $record = $this->methods[$context['class']][strtolower($context['method'])] ?? null;

        if (is_array($record)) {
            $graph->addNode($this->methodNode(
                $context['class'],
                $record['name'] ?? $context['method'],
                $record,
            ));
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
