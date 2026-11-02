<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Write;

use OCA\Mcp\Modules\Tables\Writing\CellValueDomain;
use OCA\Mcp\Modules\Tables\Writing\RowWriteRefusal;
use OCA\Mcp\Modules\Tables\Writing\UsergroupWhitelist;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * The value domain of written cells: each type accepts the values its Tables parser keeps as asked, gives the argument
 * and the effect the pinned parser produces, and refuses what the parser would lose in silence; users, groups and teams
 * follow one whitelist per release and column.
 */
final class CellValueDomainTest extends TestCase {
	/** A selection column. */
	private const SINGLE = ['type' => 'selection', 'subtype' => '', 'selectionOptions' => [['id' => 1, 'label' => 'a'], ['id' => 2, 'label' => 'b']]];

	/** A user/group column taking users and groups, several of them. */
	private const USERGROUP = ['type' => 'usergroup', 'subtype' => '', 'usergroupSelectUsers' => true, 'usergroupSelectGroups' => true,
		'usergroupSelectTeams' => true, 'usergroupMultipleItems' => true];

	/**
	 * Accepted values give the argument handed to RowService and the effect of the parser.
	 *
	 * @return void
	 */
	public function testAcceptedValues(): void {
		$domain = $this->domain();
		$this->assertSame(['argument' => 'x', 'effect' => 'x'], $domain->cell(['type' => 'text', 'subtype' => 'long'], 'x', '2.3.2', 'alice'));
		$this->assertSame(['argument' => 12, 'effect' => 12.0], $domain->cell(['type' => 'number', 'subtype' => '', 'numberMax' => 12], 12, '2.3.2', 'alice'));
		$this->assertSame(['argument' => 2.5, 'effect' => 2.5], $domain->cell(['type' => 'number', 'subtype' => ''], 2.5, '1.0.0', 'alice'));
		$this->assertSame(['argument' => 5, 'effect' => 5], $domain->cell(['type' => 'number', 'subtype' => 'stars'], 5, '2.3.2', 'alice'));
		$this->assertSame(['argument' => 100, 'effect' => 100], $domain->cell(['type' => 'number', 'subtype' => 'progress'], 100, '2.3.2', 'alice'));
		$this->assertSame(['argument' => true, 'effect' => 'true'], $domain->cell(['type' => 'selection', 'subtype' => 'check'], true, '2.3.2', 'alice'));
		$this->assertSame(['argument' => '2026-10-26 09:30', 'effect' => '2026-10-26 09:30'], $domain->cell(['type' => 'datetime', 'subtype' => ''], '2026-10-26 09:30', '2.3.2', 'alice'));
		$this->assertSame(['argument' => '23:59', 'effect' => '23:59'], $domain->cell(['type' => 'datetime', 'subtype' => 'time'], '23:59', '2.3.2', 'alice'));
		$this->assertSame(['argument' => 7, 'effect' => 7], $domain->cell(['type' => 'relation', 'subtype' => ''], 7, '2.3.2', 'alice'));
		$this->assertSame(['argument' => null, 'effect' => null], $domain->cell(['type' => 'relation', 'subtype' => ''], null, '2.3.2', 'alice'));
		foreach (['1.0.0' => 2, '1.0.10' => '2', '2.0.0' => 2, '2.2.2' => '2', '2.3.2' => '2'] as $version => $effect) {
			$this->assertSame(['argument' => 2, 'effect' => $effect], $domain->cell(self::SINGLE, 2, $version, 'alice'), "single selection in $version");
		}
		$this->assertSame(['argument' => [['id' => 'bob', 'type' => 0], ['id' => 'staff', 'type' => 1]], 'effect' => [['id' => 'bob', 'type' => 0], ['id' => 'staff', 'type' => 1]]],
			$domain->cell(self::USERGROUP, [['id' => 'bob', 'type' => 0], ['id' => 'staff', 'type' => 1]], '2.3.2', 'alice'));
		$this->assertSame(['argument' => null, 'effect' => []], $domain->cell(self::USERGROUP, null, '2.3.2', 'alice'));
		$this->assertTrue(CellValueDomain::isEmpty(['type' => 'text'], ''));
		$this->assertFalse(CellValueDomain::isEmpty(['type' => 'selection', 'subtype' => 'check'], 'false'));
	}

