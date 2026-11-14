<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Polls;

use OCA\Mcp\Platform\Compat\AppEnablement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Source contracts from SHA-verified official archives. These inspect the native ACL/context/filter flow, not just
 * method names; they remain STATIC evidence. Only integration cells execute the complete native backend.
 */
final class PollsContractTest extends TestCase {
	/** Fixture directory. */
	private const FIXTURES = __DIR__ . '/../../../../fixtures/nextcloud-api';

	/**
	 * All compared native releases, not an invented equivalence of all intermediate versions.
	 * @return iterable<string,array{0:string,1:array<string,mixed>}> version records
	 */
	public static function pins(): iterable {
		$records=json_decode(file_get_contents(self::FIXTURES.'/polls/polls-behavior.json'),true,512,JSON_THROW_ON_ERROR);
		foreach ($records as $version=>$record) {
			yield $version => [$version,$record];
		}
	}

	/**
	 * Every generated API fixture carries the same official source and only a compatible Nextcloud range.
	 * @return void
	 */
	public function testPinnedSourcesAndOldestSupportedRelease(): void {
		$pins=json_decode(file_get_contents(self::FIXTURES.'/polls/polls-sources.json'),true,512,JSON_THROW_ON_ERROR);
		$behavior=json_decode(file_get_contents(self::FIXTURES.'/polls/polls-behavior.json'),true,512,JSON_THROW_ON_ERROR);
		$this->assertSame(['9.1.4','9.3.0'],array_keys($behavior));
		foreach ($pins as $major=>$pin) {
			$fixture=require self::FIXTURES.'/'.$major.'.php';
			$this->assertSame($pin,$fixture['pollsSources']);
			$v=$pin['polls']['version'];
			$this->assertSame($behavior[$v]['sha256'],$pin['polls']['sha256']);
			$this->assertGreaterThanOrEqual($behavior[$v]['nextcloud']['min'],(int)$major);
			$this->assertLessThanOrEqual($behavior[$v]['nextcloud']['max'],(int)$major);
			$this->assertNotNull($fixture['classes']['OCA\\Polls\\Db\\Poll']);
			$this->assertNotNull($fixture['classes']['OCA\\Polls\\Service\\OptionService']);
		}
		$this->assertSame('8.6.3',AppEnablement::MINIMUM_VERSIONS['polls']);
	}

	/**
	 * The candidate query already has actor-specific ACL joins, while admin list alone is insufficient.
	 * @param string $version native release
	 * @param array<string,mixed> $record source evidence
	 * @return void
	 */
	#[DataProvider('pins')]
	public function testCandidateAclAndDetailAreNative(string $version,array $record): void {
		$m=$record['methods'];
		$this->assertStringContainsString('buildQuery(detailed: false)',$m['Db/PollMapper.php::findForMe']);
		$this->assertStringContainsString("'.deleted'",$m['Db/PollMapper.php::findForMe']);
		$this->assertStringContainsString('orWhere',$m['Db/PollMapper.php::findForMe']);
		$this->assertStringContainsString('getCurrentUserId()',$m['Db/PollMapper.php::buildQuery']);
		$this->assertStringContainsString('joinUserRole',$m['Db/PollMapper.php::buildQuery']);
		$this->assertStringContainsString('getIsAdmin()',$m['Service/PollService.php::listPolls']);
		$this->assertStringContainsString('PERMISSION_POLL_ACCESS',$m['Service/PollService.php::listPolls']);
		$this->assertStringContainsString('PERMISSION_POLL_ACCESS',$m['Service/PollService.php::get']);
		$this->assertStringContainsString('throw new NotFoundException',$m['Service/PollService.php::get']);
		$this->assertStringContainsString('linkToRouteAbsolute',$m['Db/Poll.php::getVoteUrl']);
		$this->assertStringContainsString('getAllowEditPoll()',$m['Db/Poll.php::getAllowAccessPoll']);
		$this->assertStringNotContainsString('getIsAdmin()',$m['Db/Poll.php::getAllowEditPoll']);
		$this->assertStringContainsString("defined('OC_CONSOLE')",$m['Db/Poll.php::getAllowEditPoll']);
	}

	/**
	 * Old query IDs and cached entity identity are independent and both cleanup paths are required.
	 * @param string $version native release
	 * @param array<string,mixed> $record source evidence
	 * @return void
	 */
	#[DataProvider('pins')]
	public function testNativeContextCleanupAndQueryIdentity(string $version,array $record): void {
		$m=$record['methods'];$clean=$m['UserSession.php::cleanSession'];
		$this->assertStringContainsString('SESSION_KEY_SHARE_TOKEN',$clean);
		$this->assertStringContainsString('$this->currentUser = null',$clean);
		$this->assertStringContainsString('getIsLoggedIn()',$m['UserSession.php::getCurrentUser']);
		$this->assertStringContainsString('SESSION_KEY_CRON_JOB',$m['UserSession.php::getCurrentUser']);
		$this->assertStringContainsString("defined('OC_CONSOLE')",$m['UserSession.php::getCurrentUserId']);
		$this->assertStringContainsString('$this->userId = null',$clean);
		$this->assertStringContainsString('$this->userId',$m['UserSession.php::getCurrentUserId']);

	}

	/**
	 * Native list filters options and the cached poll is by id, so a fresh native service is necessary.
	 * @param string $version native release
	 * @param array<string,mixed> $record source evidence
	 * @return void
	 */
	#[DataProvider('pins')]
	public function testNativeOptionFilteringAndCacheArePreserved(string $version,array $record): void {
		$m=$record['methods'];$list=$m['Service/OptionService.php::list'];
		$this->assertStringContainsString('PERMISSION_POLL_ACCESS',$list);
		$this->assertStringContainsString('PERMISSION_POLL_RESULTS_VIEW',$list);
		$this->assertStringContainsString('filterBookedUp()',$list);
		$this->assertStringContainsString('getId() !== $pollId',$m['Service/OptionService.php::getPoll']);
		$this->assertStringContainsString('getHideBookedUp()',$m['Service/OptionService.php::filterBookedUp']);
		$this->assertStringContainsString("'votes'",$m['Db/Option.php::jsonSerialize']);
		$this->assertStringContainsString("'currentUserStatus'",$m['Db/Poll.php::jsonSerialize']);
		$this->assertStringContainsString('getIsLocked()',$m['Db/Option.php::jsonSerialize']);
		$this->assertStringContainsString('getIsLockedByOptionLimit()',$m['Db/Option.php::getIsLocked']);
		$this->assertStringContainsString('getOptionLimit()',$m['Db/Option.php::getIsLockedByOptionLimit']);
		$this->assertStringContainsString("'.page.vote'",$m['Db/Poll.php::getVoteUrl']);
		$this->assertStringContainsString("['id' => \$this->getId()]",$m['Db/Poll.php::getVoteUrl']);
	}
}
