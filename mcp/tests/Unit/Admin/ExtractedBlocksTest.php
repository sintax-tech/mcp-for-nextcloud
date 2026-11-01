<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * The RE-12 split of the old `js/connections.js` kept the executable code of both blocks byte for byte.
 *
 * The hashes are those of the two blocks as they stood in `js/connections.js` at 1.2.5 (lines 13-254, the connections
 * script, and lines 261-298, the personal scope script). Only comments and blank lines around them were free to change.
 */
final class ExtractedBlocksTest extends TestCase {
	/** SHA-256 of the connections IIFE, the old lines 13-254. */
	private const CONNECTIONS = 'ca4d6dcdccf4d8dde2f59e239c5204ca59a1d86ec99fdf87fe36b0b8a9644067';

	/** SHA-256 of the personal scope IIFE, the old lines 261-298. */
	private const SCOPE = '84c0579979c3764e46968a3bc0d4ca35951a681b614cb3f8ee0b28291bc07fac';

	/**
	 * The shared fragment still carries the connections IIFE exactly and ends with it.
	 *
	 * @return void
	 */
	public function testTheSharedFragmentExecutesTheOriginalConnectionsBlock(): void {
		$lines = $this->lines('src/shared/connections.js');
		$this->assertSame(self::CONNECTIONS, hash('sha256', implode('', array_slice($lines, 12, 242))));
		$this->assertCount(254, $lines);
	}

	/**
	 * The personal fragment is the SPDX header, its own doc comment and exactly the original scope IIFE.
	 *
	 * @return void
	 */
	public function testThePersonalFragmentExecutesTheOriginalScopeBlock(): void {
		$lines = $this->lines('src/settings/my-scope.js');
		$start = array_search("(function () {\n", $lines, true);
		$this->assertIsInt($start);
		$this->assertSame(self::SCOPE, hash('sha256', implode('', array_slice($lines, $start))));
	}

	/**
	 * Both bundles are the fragments of their manifests: the personal one is the shared fragment then the scope one.
	 *
	 * @return void
	 */
	public function testThePersonalManifestIsTheSharedFragmentThenTheScopeFragment(): void {
		$manifest = file(dirname(__DIR__, 3) . '/src/settings.manifest', FILE_IGNORE_NEW_LINES) ?: [];
		$this->assertSame(['shared/connections.js', 'settings/my-scope.js'], $manifest);
	}

	/**
	 * @param string $path path relative to the app directory
	 * @return list<string> the lines, each with its newline
	 */
	private function lines(string $path): array {
		return file(dirname(__DIR__, 3) . '/' . $path) ?: [];
	}
}
