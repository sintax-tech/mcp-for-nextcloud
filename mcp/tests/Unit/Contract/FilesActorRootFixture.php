<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\Platform\FileAccess\ActorRoot;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/** Supplies the visibility contract's existing Alice identity to the new native context dependency. */
final class FilesActorRootFixture {
	/**
	 * Builds the real context adapter around the contract's own tree and synthetic bound session.
	 *
	 * @param TestCase $test PHPUnit double factory.
	 * @param Folder $folder Existing Alice fixture root.
	 * @return ActorRoot Native context dependency for this unit contract.
	 */
	public static function create(TestCase $test, Folder $folder): ActorRoot {
		$roots = $test->getMockBuilder(IRootFolder::class)->getMock();
		$roots->method('getUserFolder')->with('alice')->willReturn($folder);
		$roots->method('getMountsIn')->with('/alice/files')->willReturn([]);
		$user = $test->getMockBuilder(IUser::class)->getMock();
		$user->method('getUID')->willReturn('alice');
		$session = $test->getMockBuilder(IUserSession::class)->getMock();
		$session->method('getUser')->willReturn($user);
		return new ActorRoot($roots, $session);
	}
}
