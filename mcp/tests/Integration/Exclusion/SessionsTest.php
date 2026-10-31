<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * V14 (real): a session that already exists keeps working and keeps being judged by the current state. One login
 * (an app password with Basic, or an OAuth access token with Bearer) is used for every call of a scenario; between
 * the calls a tag is assigned or removed, the exclusion list changes and the file is moved by another client. The
 * second call always reflects the state at that moment, and the login is never revoked.
 */
final class SessionsTest extends ExclusionTestCase {
	/** @return array<string, array{string}> */
	public static function logins(): array {
		return ['Basic with an app password' => ['basic'], 'Bearer with an OAuth access token' => ['bearer']];
	}

	#[DataProvider('logins')]
	public function testTheNextCallOfTheSameSessionReflectsTagChangesListChangesAndMoves(string $kind): void {
		$this->makeFile('home', 'open/s.txt', 'session file');
		$locked = $this->makeFolder('home', 'locked');
		$this->tag($locked);
		$this->exclude();
		$session = $this->mcp(self::ALICE, $kind);
		$path = $this->path('home', 'open', 's.txt');
		$file = fn () => $this->node(self::ALICE, $path);

		$this->assertReadable($session, $path, 'session file', "first call ($kind)");

		// A tag assigned and removed between calls.
		$this->tag($file());
		$this->assertHiddenFromRead($session, $path, 'after the tag is assigned');
		$this->untag($file());
		$this->assertReadable($session, $path, 'session file', 'after the tag is removed');

		// The exclusion list itself changed: with the tag still on the file, dropping it from the list reveals it.
		$this->tag($file());
		$this->assertHiddenFromRead($session, $path, 'tagged again');
		$this->setHidden([]);
		$this->assertReadable($session, $path, 'session file', 'after the tag leaves the exclusion list');
		$this->exclude();
		$this->assertHiddenFromRead($session, $path, 'after the tag returns to the exclusion list');
		$this->untag($file());

		// Moved by another client between calls.
		$this->dav()->move($path, $this->path('home', 'locked', 's.txt'));
		$this->assertHiddenFromRead($session, $this->path('home', 'locked', 's.txt'), 'after being moved into the excluded folder');
		$this->dav()->move($this->path('home', 'locked', 's.txt'), $path);
		$this->assertReadable($session, $path, 'session file', 'after being moved out again');

		// An ancestor tagged and untagged between calls.
		$parent = $this->node(self::ALICE, $this->path('home', 'open'));
		$this->tag($parent);
		$this->assertHiddenFromRead($session, $path, 'after its folder is tagged');
		$this->untag($parent);
		$this->assertReadable($session, $path, 'session file', 'after its folder is untagged');

		$this->assertSame(200, $session->listStatus(), 'the login was never revoked by any of this');
	}
}
