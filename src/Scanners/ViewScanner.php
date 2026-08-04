<?php

namespace AppGraph\Scanners;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Scanners\Concerns\InteractsWithPhpAst;
use AppGraph\Support\FileFinder;
use AppGraph\Support\PhpFileFacts;
use AppGraph\Support\SourceFileObservations;
use Illuminate\Support\Str;
use PhpParser\Node as AstNode;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Statically maps Blade and plain PHP templates into first-class `view:` nodes
 * and connects them to the rest of the graph: renderers (controller methods,
 * Route::view routes, closure routes, mailables), template-to-template
 * relationships (@extends/@include/components), and named-route consumption
 * inside templates. Dynamic view names are reported as diagnostics rather
 * than guessed.
 */
class ViewScanner
{
    use InteractsWithPhpAst;

    private const VIEW_FACADES = [
        'Illuminate\Support\Facades\View',
        'View',
    ];

    private const ROUTE_FACADES = [
        'Illuminate\Support\Facades\Route',
        'Route',
    ];

    private const ROUTE_REGISTRATION_METHODS = [
        'get', 'post', 'put', 'patch', 'delete', 'options', 'any',
    ];

    private const MAILABLE_ROOTS = [
        'Illuminate\Mail\Mailable',
    ];

    private const MAIL_CONTENT_CLASS = 'Illuminate\Mail\Mailables\Content';

    /** Mail Content constructor views: parameter name => positional index. */
    private const MAIL_CONTENT_VIEW_PARAMETERS = [
        'view' => 0,
        'html' => 1,
        'text' => 2,
        'markdown' => 3,
    ];

    /** Blade directives that reference a template: directive => view-name argument index. */
    private const DIRECTIVE_VIEW_ARGUMENT = [
        'extends' => 0,
        'include' => 0,
        'includeIf' => 0,
        'component' => 0,
        'each' => 0,
        'includeWhen' => 1,
        'includeUnless' => 1,
    ];

    private const MAX_SAMPLES = 50;

    private const MAX_VIEW_NAME_BYTES = 512;

    private const MAX_DIRECTIVE_SPAN_BYTES = 4096;

    private SourceFileObservations $sourceObservations;

    /** @var array<int, string> */
    private array $viewPaths;

    private string $appNamespace;

    /** @var array<string, array{file: string, source: string, engine: string}> */
    private array $viewsByName = [];

    /** @var array<string, array{extends: ?string}> */
    private array $classes = [];

    /** @var array<string, true> */
    private array $localFunctions = [];

    /** @var array<string, array<int, string>> */
    private array $routesByName = [];

    /** @var array<string, array<string, array<int, string>>> */
    private array $routesByUri = [];

    /** @var array<string, ?string> */
    private array $routeActions = [];

    /** @var array<int, array<string, mixed>> */
    private array $unresolvedViews = [];

    /** @var array<int, array<string, mixed>> */
    private array $unresolvedComponents = [];

    /** @var array<int, array<string, mixed>> */
    private array $unresolvedRoutes = [];

    /** @var array<int, array<string, mixed>> */
    private array $dynamicReferences = [];

    /**
     * @param array<int, string>|null $viewPaths Application view roots. Defaults
     *     to resources/views; the service provider passes config('view.paths').
     */
    public function __construct(
        private FileFinder $files,
        ?SourceFileObservations $sourceObservations = null,
        ?PhpFileFacts $phpFileFacts = null,
        ?array $viewPaths = null,
        ?string $appNamespace = null,
    ) {
        $this->sourceObservations = $sourceObservations ?? new SourceFileObservations();
        $this->initializePhpFileFacts($phpFileFacts);
        $this->viewPaths = $this->normalizeViewPaths($viewPaths ?? ['resources/views']);
        $this->appNamespace = trim($appNamespace ?? 'App\\', '\\').'\\';
    }

    protected function scannerName(): string
    {
        return 'views';
    }

    protected function scannerSourceLabel(): string
    {
        return 'view_scanner';
    }

    public function scan(Graph $graph): Graph
    {
        $this->viewsByName = [];
        $this->classes = [];
        $this->localFunctions = [];
        $this->routesByName = [];
        $this->routesByUri = [];
        $this->routeActions = [];
        $this->unresolvedViews = [];
        $this->unresolvedComponents = [];
        $this->unresolvedRoutes = [];
        $this->dynamicReferences = [];

        $this->indexRoutes($graph);
        $this->discoverViews($graph);
        $this->scanTemplates($graph);
        $this->scanPhpRenderers($graph);
        $this->exportDiagnostics($graph);

        return $graph;
    }

    private function indexRoutes(Graph $graph): void
    {
        foreach ($graph->nodes() as $node) {
            if ($node->type !== 'route') {
                continue;
            }

            $name = $node->metadata['name'] ?? null;

            if (is_string($name) && $name !== '') {
                $this->routesByName[$name][] = $node->id;
            }

            $action = $node->metadata['action'] ?? null;
            $this->routeActions[$node->id] = is_string($action) ? $action : null;

            $uri = $node->metadata['uri'] ?? null;

            if (! is_string($uri)) {
                continue;
            }

            $uri = '/'.ltrim($uri, '/');

            foreach ($node->metadata['methods'] ?? [] as $method) {
                if (is_string($method) && $method !== '') {
                    $this->routesByUri[$uri][strtoupper($method)][] = $node->id;
                }
            }
        }
    }

