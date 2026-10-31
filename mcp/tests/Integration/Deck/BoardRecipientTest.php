<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Mcp\Tests\Integration\Guests\GuestScenario;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Real native membership changes, strict authorization and missing optional Circles on every supported major.
 */
#[Group('deck')]
final class BoardRecipientTest extends IntegrationTestCase {
	/**
	 * Prove fresh complete portraits without creating a share, including nested container membership.
	 *
	 * @return void
	 */
	public function testNativeRecipientsAndHashesRefreshWithoutShares(): void {
		$fixture = DeckFixture::run(DeckFixture::OWNER, 'recipient-seed');
		$scenario = new GuestScenario();
		$scenario->run(function () use ($fixture): void {
			$this->unchangedShares($fixture);
			$baseline = $this->resolve(DeckFixture::OWNER, $fixture);
			$expected = [DeckFixture::OWNER, DeckFixture::READER, DeckFixture::EDITOR];
			sort($expected, SORT_STRING);
			$this->assertSame($expected, $baseline['uids']);
			$this->assertSame($baseline['native'], $baseline['uids']);
			$this->assertTrue($baseline['presentation']['hasGroupAcl']);
			$this->assertTrue($baseline['presentation']['hasCircleAcl']);
			$this->assertSame(3, $baseline['presentation']['total']);
			$this->assertSame($baseline, $this->resolve(DeckFixture::OWNER, $fixture));
			foreach (['group', 'circle'] as $kind) {
				$change = DeckFixture::run(DeckFixture::OWNER, 'recipient-membership',
					$fixture + ['kind' => $kind, 'add' => true]);
				$this->unchangedShares($change);
				$expanded = $this->resolve(DeckFixture::OWNER, $fixture);
				$this->assertSame(4, $expanded['presentation']['total']);
				$this->assertContains(DeckFixture::OUTSIDER, $expanded['uids']);
				$this->assertSame($expanded['native'], $expanded['uids']);
				$this->assertNotSame($baseline['recipientHash'], $expanded['recipientHash']);
				$this->assertNotSame($baseline['planHash'], $expanded['planHash']);
				$this->unchangedShares(DeckFixture::run(DeckFixture::OWNER, 'recipient-membership',
					$fixture + $change + ['kind' => $kind, 'add' => false]));
				$this->assertSame($baseline, $this->resolve(DeckFixture::OWNER, $fixture));
			}
			foreach (['deck.edit', 'files.read', 'files.share'] as $grant) {
				$this->assertRefused($this->resolve(DeckFixture::OWNER, $fixture + ['deny' => $grant]));
			}
			$this->assertRefused($this->resolve(DeckFixture::READER, $fixture));
			$this->assertRefused($this->resolve(DeckFixture::OUTSIDER, $fixture));
			$this->assertSame($expected, $this->resolve(DeckFixture::EDITOR, $fixture)['uids']);
			self::occ('app:disable', 'circles');
			$this->assertRefused($this->resolve(DeckFixture::OWNER, $fixture));
			$plain = $fixture;
			$plain['board'] = $fixture['plainBoard'];
			$plain['card'] = $fixture['plainCard'];
			$this->assertSame([DeckFixture::OWNER], $this->resolve(DeckFixture::OWNER, $plain)['uids']);
		}, function () use ($fixture, $scenario): void {
			$scenario->cleanup(static fn () => self::occ('app:enable', 'circles'));
			$scenario->cleanup(function () use ($fixture, $scenario): void {
				$cleaned = DeckFixture::run(DeckFixture::OWNER, 'recipient-cleanup', $fixture);
				$scenario->check(function () use ($cleaned): void {
					$this->assertTrue($cleaned['cleaned']);
					$this->unchangedShares($cleaned);
				});
			});
		});
	}

	/**
	 * Resolve with a new native actor and assert no share changed on either acceptance or refusal.
	 *
	 * @param string $uid Disposable authenticated actor.
	 * @param array $fixture Exact source and optional denied grant.
	 * @return array Internal portrait or refusal.
	 */
	private function resolve(string $uid, array $fixture): array {
		$result = DeckFixture::run($uid, 'recipient-resolve', $fixture);
		$this->unchangedShares($result);
		unset($result['sharesBefore'], $result['sharesAfter']);
		return $result;
	}

	/**
	 * Refusals expose no count, names, identity handles or partial hash.
	 *
	 * @param array $result Resolver result.
	 * @return void
	 */
	private function assertRefused(array $result): void {
		$this->assertSame(['refused' => true], $result);
	}

	/**
	 * Compare the entire read-only core share inventory.
	 *
	 * @param array $result Native before/after observation.
	 * @return void
	 */
	private function unchangedShares(array $result): void {
		$this->assertSame($result['sharesBefore'], $result['sharesAfter']);
	}

	/**
	 * Change optional app availability only inside the disposable test instance.
	 *
	 * @param string ...$arguments Native occ arguments.
	 * @return void
	 */
	private static function occ(string ...$arguments): void {
		$process = proc_open([PHP_BINARY, getenv('NEXTCLOUD_ROOT') . '/occ', ...$arguments],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('Cannot run disposable occ');
		}
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		if (proc_close($process) !== 0) {
			throw new RuntimeException($output);
		}
	}
}
