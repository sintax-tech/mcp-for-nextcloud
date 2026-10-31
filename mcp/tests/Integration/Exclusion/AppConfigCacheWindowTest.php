<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use OCP\IConfig;
use OCP\Server;

/**
 * Measures, with core's app config cache turned on, how long a web request can keep serving an exclusion list that was
 * written by another process. Core keeps the app config in the web server's local cache (APCu) for 3 seconds
 * (AppConfig::LOCAL_CACHE_TTL, NC 32 to 35) and drops it only for writes made through the same cache. The PHP CLI of
 * this suite has no such cache, which is what an `occ config:app:set` of the list or a write from another web node looks
 * like to a web server. The rest of the suite runs with the cache off; this test turns it on for its own duration.
 */
final class AppConfigCacheWindowTest extends ExclusionTestCase {
	/** What the core's own constant allows (seconds), plus the time the poll itself takes. */
	private const BOUND = 6.0;

	protected function tearDown(): void {
		Server::get(IConfig::class)->setSystemValue('cache_app_config', false);
		parent::tearDown();
	}

	public function testATagAddedToTheExclusionListByAnotherProcessTakesEffectWithinTheCoreCacheTtl(): void {
		$this->makeFile('home', 'open/w.txt', 'window file');
		$this->tag($this->node(self::ALICE, $this->path('home', 'open', 'w.txt')));
		$alice = $this->mcp();
		$path = $this->path('home', 'open', 'w.txt');
		Server::get(IConfig::class)->setSystemValue('cache_app_config', true);

		// Warm the web server's cache with the empty list, then write the list from this process at once.
		$this->assertReadable($alice, $path, 'window file', 'before the tag is excluded (cache warm)');
		$written = microtime(true);
		$this->exclude();

		$first = $alice->tool('files_read', ['path' => $path]);
		$stale = $first->ok();
		$elapsed = microtime(true) - $written;
		while ($alice->tool('files_read', ['path' => $path])->ok() && ($elapsed = microtime(true) - $written) < 15.0) {
			usleep(100000);
		}
		$elapsed = microtime(true) - $written;
		$this->observe(sprintf('F5: exclusion list written from the CLI with cache_app_config on: first read %s; the exclusion took effect after %.2f s '
			. '(core TTL 3 s, bound asserted %.0f s)', $stale ? 'still served the old list' : 'already applied it', $elapsed, self::BOUND));
		$this->assertRefusedAsMissing($alice, $alice->tool('files_read', ['path' => $path]), 'the excluded file once the cache expired');
		$this->assertLessThanOrEqual(self::BOUND, $elapsed, 'the stale window is bounded by the core cache TTL');
	}
}
