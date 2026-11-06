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
		$this->assertSame(['4.0.0', '4.4.0', '4.5.0', '4.7.1'], array_keys($behavior));
		foreach ($pins as $pin) {
			$this->assertSame($pin['collectives']['sha256'], $behavior[$pin['collectives']['version']]['sha256']);
		}
		foreach (CollectivesCapabilities::MINIMUM as $major => $minimum) {
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
		foreach ([31, 32, 33, 34, 35] as $major) {
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
		// A6: 4.0.0 cuts the content search at a native maximum of 15 unless the caller passes its own.
		$this->assertStringContainsString('int $maxResults = 15', $search['4.0.0']);
		foreach (['4.4.0', '4.5.0', '4.7.1'] as $version) {
			$this->assertStringContainsString('searchCollective(Collective $collective, string $query): array', $search[$version]);
		}
	}
}
