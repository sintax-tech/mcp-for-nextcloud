<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture;

/** Logical map owners prevent a legacy namespace from bypassing module isolation. */
final class ModuleIsolationTest extends ArchitectureCase {
	public function testLiveModulesDoNotGainCrossModuleEdges(): void { $this->assertBoundary('module-isolation'); }
	public function testGroupedAliasFqcnAndLiteralReferencesFail(): void {
		foreach ([
			'use OCA~Mcp~Tools~Files\\{TextExtractor, FilesMessages as Messages};',
			'$x = new \\OCA~Mcp~Tools~Files~TextExtractor();',
			'$x = \'OCA~Mcp~Tools~Files~TextExtractor\';',
			'/** @param \\OCA~Mcp~Tools~Files~TextExtractor $x */ function x($x) {}',
		] as $code) { self::assertNotSame([], self::fixture('lib/Modules/Notes/Reading/X.php', $code)); }
		self::assertSame([], self::fixture('lib/Modules/Notes/X.php', 'use OCA~Mcp~Access~Grants~GrantPolicy; use OCA~Mcp~Tools~Notes~NotesRepository;'));
	}
}
