<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\MoveEquivalence;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../scripts/dev/bootstrap.php';

/**
 * A pinned Nextcloud source may use syntax newer than the runtime of the suite (NC35's user_status has typed class
 * constants, PHP 8.3+, while the suite also runs on 8.2): the move rehearsal keeps it byte for byte and never parses
 * it, and still parses every file of the app strictly and still catches a forgotten reader.
 */
final class PinnedUpstreamMoveTest extends TestCase {
	/** Typed constant plus a statement no PHP accepts, so the proof holds on every runtime of the matrix. */
	private const NEWER_SYNTAX = "<?php\nfinal class Probe {\n\tprivate const string NAME = 'n';\n\tpublic function f(): void { never parses here; }\n}\n";

	/** @return void the pinned path is skipped, the same code under an app path is a ParseError */
	public function testOnlyPinnedUpstreamSourcesEscapeTheStrictParse(): void {
		RepositoryMoveFixture::parse('tests/fixtures/user-status-native/35/Probe.php', self::NEWER_SYNTAX);
		self::assertTrue(RepositoryMoveFixture::pinnedUpstream('tests/fixtures/dav-metadata-native/35/apps/dav/lib/Probe.php'));
		foreach (['tests/Unit/Probe.php', 'tests/fixtures/Probe.php', 'tests/fixtures/nextcloud-api/35.php', 'lib/fixtures/user-status-native/Probe.php'] as $path) {
			self::assertFalse(RepositoryMoveFixture::pinnedUpstream($path), $path);
		}
		$this->expectException(\ParseError::class);
		RepositoryMoveFixture::parse('tests/Unit/Probe.php', self::NEWER_SYNTAX);
	}

	/** @return void a real move of an app file keeps every pinned source and still reports a forgotten reader */
	public function testARealMoveKeepsPinnedSourcesAndStillCatchesAForgottenReader(): void {
		[$before, $after, $map, $old, $new] = RepositoryMoveFixture::move(implode('/', ['Service', 'OverridePreview']) . '.php');
		self::assertArrayHasKey('tests/fixtures/user-status-native/35/PredefinedStatusService.php', $before, 'the PHP 8.3 source is in the tree');
		$pinned = array_filter(array_keys($before), RepositoryMoveFixture::pinnedUpstream(...));
		self::assertNotSame([], $pinned);
		foreach ($pinned as $path) {
			self::assertSame($before[$path], $after[$path], $path);
		}
		self::assertArrayNotHasKey($old, $after);
		self::assertArrayHasKey($new, $after);
		self::assertSame([], (new MoveEquivalence($before, $after, $map))->check());
		$forgotten = iterator_to_array(RepositoryMoveFixture::forgottenReaders($before, $after, $old, $new));
		self::assertNotSame([], $forgotten);
		foreach ($forgotten as $reader => $tree) {
			self::assertFalse(RepositoryMoveFixture::pinnedUpstream($reader));
			self::assertStringContainsString("$reader:", implode("\n", (new MoveEquivalence($before, $tree, $map))->check()));
		}
	}
}
