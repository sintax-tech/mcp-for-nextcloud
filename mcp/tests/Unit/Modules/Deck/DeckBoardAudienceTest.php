<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck;

use OCA\Deck\Db\Acl;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Tools/Deck/Stubs/deck_stubs.php';

/** Who can read the board behind a list, read with the actor's own read permission and never expanded. */
final class DeckBoardAudienceTest extends TestCase {
	private DeckAudienceFixture $deck;

	/** A board of alice behind list 10. @return void */
	protected function setUp(): void {
		$this->deck = new DeckAudienceFixture($this);
	}

	/** A board shared with nobody has no entries, after the read check of the list. @return void */
	public function testAPrivateBoardHasNoEntries(): void {
		self::assertSame([], $this->deck->audience()->ofStack('alice', 10));
		self::assertSame([10], $this->deck->checked);
	}

	/** Users, groups and circles are named as Deck stores them; a group is never expanded into its members. @return void */
	public function testEveryEntryIsNamedAndNeverExpanded(): void {
		$this->deck->acls = [
			new Acl(['type' => Acl::PERMISSION_TYPE_GROUP, 'participant' => 'vendas', 'permissionEdit' => true]),
			new Acl(['type' => Acl::PERMISSION_TYPE_USER, 'participant' => 'pedro']),
			new Acl(['type' => Acl::PERMISSION_TYPE_CIRCLE, 'participant' => 'c1', 'permissionShare' => true, 'permissionManage' => true]),
		];

		self::assertSame([
			['type' => 'group', 'id' => 'vendas', 'displayName' => 'Group vendas', 'edit' => true, 'share' => false, 'manage' => false],
			['type' => 'user', 'id' => 'pedro', 'displayName' => 'Pedro', 'edit' => false, 'share' => false, 'manage' => false],
			['type' => 'circle', 'id' => 'c1', 'displayName' => 'c1', 'edit' => false, 'share' => true, 'manage' => true],
		], $this->deck->audience()->ofStack('alice', 10));
	}

	/** A list the actor cannot read is refused, never answered as a private board. @return void */
	public function testAListTheActorCannotReadIsRefused(): void {
		$this->deck->refused = true;
		$this->deck->acls = [new Acl(['type' => Acl::PERMISSION_TYPE_GROUP, 'participant' => 'vendas'])];

		$this->expectException(\OCA\Deck\NoPermissionException::class);
		$this->deck->audience()->ofStack('alice', 10);
	}
}
