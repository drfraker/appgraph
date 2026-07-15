<?php

namespace AppGraph\Scanners;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Support\SchemaDumpParser;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;

class DatabaseSchemaScanner
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $dumpMetadata = [];

    public function __construct(
        private SchemaDumpParser $dumpParser,
        private Filesystem $files,
    ) {
    }

    public function scan(Graph $graph, ?string $connectionName = null): Graph
    {
        $connectionName ??= config('appgraph.database.connection');

        if (config('appgraph.database.source', 'dump') === 'dump') {
            $this->scanFromSchemaDumps($graph, is_string($connectionName) ? $connectionName : null);

            return $graph;
        }

        return $this->scanLiveConnection($graph, is_string($connectionName) ? $connectionName : null);
    }

    private function scanLiveConnection(Graph $graph, ?string $connectionName = null): Graph
    {
        $connection = DB::connection($connectionName);
        $connectionName = $connection->getName();
        $driver = $connection->getDriverName();

        foreach ($this->tables($connection) as $table) {
            $tableName = $table['name'];
            $tableId = 'table:'.$tableName;

            $graph->addNode(Node::make($tableId, 'table', $tableName, [
                'metadata' => [
                    'connection' => $connectionName,
                    'driver' => $driver,
                    'schema' => $table['schema'] ?? null,
                ],
            ]));

            foreach ($this->columns($connection, $tableName) as $column) {
                $columnId = 'column:'.$tableName.'.'.$column['name'];

                $graph->addNode(Node::make($columnId, 'column', $tableName.'.'.$column['name'], [
                    'metadata' => $column + [
                        'table' => $tableName,
                        'connection' => $connectionName,
                    ],
                ]));

                $graph->addEdge(new Edge($tableId, $columnId, 'has_column'));
            }

            foreach ($this->indexes($connection, $tableName) as $index) {
                $indexId = 'index:'.$tableName.'.'.$index['name'];

                $graph->addNode(Node::make($indexId, 'index', $index['name'], [
                    'metadata' => $index + [
                        'table' => $tableName,
                        'connection' => $connectionName,
                    ],
                ]));

                $graph->addEdge(new Edge($tableId, $indexId, 'has_index'));
            }

            foreach ($this->foreignKeys($connection, $tableName) as $foreignKey) {
                $foreignKeyId = 'foreign_key:'.$tableName.'.'.($foreignKey['name'] ?: implode('_', $foreignKey['columns']).'_foreign');

                $graph->addNode(Node::make($foreignKeyId, 'foreign_key', $foreignKey['name'] ?: $tableName.'.'.implode(',', $foreignKey['columns']), [
                    'metadata' => $foreignKey + [
                        'table' => $tableName,
                        'connection' => $connectionName,
                    ],
                ]));

                $graph->addEdge(new Edge($tableId, $foreignKeyId, 'has_foreign_key'));
            }
        }

        return $graph;
    }

    private function scanFromSchemaDumps(Graph $graph, ?string $connectionName = null): void
    {
        $this->dumpMetadata = [];

        if ((bool) config('appgraph.database.dump.run', true)) {
            $this->runSchemaDumps($connectionName);
        }

        $paths = $this->discoverSchemaDumpFiles();

        if ($paths === []) {
            throw new \RuntimeException('No schema dump files were found to parse.');
        }

        $parsedTableCount = 0;

        foreach ($paths as $path) {
            $sql = $this->files->get($path);
            $metadata = $this->dumpMetadata[$path] ?? [
                'source' => 'schema_dump_file',
                'path' => $path,
                'relativePath' => $this->relativePath($path),
                'connection' => null,
                'driver' => $this->guessDriverFromSql($sql),
                'schema' => null,
                'context' => null,
            ];

            // Register the full source descriptor once in meta.sources, keyed by a stable
            // id. Nodes then reference it by id instead of embedding the whole blob, which
            // removes the largest single source of duplication in the export.
            $graph->addMeta(['sources' => [$this->schemaSourceKey($metadata) => $metadata]]);

            $tables = $this->dumpParser->parse($sql, $metadata['driver'] ?? 'unknown');

            foreach ($tables as $table) {
                $this->addParsedDumpTable($graph, $table, $metadata);
                $parsedTableCount++;
            }
        }

        if ($parsedTableCount === 0) {
            throw new \RuntimeException('Schema dump files were found, but no tables could be parsed.');
        }
    }

    private function runSchemaDumps(?string $connectionName = null): void
    {
        if ($this->stanclTenancyIsAvailable()) {
            $this->runStanclSchemaDumps($connectionName);

            return;
        }

        $this->dumpConnection(DB::connection($connectionName), 'default');
    }

    private function runStanclSchemaDumps(?string $connectionName = null): void
    {
        if ((bool) config('appgraph.database.dump.include_central', true)) {
            $centralConnection = $connectionName
                ?? config('tenancy.database.central_connection')
                ?? config('database.default');

            $this->dumpConnection(DB::connection($centralConnection), 'central');
        }

        if (! (bool) config('appgraph.database.dump.include_tenants', true)) {
            return;
        }

        $tenantModel = config('tenancy.tenant_model');

        if (! is_string($tenantModel) || ! class_exists($tenantModel) || ! method_exists($tenantModel, 'query')) {
            return;
        }

        $tenantIds = array_filter((array) config('appgraph.database.dump.tenant_ids', []));
        $query = $tenantModel::query();

        if ($tenantIds !== []) {
            $query->whereKey($tenantIds);
        }

        foreach ($query->cursor() as $tenant) {
            $callback = function () use ($tenant): void {
                $tenantId = method_exists($tenant, 'getTenantKey')
                    ? (string) $tenant->getTenantKey()
                    : (string) ($tenant->getKey() ?? 'tenant');

                $this->dumpConnection(DB::connection(config('database.default')), 'tenant-'.$tenantId, [
                    'tenantId' => $tenantId,
                ]);
            };

            if (method_exists($tenant, 'run')) {
                $tenant->run($callback);
                continue;
            }

            if (function_exists('tenancy')) {
                tenancy()->initialize($tenant);

                try {
                    $callback();
                } finally {
                    tenancy()->end();
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $extraMetadata
     */
    private function dumpConnection(Connection $connection, string $context, array $extraMetadata = []): string
    {
        $path = $this->schemaDumpPath($connection, $context);

        $this->files->ensureDirectoryExists(dirname($path));

        $state = $connection->getSchemaState()
            ->withMigrationTable($this->migrationTable())
            ->handleOutputUsing(function (): void {
                //
            });

        $state->dump($connection, $path);

        $metadata = [
            'source' => 'schema_dump',
            'path' => $path,
            'relativePath' => $this->relativePath($path),
            'connection' => $connection->getName(),
            'driver' => $connection->getDriverName(),
            'schema' => $connection->getDatabaseName(),
            'context' => $context,
        ] + $extraMetadata;

        $this->dumpMetadata[$path] = $metadata;

        return $path;
    }

    private function schemaDumpPath(Connection $connection, string $context): string
    {
        $configuredPath = config('appgraph.database.dump_path');

        if (is_string($configuredPath) && $configuredPath !== '') {
            return $this->absolutePath(strtr($configuredPath, [
                '{connection}' => $connection->getName(),
                '{database}' => $this->sanitizePathSegment($connection->getDatabaseName()),
                '{context}' => $this->sanitizePathSegment($context),
            ]));
        }

        if ($context === 'default' || $context === 'central') {
            return database_path('schema/'.$connection->getName().'-schema.sql');
        }

        return database_path('schema/'.$this->sanitizePathSegment($context).'-schema.sql');
    }

    /**
     * @return array<int, string>
     */
    private function discoverSchemaDumpFiles(): array
    {
        $paths = array_keys($this->dumpMetadata);

        foreach ((array) config('appgraph.database.dump.search_paths', [database_path('schema')]) as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $path = $this->absolutePath($path);

            if (is_file($path) && str_ends_with($path, '.sql')) {
                $paths[] = $path;
                continue;
            }

            if (! is_dir($path)) {
                continue;
            }

            foreach ($this->files->allFiles($path) as $file) {
                if ($file->getExtension() === 'sql') {
                    $paths[] = $file->getPathname();
                }
            }
        }

        $paths = array_values(array_unique(array_map(fn (string $path): string => str_replace('\\', '/', $path), $paths)));
        sort($paths);

        return $paths;
    }

    /**
     * @param array{name: string, columns: array<int, array<string, mixed>>, indexes: array<int, array<string, mixed>>, foreignKeys: array<int, array<string, mixed>>, metadata: array<string, mixed>} $table
     * @param array<string, mixed> $metadata
     */
    private function addParsedDumpTable(Graph $graph, array $table, array $metadata): void
    {
        $tableName = $table['name'];
        $tableId = 'table:'.$tableName;
        $sourceKey = $this->schemaSourceKey($metadata);

        $graph->addNode(Node::make($tableId, 'table', $tableName, [
            'metadata' => [
                'connection' => $metadata['connection'],
                'driver' => $metadata['driver'],
                'schema' => $metadata['schema'],
                'sources' => [$sourceKey],
            ],
        ]));

        foreach ($table['columns'] as $column) {
            $columnId = 'column:'.$tableName.'.'.$column['name'];

            $graph->addNode(Node::make($columnId, 'column', $tableName.'.'.$column['name'], [
                'metadata' => $column + [
                    'table' => $tableName,
                    'connection' => $metadata['connection'],
                    'sources' => [$sourceKey],
                ],
            ]));

            $graph->addEdge(new Edge($tableId, $columnId, 'has_column'));
        }

        foreach ($table['indexes'] as $index) {
            $indexId = 'index:'.$tableName.'.'.$index['name'];

            $graph->addNode(Node::make($indexId, 'index', $index['name'], [
                'metadata' => $index + [
                    'table' => $tableName,
                    'connection' => $metadata['connection'],
                    'sources' => [$sourceKey],
                ],
            ]));

            $graph->addEdge(new Edge($tableId, $indexId, 'has_index'));
        }

        foreach ($table['foreignKeys'] as $foreignKey) {
            $foreignKeyId = 'foreign_key:'.$tableName.'.'.($foreignKey['name'] ?: implode('_', $foreignKey['columns']).'_foreign');

            $graph->addNode(Node::make($foreignKeyId, 'foreign_key', $foreignKey['name'] ?: $tableName.'.'.implode(',', $foreignKey['columns']), [
                'metadata' => $foreignKey + [
                    'table' => $tableName,
                    'connection' => $metadata['connection'],
                    'sources' => [$sourceKey],
                ],
            ]));

            $graph->addEdge(new Edge($tableId, $foreignKeyId, 'has_foreign_key'));
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function schemaSourceKey(array $metadata): string
    {
        return $this->sanitizePathSegment((string) ($metadata['context'] ?? $metadata['relativePath'] ?? $metadata['path'] ?? 'schema'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tables(ConnectionInterface $connection): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $table): ?array => $this->normalizeTable($table),
            $connection->getSchemaBuilder()->getTables()
        )));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function columns(ConnectionInterface $connection, string $table): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $column): ?array => $this->normalizeColumn($column),
            $connection->getSchemaBuilder()->getColumns($table)
        )));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function indexes(ConnectionInterface $connection, string $table): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $index): ?array => $this->normalizeIndex($index),
            $connection->getSchemaBuilder()->getIndexes($table)
        )));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function foreignKeys(ConnectionInterface $connection, string $table): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $foreignKey): ?array => $this->normalizeForeignKey($foreignKey),
            $connection->getSchemaBuilder()->getForeignKeys($table)
        )));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeTable(mixed $table): ?array
    {
        if (is_string($table)) {
            return ['name' => $table];
        }

        $table = (array) $table;
        $name = $table['name'] ?? $table['table'] ?? $table['TABLE_NAME'] ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        return [
            'name' => $name,
            'schema' => $table['schema'] ?? $table['TABLE_SCHEMA'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeColumn(mixed $column): ?array
    {
        $column = (array) $column;
        $name = $column['name'] ?? $column['column_name'] ?? $column['COLUMN_NAME'] ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        return [
            'name' => $name,
            'type' => $column['type'] ?? $column['type_name'] ?? $column['data_type'] ?? $column['DATA_TYPE'] ?? null,
            'nullable' => $column['nullable'] ?? $column['is_nullable'] ?? null,
            'default' => $column['default'] ?? $column['column_default'] ?? null,
            'autoIncrement' => $column['auto_increment'] ?? $column['autoincrement'] ?? null,
            'comment' => $column['comment'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeIndex(mixed $index): ?array
    {
        $index = (array) $index;
        $name = $index['name'] ?? $index['index_name'] ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        return [
            'name' => $name,
            'columns' => array_values($index['columns'] ?? []),
            'unique' => (bool) ($index['unique'] ?? false),
            'primary' => (bool) ($index['primary'] ?? false),
            'type' => $index['type'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeForeignKey(mixed $foreignKey): ?array
    {
        $foreignKey = (array) $foreignKey;
        $columns = array_values($foreignKey['columns'] ?? []);
        $foreignColumns = array_values($foreignKey['foreign_columns'] ?? $foreignKey['foreignColumns'] ?? []);
        $foreignTable = $foreignKey['foreign_table'] ?? $foreignKey['foreignTable'] ?? null;

        if ($columns === [] || ! is_string($foreignTable) || $foreignTable === '') {
            return null;
        }

        return [
            'name' => $foreignKey['name'] ?? '',
            'columns' => $columns,
            'foreignTable' => $foreignTable,
            'foreignColumns' => $foreignColumns,
            'onUpdate' => $foreignKey['on_update'] ?? null,
            'onDelete' => $foreignKey['on_delete'] ?? null,
        ];
    }

    private function migrationTable(): string
    {
        $migrations = config('database.migrations', 'migrations');

        return is_array($migrations) ? ($migrations['table'] ?? 'migrations') : $migrations;
    }

    private function stanclTenancyIsAvailable(): bool
    {
        return function_exists('tenancy')
            && config('tenancy') !== null
            && class_exists(\Stancl\Tenancy\Tenancy::class);
    }

    private function absolutePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if (str_starts_with($path, '/') || preg_match('/^[A-Z]:\//i', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    private function relativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $basePath = str_replace('\\', '/', base_path());

        if (str_starts_with($path, $basePath.'/')) {
            return substr($path, strlen($basePath) + 1);
        }

        return $path;
    }

    private function sanitizePathSegment(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value) ?? $value;

        return trim($value, '-') ?: 'schema';
    }

    private function guessDriverFromSql(string $sql): string
    {
        if (preg_match('/ENGINE=|AUTO_INCREMENT|`[^`]+`/i', $sql)) {
            return 'mysql';
        }

        if (preg_match('/sqlite_sequence|AUTOINCREMENT|CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?"/i', $sql)) {
            return 'sqlite';
        }

        if (preg_match('/SET\s+statement_timeout|CREATE\s+SCHEMA|public\./i', $sql)) {
            return 'pgsql';
        }

        return 'unknown';
    }
}
