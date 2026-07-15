<?php

namespace AppGraph\Tests;

use AppGraph\AppGraphServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @var array<int, string>
     */
    private array $temporaryPaths = [];

    /**
     * @param \Illuminate\Foundation\Application $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AppGraphServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.name', 'AppGraph Test App');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    protected function useFileBackedSqliteDatabase(): string
    {
        $directory = sys_get_temp_dir().'/appgraph-db-'.bin2hex(random_bytes(4));
        $database = $directory.'/testing.sqlite';
        $schemaDirectory = $directory.'/schema';

        mkdir($schemaDirectory, 0775, true);
        touch($database);

        $this->temporaryPaths[] = $database;

        config()->set('database.connections.testing.database', $database);
        config()->set('appgraph.database.source', 'dump');
        config()->set('appgraph.database.dump_path', $schemaDirectory.'/{connection}-{context}.sql');
        config()->set('appgraph.database.dump.run', true);
        config()->set('appgraph.database.dump.search_paths', [$schemaDirectory]);
        config()->set('appgraph.database.dump.include_tenants', false);

        \Illuminate\Support\Facades\DB::purge('testing');
        \Illuminate\Support\Facades\DB::reconnect('testing');

        return $database;
    }

    /**
     * @param array<string, mixed> $graph
     */
    protected function assertGraphHasNode(array $graph, string $id, ?string $type = null): void
    {
        $node = $this->graphNode($graph, $id);

        $this->assertNotNull($node, "Graph node [{$id}] was not found.");

        if ($type !== null) {
            $this->assertSame($type, $node['type']);
        }
    }

    /**
     * @param array<string, mixed> $graph
     */
    protected function assertGraphHasEdge(array $graph, string $from, string $to, string $type): void
    {
        $edge = $this->graphEdge($graph, $from, $to, $type);

        $this->assertNotNull($edge, "Graph edge [{$from}] -{$type}-> [{$to}] was not found.");
    }

    /**
     * @param array<string, mixed> $graph
     * @return array<string, mixed>|null
     */
    protected function graphNode(array $graph, string $id): ?array
    {
        foreach ($graph['nodes'] as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $graph
     * @return array<string, mixed>|null
     */
    protected function graphEdge(array $graph, string $from, string $to, string $type): ?array
    {
        foreach ($graph['edges'] as $edge) {
            if ($edge['from'] === $from && $edge['to'] === $to && $edge['type'] === $type) {
                return $edge;
            }
        }

        return null;
    }
}
