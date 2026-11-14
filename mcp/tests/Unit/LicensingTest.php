<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LicensingTest extends TestCase {
	/** Every library source must declare its copyright and license in its PHP header. */
	public function testEveryLibraryFileHasSpdxHeader(): void {
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../lib', \FilesystemIterator::SKIP_DOTS));
		$missing = [];
		foreach ($files as $file) {
			if (!$file->isFile()) {
				continue;
			}
			if (!self::hasHeader((string)file_get_contents($file->getPathname()))) {
				$missing[] = $file->getPathname();
			}
		}
		$this->assertSame([], $missing, 'Library sources without the SPDX header');
	}

	/** A contributor keeps their own copyright line after ours; ours and the project license stay mandatory. */
	public function testHeaderAcceptsContributorCopyrightOnlyBesidesOursAndTheProjectLicense(): void {
		$ours = ' * ' . 'SPDX-' . 'FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>';
		$theirs = ' * ' . 'SPDX-' . 'FileCopyrightText: 2026 Jane Doe <jane@example.invalid>';
		$license = ' * ' . 'SPDX-' . 'License-Identifier: AGPL-3.0-or-later';
		$header = static fn (string ...$lines): string => "<?php\n/*\n" . implode("\n", $lines) . "\n */\ndeclare(strict_types=1);\n";
		$this->assertTrue(self::hasHeader($header($ours, $license)));
		$this->assertTrue(self::hasHeader($header($ours, $theirs, $license)));
		$this->assertFalse(self::hasHeader($header($theirs, $license)), 'our copyright comes first');
		$this->assertFalse(self::hasHeader($header($theirs, $ours, $license)), 'our copyright comes first');
		$this->assertFalse(self::hasHeader($header($ours, $theirs)), 'the license is mandatory');
		$this->assertFalse(self::hasHeader($header($ours, $theirs, ' * ' . 'SPDX-' . 'License-Identifier: MIT')), 'only the project license');
		$this->assertFalse(self::hasHeader($header($ours, ' * contributed by Jane', $license)), 'only SPDX copyright lines in between');
	}

	/**
	 * @param string $source a PHP source
	 * @return bool whether it opens with our copyright, any further SPDX copyright lines and the project license
	 */
	private static function hasHeader(string $source): bool {
		// The tag names are split so the REUSE tool does not read this pattern as a header.
		return preg_match('~\A<\?php\s*/\*\s*\* ' . 'SPDX-' . 'FileCopyrightText: 2026 Sintax \(Jhonatan Jaworski\) <contato@sintax\.tech>'
			. '(?:\s*\* ' . 'SPDX-' . 'FileCopyrightText: \d{4}(?:-\d{4})? [^\r\n]+)*'
			. '\s*\* ' . 'SPDX-' . 'License-Identifier: AGPL-3\.0-or-later\s*\*/~', $source) === 1;
	}
}
