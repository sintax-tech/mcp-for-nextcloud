<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\MoveEquivalence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../scripts/dev/bootstrap.php';

/** Includes every literal reader discovered by filename, with explicit pre-move fixture preparation. */
final class MoveEquivalenceR5RepositoryTest extends TestCase {
	/**
	 * All readers, including bare lib-relative values and # inventory identities, must follow the pair.
	 *
	 * @param string $relative source under lib/
	 * @param list<string> $readers reader paths that must each fail when individually forgotten
	 */
	#[DataProvider('moves')]
	public function testEveryReaderIsCovered(string $relative, array $readers): void {
		[$before, $after, $map, $old, $new] = RepositoryMoveFixture::move($relative);
		$this->assertSame([], (new MoveEquivalence($before, $after, $map))->check());
		$found = RepositoryMoveFixture::readers($before, $old);
		foreach ($readers as $reader) {
			$this->assertContains($reader, $found, 'Previously covered readers must remain covered');
		}
		foreach (RepositoryMoveFixture::forgottenReaders($before, $after, $old, $new) as $reader => $forgotten) {
			$this->assertNotSame($after[$reader], $forgotten[$reader], "$reader must be individually exercised");
			$problems = implode("\n", (new MoveEquivalence($before, $forgotten, $map))->check());
			$this->assertStringContainsString("$reader:", $problems);
			$this->assertStringContainsString('still refers to the file', $problems);
		}
	}

	/**
	 * @return iterable<string,array{string,list<string>}> real readers plus the existing ephemeral Mail probe
	 */
	public static function moves(): iterable {
		yield 'OverridePreview two readers' => [implode('/', ['Service', 'OverridePreview']) . '.php', ['tests/Unit/Admin/AdminOverridesAssetsTest.php', 'tests/Unit/Admin/AdminGroupAssetsTest.php']];
		yield 'PolicyWriter sorted and keyed readers' => [implode('/', ['Service', 'Policy', 'PolicyWriter']) . '.php', ['tests/Unit/Service/Scope/ScopeBoundariesTest.php', 'tests/Unit/Migration/ScopeSchemaTest.php', 'tests/Unit/Tools/FileWriteGuardCoverageTest.php']];
		yield 'FilesModule keyed inventory' => [implode('/', ['Tools', 'Files', 'FilesModule']) . '.php', ['tests/Unit/Tools/FileWriteGuardCoverageTest.php', 'tests/Unit/Tools/Files/FileContentTypePolicyTest.php']];
		yield 'EvasionProbe Mail fixture keys' => [implode('/', ['Tools', 'Mail', 'EvasionProbe']) . '.php', ['tests/Unit/Tools/FileWriteGuardCoverageTest.php']];
	}

	/** The production counting test still needs preparation: the comparator must not bless its composed reader. */
	public function testUnpreparedOverrideIsExplicitlyRefused(): void {
		[$before, $after, $map] = RepositoryMoveFixture::move(implode('/', ['Service', 'OverridePreview']) . '.php', false);
		$problems = implode("\n", (new MoveEquivalence($before, $after, $map))->check());
		$this->assertStringContainsString('tests/Unit/Admin/AdminGroupAssetsTest.php:', $problems);
		$this->assertStringContainsString('path not proved', $problems);
		$this->assertStringContainsString('inline expected path order', $problems);
	}
}
