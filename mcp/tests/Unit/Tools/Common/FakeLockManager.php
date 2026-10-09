<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Common;

use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Lock\ILockProvider;
use OCP\Files\Lock\LockContext;
use OCP\Files\Lock\NoLockProviderException;
use OCP\Lock\ManuallyLockedException;

/**
 * ILockManager of Nextcloud 32 to 35 with files_lock behind it, or without it ($available false). It only answers
 * what the app may ask: whether a provider is there and which locks a file id has. lock(), unlock() and scopes are
 * recorded so a test can prove the app never takes, releases or borrows a lock.
 */
final class FakeLockManager implements ILockManager {
    public bool $available = true;
    /** @var array<int, list<ILock>> locks by file id */
    public array $locks = [];
    /** What getLocks() throws instead of answering, for a provider that fails. */
    public ?\Throwable $failure = null;
    /** @var list<string> every call that would change a lock or a scope */
    public array $forbidden = [];
    /** @var list<int> every file id asked about */
    public array $asked = [];

    /** Puts a lock on a file, replacing what it had: what files_lock holds after Text, Office or a person locked it. */
    public function put(ILock $lock): ILock {
        $this->locks[$lock->getFileId()] = [$lock];
        return $lock;
    }

    public function lock(LockContext $lockInfo): ILock {
        $this->forbidden[] = 'lock';
        throw new \LogicException('the app must never take a lock');
    }

    /** The exception files_lock's storage wrapper throws for that lock. */
    public static function refusal(ILock $lock, int $eta = -1): ManuallyLockedException {
        return new ManuallyLockedException('/alice/files/x', null, $lock->getToken(), $lock->getOwner(), $eta);
    }

    public function registerLockProvider(ILockProvider $lockProvider): void {
    }

    public function registerLazyLockProvider(string $lockProviderClass): void {
    }

    public function isLockProviderAvailable(): bool {
        return $this->available;
    }

    public function runInScope(LockContext $lock, callable $callback): void {
        $this->forbidden[] = 'runInScope';
        throw new \LogicException('the app must never borrow a lock scope');
    }

    public function getLockInScope(): ?LockContext {
        return null;
    }

    public function getLocks(int $fileId): array {
        if (!$this->available) {
            throw new NoLockProviderException();
        }
        $this->asked[] = $fileId;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->locks[$fileId] ?? [];
    }

    public function unlock(LockContext $lockInfo): void {
        $this->forbidden[] = 'unlock';
        throw new \LogicException('the app must never release a lock');
    }
}
