<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

use OCA\Mcp\Modules\Collectives\Integration\FoundingCapabilities;
use OCA\Mcp\Modules\Collectives\Integration\FoundingSurface;
use OCA\Mcp\Modules\Collectives\Integration\TeamsSurface;
use OCA\Mcp\Modules\Collectives\Naming\CollectiveName;
use PHPUnit\Framework\TestCase;

/**
 * The native behaviour creating a collective (C04) rests on, read from what generate-collectives-admin.php copied
 * out of the SHA-pinned Collectives 4.0.0, 4.4.0, 4.5.0 and 4.7.1 and Teams (Circles) 31 to 35 archives. Each
 * assertion is a reason in the C04 design; when a release changes it, the test names the decision to revisit.
 */
final class CollectivesAdminContractTest extends TestCase {
	/** Directory of the Collectives fixtures. */
	private const DIR = __DIR__ . '/../../../../fixtures/nextcloud-api/collectives/';

	/** @return array{collectives: array<string, array<string, mixed>>, circles: array<string, array<string, mixed>>} the facts by release */
	private static function behavior(): array {
		return json_decode((string)file_get_contents(self::DIR . 'collectives-admin-behavior.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	/** @return void the facts come from exactly the archives the read tools and the recipient fixtures are pinned to */
	public function testRecordsAreThePinnedArchives(): void {
		$sources = json_decode((string)file_get_contents(self::DIR . 'collectives-admin-sources.json'), true, 512, JSON_THROW_ON_ERROR);
		$collectives = json_decode((string)file_get_contents(self::DIR . 'collectives-sources.json'), true, 512, JSON_THROW_ON_ERROR);
		$circles = json_decode((string)file_get_contents(self::DIR . '../recipient-sources.json'), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($collectives), array_keys($sources));
		foreach ($sources as $major => $pin) {
			$this->assertSame($collectives[$major]['collectives']['sha256'], $pin['collectives']['sha256'], "NC$major Collectives archive");
			$this->assertSame($circles[$major]['circles']['sha256'], $pin['circles']['sha256'], "NC$major Teams archive");
			$this->assertNotSame([], $pin['files']);
		}
		$versions = array_values(array_unique(array_column(array_column($sources, 'collectives'), 'version')));
		sort($versions);
		$this->assertSame(['4.4.0', '4.7.1'], $versions);
	}

	/** @return void every native member the creation names exists with its arity in every pinned fixture */
	public function testFoundingMembersExistInEveryFixture(): void {
		foreach (array_intersect(FoundingCapabilities::MAJORS, ['34', '35']) as $major) {
			$classes = (require __DIR__ . '/../../../../fixtures/nextcloud-api/' . $major . '.php')['classes'];
			foreach (FoundingSurface::MEMBERS as [$class, $method, $parameters]) {
				$this->assertArrayHasKey($method, $classes[$class]['methods'] ?? [], "$class::$method in NC$major");
				$this->assertCount(count($parameters), $classes[$class]['methods'][$method], "$class::$method arity in NC$major");
			}
		}
	}

	/** @return void the creation and the adoption notice the receipt relies on (R2.1, D6) */
	public function testCreationAndItsAdoptionSignal(): void {
		foreach (self::behavior()['collectives'] as $version => $record) {
			$create = $record['methods']['Service/CollectiveService.php::createCollective'];
			$this->assertStringStartsWith('function createCollective(string $userId,', $create, $version);
			$this->assertSame(1, substr_count($create, '$message = \'\';'), "$version: the notice starts empty");
			$this->assertSame(1, preg_match_all('/\$message = \$this->l10n->t\(/', $create), "$version: it is set once");
			$this->assertMatchesRegularExpression('/catch \(CircleExistsException \$e\) \{\s*\$circle = \$this->circleHelper->findCircle\(\$safeName, \$userId, Member::LEVEL_ADMIN\);'
				. '.*flagCircleAsAppManaged.*\$message = \$this->l10n->t\(/s', $create, "$version: it is set only when an administered team is adopted");
			$this->assertStringContainsString('return [$collective, $message];', $create, $version);
			$this->assertStringContainsString('$page->setLastUserId($userId);', $create, "$version: the landing page record carries the creator");
			$this->assertMatchesRegularExpression('/initializeFolder\(\$collective->getId\(\), \$userLang, \$name\)/', $create, $version);
			$this->assertSame([], $record['l10n']['empty'], "$version: no translation of the adoption notice is empty");
			$this->assertGreaterThan(20, $record['l10n']['files'], $version);
		}
	}

	/** @return void the team is created after a privileged check of the name, and the helper does not close sessions in a finally (C3, R2.3) */
	public function testTheHelperSessionsTheModuleClosesAfterwards(): void {
		foreach (self::behavior()['collectives'] as $version => $record) {
			$m = $record['methods'];
			$create = $m['Service/CircleHelper.php::createCircle'];
			$this->assertLessThan(strpos($create, '$this->startSession($userId);'), strpos($create, '$this->existsCircle($name)'), "$version: the name is checked first");
			$this->assertStringContainsString('$this->circlesManager->createCircle($name, null, false, false);', $create, $version);
			$this->assertStringNotContainsString('finally', $create, "$version: an exception skips stopSession");
			$this->assertStringContainsString('$this->startSuperSession();', $m['Service/CircleHelper.php::existsCircle'], $version);
			$this->assertStringContainsString('$this->startSuperSession();', $m['Service/CircleHelper.php::flagCircleAsAppManaged'], "$version: the flag is written in a privileged session");
			$this->assertStringContainsString('flagAsAppManaged($circleId)', $m['Service/CircleHelper.php::flagCircleAsAppManaged'], $version);
		}
		foreach (self::behavior()['circles'] as $version => $record) {
			$stop = $record['methods']['CirclesManager.php::stopSession'];
			$this->assertStringContainsString('unsetCurrentUser()', $stop, $version);
			$this->assertStringContainsString('bypassCurrentUserCondition(false)', $stop, $version);
		}
		$this->assertStringContainsString('CircleNameTooShortException', self::behavior()['circles']['35.0.0']['methods']['Service/CircleService.php::create'],
			'35: a short name throws inside the helper session, which the cleanup test uses');
	}

	/** @return void the name the plan shows is what both apps store (R2.7) */
	public function testNameNormalizationMatchesBothApps(): void {
		foreach (self::behavior()['collectives'] as $version => $record) {
			$sanitize = $record['methods']['Fs/NodeHelper.php::sanitiseFilename'];
			$this->assertStringContainsString("str_replace('/', '-', \$name)", $sanitize, $version);
			$this->assertStringContainsString("str_replace(['*', '|', '\\\\', ':', '\"', '<', '>', '?'], '', \$name)", $sanitize, $version);
			$this->assertStringContainsString("trim(\$name, ' .')", $sanitize, $version);
			$this->assertStringContainsString('$this->l10n->t($default)', $sanitize, "$version: an empty name becomes the default, which MCP refuses");
			$this->assertStringContainsString('$safeName = $this->nodeHelper->sanitiseFilename($name);', $record['methods']['Service/CollectiveService.php::createCollective'], $version);
		}
		foreach (self::behavior()['circles'] as $version => $record) {
			$this->assertStringContainsString("preg_replace('/\\s+/', ' ', \$name)", $record['methods']['Service/CircleService.php::cleanCircleName'], $version);
			$this->assertStringContainsString('cleanCircleName($name)', $record['methods']['Service/CircleService.php::create'], $version);
		}
		$this->assertSame('ABC', CollectiveName::clean(CollectiveName::sanitize(CollectiveName::clean(CollectiveName::sanitize("ABC.\t")))),
			'the counterexample of the review: a second pass changes it, so control characters are refused');
	}

	/**
	 * @return void the emoji rule of MCP is accepted by every release: one pictographic character, the rule of 4.5.0;
	 *     4.7.1 also accepts flags and keycaps, which 4.5.0 refuses after the team exists, so MCP refuses them
	 */
	public function testTheEmojiRuleIsAcceptedByEveryRelease(): void {
		foreach (self::behavior()['collectives'] as $version => $record) {
			$helper = $record['methods']['Service/EmojiHelper.php'] ?? null;
			if (version_compare($version, '4.5.0', '<')) {
				$this->assertNull($helper, "$version checks no emoji: MCP does");
				continue;
			}
			$this->assertStringContainsString('MAX_LENGTH = ' . CollectiveName::MAX_EMOJI, (string)$helper, $version);
			$this->assertStringContainsString('grapheme_strlen($emoji) !== 1', (string)$helper, $version);
			$this->assertMatchesRegularExpression($version === '4.5.0' ? "~preg_match\\('/\\\\p\\{Extended_Pictographic\\}/u', \\\$emoji\\) !== 1~"
				: "~EMOJI_REGEX = '/\\\\p\\{Extended_Pictographic\\}\\|~", (string)$helper, "$version accepts any pictographic character");
		}
		$this->assertNotNull(CollectiveName::emoji('📘'));
		foreach (['🇧🇷', '1️⃣'] as $refused) {
			try {
				CollectiveName::emoji($refused);
				$this->fail("$refused is refused by 4.5.0 after the team exists");
			} catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/** @return void the receipt reads without repairing, and proves the page record (R2.8) */
	public function testTheReadbackReadsWithoutCreating(): void {
		foreach (self::behavior()['collectives'] as $version => $record) {
			$m = $record['methods'];
			$this->assertDoesNotMatchRegularExpression('/->get\(|new|create/i', $m['Mount/CollectiveFolderManager.php::getRootPath'], "$version: the root path is only built");
			$this->assertStringContainsString("newFolder('collectives')", $m['Mount/CollectiveFolderManager.php::getRootFolder'], "$version: the root is created when missing");
			$this->assertStringNotContainsString('newFolder', $m['Mount/CollectiveFolderManager.php::getFolder'], $version);
			$this->assertStringContainsString('$lastUserId = ($page !== null) ? $page->getLastUserId() : null;', $m['Service/PageService.php::getPageByFile'],
				"$version: a page without record has no last user");
		}
	}

	/**
	 * @return void the landing page's registration is read from the very record the page service reads, by file id and
	 *     with a select only; the page service itself is not used, as it resolves the actor's Collectives folder, whose
	 *     mounts predate a collective created in the same request (H4)
	 */
	public function testTheLandingRecordIsTheOneThePageServiceReads(): void {
		foreach (self::behavior()['collectives'] as $version => $record) {
			$m = $record['methods'];
			$this->assertStringContainsString('$page = $this->pageMapper->findByFileId($file->getId());', $m['Service/PageService.php::getPageByFile'],
				"$version: the page service reads the same record");
			$find = $m['Db/PageMapper.php::findByFileId'];
			$this->assertStringStartsWith('function findByFileId(int $fileId, bool $trashed = false): ?Page {', $find, $version);
			$this->assertStringContainsString("\$qb->select('*')", $find, "$version: a plain read");
			$this->assertMatchesRegularExpression('/\$trashed\) \{\s*\$andX\[\] = \$qb->expr\(\)->isNotNull\(\'trash_timestamp\'\);\s*\} else \{\s*\$andX\[\] = \$qb->expr\(\)->isNull\(\'trash_timestamp\'\);/',
				$find, "$version: false asks for the live record");
			$this->assertDoesNotMatchRegularExpression('/insert|update|delete|setupFS/i', $find, "$version: it writes nothing");
			$this->assertStringContainsString('$folder = $this->getCollectiveFolder($collectiveId, $userId);', $m['Service/PageService.php::find'], $version);
			$this->assertStringContainsString('$this->userFolderHelper->get($userId)->get($collectiveName);', $m['Service/PageService.php::getCollectiveFolder'],
				"$version: find goes through the actor's Collectives folder");
			$this->assertStringContainsString('$this->rootFolder->getUserFolder($userId);', $m['Fs/UserFolderHelper.php::get'], $version);
		}
	}

	/** @return void the actor's Teams identity is read from a setting, and guests are refused natively only from 4.7.1 (C2, R2.4, D7) */
	public function testIdentityGuestsAndTeamFolders(): void {
		foreach (self::behavior()['circles'] as $version => $record) {
			$major = (int)$version;
			$local = $record['methods']['Service/FederatedUserService.php::getLocalFederatedUser'];
			if ($major >= 34) {
				$this->assertSame('userSingleId', $record['userSingleId'], $version);
				$this->assertStringContainsString('getCachedLocalFederatedUser($federatedUser)', $local, "$version: a recorded identity is only read");
				$this->assertStringContainsString('setValueString($userId, Application::APP_ID, ConfigLexicon::USER_SINGLE_ID', $local, "$version: a missing one is written");
			} else {
				$this->assertNull($record['userSingleId'], "$version has no setting to read: C04 stays off (D7)");
			}
			$this->assertSame($major >= 35, $record['teamFolderListener'], "$version: a team folder may be created from 35 on");
		}
		foreach (self::behavior()['collectives'] as $version => $record) {
			$this->assertSame($version === '4.7.1', str_contains($record['methods']['Controller/CollectiveController.php::create'], 'isGuest()'),
				"$version: native guest refusal; MCP refuses guests on every release");
		}
	}

	/** @return void every Teams member the member tools name exists with its arity, and every constant has the proven value (C04b) */
	public function testTeamsMembersAndConstants(): void {
		foreach (array_intersect(FoundingCapabilities::MAJORS, ['34', '35']) as $major) {
			$classes = (require __DIR__ . '/../../../../fixtures/nextcloud-api/' . $major . '.php')['classes'];
			foreach (TeamsSurface::MEMBERS as [$class, $method, $parameters]) {
				$this->assertArrayHasKey($method, $classes[$class]['methods'] ?? [], "$class::$method in NC$major");
				$this->assertCount(count($parameters), $classes[$class]['methods'][$method], "$class::$method arity in NC$major");
			}
			$recorded = self::behavior()['circles'][$major . '.0.0']['constants'];
			foreach (TeamsSurface::CONSTANTS as [$class, $name, $value]) {
				$this->assertSame($value, $recorded[substr($class, strrpos($class, '\\') + 1) . '::' . $name], "$class::$name in NC$major");
			}
		}
	}

	/**
	 * @return void Teams stores a group as a membership of the group's system team `group:<id>`, of source group, named
	 *     after the group's display name, and lists members with that team joined: the module reads a group that way,
	 *     through one of the two proven `getBasedOn` shapes (H4: a group added was not found by its type and id)
	 */
	public function testAGroupIsAMembershipOfItsSystemTeam(): void {
		$shapes = array_map(static fn (array $shape): string => 'function getBasedOn(): ' . (str_starts_with($shape[3], '?') ? '?' : '')
			. substr($shape[3], strrpos($shape[3], '\\') + 1) . ' {', TeamsSurface::BASED_ON);
		foreach (array_intersect(FoundingCapabilities::MAJORS, ['34', '35']) as $major) {
			$record = self::behavior()['circles'][$major . '.0.0'];
			$m = $record['methods'];
			$this->assertSame(16, $record['constants']['Member::TYPE_CIRCLE'], "NC$major");
			$group = $m['Service/FederatedUserService.php::getGroupCircle'];
			$this->assertStringContainsString("setName('group:' . \$groupId)", $group, "NC$major: the system team is named after the group id");
			$this->assertStringContainsString('->setSource(Member::TYPE_GROUP)', $group, "NC$major: of source group");
			$this->assertMatchesRegularExpression('/\} else \{\s*\$this->set\(\s*\$circle->getDisplayName\(\),\s*\$circle->getInstance\(\),\s*Member::TYPE_CIRCLE,/',
				$m['Model/FederatedUser.php::importFromCircle'], "NC$major: the member is the team, named after its display name");
			$this->assertStringContainsString('bool $getBasedOn = true', $m['Db/MemberRequestBuilder.php::getMemberSelectSql'], "NC$major");
			$this->assertStringContainsString('$qb->leftJoinBasedOn(CoreQueryBuilder::MEMBER, $initiator);', $m['Db/MemberRequestBuilder.php::getMemberSelectSql'], "NC$major");
			$this->assertMatchesRegularExpression('/\$qb = \$this->getMemberSelectSql\(\$initiator\);/', $m['Db/MemberRequest.php::getMembers'], "NC$major: members come with the team joined");
			$this->assertStringContainsString('return !is_null($this->basedOn);', $m['Model/Member.php::hasBasedOn'], "NC$major");
			$this->assertContains(strtok($m['Model/Member.php::getBasedOn'], "\n"), $shapes, "NC$major: a proven shape");
			$classes = (require __DIR__ . '/../../../../fixtures/nextcloud-api/' . $major . '.php')['classes'];
			$this->assertSame([], $classes[TeamsSurface::MEMBER]['methods']['getBasedOn'] ?? null, "NC$major");
		}
	}

	/** @return void the Teams rules the member tools plan around and report (C04 §13 C4–C6, §14 R2.4–R2.6, D3, D4) */
	public function testTheMemberRulesOfTeams(): void {
		foreach (array_intersect(FoundingCapabilities::MAJORS, ['34', '35']) as $major) {
			$m = self::behavior()['circles'][$major . '.0.0']['methods'];
			$status = $m['FederatedItems/SingleMemberAdd.php::manageMemberStatus'];
			$this->assertMatchesRegularExpression('/case Member::STATUS_REQUEST:\s*\$member->setLevel\(Member::LEVEL_MEMBER\);/', $status, "NC$major: a request is accepted");
			$this->assertMatchesRegularExpression('/case Member::STATUS_INVITED:\s*throw/', $status, "NC$major: an invitation is not repeated");
			$this->assertMatchesRegularExpression('/isConfig\(Circle::CFG_INVITE\)[^{]*\{\s*\$member->setStatus\(Member::STATUS_INVITED\);\s*\} else/', $status,
				"NC$major: a new invitation keeps level 0");
			$this->assertStringContainsString('insertOrUpdate($member)', $m['FederatedItems/SingleMemberAdd.php::generateMember'], "NC$major: verify already stores");
			$this->assertStringContainsString('MembersLimitException', $m['Service/CircleService.php::confirmCircleNotFull'], "NC$major: 121 is MembersLimitException");
			$this->assertMatchesRegularExpression('/\$level === Member::LEVEL_OWNER\)\s*\{\s*\$this->verifySwitchOwner/', $m['FederatedItems/MemberLevel.php::verify'], "NC$major: owner level is a transfer (D3)");
			$this->assertStringContainsString('mustBeMember()', $m['FederatedItems/MemberLevel.php::verifyMemberLevel'], "NC$major: no level for a pending member");
			$this->assertStringContainsString('cannotBeOwner()', $m['FederatedItems/MemberLevel.php::verifyMemberLevel'], "NC$major: the owner keeps its level");
			$this->assertStringContainsString('cannotBeOwner()', $m['FederatedItems/MemberRemove.php::verify'], "NC$major: the owner is never removed");
			$this->assertMatchesRegularExpression('/getUserType\(\) !== Member::TYPE_USER \|\| !\$member->isLocal\(\)\) \{\s*return;/', $m['Service/NotificationService.php::notificationInvited'],
				"NC$major: only local accounts are notified, never groups");
			$this->assertStringContainsString('setLocalCurrentApp(', $m['Service/FederatedUserService.php::getGroupCircle'], "NC$major: resolving a group sets the current app");
			$this->assertStringContainsString('hasCurrentApp()', $m['Service/FederatedUserService.php::setMemberPatron'], "NC$major: the app is recorded as inviter then");
			$this->assertMatchesRegularExpression('/if \(\$this->hasCurrentUser\(\)\) \{\s*return \$this->getCurrentUser\(\);/', $m['Service/FederatedUserService.php::getCurrentEntity'],
				"NC$major: the readback as the actor wins over the current app");
			$this->assertMatchesRegularExpression('/TYPE_MAIL\s*&& \$member->getUserType\(\) !== Member::TYPE_CONTACT/', $m['Listeners/Files/MemberAddedSendMail.php::handle'],
				"NC$major: no direct email for accounts or groups");
		}
		foreach (self::behavior()['collectives'] as $version => $record) {
			$this->assertStringContainsString('collective.level >= memberLevels.LEVEL_ADMIN', $record['adminGate'], "$version: members are managed by admins (D4)");
		}
	}
}
