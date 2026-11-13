<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Platform\Sharing;

use OCA\Mcp\Platform\Sharing\ShareableAccount;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/** Only an exact people-search hit of the very account ID names an account; everything else reads as not found. */
final class ShareableAccountTest extends TestCase {
	public function testOnlyTheExactHitOfTheVeryIdCounts(): void {
		$search = $this->createMock(ISearch::class);
		$search->expects(self::exactly(4))->method('search')
			->with(self::anything(), [IShare::TYPE_USER], false, 25, 0)
			->willReturnCallback(static fn (string $term): array => [match ($term) {
				'bob' => ['exact' => ['users' => [['label' => 'Bob Lima', 'value' => ['shareType' => IShare::TYPE_USER, 'shareWith' => 'bob']]]], 'users' => []],
				// An e-mail or display name can be an exact hit of another account: it never names that account.
				'bob@example.com' => ['exact' => ['users' => [['label' => 'Bob Lima', 'value' => ['shareWith' => 'bob']]]], 'users' => []],
				// A partial hit of the very ID is not an exact one.
				'carol' => ['exact' => ['users' => []], 'users' => [['label' => 'Carol', 'value' => ['shareWith' => 'carol']]]],
				default => ['exact' => ['users' => []], 'users' => []],
			}, false]);
		$accounts = new ShareableAccount($search);
		self::assertSame('Bob Lima', $accounts->displayName('bob'));
		self::assertNull($accounts->displayName('bob@example.com'));
		self::assertNull($accounts->displayName('carol'));
		self::assertNull($accounts->displayName('ghost'));
		self::assertNull($accounts->displayName(''), 'an empty ID is never searched');
	}

	public function testAFailedSearchNamesNoAccount(): void {
		$search = $this->createMock(ISearch::class);
		$search->method('search')->willThrowException(new \RuntimeException('search backend down'));
		self::assertNull((new ShareableAccount($search))->displayName('bob'));
	}
}