    private function discoverViews(Graph $graph): void
    {
        foreach ($this->viewPaths as $viewPath) {
            $prefix = rtrim($viewPath, '/').'/';

            foreach ($this->files->findFiles($viewPath, ['php']) as $file) {
                if (! str_starts_with($file, $prefix)) {
                    continue;
                }

                $source = file_get_contents($file);

                if ($source === false) {
                    continue;
                }

                $this->sourceObservations->record($file, $source);

                $engine = str_ends_with($file, '.blade.php') ? 'blade' : 'php';
                $suffixLength = $engine === 'blade' ? strlen('.blade.php') : strlen('.php');
                $subPath = substr($file, strlen($prefix), -$suffixLength);

                if ($subPath === '' || str_contains($subPath, '.')) {
                    // Laravel's view finder maps dots in a view NAME to
                    // directory separators, so a path segment containing a
                    // literal dot is unreachable via view('...') and must not
                    // claim that dotted name from the resolvable template.
                    continue;
                }

                $name = str_replace('/', '.', $subPath);

                if (isset($this->viewsByName[$name])) {
                    // Laravel resolves duplicate names from the earliest view
                    // path, so later occurrences are shadowed.
                    continue;
                }

                $relative = $this->files->relativePath($file) ?? $file;
                $this->viewsByName[$name] = [
                    'file' => $relative,
                    'source' => $source,
                    'engine' => $engine,
                ];

                $graph->addNode(Node::make('view:'.$name, 'view', $name, [
                    'file' => $relative,
                    'line' => 1,
                    'endLine' => substr_count($source, "\n") + 1,
                    'metadata' => [
                        'name' => $name,
                        'engine' => $engine,
                        'source' => $this->scannerSourceLabel(),
                    ],
                ]));
            }
        }
    }

    private function scanTemplates(Graph $graph): void
    {
        foreach ($this->viewsByName as $name => $view) {
            $viewId = 'view:'.$name;
            $source = $view['engine'] === 'blade'
                ? $this->maskedBladeSource($view['source'])
                : $view['source'];

            if ($view['engine'] === 'blade') {
                $this->scanBladeDirectives($graph, $viewId, $view, $source);
                $this->scanComponentTags($graph, $viewId, $view, $source);
            }

            $this->scanNamedRouteReferences($graph, $viewId, $view, $source);
        }
    }

    /**
     * Blade never compiles {{-- --}} comments or @verbatim blocks, so their
     * contents must not produce edges. Masked bytes become spaces, preserving
     * every newline so match offsets still map to real line numbers.
     */
    private function maskedBladeSource(string $source): string
    {
        return (string) preg_replace_callback(
            '~\{\{--.*?(?:--\}\}|$)|@verbatim\b.*?(?:@endverbatim\b|$)~s',
            static fn (array $match): string => (string) preg_replace('/[^\n]/', ' ', $match[0]),
            $source,
        );
    }

