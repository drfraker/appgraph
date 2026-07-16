<?php

namespace AppGraph\Support;

use AppGraph\Storage\GraphStore;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Runs a scan through a newly bootstrapped Laravel CLI process.
 *
 * MCP servers are intentionally long lived. Reusing their already-booted
 * router, dispatcher, bus, and container after source edits would let a scan
 * pair current source bytes with stale framework registries.
 */
class FreshScanRunner implements ScanRunner
{
    private const MAX_RESULT_BYTES = 1048576;

    public function __construct(
        private GraphStore $store,
        private ?string $artisanPath = null,
        private ?string $phpBinary = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(?string $preserveGeneration = null): array
    {
        if ($preserveGeneration !== null
            && (strlen($preserveGeneration) > 19
                || preg_match('/^[1-9]\d*$/D', $preserveGeneration) !== 1)) {
            throw new RuntimeException('The AppGraph fresh scan baseline is invalid.');
        }

        $artisan = $this->artisanPath ?? base_path('artisan');

        if (! is_file($artisan) || ! is_readable($artisan)) {
            throw new RuntimeException(
                "AppGraph cannot start a fresh Laravel scan because [{$artisan}] is not a readable Artisan entry point."
            );
        }

        $token = bin2hex(random_bytes(16));
        $resultPath = $this->createResultFile($token);
        $command = [
            $this->phpBinary ?? PHP_BINARY,
            $artisan,
            'appgraph:scan',
            '--result-token='.$token,
            '--result-file='.$resultPath,
        ];

        if ($preserveGeneration !== null) {
            $command[] = '--preserve-generation='.$preserveGeneration;
        }

        $process = new Process($command, base_path());
        $timeout = (int) config('appgraph.mcp.scan_timeout_seconds', 300);
        $process->setTimeout(max(1, min(3600, $timeout)));

        try {
            $process->run();

            if (! $process->isSuccessful()) {
                $detail = $this->boundedDiagnostic(
                    trim($process->getErrorOutput()) ?: trim($process->getOutput()),
                );
                $suffix = $detail !== '' ? ' '.$detail : '';

                throw new RuntimeException(
                    'The fresh AppGraph scan process failed with exit code '
                        .($process->getExitCode() ?? 'unknown').'.'.$suffix
                );
            }

            return $this->readResult($resultPath, $token);
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'The fresh AppGraph scan process could not complete. '
                    .$this->boundedDiagnostic($throwable->getMessage()),
                previous: $throwable,
            );
        } finally {
            if (is_file($resultPath) || is_link($resultPath)) {
                @unlink($resultPath);
            }
        }
    }

    private function createResultFile(string $token): string
    {
        $directory = dirname($this->store->path());

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create AppGraph result directory [{$directory}].");
        }

        $path = $directory.'/.scan-result-'.$token.'.json';
        $handle = @fopen($path, 'x+b');

        if ($handle === false) {
            throw new RuntimeException('Unable to create the private AppGraph scan result channel.');
        }

        try {
            if (! @chmod($path, 0600)) {
                throw new RuntimeException('Unable to restrict the AppGraph scan result channel permissions.');
            }
        } catch (Throwable $throwable) {
            fclose($handle);
            @unlink($path);

            throw $throwable;
        }

        fclose($handle);

        return $path;
    }

    /** @return array<string, mixed> */
    private function readResult(string $path, string $token): array
    {
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException('The fresh AppGraph scan did not return a regular result file.');
        }

        $size = filesize($path);

        if (! is_int($size) || $size < 2 || $size > self::MAX_RESULT_BYTES) {
            throw new RuntimeException('The fresh AppGraph scan returned an invalid result size.');
        }

        $json = file_get_contents($path);

        if (! is_string($json) || strlen($json) !== $size) {
            throw new RuntimeException('The fresh AppGraph scan result could not be read completely.');
        }

        try {
            $envelope = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The fresh AppGraph scan returned invalid JSON.', previous: $exception);
        }

        if (! is_array($envelope)
            || ! is_string($envelope['token'] ?? null)
            || ! hash_equals($token, $envelope['token'])
            || ! is_array($envelope['result'] ?? null)) {
            throw new RuntimeException('The fresh AppGraph scan returned an invalid correlation envelope.');
        }

        $generation = $envelope['result']['generation']['id'] ?? null;

        if (! is_string($generation) || preg_match('/^[1-9]\d*$/D', $generation) !== 1) {
            throw new RuntimeException('The fresh AppGraph scan did not return an immutable generation id.');
        }

        return $envelope['result'];
    }

    private function boundedDiagnostic(string $message): string
    {
        $message = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $message) ?? '';
        $message = mb_substr($message, 0, 512);

        return strlen($message) > 2048
            ? mb_strcut($message, 0, 2048, 'UTF-8')
            : $message;
    }
}
