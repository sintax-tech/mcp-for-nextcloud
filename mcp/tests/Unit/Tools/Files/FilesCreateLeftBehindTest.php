<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Modules\Files\Presentation\CreationMessages;
use OCA\Mcp\Platform\FileAccess\CommonMessages;

/**
 * files_create when a lock or a full quota stops it while the new file is read back, after Nextcloud created it and
 * before its content: the answer keeps the reason and names the empty file left, which is never deleted.
 */
final class FilesCreateLeftBehindTest extends FilesToolsTestCase {
	private const FILE = '/alice/files/Documentos/novo.md';

	/** @return array<string, array{\Throwable, string}> a native lock and a full quota, with the reason they keep */
	public static function lockAndQuota(): array {
		return [
			'lock' => [new \OCP\Lock\LockedException(self::FILE), CommonMessages::locked()],
			'quota' => [new \OCP\Files\NotEnoughSpaceException(self::FILE), CommonMessages::insufficientQuota()],
		];
	}

	/**
	 * The read-back of the new file fails once: the empty file stays, named, and its content is never written.
	 *
	 * @param \Throwable $failure what the read-back raises
	 * @param string $reason the actionable reason the answer keeps
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('lockAndQuota')]
	public function testALockOrQuotaAtTheReadBackNamesTheEmptyFileLeft(\Throwable $failure, string $reason): void {
		$this->tree->afterCreate = function () use ($failure): void {
			$this->tree->beforeGet = function (string $path) use ($failure): void {
				if ($path === self::FILE) {
					$this->tree->beforeGet = null;
					throw $failure;
				}
			};
		};

		$message = $this->failure('files_create', ['path' => '/Documentos/novo.md', 'content' => 'texto']);

		$this->assertSame(CreationMessages::createLeftBehind($reason, '/Documentos/novo.md'), $message);
		$this->assertSame('', $this->tree->nodes[self::FILE]['content']);
		$this->assertNotContains('write ' . self::FILE, $this->tree->ops);
		$this->assertStringNotContainsString('delete', implode(' ', $this->tree->ops));
	}

	/**
	 * The same failure before the file exists stays a clean refusal: nothing is created and no file is named.
	 *
	 * @param \Throwable $failure what the creation raises
	 * @param string $reason the refusal it answers
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('lockAndQuota')]
	public function testTheSameFailureBeforeTheFileExistsCreatesNothing(\Throwable $failure, string $reason): void {
		$this->tree->beforeCreate = static fn () => throw $failure;

		$this->assertSame($reason, $this->failure('files_create', ['path' => '/Documentos/novo.md', 'content' => 'texto']));
		$this->assertArrayNotHasKey(self::FILE, $this->tree->nodes);
	}

	/**
	 * A policy unreadable after the file exists, with the default flag, names the empty file left like any other
	 * failure from there on; before the file exists it stays the 503 of the endpoint, with nothing created.
	 *
	 * @return void
	 */
	public function testAPolicyUnreadableAfterTheFileExistsNamesItAndBeforeStaysA503(): void {
		$this->tree->afterCreate = function (): void {
			$this->tree->beforeGet = function (string $path): void {
				if ($path === self::FILE) {
					$this->tree->beforeGet = null;
					throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('readback unavailable');
				}
			};
		};
		$this->assertSame(CreationMessages::createLeftBehind(null, '/Documentos/novo.md'), $this->failure('files_create', ['path' => '/Documentos/novo.md', 'content' => 'texto']));
		$this->assertSame('', $this->tree->nodes[self::FILE]['content']);

		$this->tree->afterCreate = null;
		$this->tree->beforeCreate = static fn () => throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('policy');
		$this->expectException(\OCA\Mcp\Access\Policy\PolicyUnavailable::class);
		try {
			$this->tool('files_create', ['path' => '/Documentos/outro.md', 'content' => 'texto']);
		} finally {
			$this->assertArrayNotHasKey('/alice/files/Documentos/outro.md', $this->tree->nodes);
		}
	}

	/**
	 * newFile throws after the node exists: the name is read again; a visible file there is named without claiming it,
	 * a name that cannot be read again is not named at all, and nothing is deleted.
	 *
	 * @return void
	 */
	public function testAFailureInsideNewFileIsReconciledWithTheName(): void {
		$this->tree->afterCreate = function (): void {
			$this->tree->afterCreate = null;
			throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('hook failed');
		};
		$this->assertSame(CreationMessages::createMayExist(null, '/Documentos/novo.md'), $this->failure('files_create', ['path' => '/Documentos/novo.md', 'content' => 'texto']));

		$this->tree->afterCreate = function (): void {
			$this->tree->afterCreate = null;
			$this->tree->beforeGet = static fn () => throw new \RuntimeException('storage');
			throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('hook failed');
		};
		$message = $this->failure('files_create', ['path' => '/Documentos/outro.md', 'content' => 'texto']);
		$this->tree->beforeGet = null;
		$this->assertSame(CreationMessages::createUnconfirmed(null), $message);
		$this->assertArrayHasKey('/alice/files/Documentos/novo.md', $this->tree->nodes);
		$this->assertArrayHasKey('/alice/files/Documentos/outro.md', $this->tree->nodes);
		$this->assertStringNotContainsString('delete', implode(' ', $this->tree->ops));
	}
}
