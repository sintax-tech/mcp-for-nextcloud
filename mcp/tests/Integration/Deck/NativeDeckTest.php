<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/** Native API proof, not MCP behavior: no new production tool is implemented by DK-6a. */
#[Group('deck')]
final class NativeDeckTest extends IntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->assertTrue(Server::get(\OCP\App\IAppManager::class)->isEnabledForAnyone('deck'),
			'Deck is required: provision it; do not skip this suite when the app is missing');
	}

	public function testPinnedManifestAndInstalledVersionMatchTheCell(): void {
		$path = getenv('NEXTCLOUD_ROOT') . '/custom_apps/deck/appinfo/info.xml';
		$xml = simplexml_load_string(file_get_contents($path));
		$this->assertNotFalse($xml);
		$this->assertSame('deck', (string)$xml->id);
		$expected = (string)getenv('MCP_IT_DECK_VERSION');
		$this->assertNotSame('', $expected, 'The harness must pass the selected pin');
		$this->assertSame($expected, (string)$xml->version);
		$this->assertSame($expected, Server::get(\OCP\IConfig::class)->getAppValue('deck', 'installed_version'));
		$this->assertSame((string)getenv('MCP_IT_NC'), (string)$xml->dependencies->nextcloud['min-version']);
		$this->assertSame((string)getenv('MCP_IT_NC'), (string)$xml->dependencies->nextcloud['max-version']);
		$apps = Server::get(\OCP\App\IAppManager::class);
		$apps->loadApp('deck');
		$native = (int)getenv('MCP_IT_NC') >= 34;
		foreach (['assignDependentCard', 'removeDependentCard'] as $method) {
			$this->assertSame($native, method_exists(\OCA\Deck\Service\CardService::class, $method), $method);
			$this->assertSame($native, str_contains(file_get_contents(dirname($path) . '/routes.php'), $method), 'route ' . $method);
		}
	}

	public function testManifestValidatorAndPinSelectionRejectInvalidInputs(): void {
		$out = DeckFixture::run(DeckFixture::OWNER, 'invalid-provisioning');
		$this->assertSame(1, $out['manifest']['status']);
		$this->assertStringContainsString('Deck manifest does not match', $out['manifest']['stderr']);
		// This subprocess exercises the pin_releases guard inside NC, not the host provisioning flow.
		$this->assertSame(1, $out['major']['status']);
		$this->assertStringContainsString('No pinned releases for Nextcloud 99', $out['major']['stderr']);
	}

	public function testFixturesHaveIndependentOwnerReaderEditorGroupAndCircleAccess(): void {
		$f = DeckFixture::run(DeckFixture::OWNER, 'seed', ['collaboration' => true]);
		$this->assertNotSame('', $f['circle']);
		foreach ([DeckFixture::OWNER, DeckFixture::READER, DeckFixture::EDITOR, DeckFixture::OUTSIDER] as $uid) {
			$seen = DeckFixture::run($uid, 'observe', $f);
			$this->assertSame($uid !== DeckFixture::OUTSIDER, $seen['source'], $uid . ' source');
			$this->assertSame($uid === DeckFixture::OWNER, $seen['private'], $uid . ' other board');
			$this->assertSame($uid !== DeckFixture::OUTSIDER, $seen['groupCard'], $uid . ' group');
			$this->assertSame(in_array($uid, [DeckFixture::OWNER, DeckFixture::EDITOR], true), $seen['circleCard'], $uid . ' circle');
			$write = DeckFixture::run($uid, 'label-write', $f);
			$this->assertSame(in_array($uid, [DeckFixture::OWNER, DeckFixture::EDITOR], true), $write['accepted'], $uid . ' EDIT');
		}
	}

	public function testLabelDeltaPreservesOthersAndForeignLabelIsRejected(): void {
		$f = DeckFixture::run(DeckFixture::OWNER, 'seed');
		$result = DeckFixture::run(DeckFixture::OWNER, 'labels', $f);
		$this->assertContains($f['keep'], $result['available']);
		$this->assertContains($f['toggle'], $result['available']);
		$both = [$f['keep'], $f['toggle']]; sort($both);
		$this->assertSame($both, $result['both']);
		$this->assertSame([$f['keep']], $result['after']);
		$this->assertFalse($result['foreign']['accepted']);
		$this->assertSame(\OCA\Deck\StatusException::class, $result['foreign']['exception']);
		$this->assertFalse(DeckFixture::run(DeckFixture::READER, 'label-write', $f)['accepted']);
	}

	public function testNativeDependencySemanticsAreAssertedOrApiIsAbsent(): void {
		$f = DeckFixture::run(DeckFixture::OWNER, 'seed');
		$hidden = DeckFixture::run(DeckFixture::OUTSIDER, 'private-seed');
		$result = DeckFixture::run(DeckFixture::OWNER, 'dependencies', $f + ['hiddenCard' => $hidden['a']]);
		$this->assertSame((int)getenv('MCP_IT_NC') >= 34, $result['available']);
		if (!$result['available']) {
			// Negative capability proof, not a skipped test; labels/files still run on NC32/33.
			$this->assertArrayNotHasKey('forward', $result);
			return;
		}
		$this->assertSame([$f['b']], $result['forward']);
		$this->assertSame([], $result['reverse']);
		$this->assertSame([$f['b']], $result['afterDuplicate'], 'read AFTER the duplicate call');
		$this->assertSame([$f['a']], $result['cycle'], 'Deck itself accepts the cycle');
		$all = [$f['a'], $f['b'], $f['privateCard']]; sort($all);
		$this->assertSame($all, $result['selfAndCrossBoard']);
		$after = [$f['a'], $f['privateCard']]; sort($after);
		$this->assertSame($after, $result['afterRemoval']);
		$reader = DeckFixture::run(DeckFixture::READER, 'observe', $f);
		$this->assertTrue($reader['source']);
		$this->assertFalse($reader['private']);
		// NATIVE ID leak: Deck returns an unreadable target. DK-3 must filter it from MCP output.
		$this->assertContains($f['privateCard'], $reader['dependentCards']);
		$this->assertFalse($result['hidden']['accepted']);
		$this->assertSame(\OCA\Deck\NoPermissionException::class, $result['hidden']['exception']);
		$this->assertFalse(DeckFixture::run(DeckFixture::READER, 'dependency-write', $f)['accepted']);
	}

	public function testFileShareAndDetachPreserveOriginalAndRecipientBytes(): void {
		$f = DeckFixture::run(DeckFixture::OWNER, 'seed');
		$this->assertSame([], DeckFixture::run(DeckFixture::READER, 'observe', $f)['files']);
		$share = DeckFixture::run(DeckFixture::OWNER, 'share', $f);
		$this->assertSame($f['fileId'], $share['fileId']);
		$this->assertSame(12, $share['type']);
		$this->assertSame(1, $share['permissions']);
		$this->assertSame(1, $share['count']);
		$this->assertSame([['id' => $f['fileId'], 'hash' => $f['hash']]], DeckFixture::run(DeckFixture::READER, 'observe', $f)['files']);
		$this->assertSame([], DeckFixture::run(DeckFixture::OUTSIDER, 'observe', $f)['files']);
		$this->assertSame(0, DeckFixture::run(DeckFixture::OWNER, 'unlink', $f + $share)['count']);
		$this->assertSame([], DeckFixture::run(DeckFixture::READER, 'observe', $f)['files']);
		$this->assertSame([['id' => $f['fileId'], 'hash' => $f['hash']]], DeckFixture::run(DeckFixture::OWNER, 'observe', $f)['files']);
	}
}
