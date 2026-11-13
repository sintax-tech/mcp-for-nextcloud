<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Modules\Files\Presentation\CreationMessages;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use PHPUnit\Framework\Attributes\DataProvider;

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

	/** @var list<int> ids of the nodes the administrator's hidden tag is on */
	private array $hiddenIds = [];

	/**
	 * Rebuilds the Files module over a gate that reads {@see self::$hiddenIds} as the nodes carrying the hidden tag.
	 *
	 * @return void
	 */
	private function withHiddenTag(): void {
		$mapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
		$mapper->method('getTagIdsForObjects')->willReturnCallback(fn (array $ids): array
			=> array_combine($ids, array_map(fn ($id): array => in_array((int)$id, $this->hiddenIds, true) ? ['999'] : [], $ids)));
		$this->config->app['mcp'][\OCA\Mcp\Access\Visibility\VisibilityGuard::CONFIG_KEY] = '["999"]';
		$this->visibilityGuard = new \OCA\Mcp\Access\ResourceScope\FilesAccessGuard($this->config->mock($this), $mapper, $this->visibilityRoot());
		$this->setUp();
	}

	/** @return array<string, array{\Closure(self): void}> each phase of files_create after newFile, where the new file is hidden and something throws */
	public static function hideAndThrow(): array {
		$hide = static function (self $test): void { $test->hiddenIds[] = (int)$test->tree->nodes[self::FILE]['id']; };
		$once = static function (self $test) use ($hide): void {
			$test->tree->beforeGet = static function () use ($test, $hide): void { $test->tree->beforeGet = null; $hide($test); throw new \RuntimeException('read failed'); };
		};
		return [
			'inside newFile' => [static function (self $test) use ($hide): void { $test->tree->afterCreate = static function () use ($test, $hide): void {
				$test->tree->afterCreate = null; $hide($test); throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('hook failed'); }; }],
			'assertVisible after newFile' => [static function (self $test) use ($hide): void { $test->tree->afterCreate = static function () use ($test, $hide): void {
				$test->tree->afterCreate = null; $hide($test); }; }],
			'read again before the content' => [static function (self $test) use ($once): void { $test->tree->afterCreate = static function () use ($test, $once): void {
				$test->tree->afterCreate = null; $once($test); }; }],
			'putContent' => [static function (self $test) use ($hide): void { $test->tree->afterWrite = static function () use ($test, $hide): void {
				$test->tree->afterWrite = null; $hide($test); throw new \RuntimeException('post-write hook'); }; }],
			'putContent locked, the lock naming the file' => [static function (self $test) use ($hide): void { $test->tree->afterWrite = static function () use ($test, $hide): void {
				$test->tree->afterWrite = null; $hide($test); throw new \OCP\Lock\ManuallyLockedException('/alice/files/Documentos/novo.md', null, 'files_lock/x', 'text', -1); }; }],
			'putContent refused, the refusal naming the file' => [static function (self $test) use ($hide): void { $test->tree->afterWrite = static function () use ($test, $hide): void {
				$test->tree->afterWrite = null; $hide($test); throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('The file /alice/files/Documentos/novo.md was refused.'); }; }],
			'read-back of the receipt' => [static function (self $test) use ($once): void { $test->tree->afterWrite = static function () use ($test, $once): void {
				$test->tree->afterWrite = null; $once($test); }; }],
		];
	}

	/**
	 * In every phase that hides the new file and throws, files_create answers without its name or path, and the file
	 * stays where it was written.
	 *
	 * @param \Closure(self): void $arrange the phase that hides the file and throws
	 * @return void
	 */
	#[DataProvider('hideAndThrow')]
	public function testAFileHiddenInAnyPhaseIsNeverNamed(\Closure $arrange): void {
		$this->withHiddenTag();
		$arrange($this);

		try {
			$answer = json_encode($this->tool('files_create', ['path' => '/Documentos/novo.md', 'content' => 'texto']));
		} catch (\Throwable $e) {
			$answer = $e->getMessage();
		}

		$this->assertStringNotContainsString('novo.md', $answer);
		$this->assertArrayHasKey(self::FILE, $this->tree->nodes, 'nothing is deleted');
	}

	/**
	 * The upload link writes through the same creation and answers through CheckoutRefusals: in every phase that hides
	 * the new file and throws, its status keeps the kind of the failure and its text names nothing of the file.
	 *
	 * @param \Closure(self): void $arrange the phase that hides the file and throws
	 * @return void
	 */
	#[DataProvider('hideAndThrow')]
	public function testTheUploadLinkNeverNamesAFileHiddenInAnyPhase(\Closure $arrange): void {
		$this->withHiddenTag();
		$arrange($this);
		$creation = FilesCatalogWiring::of($this->module)->named('creation');

		try {
			$creation->write($this->tree->rootFolder()->get('Documentos'), 'novo.md', 'texto', 'alice', false);
			self::fail('The write must not succeed');
		} catch (\Throwable $e) {
			$response = (new \OCA\Mcp\Modules\Files\Checkout\CheckoutRefusals($this->createMock(\Psr\Log\LoggerInterface::class)))->createRefusal($e);
		}

		$this->assertStringNotContainsString('novo.md', $response->render());
		$this->assertGreaterThanOrEqual(400, $response->getStatus());
		$this->assertArrayHasKey(self::FILE, $this->tree->nodes, 'nothing is deleted');
	}
}
