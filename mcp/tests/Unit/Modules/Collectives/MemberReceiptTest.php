<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Collectives;

use OCA\Mcp\Modules\Collectives\Membership\MemberReceipt;
use OCA\Mcp\Modules\Collectives\Presentation\MembershipMessages;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * How a member receipt names what the team shows after the write: applied, not visible yet, refused, or something else.
 * A membership still as before after Teams answered is not a different result (CI run 42424755894).
 */
final class MemberReceiptTest extends TestCase {
	/** A plain member. */
	private const MEMBER = ['status' => 'member', 'level' => 'member'];

	/** @return void Teams answered and the read shows what was asked */
	public function testApplied(): void {
		$receipt = MemberReceipt::of(self::MEMBER, null, null, null);
		$this->assertSame(['applied', true], [$receipt['status'], $receipt['membership_changed']]);
		$this->assertSame(MembershipMessages::applied(), $receipt['message']);
	}

	/** @return void Teams answered and the membership is still as before: not visible yet, never partial */
	public function testUnchangedWithoutErrorIsNotVisibleYet(): void {
		$receipt = MemberReceipt::of(self::MEMBER, null, self::MEMBER, null);
		$this->assertSame(['uncertain', false], [$receipt['status'], $receipt['membership_changed']]);
		$this->assertSame(self::MEMBER, $receipt['observed']);
		$this->assertSame(MembershipMessages::notVisibleYet(), $receipt['message']);
		$this->assertArrayNotHasKey('native_error', $receipt);
	}

	/** @return void Teams threw and the membership is as before */
	public function testARealErrorIsNotApplied(): void {
		$receipt = MemberReceipt::of(self::MEMBER, null, self::MEMBER, new RuntimeException('FederatedItemBadRequestException'));
		$this->assertSame(['not_applied', false], [$receipt['status'], $receipt['membership_changed']]);
		$this->assertSame(MembershipMessages::notApplied(), $receipt['message']);
		$this->assertArrayHasKey('native_error', $receipt);
	}

	/** @return void another membership than the one asked for, or a store followed by an error, stays partial */
	public function testAnotherResultOrAStoreThenAnErrorIsPartial(): void {
		$other = MemberReceipt::of(null, self::MEMBER, ['status' => 'invited', 'level' => 'none'], null);
		$this->assertSame(['partial', true], [$other['status'], $other['membership_changed']]);
		$this->assertSame(MembershipMessages::partial(), $other['message']);
		$stored = MemberReceipt::of(null, self::MEMBER, self::MEMBER, new RuntimeException('memberPreparing failed'));
		$this->assertSame('partial', $stored['status']);
		$this->assertSame(MembershipMessages::storedThenFailed(), $stored['message']);
	}

	/** @return void the team could not be read again */
	public function testUnreadIsUncertainAndUnknown(): void {
		$receipt = MemberReceipt::of(self::MEMBER, null, false, null);
		$this->assertSame(['uncertain', 'unknown'], [$receipt['status'], $receipt['membership_changed']]);
		$this->assertSame(MembershipMessages::uncertain(), $receipt['message']);
	}
}
