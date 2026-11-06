<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesCapabilities;
use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Modules\Collectives\CollectivesRig;
use OCA\Mcp\Tests\Unit\Modules\Collectives\CollectivesScene;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Effect contracts of the Collectives writes (#22): each one frozen through the endpoint with the native Collectives
 * doubles as the observable effect (the files of the collective and every subpage order), behind the real admission,
 * which reads the gate's GrantPolicy.
 */
final class CollectivesEffectsContractTest extends TestCase {
	private EffectsGate $gate;
	private CollectivesRig $rig;

	/** @return void the scene, the module over the gate's policy and the collectives.native probe */
	protected function setUp(): void {
		$this->gate = new EffectsGate($this);
		$this->rig = new CollectivesRig($this);
		$this->rig->realPolicy = $this->gate->policy();
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('4.7.1');
		$apps->method('isEnabledForAnyone')->willReturn(true);
		$logger = $this->createMock(LoggerInterface::class);
		$this->gate->serve(fn (): array => [$this->rig->module()],
			static fn (): CapabilityRegistry => new CapabilityRegistry([new CollectivesCapabilities($apps, 35, static fn (): bool => true)], $logger));
	}

	/** @return array{files: array<string, array{id:int, content:string}>, orders: array<int, list<int>>, calls: list<string>} what Collectives holds */
	private function state(): array {
		$files = [];
		foreach (CollectivesScene::$tree->nodes as $path => $node) {
			if ($node['type'] === 'file' && str_starts_with($path, CollectivesScene::ROOT . '/')) {
				$files[substr($path, strlen(CollectivesScene::ROOT) + 1)] = ['id' => $node['id'], 'content' => $node['content']];
			}
		}
		ksort($files);
		return ['files' => $files, 'orders' => CollectivesScene::$orders, 'calls' => array_values(array_filter(CollectivesScene::$calls,
			static fn (string $call): bool => in_array($call, ['create', 'move'], true)))];
	}

	/** @return void collectives_create under a leaf: plan, strict confirm, PlanChanged, effect and every refusal */
	public function testCreatingAPage(): void {
		$guide = CollectivesScene::$ids['Guide.md'];
		$flow = (new CriticalFlow('create', 'collectives_create', 'collectives', 'create',
			['collective_id' => 7, 'parent_id' => $guide, 'title' => 'Setup'], fn (): array => $this->state()))
			// Somebody adds a subpage to the same parent between the plan and the confirmation: the leaf is now a folder.
			->changing(static function () use ($guide): void {
				$nodes = &CollectivesScene::$tree->nodes;
				CollectivesScene::$tree->addFolder(CollectivesScene::ROOT . '/Guide');
				$nodes[CollectivesScene::ROOT . '/Guide/Readme.md'] = $nodes[CollectivesScene::ROOT . '/Guide.md'];
				unset($nodes[CollectivesScene::ROOT . '/Guide.md']);
				CollectivesScene::$orders[$guide] = [CollectivesScene::$tree->addFile(CollectivesScene::ROOT . '/Guide/Other.md', '', 'text/markdown')];
			})
			->hiding(fn () => $this->rig->hidden = [$guide], fn () => $this->rig->hidden = []);
		(new FlowRunner($this, $this->gate, 'collectives'))->run($flow);
	}

	/** @return void collectives_edit by append: plan with diff and etag, strict confirm, PlanChanged, effect and refusals */
	public function testEditingAPage(): void {
		$guide = CollectivesScene::$ids['Guide.md'];
		$flow = (new CriticalFlow('edit-append', 'collectives_edit', 'collectives', 'edit',
			['collective_id' => 7, 'page_id' => $guide, 'append' => 'More'], fn (): array => $this->state()))
			// Same etag, other bytes: only the plan state can tell.
			->changing(static function (): void {
				CollectivesScene::$tree->nodes[CollectivesScene::ROOT . '/Guide.md']['content'] = "Guide text, edited elsewhere\n";
			})
			->confirmingWith(static fn (mixed $plan, array $arguments): array => $arguments + ['confirm' => true,
				'etag' => $plan->result->structuredContent->etag, 'plan_state' => $plan->result->structuredContent->plan_state])
			->hiding(fn () => $this->rig->hidden = [$guide], fn () => $this->rig->hidden = []);
		(new FlowRunner($this, $this->gate, 'collectives'))->run($flow);
	}
}
