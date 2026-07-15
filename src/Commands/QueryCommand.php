<?php

namespace AppGraph\Commands;

use AppGraph\Query\GraphIndex;
use AppGraph\Query\QueryEngine;
use AppGraph\Query\StalenessChecker;
use AppGraph\Query\UnresolvedTargetException;
use AppGraph\Support\FileFinder;
use AppGraph\Support\MemoryLimit;
use Illuminate\Console\Command;
use RuntimeException;

class QueryCommand extends Command
{
    protected $signature = 'appgraph:query
        {query : One of: overview, node, search, flow-from, writes-to, reads-from, callers-of, calls-from, impact-of, routes-touching, models, tables}
        {target? : Node id, table name, table.column, Class::method, or search term depending on the query}
        {--input= : Graph JSON path. Defaults to the configured scan output path.}
        {--limit= : Maximum results to return (default from config, 50).}
        {--depth= : Maximum traversal depth for callers-of/calls-from/impact-of/routes-touching (default from config, 4).}
        {--min-confidence=0 : Drop results whose path confidence falls below this value.}
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

        if (! in_array($query, ['overview', 'node', 'search', 'flow-from', 'writes-to', 'reads-from', 'callers-of', 'calls-from', 'impact-of', 'routes-touching', 'models', 'tables'], true)) {
            return $this->failWith("Unknown query [{$query}].");
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
            $depth = $this->intOption('depth', (int) config('appgraph.query.depth', 4));
            $minConfidence = (float) $this->option('min-confidence');

            $payload = match ($query) {
                'overview' => $engine->overview(),
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
        } catch (RuntimeException $exception) {
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
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

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
