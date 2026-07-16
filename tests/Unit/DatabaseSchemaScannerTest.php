<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Scanners\DatabaseSchemaScanner;
use AppGraph\Support\SourceFileObservations;
use AppGraph\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;

class DatabaseSchemaScannerTest extends TestCase
{
    public function test_schema_source_keys_cannot_collide_after_path_sanitization(): void
    {
        $root = storage_path('appgraph-schema-source-collision');
        $nested = $root.'/a/b.sql';
        $flat = $root.'/a-b.sql';
        $files = new Filesystem();
        $files->ensureDirectoryExists(dirname($nested));
        file_put_contents($nested, 'CREATE TABLE nested_source (id INTEGER);');
        file_put_contents($flat, 'CREATE TABLE flat_source (id INTEGER);');
        config()->set('appgraph.database.source', 'dump');
        config()->set('appgraph.database.dump.run', false);
        config()->set('appgraph.database.dump.search_paths', [$root]);
        $graph = new Graph();

        try {
            app(DatabaseSchemaScanner::class)->scan($graph);
            $array = $graph->toArray();
            $sources = $array['meta']['sources'];
            $nestedNode = $this->graphNode($array, 'table:nested_source');
            $flatNode = $this->graphNode($array, 'table:flat_source');

            $this->assertCount(2, $sources);
            $this->assertNotSame(
                $nestedNode['metadata']['sources'][0],
                $flatNode['metadata']['sources'][0],
            );
            $this->assertSame(
                str_replace('\\', '/', $nested),
                $sources[$nestedNode['metadata']['sources'][0]]['path'],
            );
            $this->assertSame(
                str_replace('\\', '/', $flat),
                $sources[$flatNode['metadata']['sources'][0]]['path'],
            );
        } finally {
            @unlink($nested);
            @unlink($flat);
            @rmdir(dirname($nested));
            @rmdir($root);
        }
    }

    public function test_duplicate_logical_tables_preserve_each_schema_identity_and_warn(): void
    {
        $root = storage_path('appgraph-schema-table-collision');
        $central = $root.'/central.sql';
        $tenant = $root.'/tenant.sql';
        $files = new Filesystem();
        $files->ensureDirectoryExists($root);
        file_put_contents($central, 'CREATE TABLE users (id INTEGER, central_only TEXT);');
        file_put_contents($tenant, 'CREATE TABLE users (id INTEGER, tenant_only TEXT);');
        config()->set('appgraph.database.source', 'dump');
        config()->set('appgraph.database.dump.run', false);
        config()->set('appgraph.database.dump.search_paths', [$root]);
        $graph = new Graph();

        try {
            app(DatabaseSchemaScanner::class)->scan($graph);
            $array = $graph->toArray();
            $table = $this->graphNode($array, 'table:users');
            $warning = $array['meta']['warnings'][0];

            $this->assertTrue($table['metadata']['identityAmbiguous']);
            $this->assertCount(2, $table['metadata']['schemaIdentities']);
            $this->assertCount(2, $table['metadata']['sources']);
            $this->assertGraphHasNode($array, 'column:users.central_only', 'column');
            $this->assertGraphHasNode($array, 'column:users.tenant_only', 'column');
            $this->assertSame('logical_table_has_multiple_schema_sources', $warning['reason']);
            $this->assertSame(['users'], $warning['tables']);
            $this->assertSame(1, $array['meta']['analysis']['databaseSchema']['ambiguousLogicalTableCount']);
        } finally {
            @unlink($central);
            @unlink($tenant);
            @rmdir($root);
        }
    }

    public function test_it_scans_database_tables_columns_indexes_and_foreign_keys(): void
    {
        $this->useFileBackedSqliteDatabase();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });

        Schema::create('progress_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('title')->index();
            $table->text('body')->nullable();
            $table->timestamps();
        });

        $graph = new Graph();

        app(DatabaseSchemaScanner::class)->scan($graph);

        $array = $graph->toArray();

        $this->assertGraphHasNode($array, 'table:progress_notes', 'table');
        $this->assertGraphHasNode($array, 'column:progress_notes.title', 'column');
        $this->assertGraphHasEdge($array, 'table:progress_notes', 'column:progress_notes.title', 'has_column');

        $indexNodes = array_filter($array['nodes'], static fn (array $node): bool => $node['type'] === 'index' && str_starts_with($node['id'], 'index:progress_notes.'));
        $foreignKeyNodes = array_filter($array['nodes'], static fn (array $node): bool => $node['type'] === 'foreign_key' && str_starts_with($node['id'], 'foreign_key:progress_notes.'));

        $this->assertNotEmpty($indexNodes);
        $this->assertNotEmpty($foreignKeyNodes);
        $observed = app(SourceFileObservations::class)->hashes();

        $this->assertNotEmpty($observed);
        $this->assertTrue((bool) array_filter(
            array_keys($observed),
            static fn (string $path): bool => str_ends_with($path, '.sql'),
        ));
    }
}
