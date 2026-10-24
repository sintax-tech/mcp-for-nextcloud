<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tasks;

use OCP\Lock\ILockingProvider;

/**
 * A locking provider that excludes nothing, the way the server's provider behaves with `filelocking.enabled` false
 * (Nextcloud 32 to 35): acquiring returns without error, nothing is ever held and isLocked() answers false. Every
 * call is recorded, so a test can tell that the lock was released.
 */
final class IneffectiveLocks implements ILockingProvider {
	/** @var list<string> "acquire:<key>" and "release:<key>", in order */
	public array $calls = [];

	/** {@inheritDoc} */
	public function isLocked(string $path, int $type): bool {
		return false;
	}

	/** {@inheritDoc} */
	public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
		$this->calls[] = 'acquire:' . $path;
	}

	/** {@inheritDoc} */
	public function releaseLock(string $path, int $type): void {
		$this->calls[] = 'release:' . $path;
	}

	/** {@inheritDoc} */
	public function changeLock(string $path, int $targetType): void {
	}

	/** {@inheritDoc} */
	public function releaseAll(): void {
	}
}
