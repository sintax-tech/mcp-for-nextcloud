<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Files;

use PHPUnit\Framework\Assert;

/** Checks an observation at deletion time without promising future recoverability. */
final class DeleteReceiptAssertions {
	/**
	 * @param array<string, mixed> $receipt Actual native deletion receipt.
	 * @return void
	 */
	public static function observedInOwnTrash(array $receipt): void {
		Assert::assertSame('removed_observed_in_trash', $receipt['status']);
		Assert::assertSame('observed_in_own_trash', $receipt['trash']);
		Assert::assertNotEmpty($receipt['trashCheckedAt']);
		Assert::assertFalse($receipt['recoveryConfirmed']);
	}
}