	/**
	 * Values the parser would turn into null, '' or 0 in silence, or that break the column's limits, are refused.
	 *
	 * @return void
	 */
	public function testSilentLossesAreRefused(): void {
		$domain = $this->domain();
		foreach ([
			'option that does not exist' => [self::SINGLE, 3],
			'option by label' => [self::SINGLE, 'a'],
			'multiple with one unknown' => [['type' => 'selection', 'subtype' => 'multi', 'selectionOptions' => [['id' => 1]]], [1, 9]],
			'multiple repeated' => [['type' => 'selection', 'subtype' => 'multi', 'selectionOptions' => [['id' => 1]]], [1, 1]],
			'date in words' => [['type' => 'datetime', 'subtype' => 'date'], 'tomorrow'],
			'impossible date' => [['type' => 'datetime', 'subtype' => 'date'], '2026-13-01'],
			'date time in ATOM' => [['type' => 'datetime', 'subtype' => ''], '2026-10-26T09:30:00+00:00'],
			'stars out of range' => [['type' => 'number', 'subtype' => 'stars'], 6],
			'stars under the column minimum' => [['type' => 'number', 'subtype' => 'stars', 'numberMin' => 3], 2],
			'progress over the column maximum' => [['type' => 'number', 'subtype' => 'progress', 'numberMax' => 50], 51],
			'number null before 2.3.2' => [['type' => 'number', 'subtype' => ''], null, '2.2.2'],
			'stars null' => [['type' => 'number', 'subtype' => 'stars'], null],
			'progress null' => [['type' => 'number', 'subtype' => 'progress'], null],
			'check null' => [['type' => 'selection', 'subtype' => 'check'], null],
			'check as text' => [['type' => 'selection', 'subtype' => 'check'], 'yes'],
			'number as text' => [['type' => 'number', 'subtype' => ''], '12'],
			'number over the maximum' => [['type' => 'number', 'subtype' => '', 'numberMax' => 10], 11],
			'number under the minimum' => [['type' => 'number', 'subtype' => '', 'numberMin' => 0], -1],
			'text over its length' => [['type' => 'text', 'subtype' => 'line', 'textMaxLength' => 3], 'abcd'],
			'link with javascript' => [['type' => 'text', 'subtype' => 'link', 'textAllowedPattern' => 'url'], ['url' => 'javascript:alert(1)']],
			'link without the url provider' => [['type' => 'text', 'subtype' => 'link', 'textAllowedPattern' => 'files'], ['url' => 'https://example.org']],
			'link with another key' => [['type' => 'text', 'subtype' => 'link', 'textAllowedPattern' => 'url'], ['url' => 'https://example.org', 'providerId' => 'x']],
			'link read as "title (url)"' => [['type' => 'text', 'subtype' => 'link', 'textAllowedPattern' => 'url'], ['url' => 'https://example.org', 'title' => 'a (https://b.c)']],
			'relation by label' => [['type' => 'relation', 'subtype' => ''], 'Hall'],
			'unknown type' => [['type' => 'formula', 'subtype' => ''], 'x'],
			'user that does not exist' => [self::USERGROUP, [['id' => 'nobody', 'type' => 0]]],
			'group that does not exist' => [self::USERGROUP, [['id' => 'nogroup', 'type' => 1]]],
			'federated type' => [self::USERGROUP, [['id' => 'x@y', 'type' => 3]]],
			'entry twice' => [self::USERGROUP, [['id' => 'bob', 'type' => 0], ['id' => 'bob', 'type' => 0]]],
			'entry with display name' => [self::USERGROUP, [['id' => 'bob', 'type' => 0, 'displayName' => 'Bob']]],
		] as $label => $case) {
			[$column, $value, $version] = $case + [2 => '2.3.2'];
			try {
				$domain->cell($column, $value, $version, 'alice');
				$this->fail('accepted: ' . $label);
			} catch (RowWriteRefusal) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/**
	 * Users and groups only where the column takes them, one entry unless it takes several, and teams only in the releases
	 * that check them against the caller, with Circles enabled.
	 *
	 * @return void
	 */
	public function testTheUsergroupWhitelist(): void {
		$usersOnly = ['type' => 'usergroup', 'subtype' => '', 'usergroupSelectUsers' => true];
		$refused = [
			[$usersOnly, [['id' => 'staff', 'type' => 1]], '2.3.2', true],
			[$usersOnly, [['id' => 'bob', 'type' => 0], ['id' => 'carol', 'type' => 0]], '2.3.2', true],
			[self::USERGROUP, [['id' => 'team', 'type' => 2]], '1.0.0', true],
			[self::USERGROUP, [['id' => 'team', 'type' => 2]], '2.0.0', true],
			[self::USERGROUP, [['id' => 'team', 'type' => 2]], '2.3.2', false],
		];
		foreach ($refused as $i => [$column, $value, $version, $circles]) {
			try {
				$this->domain($circles)->cell($column, $value, $version, 'alice');
				$this->fail("accepted case $i");
			} catch (RowWriteRefusal) {
				$this->addToAssertionCount(1);
			}
		}
		foreach (['1.0.10', '2.2.2', '2.3.2'] as $version) {
			$this->assertSame([['id' => 'team', 'type' => 2]], $this->domain(true)->cell(self::USERGROUP, [['id' => 'team', 'type' => 2]], $version, 'alice')['effect'], $version);
		}
	}

	/**
	 * The domain with the scene's users (alice, bob, carol) and groups (staff).
	 *
	 * @param bool $circles whether Circles is enabled for the caller
	 * @return CellValueDomain the domain
	 */
	private function domain(bool $circles = false): CellValueDomain {
		$users = $this->createStub(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $id): bool => in_array($id, ['alice', 'bob', 'carol'], true));
		$users->method('get')->willReturn($this->createStub(IUser::class));
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(static fn (string $id): bool => $id === 'staff');
		$apps = $this->createStub(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(static fn (string $app): bool => $app === 'circles' && $circles);
		return new CellValueDomain(new UsergroupWhitelist($users, $groups, $apps));
	}
}
