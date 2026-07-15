<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Scanners\DatabaseSchemaScanner;
use AppGraph\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DatabaseSchemaScannerTest extends TestCase
{
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
    }
}