    /** @param array{file: string, source: string, engine: string} $view */
    private function scanBladeDirectives(Graph $graph, string $viewId, array $view, string $source): void
    {
        $matches = [];
        // @@include is Blade's escape for a literal directive; the lookbehind
        // skips it. Argument spans are parsed with a quote-aware bracket
        // scanner so idiomatic conditions like auth()->check() survive.
        preg_match_all(
            '~(?<!@)@(extends|includeWhen|includeUnless|includeIf|includeFirst|include|component|each)\s*\(~',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($matches[1] ?? [] as $index => [$directive, $_offset]) {
            [$full, $offset] = $matches[0][$index];
            $line = $this->lineAt($source, $offset);
            $arguments = $this->directiveArguments($source, $offset + strlen($full) - 1);

            if ($arguments === null) {
                continue;
            }

            if ($directive === 'includeFirst') {
                $this->addIncludeFirstEdges($graph, $viewId, $view['file'], $arguments[0] ?? '', $line);

                continue;
            }

            $argument = $arguments[self::DIRECTIVE_VIEW_ARGUMENT[$directive]] ?? null;
            $name = $argument !== null ? $this->stringLiteral($argument) : null;

            if ($name === null) {
                $this->sample($this->dynamicReferences, [
                    'file' => $view['file'],
                    'line' => $line,
                    'syntax' => '@'.$directive,
                ]);

                continue;
            }

            $edgeType = match ($directive) {
                'extends' => 'extends',
                'component' => 'uses_component',
                default => 'includes',
            };
            $confidence = in_array($directive, ['includeWhen', 'includeUnless'], true) ? 0.9 : 0.95;
            $this->addTemplateEdge(
                $graph,
                $viewId,
                $view['file'],
                $name,
                $edgeType,
                $confidence,
                '@'.$directive,
                $line,
            );
        }
    }

    private function addIncludeFirstEdges(
        Graph $graph,
        string $viewId,
        string $file,
        string $candidateList,
        int $line,
    ): void {
        if (! str_starts_with($candidateList, '[')) {
            $this->sample($this->dynamicReferences, [
                'file' => $file,
                'line' => $line,
                'syntax' => '@includeFirst',
            ]);

            return;
        }

        $candidates = [];
        preg_match_all('~([\'"])((?:\\\\.|(?!\1).)*)\1~', $candidateList, $candidates);

        foreach ($candidates[2] ?? [] as $target) {
            $this->addTemplateEdge(
                $graph,
                $viewId,
                $file,
                $target,
                'includes',
                0.7,
                '@includeFirst',
                $line,
                'possible',
            );
        }
    }

    /**
     * Splits the balanced parenthesized span starting at $openOffset (which
     * must point at '(') into trimmed top-level argument expressions. Quotes
     * and nested brackets are respected; unbalanced or oversized spans return
     * null rather than guessing.
     *
     * @return array<int, string>|null
     */
    private function directiveArguments(string $source, int $openOffset): ?array
    {
        $length = min(strlen($source), $openOffset + self::MAX_DIRECTIVE_SPAN_BYTES);
        $depth = 0;
        $quote = null;
        $arguments = [];
        $current = '';

        for ($i = $openOffset; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                $current .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $source[++$i];

                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $current .= $char;

                continue;
            }

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;

                if ($depth > 1 || $char !== '(') {
                    $current .= $char;
                }

                continue;
            }

            if ($char === ')' || $char === ']' || $char === '}') {
                $depth--;

                if ($depth === 0) {
                    $arguments[] = trim($current);

                    return $arguments;
                }

                $current .= $char;

                continue;
            }

            if ($char === ',' && $depth === 1) {
                $arguments[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        return null;
    }

    /**
     * Returns the literal value when the expression is a plain single- or
     * double-quoted string without interpolation; null otherwise.
     */
    private function stringLiteral(string $expression): ?string
    {
        if (preg_match('~^([\'"])((?:\\\\.|(?!\1).)*)\1$~s', $expression, $match) !== 1) {
            return null;
        }

        if ($match[1] === '"' && str_contains($match[2], '$')) {
            return null;
        }

        return str_replace(['\\'.$match[1], '\\\\'], [$match[1], '\\'], $match[2]);
    }

    /** @param array{file: string, source: string, engine: string} $view */
    private function scanComponentTags(Graph $graph, string $viewId, array $view, string $source): void
    {
        $matches = [];
        preg_match_all('~<x-([a-zA-Z0-9][\w:.-]*)~', $source, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] ?? [] as [$component, $offset]) {
            $component = rtrim($component, '-.');
            $line = $this->lineAt($source, $offset);

            if ($component === 'slot' || str_starts_with($component, 'slot:')) {
                continue;
            }

            if (str_contains($component, '::')) {
                $this->sample($this->unresolvedComponents, [
                    'file' => $view['file'],
                    'line' => $line,
                    'component' => $component,
                    'reason' => 'namespaced_component',
                ]);

                continue;
            }

            if ($component === 'dynamic-component') {
                $this->sample($this->dynamicReferences, [
                    'file' => $view['file'],
                    'line' => $line,
                    'syntax' => 'dynamic_component_tag',
                ]);

                continue;
            }

            $classFqcn = $this->componentClass($component);

            if ($classFqcn !== null) {
                $classFile = 'app/View/Components/'.str_replace('\\', '/', substr($classFqcn, strlen($this->appNamespace.'View\\Components\\'))).'.php';
                $graph->addNode(Node::make($classFqcn, 'class', class_basename($classFqcn), [
                    'namespace' => $this->namespaceFromClass($classFqcn),
                    'class' => class_basename($classFqcn),
                    'file' => $classFile,
                ]));
                $graph->addEdge(new Edge($viewId, $classFqcn, 'uses_component', 0.9, [
                    'component' => $component,
                    'line' => $line,
                    'syntax' => 'component_tag',
                ]));

                continue;
            }

            $anonymous = $this->anonymousComponentView($component);

            if ($anonymous !== null) {
                $graph->addEdge(new Edge($viewId, 'view:'.$anonymous, 'uses_component', 0.9, [
                    'component' => $component,
                    'line' => $line,
                    'syntax' => 'component_tag',
                ]));

                continue;
            }

            $this->sample($this->unresolvedComponents, [
                'file' => $view['file'],
                'line' => $line,
                'component' => $component,
            ]);
        }
    }

    /** @param array{file: string, source: string, engine: string} $view */
    private function scanNamedRouteReferences(Graph $graph, string $viewId, array $view, string $source): void
    {
        $matches = [];
        preg_match_all('~(?<![\w$])route\s*\(\s*([\'"])([^\'"]+)\1~', $source, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[2] ?? [] as [$routeName, $offset]) {
            $line = $this->lineAt($source, $offset);

            if (! isset($this->routesByName[$routeName])) {
                $this->sample($this->unresolvedRoutes, [
                    'file' => $view['file'],
                    'line' => $line,
                    'routeName' => $routeName,
                ]);

                continue;
            }

            foreach ($this->routesByName[$routeName] as $routeId) {
                $graph->addEdge(new Edge($viewId, $routeId, 'consumes_route', 0.98, [
                    'routeName' => $routeName,
                    'line' => $line,
                    'syntax' => 'named_route_helper',
                    'matchCertainty' => 'exact',
                ]));
            }
        }
    }

    private function scanPhpRenderers(Graph $graph): void
    {
        $parsedFiles = [];

        foreach ($this->files->findPhpFiles(['app', 'routes']) as $file) {
            $statements = $this->parseFile($file, $graph);

            if ($statements !== null) {
                $parsedFiles[] = [
                    'statements' => $statements,
                    'file' => $this->files->relativePath($file) ?? $file,
                ];
            }
        }

        $finder = new NodeFinder();

        foreach ($parsedFiles as $parsedFile) {
            foreach ($finder->findInstanceOf($parsedFile['statements'], Stmt\ClassLike::class) as $classLike) {
                $name = $this->classLikeName($classLike);

                if ($name !== null) {
                    $this->classes[$name] = [
                        'extends' => $classLike instanceof Stmt\Class_
                            ? $this->resolvedName($classLike->extends)
                            : null,
                    ];
                }
            }

            foreach ($finder->findInstanceOf($parsedFile['statements'], Stmt\Function_::class) as $function) {
                $namespacedName = $function->namespacedName ?? null;
                $name = $namespacedName instanceof Name
                    ? $namespacedName->toString()
                    : $function->name->toString();
                $this->localFunctions[strtolower(ltrim($name, '\\'))] = true;
            }
        }

        foreach ($parsedFiles as $parsedFile) {
            foreach ($finder->findInstanceOf($parsedFile['statements'], Stmt\ClassLike::class) as $classLike) {
                $className = $this->classLikeName($classLike);

                if ($className === null || $classLike instanceof Stmt\Interface_) {
                    continue;
                }

                foreach ($classLike->getMethods() as $method) {
                    $this->collectMethodRenders($graph, $parsedFile['file'], $className, $method);
                }
            }

            $this->collectRouteRegistrations($graph, $parsedFile['file'], $parsedFile['statements']);
        }
    }

    private function classLikeName(Stmt\ClassLike $classLike): ?string
    {
        $namespacedName = $classLike->namespacedName ?? null;

        if ($namespacedName instanceof Name) {
            return $namespacedName->toString();
        }

        return $classLike->name?->toString();
    }

    private function collectMethodRenders(
        Graph $graph,
        string $file,
        string $className,
        Stmt\ClassMethod $method,
    ): void {
        if ($method->stmts === null) {
            return;
        }

        $references = [];
        $this->collectViewReferences($method->stmts, $file, $className, $references);

        if ($references === []) {
            return;
        }

        $methodId = $className.'::'.$method->name->toString();
        $graph->addNode($this->methodNode($className, $method->name->toString(), [
            'file' => $file,
            'line' => $method->getStartLine(),
            'endLine' => $method->getEndLine(),
            'signature' => $this->methodSignature($method),
            'inputs' => $this->methodInputs($method),
            'outputs' => $this->methodOutputs($method),
            'visibility' => $this->methodVisibility($method),
            'static' => $method->isStatic(),
        ]));

        foreach ($references as $reference) {
            $this->addRendersEdge($graph, $methodId, $file, $reference);
        }
    }

    /**
     * Recursively walks statements for Route facade registrations, carrying
     * the literal prefix stack of enclosing Route::group closures so URIs are
     * matched against the routes Laravel actually registered.
     *
     * @param array<int, AstNode|null> $nodes
     * @param array<int, string> $prefixes
     */
    private function collectRouteRegistrations(
        Graph $graph,
        string $file,
        array $nodes,
        array $prefixes = [],
        bool $prefixUnknown = false,
    ): void {
        foreach ($nodes as $node) {
            if ($node instanceof AstNode) {
                $this->collectRouteRegistrationsFromNode($graph, $file, $node, $prefixes, $prefixUnknown);
            }
        }
    }

    /** @param array<int, string> $prefixes */
    private function collectRouteRegistrationsFromNode(
        Graph $graph,
        string $file,
        AstNode $node,
        array $prefixes,
        bool $prefixUnknown,
    ): void {
        $chain = $this->routeChain($node);

        if ($chain !== null) {
            [$chainPrefixes, $chainUnknown] = $this->chainPrefixes($chain, $prefixes, $prefixUnknown);

            if ($chain['group'] !== null) {
                $body = $chain['group'] instanceof Expr\Closure
                    ? $chain['group']->stmts
                    : [$chain['group']->expr];
                $this->collectRouteRegistrations($graph, $file, $body, $chainPrefixes, $chainUnknown);

                return;
            }

            if ($chain['registration'] !== null) {
                $this->handleRouteRegistration(
                    $graph,
                    $file,
                    $chain['registration']['name'],
                    $chain['registration']['args'],
                    $chainPrefixes,
                    $chainUnknown,
                );

                return;
            }
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof AstNode) {
                $this->collectRouteRegistrationsFromNode($graph, $file, $value, $prefixes, $prefixUnknown);
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof AstNode) {
                        $this->collectRouteRegistrationsFromNode($graph, $file, $item, $prefixes, $prefixUnknown);
                    }
                }
            }
        }
    }

    /**
     * Describes a Route facade call chain such as
     * Route::prefix('admin')->middleware('auth')->group(fn () => ...) or
     * Route::get('/home', fn () => view('home'))->name('home').
     *
     * @return array{
     *     calls: array<int, array{name: string, args: array<int, AstNode\Arg|AstNode\VariadicPlaceholder>}>,
     *     group: Expr\Closure|Expr\ArrowFunction|null,
     *     registration: array{name: string, args: array<int, AstNode\Arg|AstNode\VariadicPlaceholder>}|null
     * }|null
     */
    private function routeChain(AstNode $node): ?array
    {
        if (! ($node instanceof Expr\StaticCall || $node instanceof Expr\MethodCall)) {
            return null;
        }

        $calls = [];
        $current = $node;

        while ($current instanceof Expr\MethodCall) {
            if (! $current->name instanceof Identifier) {
                return null;
            }

            $calls[] = ['name' => strtolower($current->name->toString()), 'args' => $current->args];
            $current = $current->var;
        }

        if (! $current instanceof Expr\StaticCall
            || ! $current->name instanceof Identifier
            || ! in_array($this->resolvedName($current->class), self::ROUTE_FACADES, true)) {
            return null;
        }

        $calls[] = ['name' => strtolower($current->name->toString()), 'args' => $current->args];
        $calls = array_reverse($calls);

        $group = null;
        $registration = null;

        foreach ($calls as $call) {
            if ($call['name'] === 'group') {
                $handler = null;

                foreach ($call['args'] as $arg) {
                    $value = $arg instanceof AstNode\Arg ? $arg->value : null;

                    if ($value instanceof Expr\Closure || $value instanceof Expr\ArrowFunction) {
                        $handler = $value;
                    }
                }

                if ($handler !== null) {
                    $group = $handler;
                }

                continue;
            }

            if ($registration === null
                && ($call['name'] === 'view'
                    || in_array($call['name'], self::ROUTE_REGISTRATION_METHODS, true))) {
                $registration = $call;
            }
        }

        return ['calls' => $calls, 'group' => $group, 'registration' => $registration];
    }

    /**
     * Folds literal prefix() calls and group(['prefix' => ...]) attributes into
     * the inherited prefix stack. Any non-literal prefix poisons exact URI
     * composition, downgrading matches to the unique-candidate path.
     *
     * @param array{calls: array<int, array{name: string, args: array<int, AstNode\Arg|AstNode\VariadicPlaceholder>}>} $chain
     * @param array<int, string> $prefixes
     * @return array{0: array<int, string>, 1: bool}
     */
    private function chainPrefixes(array $chain, array $prefixes, bool $prefixUnknown): array
    {
        foreach ($chain['calls'] as $call) {
            if ($call['name'] === 'prefix') {
                $prefix = $this->stringArgument($call['args'], 0, 'prefix');

                if ($prefix === null) {
                    $prefixUnknown = true;
                } elseif (trim($prefix, '/') !== '') {
                    $prefixes[] = trim($prefix, '/');
                }

                continue;
            }

            if ($call['name'] === 'group' && ($call['args'][0]->value ?? null) instanceof Expr\Array_) {
                foreach ($call['args'][0]->value->items as $item) {
                    if ($item->key instanceof String_ && $item->key->value === 'prefix') {
                        if ($item->value instanceof String_) {
                            if (trim($item->value->value, '/') !== '') {
                                $prefixes[] = trim($item->value->value, '/');
                            }
                        } else {
                            $prefixUnknown = true;
                        }
                    }
                }
            }
        }

        return [$prefixes, $prefixUnknown];
    }

    /**
     * @param array<int, AstNode\Arg|AstNode\VariadicPlaceholder> $args
     * @param array<int, string> $prefixes
     */
    private function handleRouteRegistration(
        Graph $graph,
        string $file,
        string $method,
        array $args,
        array $prefixes,
        bool $prefixUnknown,
    ): void {
        $uri = $this->stringArgument($args, 0, 'uri');

        if ($uri === null) {
            return;
        }

        $line = null;
        $references = [];

        if ($method === 'view') {
            $viewName = $this->stringArgument($args, 1, 'view');
            $viewArg = $args[1] ?? null;
            $line = $viewArg instanceof AstNode\Arg ? $viewArg->value->getStartLine() : null;

            if ($viewName === null) {
                if ($viewArg !== null) {
                    $this->sample($this->dynamicReferences, [
                        'file' => $file,
                        'line' => $line,
                        'syntax' => 'route_view',
                    ]);
                }

                return;
            }

            $references[] = [
                'view' => $viewName,
                'line' => $line,
                'syntax' => 'route_view',
                'confidence' => 0.95,
            ];
            $httpMethod = 'GET';
            $requiredAction = 'ViewController';
        } else {
            $handler = ($args[1] ?? null) instanceof AstNode\Arg ? $args[1]->value : null;

            if (! ($handler instanceof Expr\Closure || $handler instanceof Expr\ArrowFunction)) {
                return;
            }

            $body = $handler instanceof Expr\Closure ? $handler->stmts : [$handler->expr];
            $this->collectViewReferences($body, $file, null, $references);

            if ($references === []) {
                return;
            }

            $line = $handler->getStartLine();
            $httpMethod = $method === 'any' ? null : strtoupper($method);
            $requiredAction = 'Closure';
        }

        $match = $this->matchRegisteredRoutes($uri, $prefixes, $prefixUnknown, $httpMethod, $requiredAction);

        if ($match['ids'] === []) {
            $this->sample($this->unresolvedRoutes, array_filter([
                'file' => $file,
                'line' => $line,
                'routeUri' => $uri,
                'reason' => $match['ambiguous'] ? 'ambiguous_route_uri' : null,
            ], static fn (mixed $value): bool => $value !== null));

            return;
        }

        foreach ($match['ids'] as $routeId) {
            foreach ($references as $reference) {
                $confidence = $match['certainty'] === 'exact'
                    ? min($reference['confidence'], $method === 'view' ? 0.95 : 0.9)
                    : 0.7;
                $this->addRendersEdge($graph, $routeId, $file, [
                    ...$reference,
                    'syntax' => $method === 'view' ? 'route_view' : 'route_closure',
                    'confidence' => $confidence,
                    'matchCertainty' => $match['certainty'] === 'exact' ? null : 'possible',
                ]);
            }
        }
    }

    /**
     * Matches a registration literal against the routes the router actually
     * registered. Exact composed-URI matches win; otherwise a UNIQUE
     * action-compatible suffix candidate is accepted at reduced certainty and
     * anything ambiguous is reported, never guessed. The action check keeps a
     * grouped closure's literal from binding to a same-URI controller route.
     *
     * @param array<int, string> $prefixes
     * @return array{ids: array<int, string>, certainty: string, ambiguous: bool}
     */
    private function matchRegisteredRoutes(
        string $uri,
        array $prefixes,
        bool $prefixUnknown,
        ?string $httpMethod,
        string $requiredAction,
    ): array {
        if (! $prefixUnknown) {
            $composed = '/'.ltrim(implode('/', [...$prefixes, trim($uri, '/')]), '/');
            $composed = rtrim($composed, '/') === '' ? '/' : rtrim($composed, '/');
            $exact = array_values(array_filter(
                $this->routeIdsFor($composed, $httpMethod),
                fn (string $id): bool => $this->actionCompatible($id, $requiredAction),
            ));

            if ($exact !== []) {
                return ['ids' => $exact, 'certainty' => 'exact', 'ambiguous' => false];
            }
        }

        $suffix = '/'.trim($uri, '/');

        if ($suffix === '/') {
            // A bare "/" inside an unknown prefix would suffix-match every
            // route; the group root is only resolvable via the exact path.
            return ['ids' => [], 'certainty' => 'possible', 'ambiguous' => false];
        }

        $candidates = [];

        foreach ($this->routesByUri as $registeredUri => $byMethod) {
            if ($registeredUri !== $suffix && ! str_ends_with($registeredUri, $suffix)) {
                continue;
            }

            $ids = $httpMethod !== null
                ? ($byMethod[$httpMethod] ?? [])
                : array_merge(...array_values($byMethod));

            foreach ($ids as $id) {
                if ($this->actionCompatible($id, $requiredAction)) {
                    $candidates[$id] = true;
                }
            }
        }

        if (count($candidates) === 1) {
            return ['ids' => [array_key_first($candidates)], 'certainty' => 'possible', 'ambiguous' => false];
        }

        return ['ids' => [], 'certainty' => 'possible', 'ambiguous' => count($candidates) > 1];
    }

    private function actionCompatible(string $routeId, string $requiredAction): bool
    {
        $action = $this->routeActions[$routeId] ?? null;

        if ($action === null) {
            return true;
        }

        return str_contains($action, $requiredAction);
    }

    /**
     * Walks statements for statically resolvable view constructions and
     * appends {view, line, syntax, confidence} entries to $references.
     *
     * @param array<int, AstNode|null> $nodes
     * @param array<int, array<string, mixed>> $references
     */
    private function collectViewReferences(
        array $nodes,
        string $file,
        ?string $enclosingClass,
        array &$references,
    ): void {
        foreach ($nodes as $node) {
            if (! $node instanceof AstNode) {
                continue;
            }

            $this->collectViewReferencesFromNode($node, $file, $enclosingClass, $references);
        }
    }

    /** @param array<int, array<string, mixed>> $references */
    private function collectViewReferencesFromNode(
        AstNode $node,
        string $file,
        ?string $enclosingClass,
        array &$references,
    ): void {
        // Route facade chains register handlers rather than render views here;
        // their closures are attributed to route nodes by
        // collectRouteRegistrations, so descending would double-attribute
        // every view() inside a registered closure to the enclosing method.
        if (($node instanceof Expr\StaticCall || $node instanceof Expr\MethodCall)
            && ($chain = $this->routeChain($node)) !== null
            && ($chain['group'] !== null || $chain['registration'] !== null)) {
            return;
        }

        if ($node instanceof Expr\FuncCall) {
            $this->collectHelperReference($node, $file, $enclosingClass, $references);
        } elseif ($node instanceof Expr\StaticCall) {
            $this->collectFacadeReference($node, $file, $references);
        } elseif ($node instanceof Expr\MethodCall) {
            $this->collectMethodCallReference($node, $file, $enclosingClass, $references);
        } elseif ($node instanceof Expr\New_) {
            $this->collectMailContentReference($node, $references);
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof AstNode) {
                $this->collectViewReferencesFromNode($value, $file, $enclosingClass, $references);
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof AstNode) {
                        $this->collectViewReferencesFromNode($item, $file, $enclosingClass, $references);
                    }
                }
            }
        }
    }

    /** @param array<int, array<string, mixed>> $references */
    private function collectHelperReference(
        Expr\FuncCall $call,
        string $file,
        ?string $enclosingClass,
        array &$references,
    ): void {
        if ($this->resolvedHelperName($call, $enclosingClass) !== 'view' || $call->args === []) {
            return;
        }

        $viewName = $this->stringArgument($call->args, 0, 'view');

        if ($viewName === null) {
            $this->sampleDynamicArgument($call, $file, 'view_helper');

            return;
        }

        $references[] = [
            'view' => $viewName,
            'line' => $call->getStartLine(),
            'syntax' => 'view_helper',
            'confidence' => 0.95,
        ];
    }

    /** @param array<int, array<string, mixed>> $references */
    private function collectFacadeReference(Expr\StaticCall $call, string $file, array &$references): void
    {
        if (! $call->name instanceof Identifier
            || ! in_array($this->resolvedName($call->class), self::VIEW_FACADES, true)) {
            return;
        }

        $this->collectFactoryCall(
            strtolower($call->name->toString()),
            $call->args,
            $call->getStartLine(),
            $file,
            'view_facade',
            $references,
        );
    }

    /**
     * Shared handling for make()/first() on the View facade and the view()
     * helper factory.
     *
     * @param array<int, AstNode\Arg|AstNode\VariadicPlaceholder> $args
     * @param array<int, array<string, mixed>> $references
     */
    private function collectFactoryCall(
        string $methodName,
        array $args,
        int $line,
        string $file,
        string $syntaxPrefix,
        array &$references,
    ): void {
        if ($methodName === 'make') {
            $viewName = $this->stringArgument($args, 0, 'view');

            if ($viewName === null) {
                $this->sample($this->dynamicReferences, [
                    'file' => $file,
                    'line' => $line,
                    'syntax' => $syntaxPrefix,
                ]);

                return;
            }

            $references[] = [
                'view' => $viewName,
                'line' => $line,
                'syntax' => $syntaxPrefix,
                'confidence' => 0.95,
            ];

            return;
        }

        if ($methodName === 'first' && ($args[0] ?? null) instanceof AstNode\Arg && $args[0]->value instanceof Expr\Array_) {
            foreach ($args[0]->value->items as $item) {
                if ($item->value instanceof String_) {
                    $references[] = [
                        'view' => $item->value->value,
                        'line' => $line,
                        'syntax' => $syntaxPrefix.'_first',
                        'confidence' => 0.7,
                        'matchCertainty' => 'possible',
                    ];
                }
            }
        }
    }

    /** @param array<int, array<string, mixed>> $references */
    private function collectMethodCallReference(
        Expr\MethodCall $call,
        string $file,
        ?string $enclosingClass,
        array &$references,
    ): void {
        if (! $call->name instanceof Identifier) {
            return;
        }

        $methodName = strtolower($call->name->toString());

        // view()->make('x') / view()->first([...]) on the zero-argument factory.
        if ($call->var instanceof Expr\FuncCall
            && $call->var->args === []
            && in_array($methodName, ['make', 'first'], true)
            && $this->resolvedHelperName($call->var, $enclosingClass) === 'view') {
            $this->collectFactoryCall(
                $methodName,
                $call->args,
                $call->getStartLine(),
                $file,
                'view_factory',
                $references,
            );

            return;
        }

        // response()->view('x').
        if ($call->var instanceof Expr\FuncCall
            && $call->var->args === []
            && $methodName === 'view'
            && $this->resolvedHelperName($call->var, $enclosingClass) === 'response') {
            $viewName = $this->stringArgument($call->args, 0, 'view');

            if ($viewName !== null) {
                $references[] = [
                    'view' => $viewName,
                    'line' => $call->getStartLine(),
                    'syntax' => 'response_view',
                    'confidence' => 0.95,
                ];
            } else {
                $this->sampleDynamicArgument($call, $file, 'response_view');
            }

            return;
        }

        // $this->view('x') / $this->markdown('x') / $this->text('x') inside mailables.
        if ($call->var instanceof Expr\Variable
            && $call->var->name === 'this'
            && in_array($methodName, ['view', 'markdown', 'text'], true)
            && $enclosingClass !== null
            && in_array($this->ancestryRoot($enclosingClass), self::MAILABLE_ROOTS, true)) {
            $viewName = $this->stringArgument($call->args, 0, 'view');

            if ($viewName !== null) {
                $references[] = [
                    'view' => $viewName,
                    'line' => $call->getStartLine(),
                    'syntax' => 'mailable_'.$methodName,
                    'confidence' => 0.9,
                ];
            } else {
                $this->sampleDynamicArgument($call, $file, 'mailable_'.$methodName);
            }
        }
    }

    /** @param array<int, array<string, mixed>> $references */
    private function collectMailContentReference(Expr\New_ $new, array &$references): void
    {
        if ($this->resolvedName($new->class) !== self::MAIL_CONTENT_CLASS) {
            return;
        }

        foreach (self::MAIL_CONTENT_VIEW_PARAMETERS as $parameter => $position) {
            $viewName = $this->stringArgument($new->args, $position, $parameter);

            if ($viewName !== null) {
                $references[] = [
                    'view' => $viewName,
                    'line' => $new->getStartLine(),
                    'syntax' => 'mail_content',
                    'confidence' => 0.9,
                ];
            }
        }
    }

    /**
     * @param array<string, mixed> $reference {view, line, syntax, confidence, matchCertainty?}
     */
    private function addRendersEdge(Graph $graph, string $fromId, string $file, array $reference): void
    {
        $name = $this->normalizeViewName($reference['view']);

        if ($name === null) {
            return;
        }

        if (str_contains($name, '::')) {
            $this->sample($this->unresolvedViews, [
                'file' => $file,
                'line' => $reference['line'],
                'view' => $name,
                'reason' => 'namespaced_view',
            ]);

            return;
        }

        if (! isset($this->viewsByName[$name])) {
            $this->sample($this->unresolvedViews, [
                'file' => $file,
                'line' => $reference['line'],
                'view' => $name,
            ]);

            return;
        }

        $graph->addEdge(new Edge($fromId, 'view:'.$name, 'renders', $reference['confidence'], array_filter([
            'view' => $name,
            'line' => $reference['line'],
            'syntax' => $reference['syntax'],
            'matchCertainty' => $reference['matchCertainty'] ?? null,
        ], static fn (mixed $value): bool => $value !== null)));
    }

    /**
     * Template-to-template edges share the resolution and diagnostics rules of
     * renders edges but keep the Blade-specific edge type and certainty.
     */
    private function addTemplateEdge(
        Graph $graph,
        string $fromViewId,
        string $file,
        string $target,
        string $edgeType,
        float $confidence,
        string $syntax,
        int $line,
        ?string $matchCertainty = null,
    ): void {
        $name = $this->normalizeViewName($target);

        if ($name === null) {
            return;
        }

        if (str_contains($name, '::')) {
            $this->sample($this->unresolvedViews, [
                'file' => $file,
                'line' => $line,
                'view' => $name,
                'reason' => 'namespaced_view',
            ]);

            return;
        }

        if (! isset($this->viewsByName[$name])) {
            $this->sample($this->unresolvedViews, [
                'file' => $file,
                'line' => $line,
                'view' => $name,
            ]);

            return;
        }

        $graph->addEdge(new Edge($fromViewId, 'view:'.$name, $edgeType, $confidence, array_filter([
            'view' => $name,
            'line' => $line,
            'syntax' => $syntax,
            'matchCertainty' => $matchCertainty,
        ], static fn (mixed $value): bool => $value !== null)));
    }

    private function exportDiagnostics(Graph $graph): void
    {
        $analysis = array_filter([
            'templates' => count($this->viewsByName),
            'unresolvedViews' => $this->bucket($this->unresolvedViews),
            'unresolvedComponents' => $this->bucket($this->unresolvedComponents),
            'unresolvedRoutes' => $this->bucket($this->unresolvedRoutes),
            'dynamicReferences' => $this->bucket($this->dynamicReferences),
        ], static fn (mixed $value): bool => $value !== null);

        if ($analysis !== ['templates' => 0]) {
            $graph->addMeta(['analysis' => ['views' => $analysis]]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $samples
     * @return array{count: int, samples: array<int, array<string, mixed>>}|null
     */
    private function bucket(array $samples): ?array
    {
        if ($samples === []) {
            return null;
        }

        return [
            'count' => count($samples),
            'samples' => array_slice($samples, 0, self::MAX_SAMPLES),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $bucket
     * @param array<string, mixed> $sample
     */
    private function sample(array &$bucket, array $sample): void
    {
        if (count($bucket) < self::MAX_SAMPLES) {
            $bucket[] = $sample;
        } else {
            // Past the sample budget only the count remains meaningful.
            $bucket[] = ['file' => $sample['file'] ?? null];
        }
    }

    private function sampleDynamicArgument(Expr $call, string $file, string $syntax): void
    {
        // Recorded on the shared diagnostics channel rather than as an edge:
        // a dynamic view name cannot be resolved without guessing.
        $this->sample($this->dynamicReferences, [
            'file' => $file,
            'line' => $call->getStartLine(),
            'syntax' => $syntax,
        ]);
    }

    private function componentClass(string $component): ?string
    {
        $segments = array_map(
            static fn (string $segment): string => Str::studly($segment),
            explode('.', $component),
        );
        $relativeClass = implode('\\', $segments);
        $classFile = $this->files->absolutePath('app/View/Components/'.implode('/', $segments).'.php');

        if (! is_file($classFile)) {
            return null;
        }

        return $this->appNamespace.'View\\Components\\'.$relativeClass;
    }

    private function anonymousComponentView(string $component): ?string
    {
        $name = 'components.'.str_replace('/', '.', $component);

        if (isset($this->viewsByName[$name])) {
            return $name;
        }

        if (isset($this->viewsByName[$name.'.index'])) {
            return $name.'.index';
        }

        return null;
    }

    /** @return array<int, string> */
    private function routeIdsFor(string $uri, ?string $httpMethod): array
    {
        $uri = '/'.ltrim($uri, '/');
        $byMethod = $this->routesByUri[$uri] ?? [];

        if ($httpMethod !== null) {
            return $byMethod[$httpMethod] ?? [];
        }

        $ids = [];

        foreach ($byMethod as $routeIds) {
            $ids = [...$ids, ...$routeIds];
        }

        return array_values(array_unique($ids));
    }

    private function resolvedHelperName(Expr\FuncCall $call, ?string $enclosingClass): ?string
    {
        if (! $call->name instanceof Name) {
            return null;
        }

        if ($call->name->isFullyQualified()) {
            $target = strtolower(ltrim($call->name->toString(), '\\'));

            return isset($this->localFunctions[$target]) ? null : $target;
        }

        $resolvedName = $call->name->getAttribute('resolvedName');

        if ($resolvedName instanceof Name) {
            $target = strtolower(ltrim($resolvedName->toString(), '\\'));

            return isset($this->localFunctions[$target]) ? null : $target;
        }

        if (! $call->name->isUnqualified()) {
            return null;
        }

        $target = strtolower($call->name->toString());
        $namespace = $enclosingClass !== null ? $this->namespaceFromClass($enclosingClass) : null;
        $namespaced = strtolower(($namespace === null ? '' : $namespace.'\\').$target);

        if (isset($this->localFunctions[$namespaced]) || isset($this->localFunctions[$target])) {
            return null;
        }

        return $target;
    }

    private function ancestryRoot(string $class): string
    {
        $seen = [];

        while (isset($this->classes[$class]) && ! isset($seen[$class])) {
            $seen[$class] = true;
            $parent = $this->classes[$class]['extends'];

            if ($parent === null) {
                return $class;
            }

            $class = $parent;
        }

        return $class;
    }

    /**
     * @param array<int, AstNode\Arg|AstNode\VariadicPlaceholder> $args
     */
    private function stringArgument(array $args, ?int $position, string $parameterName): ?string
    {
        foreach ($args as $index => $arg) {
            if (! $arg instanceof AstNode\Arg || $arg->unpack) {
                continue;
            }

            $matchesPosition = $arg->name === null && $position !== null && $index === $position;
            $matchesName = $arg->name instanceof Identifier && $arg->name->toString() === $parameterName;

            if (($matchesPosition || $matchesName) && $arg->value instanceof String_) {
                return $arg->value->value;
            }
        }

        return null;
    }

    private function normalizeViewName(mixed $name): ?string
    {
        if (! is_string($name)) {
            return null;
        }

        $name = trim(str_replace('/', '.', $name), " \t\n\r.");

        if ($name === '' || strlen($name) > self::MAX_VIEW_NAME_BYTES || str_contains($name, "\0")) {
            return null;
        }

        return $name;
    }

    /** @param array<int, string> $paths */
    private function normalizeViewPaths(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            if (is_string($path) && trim($path) !== '') {
                // Lexically canonical so observed-template manifest keys match
                // publication's normalized source paths even when view.paths
                // carries '//' or '..' segments.
                $normalized[] = $this->files->canonicalPath($path);
            }
        }

        return array_values(array_unique($normalized));
    }

    private function lineAt(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }
}
