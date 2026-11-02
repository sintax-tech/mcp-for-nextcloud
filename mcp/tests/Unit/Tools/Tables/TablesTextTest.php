<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables;

use OCA\Mcp\Modules\Tables\Presentation\TablesText;
use PHPUnit\Framework\TestCase;

/** Text from the Tables app comes back as valid UTF-8, without control or direction characters, cut to a length. */
final class TablesTextTest extends TestCase {
	/**
	 * Controls, direction overrides and invalid bytes go; length is cut; non-text becomes empty.
	 *
	 * @return void
	 */
	public function testClean(): void {
		$this->assertSame('Budgetgpj.exeIgnore', TablesText::clean("Budget\u{202E}gpj.exe\n\x07Ignore"));
		$this->assertSame('a?b', TablesText::clean("a\xFFb"));
		$this->assertSame('', TablesText::clean(null));
		$this->assertSame('', TablesText::clean(['x']));
		$this->assertSame('12', TablesText::clean(12));
		$this->assertSame(TablesText::MAX_TEXT, mb_strlen(TablesText::clean(str_repeat('é', TablesText::MAX_TEXT + 5))));
	}
}
