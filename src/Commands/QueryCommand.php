<?php

namespace AppGraph\Commands;

use AppGraph\Query\GraphIndex;
use AppGraph\Query\QueryEngine;
use AppGraph\Query\StalenessChecker;
use AppGraph\Query\UnresolvedTargetException;
use AppGraph\Support\FileFinder;
use AppGraph\Support\MemoryLimit;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class QueryCommand extends Command
{
    protected $signature = 'appgraph:query
        {query : One of: overview, context-for-task, node, search, flow-from, writes-to, reads-from, callers-of, calls-from, impact-of, routes-touching, models, tables}
        {target? : Node id, table name, table.column, Class::method, search term, or task description depending on the query}
        {--input= : Graph JSON path. Defaults to the configured scan output path.}
        {--limit= : Maximum results to return (default from config, 50).}
        {--depth= : Maximum traversal depth for context-for-task/callers-of/calls-from/impact-of/routes-touching (default 4).}
        {--min-confidence= : Drop results whose path confidence falls below this value.}
        {--context-target=* : Explicit graph target for context-for-task. Repeat for up to 10 targets.}
        {--changed-file=* : Changed project file for context-for-task. Repeat for up to 50 files.}
        {--token-budget= : Approximate source-reading token budget for context-for-task (512-16000, default 4000).}
        {--type= : Restrict search results to a node type.}
        {--full : Include node metadata in node output.}
        {--pretty : Pretty-print the JSON output.}';

    protected $description = 'Query the AppGraph knowledge graph without loading it into context.';

    private const TARGETLESS = ['overview', 'models', 'tables'];

    public function handle(): int
    {
        MemoryLimit::ensure(config('appgraph.memory_limit', '256M'));

        $query = (string) $this->argument('query');
        $target = $this->argument('target');

        if (! in_array($query, ['overview', 'context-for-task', 'node', 'search', 'flow-from', 'writes-to', 'reads-from', 'callers-of', 'calls-from', 'impact-of', 'routes-touching', 'models', 'tables'], true)) {
            return $this->failWith("Unknown query [{$query}].");
        }

        if ($query === 'context-for-task' && (! is_string($target) || trim($target) === '')) {
            return $this->failWith('The [context-for-task] query requires a nonblank task description.');
        }

        if (! in_array($query, self::TARGETLESS, true) && (! is_string($target) || $target === '')) {
            return $this->failWith("The [{$query}] query requires a target argument.");
        }

        try {
            $engine = new QueryEngine(
                GraphIndex::load($this->inputPath()),
                new StalenessChecker(new FileFinder())
            );

            $limit = $this->intOption('limit', (int) config('appgraph.query.limit', 50));
            $contextDepth = max(1, min(6, (int) config('appgraph.query.context.depth', 4)));
            $contextMinConfidence = max(0.0, min(
                1.0,
                (float) config('appgraph.query.context.min_confidence', 0.0),
            ));
            $contextTokenBudget = max(512, min(
                16000,
                (int) config('appgraph.query.context.token_budget', 4000),
            ));
            $depth = $query === 'context-for-task'
                ? $this->boundedIntegerOption('depth', $contextDepth, 1, 6)
                : $this->intOption('depth', (int) config('appgraph.query.depth', 4));
            $minConfidence = $query === 'context-for-task'
                ? $this->boundedFloatOption('min-confidence', $contextMinConfidence, 0.0, 1.0)
                : (float) $this->option('min-confidence');
            $contextTask = null;
            $contextTargets = [];
            $changedFiles = [];
            $tokenBudget = $contextTokenBudget;

            if ($query === 'context-for-task') {
                $contextTask = $this->taskDescription($target);
                $contextTargets = $this->stringListOption('context-target', 10, 512);
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
                'search' => $engine->search($target, $this->stringOption('type'), $limit),
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
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

        if ((bool) $this->option('pretty')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return (string) json_encode($payload, $flags);
    }

    private function inputPath(): string
    {
        $input = $this->option('input');

        if (is_string($input) && $input !== '') {
            return $this->isAbsolutePath($input) ? $input : base_path($input);
        }

        return storage_path(config('appgraph.output_path', 'appgraph/appgraph.json'));
    }

    private function intOption(string $name, int $default): int
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? max(1, (int) $value) : $default;
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

            if (mb_strlen($value) > $maximumLength) {
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

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Z]:[\/\\\\]/i', $path) === 1;
    }
}
