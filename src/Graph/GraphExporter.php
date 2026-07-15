<?php

namespace AppGraph\Graph;

use JsonException;
use RuntimeException;

class GraphExporter
{
    /**
     * @throws JsonException
     */
    public function export(Graph $graph, string $path, bool $pretty = false): string
    {
        return $this->exportData($graph->toArray(), $path, $pretty);
    }

    /**
     * Encode an arbitrary data structure to JSON and write it to disk.
     *
     * Shared by the main graph export and the overview projection.
     *
     * @param array<string, mixed> $data
     *
     * @throws JsonException
     */
    public function exportData(array $data, string $path, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($data, $flags);

        if ($pretty) {
            $json .= PHP_EOL;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create AppGraph output directory [{$directory}].");
        }

        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException("Unable to write AppGraph output file [{$path}].");
        }

        return $path;
    }
}
