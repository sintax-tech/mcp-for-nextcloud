<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Files_Versions\Versions\FakeVersion;
use OCA\Files_Versions\Versions\FakeVersionManager;
use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/../../Tools/Files/Stubs/versions_api.php';

/** The two remaining Files write tools: minted create link and actual version bytes restored after backup. */
final class FilesUploadRestoreEffectsContractTest extends TestCase {
	private EffectsGate $gate;
	private FakeTree $tree;
	private FilesFlowWiring $wiring;

	/** @return void native tree and all optional production collaborators, including the live lock provider */
	protected function setUp(): void {
		$this->gate = new EffectsGate($this);
		$this->tree = new FakeTree($this);
		$this->tree->addFile('/alice/files/Docs/old.md', 'current bytes', 'text/markdown');
		$this->wiring = new FilesFlowWiring($this, $this->gate, $this->tree);
		$this->gate->serve(fn (): array => [$this->wiring->module()]);
	}

	/** @return void upload has no content effect until its single-use create link is spent through the real route */
	public function testUploadAndTheCreateRoute(): void {
		$folder = $this->tree->nodes['/alice/files/Docs']['id'];
		$flow = (new CriticalFlow('upload', 'files_upload', 'files', 'create', ['path' => '/Docs/new.md'], fn (): array => $this->state()))
			->withoutPlanChanged('No PlanState: each call rechecks the destination; a minted link rechecks it when used.')
			->hiding(fn () => $this->wiring->hidden = [$folder], fn () => $this->wiring->hidden = [])
			->normalizing(fn (): array => $this->literals());
		(new FlowRunner($this, $this->gate, 'files'))->run($flow);
		$this->assertArrayNotHasKey('/alice/files/Docs/new.md', $this->tree->nodes);
		$this->gate->policy()->setGlobalEnabled(true);
		$this->gate->policy()->setEligible('alice', true);
		$this->gate->policy()->setConnected('alice', true);
		$routes = new CheckoutRoutes($this, $this->wiring);
		$t = new EffectTranscript($this, 'files.upload-create-route');
		$token = $this->wiring->issued[0];
		$out = $routes->call('PUT', $token, 'uploaded bytes');
		$t->record('create-upload', ['token' => $token, 'body' => 'uploaded bytes'], $out, $this->state());
		$this->assertSame(201, $out['status']);
		$this->assertSame('uploaded bytes', $this->tree->nodes['/alice/files/Docs/new.md']['content']);
		$out = $routes->call('PUT', $token, 'again');
		$t->record('spent-create-link', ['token' => $token], $out, $this->state());
		$this->assertSame(410, $out['status']);
		$t->assertGolden($this->literals());
	}

	/** @return void real VersionRestore reads the version and writes through the node, preserving a current-byte backup */
	public function testRestoringTheSelectedVersion(): void {
		$version = (new FakeVersion(1759100000, 1759100000, 13, 'text/markdown', 'old.md'))->withContent('version bytes');
		$manager = FakeVersionManager::with([$version]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $id === 'OCA\Files_Versions\Versions\IVersionManager'
			? $manager : throw new \RuntimeException('Unexpected service ' . $id));
		$this->wiring->services[ContainerInterface::class] = $container;
		$path = '/alice/files/Docs/old.md';
		$flow = (new CriticalFlow('version-restore', 'files_version_restore', 'files', 'restore',
			fn (): array => ['path' => '/Docs/old.md', 'version' => '1759100000'],
			fn (): array => $this->state()))
			->withoutPlanChanged('Version restore uses the supplied ETag rather than a PlanState.')
			->hiding(fn () => $this->wiring->hidden = [$this->tree->nodes[$path]['id']], fn () => $this->wiring->hidden = []);
		(new FlowRunner($this, $this->gate, 'files'))->run($flow);
		$this->assertSame('version bytes', $this->tree->nodes[$path]['content']);
		$this->assertSame('current bytes', $this->tree->nodes['/alice/files/MCP backups/Docs/old.md.20260921-141320.bak']['content']);
		$this->assertContains('read', $manager->ops);
		$this->assertNotContains('rollback', $manager->ops, 'with a lock provider the node write is required');
	}

	/** @return array<string, mixed> files, backups, operation log and minted token rows */
	private function state(): array {
		return ['tree' => FilesEffectState::tree($this->tree), 'tokens' => FilesEffectState::tokens($this->wiring->tokens)];
	}

	/** @return array<string, string> random create tokens replaced only after checking their prefix and shape */
	private function literals(): array {
		$map = [];
		foreach ($this->wiring->issued as $i => $token) {
			$this->assertMatchesRegularExpression('/^ncmcp_co_c_[A-Za-z0-9_-]{43}$/', $token);
			$map[$token] = '<create-token#' . ($i + 1) . '>';
		}
		return $map;
	}
}
