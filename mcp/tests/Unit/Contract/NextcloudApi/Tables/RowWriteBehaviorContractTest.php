<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Tables;

use OCA\Mcp\Modules\Tables\Writing\CellValueDomain;
use OCA\Mcp\Modules\Tables\Writing\RowWriteRefusal;
use OCA\Mcp\Modules\Tables\Writing\UsergroupWhitelist;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * The row write tools against what each supported release really does, read from the pinned sources into
 * tables-write.json (generate-tables-write.php): the effect {@see CellValueDomain} announces for each type is the one the
 * release's parser gives, the values it refuses are the ones the parser would lose in silence, the team rule of
 * {@see UsergroupWhitelist} follows the releases that check teams against the caller, and the facts the gates and the
 * uncertain answers rest on (validation before the enhancer, first match per hidden column, the relation lookup before
 * anything, no transaction, events after the write) hold in every pin.
 */
final class RowWriteBehaviorContractTest extends TestCase {
	/** The fixture. */
	private const FILE = __DIR__ . '/../../../../fixtures/nextcloud-api/tables/tables-write.json';

	/** Options of the selection columns. */
	private const OPTIONS = [['id' => 1, 'label' => 'a'], ['id' => 10, 'label' => 'b']];

	/**
	 * The fixture covers exactly the supported pins, from the same verified tarballs as tables-behavior.json.
	 *
	 * @return void
	 */
	public function testTheFixtureIsTheSupportedPins(): void {
		$fixture = self::fixture();
		$this->assertSame(['1.0.0', '1.0.10', '2.0.0', '2.2.2', '2.3.2'], array_keys($fixture['versions']));
		$sources = TablesBehavior::sources();
		foreach ($fixture['sha256'] as $version => $sha256) {
			$this->assertSame($sources[$version]['sha256'], $sha256, $version);
		}
	}

	/**
	 * The announced effect of each type is the parser's, and null is refused where the parser keeps something else.
	 *
	 * @return void
	 */
	public function testTheEffectsAreTheParsers(): void {
		foreach (self::fixture()['versions'] as $version => $facts) {
			$domain = new CellValueDomain(self::whitelist());
			$this->assertTrue($facts['checkParse']['positiveOnly'] && $facts['checkParse']['givesTrueOrFalse'] && $facts['checkParse']['acceptsNull'], $version);
			$this->assertSame(['argument' => false, 'effect' => 'false'], $domain->cell(['type' => 'selection', 'subtype' => 'check'], false, $version, 'alice'), $version);
			$this->assertRefused(fn () => $domain->cell(['type' => 'selection', 'subtype' => 'check'], null, $version, 'alice'), "$version check null becomes false natively");
			$this->assertTrue($facts['starsParseCastsToInt'] && $facts['progressParseCastsToInt'], $version);
			foreach (['stars', 'progress'] as $subtype) {
				$this->assertSame(['argument' => 0, 'effect' => 0], $domain->cell(['type' => 'number', 'subtype' => $subtype], 0, $version, 'alice'), "$version $subtype");
				$this->assertRefused(fn () => $domain->cell(['type' => 'number', 'subtype' => $subtype], null, $version, 'alice'), "$version $subtype null becomes 0 natively");
			}
			$single = $domain->cell(['type' => 'selection', 'subtype' => '', 'selectionOptions' => self::OPTIONS], 10, $version, 'alice')['effect'];
			$this->assertSame($facts['singleParse'] === 'string' ? '10' : 10, $single, "$version single selection");
			$this->assertContains($facts['singleParse'], ['string', 'integer'], $version);
			$this->assertTrue($facts['multiParse']['nullIsEmpty'] && $facts['multiParse']['sorts'], $version);
			$this->assertSame(['argument' => [10, 1], 'effect' => [1, 10]], $domain->cell(['type' => 'selection', 'subtype' => 'multi', 'selectionOptions' => self::OPTIONS], [10, 1], $version, 'alice'));
			$this->assertSame(['argument' => null, 'effect' => []], $domain->cell(['type' => 'selection', 'subtype' => 'multi', 'selectionOptions' => self::OPTIONS], null, $version, 'alice'));
			$this->assertTrue($facts['usergroupParseNullIsEmpty'], $version);
			$this->assertSame(['argument' => null, 'effect' => []], $domain->cell(['type' => 'usergroup', 'subtype' => ''], null, $version, 'alice'));
			$this->assertTrue($facts['numberParseNullIsEmpty'], $version);
			$this->assertSame($facts['mappers']['numberNullStaysEmpty'], CellValueDomain::keepsEmptyNumbers($version), "$version number mapper and null");
			if ($facts['mappers']['numberNullStaysEmpty']) {
				$this->assertSame(['argument' => null, 'effect' => null], $domain->cell(['type' => 'number', 'subtype' => ''], null, $version, 'alice'), $version);
			} else {
				$this->assertRefused(fn () => $domain->cell(['type' => 'number', 'subtype' => ''], null, $version, 'alice'), "$version number null becomes 0 in the mapper");
			}
		}
	}

