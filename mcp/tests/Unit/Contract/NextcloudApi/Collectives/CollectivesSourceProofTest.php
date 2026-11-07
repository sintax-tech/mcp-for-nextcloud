<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

use PHPUnit\Framework\TestCase;

/** R12: audit concrete bodies and locked dependencies; R13: keep the reader, scripts and tests below the size limit. */
final class CollectivesSourceProofTest extends TestCase {
	private const DIR = __DIR__ . '/../../../../fixtures/nextcloud-api/collectives/';

	/** @return void dependency versions, references and the lock bytes all have to match, independently */
	public function testChangedLocksVersionsAndReferencesAreRejected(): void {
		require_once self::DIR . 'CollectivesSourcePins.php';
		$lock = json_encode(['packages' => [['name' => 'wamania/php-stemmer', 'version' => 'v4.0.0', 'source' => ['reference' => 'pinned']]]]);
		$pin = ['composerLockSha256' => hash('sha256', $lock), 'vendor' => ['wamania/php-stemmer' => ['version' => 'v4.0.0', 'reference' => 'pinned']]];
		\CollectivesSourcePins::check($lock, $pin);
		$badVersion = $pin;
		$badVersion['vendor']['wamania/php-stemmer']['version'] = 'v5.0.0';
		$badReference = $pin;
		$badReference['vendor']['wamania/php-stemmer']['reference'] = 'unreviewed';
		foreach ([[$lock . ' ', $pin], [$lock, $badVersion], [$lock, $badReference]] as [$bytes, $expected]) {
			try {
				\CollectivesSourcePins::check($bytes, $expected);
			} catch (\RuntimeException $e) {
				$this->assertMatchesRegularExpression('/SHA mismatch|not locked/', $e->getMessage());
				continue;
			}
			$this->fail('A changed lock, version or reference was accepted');
		}
	}

	/** @return void an identical diagnostic in another entry, a changed body, and error_log always fail */
	public function testDiagnosticsAreRestrictedToTheExactAuditedBodies(): void {
		require_once self::DIR . 'CollectivesDiagnostics.php';
		$method = 'function utf8_to_unicode@joomla/phputf8/utils/unicode.php';
		$body = 'function utf8_to_unicode($s) { trigger_error("bad UTF-8", E_USER_WARNING); }';
		$allowed = [$method => [hash('sha256', $body)]];
		\CollectivesDiagnostics::check([$method => $body], $allowed);
		foreach ([['function other@other.php' => $body], [$method => $body . ' '],
			[$method => 'function utf8_to_unicode($s) { error_log($s); }'],
			['App::read' => 'public function read() { trigger_error("other"); }']] as $methods) {
			try {
				\CollectivesDiagnostics::check($methods, $allowed);
			} catch (\RuntimeException $e) {
				$this->assertStringContainsString('Unproved diagnostic', $e->getMessage());
				continue;
			}
			$this->fail('A diagnostic outside the exact pinned body was accepted');
		}
	}

	/** @return void probes and Wamania are followed bodies rather than leaves justified by imports or composer ranges */
	public function testTheRealChainsFollowTheProbesAndEveryStemmer(): void {
		$chains = json_decode(file_get_contents(self::DIR . 'collectives-read-chains.json'), true, 512, JSON_THROW_ON_ERROR);
		foreach ($chains as $major => $chain) {
			foreach (['CircleProbe::__construct', 'DataProbe::__construct', 'DataProbe::add', 'MemberProbe::mustBeMember'] as $method) {
				$this->assertArrayHasKey('OCA\\Circles\\Model\\Probes\\' . $method, $chain['read']['methods'], "NC$major");
			}
			if ($chain['collectives'] === '4.0.0') {
				continue;
			}
			$methods = $chain['search']['methods'];
			$this->assertArrayHasKey('Wamania\\Snowball\\StemmerFactory::create', $methods);
			$this->assertArrayHasKey('Wamania\\Snowball\\Stemmer\\English::stem', $methods);
			$this->assertGreaterThan(100, count(array_filter(array_keys($methods), static fn (string $m): bool => str_starts_with($m, 'Wamania\\'))));
			$this->assertSame([], array_values(array_filter($chain['search']['leaves'], static fn (string $m): bool => str_starts_with($m, 'Wamania\\'))));
		}
	}

	/** @return void R13's limit includes helpers, synthetic tests and the fixture generator */
	public function testEveryReaderAndTestFileStaysWithinThreeHundredLines(): void {
		$files = [...glob(self::DIR . '*.php'), ...glob(self::DIR . 'callgraph/*.php'), ...glob(__DIR__ . '/*.php')];
		foreach ($files as $file) {
			$this->assertLessThanOrEqual(300, count(file($file)), basename($file));
		}
	}
}
