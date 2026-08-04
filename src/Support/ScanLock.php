<?php

namespace AppGraph\Support;

use RuntimeException;

class ScanLock
{
    /** @var resource|null */
    private $handle = null;

    private ?string $ownerToken = null;

    public function __construct(
        private string $path,
        private int $timeoutMs = 30000,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function acquire(): string
    {
        if (is_resource($this->handle)) {
            throw new RuntimeException('The AppGraph scan lock is already held by this process.');
        }

        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create AppGraph lock directory [{$directory}].");
        }

        if (is_link($this->path)) {
            throw new RuntimeException(
                "Refusing to use symbolic link [{$this->path}] as the AppGraph scan lock."
            );
        }

        $handle = fopen($this->path, 'c+');

        if ($handle === false) {
            throw new RuntimeException("Unable to open AppGraph scan lock [{$this->path}].");
        }

        if (! @chmod($this->path, 0600)) {
            fclose($handle);

            throw new RuntimeException("Unable to restrict AppGraph scan lock permissions for [{$this->path}].");
        }

        $timeoutMs = max(0, min(300000, $this->timeoutMs));
        $deadline = hrtime(true) + ($timeoutMs * 1_000_000);

        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $this->handle = $handle;
                $this->ownerToken = bin2hex(random_bytes(16));

                return $this->ownerToken;
            }

            if (hrtime(true) >= $deadline) {
                fclose($handle);

                throw new RuntimeException("Timed out waiting for the AppGraph scan lock after {$timeoutMs}ms.");
            }

            usleep(50000);
        } while (true);
    }

    public function release(?string $ownerToken = null): void
    {
        if (! is_resource($this->handle)) {
            return;
        }

        if ($ownerToken !== null
            && ($this->ownerToken === null || ! hash_equals($this->ownerToken, $ownerToken))) {
            throw new RuntimeException('The AppGraph scan lock is owned by another operation.');
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
        $this->ownerToken = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