	/**
	 * The column's minimum and maximum apply to every numeric subtype where Tables applies them, and everywhere here: stars
	 * and progress within their fixed range but outside the column's limits are refused, the exact bounds accepted.
	 *
	 * @return void
	 */
	public function testNumberLimitsCoverEverySubtype(): void {
		foreach (self::fixture()['versions'] as $version => $facts) {
			$this->assertTrue($facts['numberLimits']['applied'] === false || $facts['numberLimits']['everySubtype'] === true, $version);
			$domain = new CellValueDomain(self::whitelist());
			foreach ([['stars', 3, 4], ['progress', 20, 80]] as [$subtype, $min, $max]) {
				$column = ['type' => 'number', 'subtype' => $subtype, 'numberMin' => $min, 'numberMax' => $max];
				$this->assertSame($min, $domain->cell($column, $min, $version, 'alice')['effect'], "$version $subtype at the minimum");
				$this->assertSame($max, $domain->cell($column, $max, $version, 'alice')['effect'], "$version $subtype at the maximum");
				$this->assertRefused(fn () => $domain->cell($column, $min - 1, $version, 'alice'), "$version $subtype under the minimum");
				$this->assertRefused(fn () => $domain->cell($column, $max + 1, $version, 'alice'), "$version $subtype over the maximum");
			}
		}
	}

	/**
	 * A link is the JSON the parser keeps, and one its "title (url)" pattern would read first is refused.
	 *
	 * @return void
	 */
	public function testTheLinkIsTheParsersJson(): void {
		$column = ['type' => 'text', 'subtype' => 'link', 'textAllowedPattern' => 'url'];
		foreach (self::fixture()['versions'] as $version => $facts) {
			$this->assertTrue($facts['linkParse']['titlePatternBeforeJson'] && $facts['linkParse']['keepsCompleteJson'] && $facts['linkParse']['checksProviders'], $version);
			$domain = new CellValueDomain(self::whitelist());
			$this->assertSame('{"title":"Docs","value":"https://example.org","providerId":"url"}',
				$domain->cell($column, ['url' => 'https://example.org', 'title' => 'Docs'], $version, 'alice')['effect'], $version);
			$this->assertRefused(fn () => $domain->cell($column, ['url' => 'https://example.org', 'title' => 'Docs (http://x.y)'], $version, 'alice'), "$version title read as a url");
		}
	}

	/**
	 * Teams are written only in the releases whose own validation checks them against the caller.
	 *
	 * @return void
	 */
	public function testTeamsFollowTheReleasesThatCheckThem(): void {
		foreach (self::fixture()['versions'] as $version => $facts) {
			$this->assertSame($facts['usergroupValidatesTeamsForTheCaller'], UsergroupWhitelist::checksTeams($version), $version);
			$this->assertSame($facts['singleParse'] === 'string', CellValueDomain::parsesOptionAsString($version), $version);
		}
	}

	/**
	 * What the gates and the uncertain answers rest on holds in every pin; the relation lookup exists from 2.2.2 on and
	 * starts with the target, null included.
	 *
	 * @return void
	 */
	public function testTheWritePathFactsHold(): void {
		foreach (self::fixture()['versions'] as $version => $facts) {
			$this->assertSame(['validatesBeforeEnhancing' => true, 'eventsAfterInsert' => true], $facts['create'], $version);
			$this->assertSame(['operators' => true, 'skipsVisible' => true, 'firstMatchWins' => true], $facts['enhancer'], $version);
			$this->assertSame(['readsAndUpdates' => true, 'eventAfterUpdate' => true], $facts['updateSet'], $version);
			$this->assertTrue($facts['insertWithoutTransaction'] && $facts['updateWithoutTransaction'], $version);
			$this->assertSame(['numberFloats' => true, 'numberNullStaysEmpty' => $version === '2.3.2', 'singleKeepsRaw' => true], $facts['mappers'], $version);
			$this->assertSame(version_compare($version, '2.2.2', '>=') ? ['parse' => true, 'validateSkipsNull' => true] : null, $facts['relationReadsTargetFirst'], $version);
		}
	}

	/**
	 * Asserts a refusal of the domain.
	 *
	 * @param callable $call the call
	 * @param string $message what is refused
	 * @return void
	 */
	private function assertRefused(callable $call, string $message): void {
		try {
			$call();
			$this->fail('accepted: ' . $message);
		} catch (RowWriteRefusal) {
			$this->addToAssertionCount(1);
		}
	}

	/**
	 * A whitelist that knows nobody, enough for the types other than users and groups.
	 *
	 * @return UsergroupWhitelist the whitelist
	 */
	private static function whitelist(): UsergroupWhitelist {
		$stub = \Closure::bind(static fn (string $type): object => TestCase::createStub($type), null, TestCase::class);
		return new UsergroupWhitelist($stub(IUserManager::class), $stub(IGroupManager::class), $stub(IAppManager::class));
	}

	/**
	 * The fixture.
	 *
	 * @return array{sha256: array<string, string>, versions: array<string, array<string, mixed>>} the facts
	 */
	private static function fixture(): array {
		return json_decode((string)file_get_contents(self::FILE), true, 512, JSON_THROW_ON_ERROR);
	}
}
