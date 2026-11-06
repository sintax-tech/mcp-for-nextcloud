<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Polls;

use OCA\Mcp\Modules\Polls\Presentation\PollsWriteSchema;
use PHPUnit\Framework\TestCase;

/** Discovery lets a client distinguish effects and delegated access before planning. */
final class PollsMutationDescriptionsTest extends TestCase {
	/**
	 * @return void
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function testEachEffectHasItsOwnDescriptionAndAccessWarning(): void {
		$descriptions = [];
		foreach (array_keys(PollsWriteSchema::OPERATIONS) as $tool) {
			$descriptions[$tool] = PollsWriteSchema::definition($tool)->description;
		}
		self::assertCount(10, array_unique($descriptions));
		self::assertStringContainsString('admin explicitly grants editing rights', $descriptions['polls_add_share']);
		self::assertStringContainsString('set_admin grants editing rights', $descriptions['polls_update_share']);
		foreach (['allowDownload', 'timezoneName'] as $field) {
			self::assertStringContainsString('unavailable on Polls 8.6.x', PollsWriteSchema::configuration()[$field]['description']);
		}
	}
}
