<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Polls;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Static source discriminants for protected reads in each pin; these are not runtime claims. */
final class PollsContentContractTest extends TestCase {
	/** @return iterable<string,array{string,array<string,mixed>}> four SHA-bound native sources */
	public static function pins(): iterable {
		return PollsContractTest::pins();
	}

	/**
	 * Own/all selection is native, and negative anonymization precedes owner/delegate exceptions.
	 * @param string $version native release
	 * @param array<string,mixed> $record source methods
	 * @return void
	 */
	#[DataProvider('pins')]
	public function testNativeScopeAnonymizationAndLoggedInDiscriminator(string $version, array $record): void {
		$m = $record['methods'];
		$list = $m['Service/VoteService.php::list'];
		$this->assertStringContainsString('PERMISSION_POLL_ACCESS', $list);
		$this->assertStringContainsString('PERMISSION_POLL_RESULTS_VIEW', $list);
		$this->assertStringContainsString('findByPollAndUser($pollId', $list);
		$this->assertStringContainsString('findByPoll($pollId)', $list);
		$anon = $m['Db/EntityWithUser.php::getEntityAnonymization'];
		$own = strpos($anon, 'getCurrentUserIsEntityUser()');
		$locked = strpos($anon, 'getAnonymized() < 0');
		$owner = strpos($anon, 'getPollOwnerId()');
		$delegate = strpos($anon, 'Share::TYPE_ADMIN');
		$this->assertIsInt($own);
		$this->assertIsInt($locked);
		$this->assertIsInt($owner);
		$this->assertIsInt($delegate);
		$this->assertTrue($own < $locked && $locked < $owner && $owner < $delegate);
		$this->assertStringContainsString('new Anon($this->getUserId())', $m['Db/EntityWithUser.php::getUser']);
		$safe = $m['Model/UserBase.php::getSafeType'];
		$this->assertStringContainsString('getIsLoggedIn()', $safe);
		$this->assertStringContainsString('getSimpleType()', $safe);
		$this->assertStringContainsString('getType()', $safe);
		$this->assertStringContainsString('getUser()', $m['Db/Vote.php::jsonSerialize']);
		if ($version !== '8.6.3') {
			$this->assertStringContainsString('NameGenerator', $m['Model/User/Anon.php::getDisplayName']);
		}
		$this->assertStringContainsString('getUserIdHash', $m['Model/UserBase.php::getHashedUserId']);
	}

	/**
	 * Native direct link eligibility is distinct from enumeration and credential use; list itself performs no registration.
	 * @param string $version native release
	 * @param array<string,mixed> $record source methods
	 * @return void
	 */
	#[DataProvider('pins')]
	public function testEditorListAndUsableTypesDoNotResolveShares(string $version, array $record): void {
		$m = $record['methods'];
		$list = $m['Service/ShareService.php::list'];
		$this->assertStringContainsString('PERMISSION_POLL_EDIT', $list);
		$this->assertStringContainsString('getPollGroups()', $list);
		$this->assertStringContainsString('findByPoll(', $list);
		$this->assertStringContainsString('sortByCategory', $list);
		foreach (['getEffectiveShare(', '->request(', 'sendInvitation(', 'register('] as $effect) {
			// Poll::request is the legitimate ACL check, not ShareService::request which creates a personal share.
			if ($effect !== '->request(') {
				$this->assertStringNotContainsString($effect, $list);
			}
		}
		$this->assertStringContainsString('TYPE_SORT_ARRAY', $m['Service/ShareService.php::sortByCategory']);
		$validate = $m['Service/ShareService.php::validateShareType'];
		foreach (['TYPE_PUBLIC', 'TYPE_USER', 'TYPE_ADMIN', 'TYPE_GROUP', 'TYPE_EMAIL', 'TYPE_CONTACT', 'TYPE_EXTERNAL', 'default => throw new ForbiddenException'] as $piece) {
			$this->assertStringContainsString($piece, $validate);
		}
		$this->assertStringNotContainsString('TYPE_CONTACTGROUP', $validate);
		$this->assertStringNotContainsString('TYPE_TEAM', $validate);
		$this->assertStringNotContainsString('TYPE_CIRCLE', $validate);
	}
	/**
	 * Both names take no positional arguments and declare bool; VERSION_GATED covers only the source pins where absent.
	 * @param string $version native pin
	 * @param array<string,mixed> $record exact source methods
	 * @return void
	 */
	#[DataProvider('pins')]
	public function testNativeExpirationRenameKeepsExactSignature(string $version, array $record): void {
		$method = version_compare($version, '9.0.0', '<') ? 'getExpired' : 'getIsExpired';
		$this->assertMatchesRegularExpression('/^function ' . $method . '\(\): bool\s*\{/', $record['methods']['Db/Poll.php::' . $method]);
		$this->assertStringContainsString('getExpire()', $record['methods']['Db/Poll.php::' . $method]);
	}

}
