<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Exclusive locks shared by several workers, as the core's locking provider: a lock held elsewhere is refused at
 * once with LockedException. $onAcquire runs right after a lock is taken, to interleave another worker there.
 */
final class InMemoryLocks implements ILockingProvider {
    /** @var array<string, true> keys held */
    public array $held = [];
    /** @var list<string> every key acquired, in order */
    public array $acquired = [];
    /** @var (callable(string): void)|null */
    public $onAcquire = null;

    public function isLocked(string $path, int $type): bool {
        return isset($this->held[$path]);
    }

    public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
        if (isset($this->held[$path])) {
            throw new LockedException($path);
        }
        $this->held[$path] = true;
        $this->acquired[] = $path;
        if ($this->onAcquire !== null) {
            $hook = $this->onAcquire;
            $this->onAcquire = null;
            $hook($path);
        }
    }

    public function releaseLock(string $path, int $type): void {
        unset($this->held[$path]);
    }

    public function changeLock(string $path, int $targetType): void {
    }

    public function releaseAll(): void {
        $this->held = [];
    }
}
