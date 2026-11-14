<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

use OCA\Mcp\Modules\Collectives\Integration\CollectivesCapabilities;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesSurface;
use PHPUnit\Framework\TestCase;

/**
 * The native Collectives behaviour the module is built on, read from the methods generate-collectives.php copied out
 * of the SHA-pinned releases 4.0.0, 4.4.0, 4.5.0 and 4.7.1. Each assertion is a reason in the design: if a release
 * changes it, this test says which decision to revisit.
 */
final class CollectivesContractTest extends TestCase {
	/** Directory of the Collectives fixtures. */
	private const DIR = __DIR__ . '/../../../../fixtures/nextcloud-api/collectives/';

	/** @return array<string, array{nextcloud: array{min:int, max:int}, sha256: string, methods: array<string, string>}> behaviour by release */
	private static function behavior(): array {
		return json_decode((string)file_get_contents(self::DIR . 'collectives-behavior.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	/** @return void the records come from exactly the pinned archives, and the probe's minimum per major matches info.xml */
	public function testRecordsAreThePinnedReleasesAndTheMinimumsTheirDeclaredRange(): void {
		$pins = json_decode((string)file_get_contents(self::DIR . 'collectives-sources.json'), true, 512, JSON_THROW_ON_ERROR);
		$behavior = self::behavior();
		$this->assertSame(['4.4.0', '4.5.0', '4.7.1'], array_keys($behavior));
		foreach ($pins as $pin) {
			$this->assertSame($pin['collectives']['sha256'], $behavior[$pin['collectives']['version']]['sha256']);
		}
		foreach (array_intersect_key(CollectivesCapabilities::MINIMUM, array_flip([34, 35])) as $major => $minimum) {
			$range = $behavior[$minimum]['nextcloud'];
			$this->assertTrue($major >= $range['min'] && $major <= $range['max'], "Collectives $minimum declares Nextcloud $major");
			foreach ($behavior as $version => $record) {
				if (version_compare($version, $minimum, '<')) {
					$this->assertGreaterThan($record['nextcloud']['max'], $major, "$version is below the minimum of $major but declares it");
				}
			}
		}
		$this->assertTrue(version_compare('4.7.1', CollectivesCapabilities::CEILING, '<'));
	}

	/** @return void every surface member keeps its arity in every pinned fixture */
	public function testSurfaceMembersExistInEveryFixture(): void {
		foreach ([34, 35] as $major) {
			$classes = (require __DIR__ . '/../../../../fixtures/nextcloud-api/' . $major . '.php')['classes'];
			foreach (CollectivesSurface::MEMBERS as [$class, $method, $parameters]) {
				$this->assertArrayHasKey($method, $classes[$class]['methods'] ?? [], "$class::$method in NC$major");
				$this->assertCount(count($parameters), $classes[$class]['methods'][$method], "$class::$method arity in NC$major");
			}
		}
	}

	/** @return void the facts each design decision rests on, in every release */
	public function testNativeBehaviourTheModuleReliesOn(): void {
		foreach (self::behavior() as $version => $record) {
			$m = $record['methods'];
			// A2: findAll caches the tree for the request and forces a landing page; the module reads getPagesFromFolder instead.
			$this->assertStringContainsString('$this->allPageInfos', $m['Service/PageService.php::findAll'], $version);
			$this->assertStringContainsString(', true, true)', $m['Service/PageService.php::findAll'], $version);
			$this->assertStringNotContainsString('allPageInfos', $m['Service/PageService.php::getPagesFromFolder'], $version);
			// R1: the module reads pages with find, which only reads: no file, page or index is created on the way.
			foreach (['find', 'getPageByFile', 'getParentPageId', 'getIndexPageFile'] as $read) {
				$this->assertDoesNotMatchRegularExpression('/newFile|newPage|createIndexPage|initSubFolder|->insert|->update|updateOrInsert/',
					$m['Service/PageService.php::' . $read], "$version $read");
			}
			$this->assertStringContainsString('getPageByFile($this->nodeHelper->getFileById($folder, $id))', $m['Service/PageService.php::find'], $version);
			$this->assertStringContainsString('getIndexPageFile', $m['Service/PageService.php::getParentPageId'], $version);
			// A1: the edit check reads the collective the page service caches; the module also fetches it fresh.
			$this->assertStringContainsString('canEdit()', $m['Service/PageService.php::verifyEditPermissions'], $version);
			$this->assertStringContainsString('findByIdAndUser($id, $userId)', $m['Service/CollectiveServiceBase.php::getCollective'], $version);
			// C1: a leaf parent becomes a folder; creation adds the page to the parent's order.
			$this->assertStringContainsString('initSubFolder', $m['Service/PageService.php::createBase'], $version);
			$this->assertStringContainsString('addToSubpageOrder', $m['Service/PageService.php::create'], $version);
			// A8: zero keeps the parent, and the order only changes when the parent changes.
			$this->assertStringContainsString('$parentId = $parentId ?: $oldParentId;', $m['Service/PageService.php::move'], $version);
			$this->assertMatchesRegularExpression('/if \(\$oldParentId !== \$parentId\) \{[^}]*addToSubpageOrder\(\$collectiveId, \$parentId, \$newFile->getId\(\), \$index/s', $m['Service/PageService.php::move'], $version);
			// A5: resolving the Collectives folder the first time writes a user setting.
			$this->assertStringContainsString('setUserValue', $m['Fs/UserFolderHelper.php::getUserFolderSetting'], $version);
			// The page model carries a share token: projection is by allowlist.
			$this->assertStringContainsString("'shareToken'", $m['Model/PageInfo.php::jsonSerialize'], $version);
			// Editing by file runs the native listener (links, last author, notification).
			$this->assertStringContainsString('function handle(', $m['Listeners/NodeWrittenListener.php::handle'], $version);
		}
		$search = array_map(static fn (array $record): string => $record['methods']['Service/SearchService.php::searchCollective'], self::behavior());
		foreach (['4.4.0', '4.5.0', '4.7.1'] as $version) {
			$this->assertStringContainsString('searchCollective(Collective $collective, string $query): array', $search[$version]);
		}
	}

	/** @return array<string, array{majors:list<string>, effect:string, why:string, proof:list<array{source:string, file:string, excerpt:string}>}> */
	private static function allowlist(): array {
		return json_decode((string)file_get_contents(self::DIR . 'collectives-external-leaves.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * Every method a read, a plan, a read-back or a content search can reach in each pinned major, followed through the
	 * source by CollectivesCallGraph with that major's pinned OCP and Circles types. No form is left unresolved, and every
	 * call that leaves Collectives is in collectives-external-leaves.json for that major, with a proof the generator
	 * found in the pinned file it quotes. A read reaches no write of its own: the one stored setting is the Collectives
	 * folder, written only when unset, which CollectivesAdmission refuses before; the only platform effects are the
	 * account's files folder and Circles' single circle, created by Nextcloud when the account has none yet. The 4.0.0
	 * content search creates the indices folder, so the module refuses that shape; 4.4.0 and later only read, apart
	 * from the exact pinned phputf8 bodies emitting the explicitly accepted PHP diagnostics on invalid input.
	 * @return void
	 */
	public function testEveryChainAReadReachesOnlyReads(): void {
		$chains = json_decode((string)file_get_contents(self::DIR . 'collectives-read-chains.json'), true, 512, JSON_THROW_ON_ERROR);
		$pins = json_decode((string)file_get_contents(self::DIR . 'collectives-sources.json'), true, 512, JSON_THROW_ON_ERROR);
		$allowed = self::allowlist();
		require_once self::DIR . 'CollectivesDiagnostics.php';
		$this->assertSame(array_keys($pins), array_keys($chains));
		$folder = 'OCA\\Collectives\\Fs\\UserFolderHelper::getUserFolderSetting';
		foreach ($chains as $major => $chain) {
			$this->assertSame($pins[$major]['collectives']['version'], $chain['collectives']);
			$read = $chain['read'];
			$this->assertSame([], $read['unknown'], "NC$major: every form of the read chain is resolved");
			$this->assertGreaterThan(50, count($read['methods']), "NC$major: the read chain was followed");
			foreach (['OCA\\Collectives\\Service\\PageService::find', 'OCA\\Collectives\\Service\\PageService::getParentPageId',
				'OCA\\Collectives\\Service\\CollectiveServiceBase::getCollective', $folder] as $reached) {
				$this->assertArrayHasKey($reached, $read['methods'], "NC$major reaches $reached");
			}
			$this->assertSame([$folder => ['setUserValue']], NativeWrites::in($read['methods']), "NC$major: no Collectives body of the read chain writes otherwise");
			$this->assertMatchesRegularExpression("/if \\(\\\$userCollectivesPath === ''\\) \\{[^}]*->setUserValue\\(/s", $read['methods'][$folder],
				"NC$major: the folder setting is only written when it is unset");
			$effects = $this->leavesAllowed((string)$major, $read['leaves'], $chain['proved'], 'read');
			$this->assertSame(['OCP\\IConfig::setUserValue'], array_keys($effects, 'write', true), "NC$major: the one write is the guarded folder setting");
			$this->assertSame(['OCA\\Circles\\CirclesManager::getFederatedUser', 'OCP\\Files\\IRootFolder::getUserFolder'], array_keys($effects, 'platform', true),
				"NC$major: no other platform effect");
			$search = $chain['search'];
			if ($chain['collectives'] === '4.0.0') {
				$this->assertContains('newFolder', NativeWrites::in($search['methods'])['OCA\\Collectives\\Service\\SearchService::getIndicesFolder'] ?? [],
					'4.0.0 creates the indices folder on a search: the module refuses that shape');
				continue;
			}
			\CollectivesDiagnostics::check($search['methods'], $allowed['function trigger_error']['callers']);
			$this->assertSame([], $search['unknown'], "NC$major: every form of the content search is resolved");
			$this->assertArrayHasKey('OCA\\Collectives\\Search\\FileSearch\\FileSearcher::search', $search['methods'], "NC$major: the search chain was followed");
			$this->assertSame([], NativeWrites::in($search['methods']), "NC$major: the content search only reads");
			$effects = $this->leavesAllowed((string)$major, $search['leaves'], $chain['proved'], 'search');
			$this->assertSame([], array_diff($effects, ['read', 'memory', 'diagnostic']), "NC$major: the content search leaves Collectives only to read");
		}
	}

	/**
	 * @param string $major Nextcloud major
	 * @param list<string> $leaves calls a chain makes outside Collectives
	 * @param list<string> $proved the leaves whose proofs the generator found for the major
	 * @param string $kind the chain, for the messages
	 * @return array<string, string> the effect of each leaf
	 */
	private function leavesAllowed(string $major, array $leaves, array $proved, string $kind): array {
		$allowed = self::allowlist();
		$effects = [];
		foreach ($leaves as $leaf) {
			$this->assertContains($major, $allowed[$leaf]['majors'] ?? [], "NC$major $kind: $leaf is not in the allowlist for this major");
			$this->assertContains($leaf, $proved, "NC$major $kind: the proof of $leaf was not found in its pinned file");
			$effects[$leaf] = $allowed[$leaf]['effect'];
		}
		return $effects;
	}

	/** @return void R12: a call leaving Collectives that the allowlist does not list for the major fails, whatever its name */
	public function testALeafOutsideTheAllowlistFails(): void {
		foreach ([['35', 'OCA\\Collectives\\Hidden::persist'], ['35', 'OCP\\Files\\Node::touchAll'], ['34', 'OCP\\DB\\QueryBuilder\\IFunctionBuilder::unapprovedSum']] as [$major, $leaf]) {
			try {
				$this->leavesAllowed($major, [$leaf], [$leaf], 'synthetic');
			} catch (\PHPUnit\Framework\AssertionFailedError $e) {
				$this->assertStringContainsString("$leaf is not in the allowlist for this major", $e->getMessage());
				continue;
			}
			$this->fail("$leaf in NC$major was accepted");
		}
		$this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
		$this->leavesAllowed('35', ['OCP\\IL10N::t'], [], 'synthetic');
	}

	/** @return void native leaves are reached and proved; the explicit PHP-core catalog is verified against internal declarations */
	public function testTheAllowlistIsExplicit(): void {
		$chains = json_decode((string)file_get_contents(self::DIR . 'collectives-read-chains.json'), true, 512, JSON_THROW_ON_ERROR);
		$reached = [];
		foreach ($chains as $major => $chain) {
			foreach ($chain['collectives'] === '4.0.0' ? ['read'] : ['read', 'search'] as $kind) {
				foreach ($chain[$kind]['leaves'] as $leaf) {
					$reached[$leaf][] = (string)$major;
				}
			}
		}
		foreach (self::allowlist() as $leaf => $entry) {
			if (str_starts_with($leaf, 'php-core ')) {
				require_once self::DIR . 'CollectivesCoreProof.php';
				\CollectivesCoreProof::check($leaf);
			} else {
				$this->assertSame(array_values(array_unique($reached[$leaf] ?? [])), $entry['majors'], "$leaf is allowed exactly where it is reached");
			}
			$this->assertContains($entry['effect'], ['read', 'memory', 'cache', 'platform', 'write', 'diagnostic'], $leaf);
			$this->assertNotSame('', $entry['why'], $leaf);
			if ($entry['effect'] === 'diagnostic') {
				$this->assertSame('function trigger_error', $leaf, 'only the pinned phputf8 diagnostic is admitted');
			}
			$this->assertNotSame([], $entry['proof'], $leaf);
			foreach ($entry['proof'] as $proof) {
				$this->assertContains($proof['source'], ['php', 'php-core', 'ocp', 'server', 'circles', 'collectives', 'joomla'], $leaf);
				$this->assertSame(in_array($proof['source'], ['php', 'php-core'], true), $proof['excerpt'] === '', "$leaf: a pinned source is quoted, PHP itself is not");
			}
			// PHP itself: built-in functions, its exceptions, and getMessage, which is final in Exception.
			if (str_starts_with($leaf, 'function ') || str_ends_with($leaf, '::getMessage') || (class_exists(explode('::', $leaf)[0], false) && (new \ReflectionClass(explode('::', $leaf)[0]))->isInternal())) {
				continue;
			}
			$this->assertNotSame('php', $entry['proof'][0]['source'], "$leaf: only PHP itself is proved by PHP");
		}
	}

	/** @return void the write finder catches every kind of write a Collectives body is checked for, and nothing in a read */
	public function testTheWriteFinderCatchesEveryWrite(): void {
		foreach (['$folder->newFolder(\'indices\')', '$folder->newFile($name)', '$file->putContent($x)', '$this->newPage($c, $f)',
			'$this->createIndexPage($f, 0)', '$this->initSubFolder($file)', '$qb->insert(\'t\')', '$qb->update(\'t\')',
			'$this->mapper->delete($e)', '$this->mapper->updateOrInsert($p)', '$this->config->setUserValue($u, $a, $k, $v)',
			'$node->move($to)', '$node->copy($to)', '$qb->executeStatement()'] as $write) {
			$this->assertNotSame([], NativeWrites::in(['X::m' => "function m() { $write; }"]), $write);
		}
		$this->assertSame([], NativeWrites::in(['X::m' => 'function m() { return $this->mapper->findByFileId($id) ?? $qb->select(\'*\')->executeQuery(); }']));
	}
}
