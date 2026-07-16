<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Support\ScanLock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ScanLockTest extends TestCase
{
    private ?string $directory = null;

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            $path = $this->directory.'/.scan.lock';

            if (is_file($path)) {
                @unlink($path);
            }

            @rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_a_lock_can_be_acquired_released_and_acquired_by_another_instance(): void
    {
        $path = $this->path();
        $first = new ScanLock($path, timeoutMs: 0);
        $second = new ScanLock($path, timeoutMs: 0);

        $first->acquire();
        $first->release();
        $first->release();
        $second->acquire();
        $second->release();

        $this->assertFileExists($path);
    }

    public function test_a_separate_instance_times_out_while_the_lock_is_held(): void
    {
        $path = $this->path();
        $first = new ScanLock($path, timeoutMs: 0);
        $second = new ScanLock($path, timeoutMs: 10);
        $first->acquire();

        try {
            $second->acquire();
            $this->fail('A separate lock instance should not acquire an already-held scan lock.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Timed out waiting for the AppGraph scan lock after 10ms.',
                $exception->getMessage(),
            );
        } finally {
            $first->release();
        }

        $second->acquire();
        $second->release();
    }

    public function test_one_instance_cannot_acquire_the_same_lock_twice(): void
    {
        $lock = new ScanLock($this->path(), timeoutMs: 0);
        $lock->acquire();

        try {
            $lock->acquire();
            $this->fail('A lock instance should reject a second acquire call.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The AppGraph scan lock is already held by this process.',
                $exception->getMessage(),
            );
        } finally {
            $lock->release();
        }
    }

    public function test_owner_token_allows_scoped_reentry_without_unlocking_early(): void
    {
        $path = $this->path();
        $lock = new ScanLock($path, timeoutMs: 0);
        $other = new ScanLock($path, timeoutMs: 0);
        $owner = $lock->acquire();

        $this->assertSame($owner, $lock->acquire($owner));

        try {
            $lock->release(str_repeat('0', 32));
            $this->fail('A different owner token must not release the lock.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('owned by another operation', $exception->getMessage());
        }

        $lock->release($owner);

        try {
            $other->acquire();
            $this->fail('One nested release must leave the outer lock held.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Timed out', $exception->getMessage());
        }

        $lock->release($owner);
        $other->acquire();
        $other->release();
    }

    public function test_lock_file_permissions_are_owner_only(): void
    {
        $lock = new ScanLock($this->path(), timeoutMs: 0);
        $owner = $lock->acquire();

        $this->assertSame(0600, fileperms($this->path()) & 0777);

        $lock->release($owner);
    }

    private function path(): string
    {
        $this->directory ??= sys_get_temp_dir().'/appgraph-scan-lock-'.bin2hex(random_bytes(8));

        return $this->directory.'/.scan.lock';
    }
}
