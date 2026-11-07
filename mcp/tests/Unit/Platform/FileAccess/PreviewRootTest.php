<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Platform\FileAccess;

use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\ActorRoot;
use OCA\Mcp\Platform\FileAccess\PreviewRoot;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\ForbiddenException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/** Distinguishes native misses from policy refusals before common exception mapping, without any sink. */
final class PreviewRootTest extends TestCase {
	/**
	 * Creates a canonical root and real actor adapter around native interface doubles.
	 *
	 * @param int $lookups Expected root lookups including fallback.
	 * @param int $setups Expected full provider initializations.
	 * @return array{PreviewRoot,IRootFolder,IUserSession,Folder} Runner and observable dependencies.
	 */
	private function context(int $lookups, int $setups): array {
		$root = $this->createMock(Folder::class);
		$root->method('getPath')->willReturn('/bob/files');
		$root->expects(self::never())->method('getDirectoryListing');
		$roots = $this->createMock(IRootFolder::class);
		$roots->expects(self::exactly($lookups))->method('getUserFolder')->with('bob')->willReturn($root);
		$roots->expects(self::exactly($setups))->method('getMountsIn')->with('/bob/files')->willReturn([]);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return [new PreviewRoot($roots, new ActorRoot($roots, $session)), $roots, $session, $root];
	}

	/**
	 * Existing targets return their plan without explicit full mount setup.
	 *
	 * @return void
	 */
	public function testExistingPlanStaysLazy(): void {
		[$runner, , , $root] = $this->context(1, 0);
		self::assertSame(['id' => 17], $runner->run('bob', static function (Folder $actual) use ($root): array {
			self::assertSame($root, $actual);
			return ['id' => 17];
		}));
	}

	/**
	 * Only the first native miss triggers full setup and a second execution of the same plan.
	 *
	 * @return void
	 */
	public function testNativeMissRetriesExactlyOnceBeforeMapping(): void {
		[$runner] = $this->context(2, 1);
		$count = 0;
		$plan = static function () use (&$count): array {
			if (++$count === 1) {
				throw new NotFoundException('native lookup miss');
			}
			return ['id' => 19];
		};
		$result = NodeAccess::run(fn (): array => $runner->run('bob', $plan));
		self::assertSame(2, $count);
		self::assertSame(['id' => 19], $result);
	}

	/**
	 * A second native miss is mapped normally instead of permitting an unbounded retry.
	 *
	 * @return void
	 */
	public function testSecondMissStopsAndMaps(): void {
		[$runner] = $this->context(2, 1);
		$count = 0;
		$plan = static function () use (&$count): array {
			$count++;
			throw new NotFoundException('still missing');
		};
		try {
			NodeAccess::run(fn (): array => $runner->run('bob', $plan));
			self::fail('Second miss admitted');
		} catch (ToolFailure $error) {
			self::assertSame(ToolFailure::NOT_FOUND, $error->getMessage());
			self::assertSame(2, $count);
		}
	}

	/**
	 * Scope/visibility refusals and native ACL exceptions cannot be mistaken for a native missing node.
	 *
	 * @return void
	 */
	public function testPolicyAndAclRefusalsNeverRetry(): void {
		foreach ([new ToolFailure(ToolFailure::NOT_FOUND), new ToolFailure('scope refused'),
			new ForbiddenException('native acl', false), new NotPermittedException('native permission')] as $error) {
			[$runner] = $this->context(1, 0);
			$count = 0;
			try {
				$runner->run('bob', static function () use ($error, &$count): array {
					$count++;
					throw $error;
				});
				self::fail('Refused plan admitted');
			} catch (\Throwable $actual) {
				self::assertSame($error, $actual);
				self::assertSame(1, $count);
			}
		}
	}

	/**
	 * A session changed after the native miss is rejected before fallback root lookup or provider setup.
	 *
	 * @return void
	 */
	public function testSessionIsRecheckedBeforeFallback(): void {
		$root = $this->createMock(Folder::class);
		$root->method('getPath')->willReturn('/bob/files');
		$roots = $this->createMock(IRootFolder::class);
		$roots->expects(self::once())->method('getUserFolder')->willReturn($root);
		$roots->expects(self::never())->method('getMountsIn');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$active = $user;
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(static function () use (&$active): ?IUser {
			return $active;
		});
		$runner = new PreviewRoot($roots, new ActorRoot($roots, $session));
		$this->expectException(ToolFailure::class);
		$runner->run('bob', static function () use (&$active): array {
			$active = null;
			throw new NotFoundException('native miss before identity changed');
		});
	}

	/**
	 * Native lazy lookup itself cannot deliver a root after a persistent actor change.
	 *
	 * @return void
	 */
	public function testSessionIsRecheckedAfterLazyRootLookup(): void {
		$root = $this->createMock(Folder::class);
		$root->method('getPath')->willReturn('/bob/files');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$active = $user;
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(static function () use (&$active): ?IUser {
			return $active;
		});
		$roots = $this->createMock(IRootFolder::class);
		$roots->expects(self::once())->method('getUserFolder')->willReturnCallback(static function () use ($root, &$active): Folder {
			$active = null;
			return $root;
		});
		$roots->expects(self::never())->method('getMountsIn');
		$this->expectException(ToolFailure::class);
		(new PreviewRoot($roots, new ActorRoot($roots, $session)))->run('bob', static function (): array {
			self::fail('Plan called after identity changed');
		});
	}

	/**
	 * Legacy constructions without an actor adapter never invent a full-setup fallback.
	 *
	 * @return void
	 */
	public function testNoActorAdapterHasNoFallback(): void {
		$roots = $this->createMock(IRootFolder::class);
		$roots->method('getUserFolder')->willReturn($this->createMock(Folder::class));
		$roots->expects(self::never())->method('getMountsIn');
		$this->expectException(NotFoundException::class);
		(new PreviewRoot($roots, null))->run('bob', static function (): array {
			throw new NotFoundException('legacy missing root');
		});
	}
}
