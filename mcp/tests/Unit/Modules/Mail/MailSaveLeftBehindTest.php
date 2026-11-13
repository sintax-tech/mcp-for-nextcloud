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
}
