<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture;

use OCA\Mcp\Tests\Architecture\Formatting\SourceStyle;

/** App style ignores HTML/string content but never ignores new code indentation. */
final class StyleTest extends ArchitectureCase {
	public function testAppStyleHasOnlyExactLegacyDebts(): void { self::assertSame([], SourceStyle::problems(self::tree()->files, self::json('style-debts'), self::tickets())); }
	public function testStyleMutantsAndNonCodeWhitespace(): void {
		// REUSE-IgnoreStart
		$clean = "<?php\n/* SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>\n * SPDX-License-Identifier: AGPL-3.0-or-later */\ndeclare(strict_types=1);\nfunction x() {\n\treturn 1;\n}\n";
		// REUSE-IgnoreEnd
		self::assertSame([], SourceStyle::issues('lib/X.php', $clean));
		foreach ([str_replace('declare(strict_types=1);', '', $clean), str_replace('strict_types=1', 'strict_types=0', $clean), str_replace('2026', '2025', $clean), str_replace('Jhonatan Jaworski', 'Other Author', $clean), str_replace('AGPL-3.0-or-later', 'MIT', $clean), str_replace("\treturn", '    return', $clean)] as $mutant) { self::assertNotSame([], SourceStyle::issues('lib/X.php', $mutant)); }
		$nonCode = $clean . "\$x = <<<'TEXT'\n    content\nTEXT;\n?>\n    <p>HTML</p>\n";
		self::assertSame([], SourceStyle::issues('lib/X.php', $nonCode));
		$js = str_replace(['<?php', 'declare(strict_types=1);'], ['', ''], $clean);
		self::assertSame([], SourceStyle::issues('src/x.js', $js));
		self::assertNotSame([], SourceStyle::issues('src/x.js', str_replace("\treturn", '    return', $js)));
		self::assertSame([], SourceStyle::issues('src/x.js', $js . "const text = `\n    literal content\n`;\n"));
	}
}
