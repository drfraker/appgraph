<?php

namespace AppGraph\Commands;

use AppGraph\Query\GraphIndex;
use AppGraph\Query\QueryEngine;
use AppGraph\Query\StalenessChecker;
use AppGraph\Query\UnresolvedTargetException;
use AppGraph\Storage\ChangeVerifier;
use AppGraph\Storage\GenerationDiffer;
use AppGraph\Storage\GraphStore;
use AppGraph\Support\AgentPayloadLimiter;
use AppGraph\Support\MemoryLimit;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class QueryCommand extends Command
{
    protected $signature = 'appgraph:query
        {query : One of: overview, context-for-task, node, search, flow-from, writes-to, reads-from, callers-of, calls-from, impact-of, routes-touching, models, tables, generations, diff, verify-change}
        {target? : Node id, table name, table.column, Class::method, search term, or task description depending on the query}
        {--input= : Graph JSON path. Defaults to the configured scan output path.}
        {--limit= : Maximum results; for diff it globally bounds details, while verification applies it independently to details and findings.}
        {--depth= : Maximum traversal depth for context-for-task/callers-of/calls-from/impact-of/routes-touching (default 4).}
        {--min-confidence= : Drop results whose path confidence falls below this value.}
        {--context-target=* : Explicit graph target for context-for-task or verification scope. Repeat for up to 10 targets.}
        {--changed-file=* : Changed project file for context-for-task or verification scope. Repeat for up to 50 files.}
        {--from-generation= : Required positive numeric baseline generation id for diff and verify-change.}
        {--to-generation= : Positive numeric comparison generation id for diff and verify-change (default current).}
        {--before-generation= : Positive numeric cutoff for older-generation pagination; need not still be retained.}
        {--category=* : Diff category: nodes, edges, routes, writes, authorization, queues, or tests.}
        {--token-budget= : Approximate source-reading token budget for context-for-task (512-16000, default 4000).}
        {--type= : Restrict search results to a node type.}
        {--full : Include node metadata in node output.}
        {--pretty : Pretty-print the JSON output.}';

    protected $description = 'Query the AppGraph knowledge graph without loading it into context.';

    private const TARGETLESS = ['overview', 'models', 'tables', 'generations', 'diff', 'verify-change'];

    public function handle(
        GraphStore $store,
        GenerationDiffer $differ,
        ChangeVerifier $verifier,
        StalenessChecker $staleness,
    ): int
    {
        MemoryLimit::ensure(config('appgraph.memory_limit', '256M'));

        $query = (string) $this->argument('query');
        $target = $this->argument('target');

        if (! in_array($query, ['overview', 'context-for-task', 'node', 'search', 'flow-from', 'writes-to', 'reads-from', 'callers-of', 'calls-from', 'impact-of', 'routes-touching', 'models', 'tables', 'generations', 'diff', 'verify-change'], true)) {
            return $this->failWith("Unknown query [{$query}].");
        }

        if ($query === 'context-for-task' && (! is_string($target) || trim($target) === '')) {
            return $this->failWith('The [context-for-task] query requires a nonblank task description.');
        }

        if ($query === 'search' && (! is_string($target) || trim($target) === '')) {
            return $this->failWith('The [search] query requires a nonblank search term.');
        }

        if (! in_array($query, self::TARGETLESS, true) && (! is_string($target) || $target === '')) {
            return $this->failWith("The [{$query}] query requires a target argument.");
        }

        if (in_array($query, self::TARGETLESS, true) && is_string($target) && $target !== '') {
            return $this->failWith("The [{$query}] query does not accept a target argument.");
        }

        try {
            $this->validateGenerationOptionScope($query);

            if (in_array($query, ['generations', 'diff', 'verify-change'], true)) {
                if (is_string($this->option('input')) && $this->option('input') !== '') {
                    throw new RuntimeException('Historical AppGraph queries require the SQLite store and cannot use --input JSON.');
                }

                $payload = match ($query) {
                    'generations' => ['query' => 'generations'] + $store->generations(
                        $this->boundedIntegerOption('limit', 20, 1, 100),
                        $this->optionalNumericGenerationOption('before-generation'),
                    ),
                    'diff' => $differ->diff(
                        $this->requiredNumericGenerationOption('from-generation'),
                        $this->optionalNumericGenerationOption('to-generation') ?? 'current',
                        $this->categoryOptions(),
                        $this->boundedIntegerOption('limit', 50, 1, 200),
                    ),
                    'verify-change' => $verifier->verify(
                        $this->requiredNumericGenerationOption('from-generation'),
                        $this->optionalNumericGenerationOption('to-generation') ?? 'current',
                        $this->stringListOption('context-target', 10, 4096),
                        $this->stringListOption('changed-file', 50, 1024),
                        $this->boundedIntegerOption('depth', 4, 1, 6),
                        $this->boundedFloatOption('min-confidence', 0.0, 0.0, 1.0),
                        $this->boundedIntegerOption('limit', 50, 1, 200),
                    ),
                };

                $this->line($this->encode($payload));

                return self::SUCCESS;
            }

            if ($query === 'search'
                && (! is_string($this->option('input')) || $this->option('input') === '')
                && $store->hasCurrent()) {
                $payload = $this->storedSearchPayload(
                    $store,
                    $this->searchTerm($target),
                    $this->searchType($this->option('type')),
                    $this->boundedIntegerOption(
                        'limit',
                        max(1, min(200, (int) config('appgraph.query.limit', 50))),
                        1,
                        200,
                    ),
                );
                $this->line($this->encode($payload));

                return self::SUCCESS;
            }

            $engine = new QueryEngine(
                $this->graphIndex($store),
                $staleness,
            );

            $limit = $this->boundedIntegerOption(
                'limit',
                max(1, min(200, (int) config('appgraph.query.limit', 50))),
                1,
                200,
            );
            $contextDepth = max(1, min(6, (int) config('appgraph.query.context.depth', 4)));
            $contextMinConfidence = max(0.0, min(
                1.0,
                (float) config('appgraph.query.context.min_confidence', 0.0),
            ));
            $contextTokenBudget = max(512, min(
                16000,
                (int) config('appgraph.query.context.token_budget', 4000),
            ));
            $depth = $this->boundedIntegerOption(
                'depth',
                $query === 'context-for-task'
                    ? $contextDepth
                    : max(1, min(6, (int) config('appgraph.query.depth', 4))),
                1,
                6,
            );
            $minConfidence = $this->boundedFloatOption(
                'min-confidence',
                $query === 'context-for-task' ? $contextMinConfidence : 0.0,
                0.0,
                1.0,
            );
            $contextTask = null;
            $contextTargets = [];
            $changedFiles = [];
            $tokenBudget = $contextTokenBudget;

            if ($query === 'context-for-task') {
                $contextTask = $this->taskDescription($target);
                $contextTargets = $this->stringListOption('context-target', 10, 4096);
                $changedFiles = $this->stringListOption('changed-file', 50, 1024);
                $tokenBudget = $this->boundedIntegerOption(
                    'token-budget',
                    $contextTokenBudget,
                    512,
                    16000,
                );
            }

            $payload = match ($query) {
                'overview' => $engine->overview(),
                'context-for-task' => $engine->contextForTask(
                    $contextTask,
                    $contextTargets,
                    $changedFiles,
                    $tokenBudget,
                    $depth,
                    $minConfidence,
                ),
                'node' => $engine->node($target, $limit, (bool) $this->option('full')),
                'search' => $engine->search($this->searchTerm($target), $this->searchType($this->option('type')), $limit),
                'writes-to' => $engine->writesTo($target, $limit),
                'reads-from' => $engine->readsFrom($target, $limit),
                'flow-from' => $engine->flowFrom($target, $depth, $limit, $minConfidence),
                'callers-of' => $engine->callersOf($target, $depth, $limit, $minConfidence),
                'calls-from' => $engine->callsFrom($target, $depth, $limit, $minConfidence),
                'impact-of' => $engine->impactOf($target, $depth, $limit, $minConfidence),
                'routes-touching' => $engine->routesTouching($target, $depth, $limit, $minConfidence),
                'models' => $engine->models($limit),
                'tables' => $engine->tables($limit),
            };
        } catch (UnresolvedTargetException $exception) {
            return $this->failWith($exception->getMessage(), $exception->candidates);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return $this->failWith($exception->getMessage());
        }

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }

    /**
     * @param array<int, string> $candidates
     */
    private function failWith(string $message, array $candidates = []): int
    {
        $this->line($this->encode(array_filter([
            'error' => $message,
            'candidates' => $candidates,
        ], static fn ($value): bool => $value !== [])));

        return self::FAILURE;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return AgentPayloadLimiter::encode($payload, (bool) $this->option('pretty'));
    }

    private function inputPath(): string
    {
        $input = $this->option('input');

        if (is_string($input) && $input !== '') {
            return $this->isAbsolutePath($input) ? $input : base_path($input);
        }

        return storage_path(config('appgraph.output_path', 'appgraph/appgraph.json'));
    }

    private function graphIndex(GraphStore $store): GraphIndex
    {
        $input = $this->option('input');

        if (is_string($input) && $input !== '') {
            return GraphIndex::load($this->inputPath());
        }

        if ($store->hasCurrent()) {
            return GraphIndex::loadStore($store);
        }

        return GraphIndex::load($this->inputPath());
    }

    /** @return array<string, mixed> */
    private function storedSearchPayload(GraphStore $store, string $term, ?string $type, int $limit): array
    {
        $search = $store->searchNodes($term, $type, $limit);
        $generation = $search['generation'];
        $payload = [
            'query' => 'search',
            'target' => $term,
            'generation' => $generation,
            'generatedAt' => $generation['generatedAt'],
            'results' => array_map(
                static fn (array $node): array => array_filter([
                    'id' => $node['id'],
                    'type' => $node['type'],
                    'label' => $node['label'] ?? null,
                    'file' => $node['file'] ?? null,
                    'line' => $node['line'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
                $search['results'],
            ),
            'searchBackend' => $search['fts'],
        ];
        $timestamp = strtotime((string) $generation['generatedAt']);

        if ($timestamp !== false) {
            $payload['graphAgeSeconds'] = max(0, time() - $timestamp);
        }

        if ($search['truncated']) {
            $payload['truncated'] = true;
        }

        return $payload;
    }

    private function boundedIntegerOption(string $name, int $default, int $minimum, int $maximum): int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_string($value) || preg_match('/^\d+$/D', $value) !== 1) {
            throw new RuntimeException("The [--{$name}] option must be an integer between {$minimum} and {$maximum}.");
        }

        $integer = (int) $value;

        if ($integer < $minimum || $integer > $maximum) {
            throw new RuntimeException("The [--{$name}] option must be an integer between {$minimum} and {$maximum}.");
        }

        return $integer;
    }

    private function boundedFloatOption(string $name, float $default, float $minimum, float $maximum): float
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_string($value) || ! is_numeric($value)) {
            throw new RuntimeException("The [--{$name}] option must be a number between {$minimum} and {$maximum}.");
        }

        $number = (float) $value;

        if (! is_finite($number) || $number < $minimum || $number > $maximum) {
            throw new RuntimeException("The [--{$name}] option must be a number between {$minimum} and {$maximum}.");
        }

        return $number;
    }

    /**
     * @return array<int, string>
     */
    private function stringListOption(string $name, int $maximumItems, int $maximumLength): array
    {
        $values = $this->option($name);

        if (! is_array($values)) {
            throw new RuntimeException("The [--{$name}] option must be repeated once per value.");
        }

        if (count($values) > $maximumItems) {
            throw new RuntimeException("The [--{$name}] option may be repeated at most {$maximumItems} times.");
        }

        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException("Each [--{$name}] value must be a nonblank string.");
            }

            $value = trim($value);

            if (str_contains($value, "\0")
                || mb_strlen($value) > $maximumLength
                || strlen($value) > $maximumLength * 4) {
                throw new RuntimeException("Each [--{$name}] value must not exceed {$maximumLength} characters.");
            }

            if (in_array($value, $normalized, true)) {
                throw new RuntimeException("Each [--{$name}] value must be distinct.");
            }

            $normalized[] = $value;
        }

        return $normalized;
    }

    private function taskDescription(mixed $value): string
    {
        $task = is_string($value) ? trim($value) : '';

        if ($task === '') {
            throw new RuntimeException('The [context-for-task] query requires a nonblank task description.');
        }

        if (mb_strlen($task) > 4000) {
            throw new RuntimeException('The [context-for-task] task description must not exceed 4000 characters.');
        }

        return $task;
    }

    private function searchTerm(mixed $value): string
    {
        $term = is_string($value) ? trim($value) : '';

        if ($term === '' || str_contains($term, "\0")) {
            throw new RuntimeException('The [search] query requires a nonblank search term without NUL bytes.');
        }

        if (mb_strlen($term) > 512 || strlen($term) > 2048) {
            throw new RuntimeException('The [search] query term must not exceed 512 characters or 2048 bytes.');
        }

        return $term;
    }

    private function searchType(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $type = is_string($value) ? trim($value) : '';

        if ($type === '' || str_contains($type, "\0")) {
            throw new RuntimeException('The [--type] option must be a nonblank node type without NUL bytes.');
        }

        if (mb_strlen($type) > 128 || strlen($type) > 512) {
            throw new RuntimeException('The [--type] option must not exceed 128 characters or 512 bytes.');
        }

        return $type;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function requiredNumericGenerationOption(string $name): string
    {
        $value = $this->optionalNumericGenerationOption($name);

        if ($value === null) {
            throw new RuntimeException("The [--{$name}] option is required.");
        }

        return $value;
    }

    private function optionalNumericGenerationOption(string $name): ?string
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        $value = is_string($value) ? trim($value) : '';

        if (strlen($value) > 19 || preg_match('/^[1-9]\d*$/D', $value) !== 1) {
            throw new RuntimeException("The [--{$name}] option must be a positive numeric generation id.");
        }

        return $value;
    }

    /** @return array<int, string> */
    private function categoryOptions(): array
    {
        $categories = $this->option('category');

        if (! is_array($categories) || count($categories) > 7) {
            throw new RuntimeException('The [--category] option may be repeated at most 7 times.');
        }

        $allowed = ['nodes', 'edges', 'routes', 'writes', 'authorization', 'queues', 'tests'];
        $normalized = [];

        foreach ($categories as $category) {
            if (! is_string($category) || ! in_array($category, $allowed, true)) {
                throw new RuntimeException('Each [--category] value must be one of: '.implode(', ', $allowed).'.');
            }

            if (in_array($category, $normalized, true)) {
                throw new RuntimeException('Each [--category] value must be distinct.');
            }

            $normalized[] = $category;
        }

        return $normalized;
    }

    private function validateGenerationOptionScope(string $query): void
    {
        $allowed = [
            'limit' => [
                'node',
                'search',
                'flow-from',
                'writes-to',
                'reads-from',
                'callers-of',
                'calls-from',
                'impact-of',
                'routes-touching',
                'models',
                'tables',
                'generations',
                'diff',
                'verify-change',
            ],
            'from-generation' => ['diff', 'verify-change'],
            'to-generation' => ['diff', 'verify-change'],
            'before-generation' => ['generations'],
            'category' => ['diff'],
            'context-target' => ['context-for-task', 'verify-change'],
            'changed-file' => ['context-for-task', 'verify-change'],
            'token-budget' => ['context-for-task'],
            'type' => ['search'],
            'full' => ['node'],
            'depth' => [
                'context-for-task',
                'flow-from',
                'callers-of',
                'calls-from',
                'impact-of',
                'routes-touching',
                'verify-change',
            ],
            'min-confidence' => [
                'context-for-task',
                'flow-from',
                'callers-of',
                'calls-from',
                'impact-of',
                'routes-touching',
                'verify-change',
            ],
        ];

        foreach ($allowed as $option => $queries) {
            $value = $this->option($option);
            $provided = is_array($value)
                ? $value !== []
                : (is_bool($value) ? $value : $value !== null && $value !== '');

            if ($provided && ! in_array($query, $queries, true)) {
                throw new RuntimeException(
                    "The [--{$option}] option is not valid for the [{$query}] query."
                );
            }
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Z]:[\/\\\\]/i', $path) === 1;
    }
}
