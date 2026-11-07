<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Files_Versions\Versions\FakeVersion;
use OCA\Files_Versions\Versions\FakeVersionManager;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\GoldenFile;
use OCA\Mcp\Tests\Unit\Contract\Oracle\VolatileFields;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/** The endpoint orders native versions before limiting and keeps exact-revision reads independent of listing order. */
final class FilesVersionsOrderContractTest extends TestCase {
	private EffectsGate $gate;
	private FakeVersionManager $manager;
	private FakeTree $tree;

	/** @return void wires the real Files catalog over an intentionally oldest-first native version list */
	protected function setUp(): void {
		require_once __DIR__ . '/../../Tools/Files/Stubs/versions_api.php';
		$this->tree = new FakeTree($this);
		$this->tree->addFile('/alice/files/ata.md', 'current', 'text/markdown');
		$this->manager = FakeVersionManager::with([
			(new FakeVersion('old', 100, 3, 'text/markdown', 'ata.md'))->withContent('old'),
			(new FakeVersion('2', 200, 3, 'text/markdown', 'ata.md'))->withContent('two'),
			new FakeVersion('10', 200, 10, 'text/markdown', 'ata.md'),
			new FakeVersion('new', 300, 4, 'text/markdown', 'ata.md'),
		]);
		$this->gate = new EffectsGate($this);
		$wiring = new FilesFlowWiring($this, $this->gate, $this->tree);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => $id === 'OCA\Files_Versions\Versions\IVersionManager'
			? $this->manager : throw new \RuntimeException('Unexpected service ' . $id));
		$wiring->services[ContainerInterface::class] = $container;
		$this->gate->serve(fn (): array => [$wiring->module()]);
	}

	/** @return void ascending, descending and mixed native input all produce the same newest-first bounded response */
	public function testNewestVersionsAreSelectedBeforeApplyingTheLimit(): void {
		$original = $this->manager->versions;
		$steps = [];
		foreach ([$original, array_reverse($original), [$original[1], $original[3], $original[0], $original[2]]] as $versions) {
			$this->manager->versions = $versions;
			$all = $this->listing(50);
			$limited = $this->listing(2);
			$this->assertSame(['new', '10', '2', 'old'], array_column($all['versions'], 'revision'));
			$this->assertFalse($all['truncated']);
			$this->assertSame(array_slice($all['versions'], 0, 2), $limited['versions']);
			$this->assertTrue($limited['truncated']);
			$this->assertSame($versions, $this->manager->versions, 'listing must not mutate the native manager state');
			$steps[] = ['all' => $all, 'limited' => $limited];
		}
		$this->assertSame([], $this->tree->ops, 'listing writes nothing');
		GoldenFile::assertMatches($this, __DIR__ . '/../effects/files.versions-list.json', VolatileFields::normalize($steps));
	}

	/** @return void revision ties are deterministic without losing precision on numeric identifiers */
	public function testRevisionTieBreakDoesNotDependOnBackendOrderOrIntegerRange(): void {
		$ids = ['9223372036854775808', '02', '9223372036854775809', '2', '10000000000000000000'];
		$versions = array_map(static fn (string $id): FakeVersion => new FakeVersion($id, 200, 1, 'text/plain', 'ata.md'), $ids);
		foreach ([$versions, array_reverse($versions)] as $order) {
			$this->manager->versions = $order;
			$this->assertSame(['10000000000000000000', '9223372036854775809', '9223372036854775808', '2', '02'], array_column($this->listing(50)['versions'], 'revision'));
		}
	}

	/** @return void the selected revision stays readable after listing with a limit that excludes it */
	public function testReadUsesTheExactRevisionAfterALimitedListing(): void {
		$this->listing(1);
		$response = $this->gate->call('files_version_read', ['path' => '/ata.md', 'version' => 'old']);
		$this->assertFalse($response['body']->result->isError ?? false, json_encode($response));
		$data = json_decode($response['body']->result->content[0]->text, true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame('old', $data['version']);
		$this->assertSame('old', $data['text']);
	}

	/** @return void restoration selects the requested revision even when the newest-only list excludes it */
	public function testRestoreUsesTheExactRevisionAfterALimitedListing(): void {
		$this->listing(1);
		$response = $this->gate->call('files_version_restore', ['path' => '/ata.md', 'version' => '2', 'confirm' => true]);
		$this->assertFalse($response['body']->result->isError ?? false, json_encode($response));
		$data = json_decode($response['body']->result->content[0]->text, true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame('2', $data['version']);
		$this->assertSame('two', $this->tree->nodes['/alice/files/ata.md']['content']);
		$this->assertSame('current', $this->tree->nodes['/alice/files/MCP backups/ata.md.20260921-141320.bak']['content']);
	}

	/**
	 * @param int $limit maximum number of returned versions
	 * @return array<string,mixed> version list returned through tools/call
	 */
	private function listing(int $limit): array {
		$response = $this->gate->call('files_versions_list', ['path' => '/ata.md', 'limit' => $limit]);
		$this->assertFalse($response['body']->result->isError ?? false, json_encode($response));
		return json_decode($response['body']->result->content[0]->text, true, 512, JSON_THROW_ON_ERROR);
	}
}
