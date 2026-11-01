<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tools\Files\Access\ActorRoot;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/** Discriminates actor identity, native initialization and absence of child enumeration or fallback. */
final class ActorRootTest extends TestCase {
	/**
	 * @param string $uid Synthetic user identity.
	 * @return IUser Native identity double.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	/**
	 * Completes native mount setup before returning the same root, without reading child nodes.
	 *
	 * @return void
	 */
	public function testActorRootUsesPublicMountSetupWithoutFileEnumeration(): void {
		$root = $this->createMock(Folder::class);
		$root->method('getPath')->willReturn('/bob/files');
		$root->expects(self::never())->method('getDirectoryListing');
		$root->expects(self::never())->method('getById');
		$roots = $this->createMock(IRootFolder::class);
		$roots->expects(self::once())->method('getUserFolder')->with('bob')->willReturn($root);
		$roots->expects(self::once())->method('getMountsIn')->with('/bob/files')->willReturn([]);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user('bob'));
		self::assertSame($root, (new ActorRoot($roots, $session))->get('bob'));
	}

	/**
	 * A missing or foreign actor never looks up a root or initializes mounts.
	 *
	 * @return void
	 */
	public function testIdentityMismatchHasZeroRootEffects(): void {
		foreach ([null, $this->user('alice')] as $user) {
			$roots = $this->createMock(IRootFolder::class);
			$roots->expects(self::never())->method('getUserFolder');
			$roots->expects(self::never())->method('getMountsIn');
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn($user);
			try {
				(new ActorRoot($roots, $session))->get('bob');
				self::fail('Foreign actor admitted');
			} catch (ToolFailure $error) {
				self::assertSame(ToolFailure::NOT_FOUND, $error->getMessage());
			}
		}
	}

	/**
	 * Native mount failure propagates; no alternate lookup or authority is tried.
	 *
	 * @return void
	 */
	public function testNativeSetupFailureDoesNotFallBack(): void {
		$root = $this->createMock(Folder::class);
		$root->method('getPath')->willReturn('/bob/files');
		$roots = $this->createMock(IRootFolder::class);
		$roots->expects(self::once())->method('getUserFolder')->with('bob')->willReturn($root);
		$error = new \RuntimeException('native mount setup failed');
		$roots->expects(self::once())->method('getMountsIn')->willThrowException($error);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user('bob'));
		$this->expectExceptionObject($error);
		(new ActorRoot($roots, $session))->get('bob');
	}

	/**
	 * A provider hook changing identity cannot hand the previously resolved root to a tool.
	 *
	 * @return void
	 */
	public function testIdentityChangeDuringSetupRefusesTheRoot(): void {
		$root = $this->createMock(Folder::class);
		$root->method('getPath')->willReturn('/bob/files');
		$roots = $this->createMock(IRootFolder::class);
		$roots->method('getUserFolder')->willReturn($root);
		$roots->expects(self::once())->method('getMountsIn')->with('/bob/files')->willReturn([]);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnOnConsecutiveCalls($this->user('bob'), $this->user('alice'));
		$this->expectException(ToolFailure::class);
		(new ActorRoot($roots, $session))->get('bob');
	}
	/**
	 * A root from a different actor is rejected before any native mount initialization.
	 *
	 * @return void
	 */
	public function testForeignRootPathHasZeroMountEffects(): void {
		$root = $this->createMock(Folder::class);
		$root->method('getPath')->willReturn('/alice/files');
		$roots = $this->createMock(IRootFolder::class);
		$roots->method('getUserFolder')->with('bob')->willReturn($root);
		$roots->expects(self::never())->method('getMountsIn');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user('bob'));
		$this->expectException(ToolFailure::class);
		(new ActorRoot($roots, $session))->get('bob');
	}

	/**
	 * Construction with no actor does not capture that null state before the real request binds its session.
	 *
	 * @return void
	 */
	public function testActorIsReadAtUseRatherThanCapturedAtConstruction(): void {
		$active = null;
		$root = $this->createMock(Folder::class);
		$root->method('getPath')->willReturn('/bob/files');
		$roots = $this->createMock(IRootFolder::class);
		$roots->method('getUserFolder')->with('bob')->willReturn($root);
		$roots->expects(self::once())->method('getMountsIn')->with('/bob/files')->willReturn([]);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(static function () use (&$active): ?IUser {
			return $active;
		});
		$context = new ActorRoot($roots, $session);
		$active = $this->user('bob');
		self::assertSame($root, $context->get('bob'));
	}

}
