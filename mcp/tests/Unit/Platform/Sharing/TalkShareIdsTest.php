<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Platform\Sharing;

use OCA\Mcp\Platform\Sharing\TalkShareIds;
use OCA\Mcp\Modules\Talk\Attachments\AttachmentAccess;
use PHPUnit\Framework\TestCase;

/** Shared Talk share identifiers preserve qualified IDs and the existing attachment facade. */
final class TalkShareIdsTest extends TestCase {
	/** @return void Bare integer/string IDs receive one prefix; every already qualified ID stays byte-identical. */
	public function testNativeIdentifierFormattingAndFacadeAreIdentical(): void {
		foreach ([[42, 'ocRoomShare:42'], ['0042', 'ocRoomShare:0042'], ['ocRoomShare:42', 'ocRoomShare:42'], ['other:42', 'other:42'], ['', 'ocRoomShare:']] as [$input, $expected]) {
			self::assertSame($expected, TalkShareIds::fullShareId($input));
			self::assertSame($expected, AttachmentAccess::fullShareId($input));
		}
	}
}
