<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Presentation\CreationMessages;
use OCA\Mcp\Platform\FileAccess\CommonMessages;

/**
 * mail_save_attachment when a lock or a full quota stops it: before the file exists it is a clean refusal; after,
 * at the content write or at the read-back of the new file, an uncertain receipt that names the empty file left.
 */
final class MailSaveLeftBehindTest extends MailSaveAttachmentTestCase {
	private const TARGET = '/alice/files/Documentos/saved.pdf';

	/**
	 * A lock or a full quota after the file exists keeps the actionable message of files_create, names the empty file
	 * left and answers an uncertain receipt with its id; the file is never deleted.
	 *
	 * @return void
	 */
	public function testNativeLockAndQuotaAfterTheFileExistsAreUncertainAndNameTheFile(): void {
		foreach ([
			[new \OCP\Lock\LockedException('/alice/files/Documentos/saved.pdf'), \OCA\Mcp\Platform\FileAccess\CommonMessages::locked()],
			[new \OCP\Files\NotEnoughSpaceException('/alice/files/Documentos/saved.pdf'), \OCA\Mcp\Platform\FileAccess\CommonMessages::insufficientQuota()],
		] as [$failure, $reason]) {
			$this->tree->writeFailure = $failure;
			$out = $this->saveFile();
			self::assertSame(['uncertain', false], [$out['outcome'], $out['verified']]);
			self::assertSame(\OCA\Mcp\Modules\Files\Presentation\CreationMessages::createLeftBehind($reason, '/Documentos/saved.pdf'), $out['message']);
			self::assertSame($this->tree->nodes['/alice/files/Documentos/saved.pdf']['id'], $out['fileId']);
			self::assertSame('', $this->tree->nodes['/alice/files/Documentos/saved.pdf']['content']);
			self::assertStringNotContainsString('delete', implode(' ', $this->tree->ops));
			// The empty node from the first failed attempt is a fixture, not an app rollback.
			unset($this->tree->nodes['/alice/files/Documentos/saved.pdf']);
		}
	}
	/**
	 * A full quota before the file exists stays a clean refusal with the same message, and nothing is created.
	 *
	 * @return void
	 */
	public function testNativeQuotaBeforeTheFileExistsRefusesAndCreatesNothing(): void {
		$this->tree->beforeCreate = static fn () => throw new \OCP\Files\NotEnoughSpaceException('/alice/files/Documentos/saved.pdf');
		try {
			$this->saveFile();
			self::fail('The native refusal must propagate');
		} catch (ToolFailure $e) {
			self::assertSame(\OCA\Mcp\Platform\FileAccess\CommonMessages::insufficientQuota(), $e->getMessage());
		}
		self::assertArrayNotHasKey('/alice/files/Documentos/saved.pdf', $this->tree->nodes);
	}

	/** @return array<string, array{\Throwable, string}> a native lock and a full quota, with the reason they keep */
	public static function lockAndQuota(): array {
		return [
			'lock' => [new \OCP\Lock\LockedException(self::TARGET), CommonMessages::locked()],
			'quota' => [new \OCP\Files\NotEnoughSpaceException(self::TARGET), CommonMessages::insufficientQuota()],
		];
	}

	/**
	 * A lock or a full quota while the new file is read back, before its content, leaves it empty and named, with an
	 * uncertain receipt that carries its id; nothing is written or deleted.
	 *
	 * @param \Throwable $failure what the read-back raises
	 * @param string $reason the actionable reason the answer keeps
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('lockAndQuota')]
	public function testALockOrQuotaAtTheReadBackOfTheNewFileIsUncertainAndNamesIt(\Throwable $failure, string $reason): void {
		$this->tree->afterCreate = function () use ($failure): void {
			$this->tree->beforeGet = function (string $path) use ($failure): void {
				if ($path === self::TARGET) {
					$this->tree->beforeGet = null;
					throw $failure;
				}
			};
		};

		$out = $this->saveFile();

		self::assertSame(['uncertain', false], [$out['outcome'], $out['verified']]);
		self::assertSame(CreationMessages::createLeftBehind($reason, '/Documentos/saved.pdf'), $out['message']);
		self::assertSame($this->tree->nodes[self::TARGET]['id'], $out['fileId']);
		self::assertSame('', $this->tree->nodes[self::TARGET]['content']);
		self::assertNotContains('write ' . self::TARGET, $this->tree->ops);
		self::assertStringNotContainsString('delete', implode(' ', $this->tree->ops));
	}

	/**
	 * A policy the read-back of the written file cannot read, with the default flag, answers the uncertain receipt
	 * with the path and id of the file, never a 503: the file exists with its bytes.
	 *
	 * @return void
	 */
	public function testAPolicyUnreadableAtTheReadBackIsUncertainAndNamesTheFile(): void {
		$this->tree->afterWrite = function (): void {
			$this->tree->beforeGet = function (): void {
				$this->tree->beforeGet = null;
				throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('readback unavailable');
			};
		};

		$out = $this->saveFile();

		self::assertSame(['uncertain', false], [$out['outcome'], $out['verified']]);
		self::assertSame(['/Documentos/saved.pdf', $this->tree->nodes[self::TARGET]['id']], [$out['path'], $out['fileId']]);
		self::assertNotSame('', $this->tree->nodes[self::TARGET]['content']);
		self::assertStringNotContainsString('delete', implode(' ', $this->tree->ops));
	}

	/** A policy unreadable before the file exists still leaves as the endpoint's 503, with nothing created. @return void */
	public function testAPolicyUnreadableBeforeTheFileExistsStaysA503(): void {
		$this->tree->beforeCreate = static fn () => throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('policy');

		$this->expectException(\OCA\Mcp\Access\Policy\PolicyUnavailable::class);
		try {
			$this->saveFile();
		} finally {
			self::assertArrayNotHasKey(self::TARGET, $this->tree->nodes);
		}
	}

	/**
	 * newFile throws after the node exists: a file visible at the name is named, without an id that would claim it;
	 * a name that cannot be read again names nothing. Nothing is deleted.
	 *
	 * @return void
	 */
	public function testAFailureInsideNewFileIsReconciledWithTheName(): void {
		$this->tree->afterCreate = function (): void {
			$this->tree->afterCreate = null;
			throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('hook failed');
		};
		$out = $this->saveFile();
		self::assertSame(['uncertain', '/Documentos/saved.pdf', CreationMessages::createMayExist(null, '/Documentos/saved.pdf')], [$out['outcome'], $out['path'], $out['message']]);
		self::assertArrayNotHasKey('fileId', $out);

		unset($this->tree->nodes[self::TARGET]);
		$this->tree->afterCreate = function (): void {
			$this->tree->afterCreate = null;
			$this->tree->beforeGet = static fn () => throw new \RuntimeException('storage');
			throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('hook failed');
		};
		$out = $this->saveFile();
		$this->tree->beforeGet = null;
		self::assertSame(['uncertain', CreationMessages::createUnconfirmed(null)], [$out['outcome'], $out['message']]);
		self::assertArrayNotHasKey('path', $out);
		self::assertArrayNotHasKey('fileId', $out);
		self::assertArrayHasKey(self::TARGET, $this->tree->nodes);
	}
}
