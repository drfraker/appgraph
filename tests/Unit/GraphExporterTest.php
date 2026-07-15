<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\GraphExporter;
use PHPUnit\Framework\TestCase;

class GraphExporterTest extends TestCase
{
    public function test_export_replaces_an_existing_generation_without_leaving_temporary_files(): void
    {
        $directory = sys_get_temp_dir().'/appgraph-export-'.bin2hex(random_bytes(4));
        $path = $directory.'/appgraph.json';
        mkdir($directory, 0775, true);
        file_put_contents($path, '{"old":true}');

        try {
            (new GraphExporter())->exportData(['generation' => 2], $path);

            $this->assertSame('{"generation":2}', file_get_contents($path));
            $this->assertSame([], glob($directory.'/.appgraph-*') ?: []);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }
}
