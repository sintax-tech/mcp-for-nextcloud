<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesCapabilities;
use OCA\Mcp\Modules\Collectives\Integration\FoundingCapabilities;
use OCA\Mcp\Modules\Collectives\Integration\TeamsCapabilities;
use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Modules\Collectives\CollectivesRig;
use OCA\Mcp\Tests\Unit\Modules\Collectives\CollectivesScene;
use OCA\Mcp\Tests\Unit\Modules\Collectives\TeamsScene;
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

	/** @return void the scene, the module over the gate's policy and the collectives.native and collectives.founding probes */
	protected function setUp(): void {
		$this->gate = new EffectsGate($this);
		$this->rig = new CollectivesRig($this);
		$this->rig->realPolicy = $this->gate->policy();
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturnCallback(static fn (string $app): string => $app === 'circles' ? '35.0.0' : '4.7.1');
		$apps->method('isEnabledForAnyone')->willReturn(true);
		$logger = $this->createMock(LoggerInterface::class);
		$native = new CollectivesCapabilities($apps, 35, static fn (): bool => true);
		$this->gate->serve(fn (): array => [$this->rig->module()], static fn (): CapabilityRegistry => new CapabilityRegistry([$native,
			new FoundingCapabilities($apps, $native, 35, static fn (): bool => true), new TeamsCapabilities($apps, $native, 35, static fn (): bool => true)], $logger));
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

	/** @return array{collectives: array<int, array<string, mixed>>, teams: array<string, array<string, mixed>>, folders: array<int, array<string, mixed>>, calls: list<string>} what Collectives and Teams hold */
	private function founded(): array {
		return ['collectives' => CollectivesScene::$collectives, 'teams' => TeamsScene::$teams, 'folders' => TeamsScene::$folders,
			'calls' => array_values(array_filter(TeamsScene::$calls, static fn (string $call): bool => str_starts_with($call, 'createCollective')))];
	}

	/** @return void collectives_create_collective: plan, strict confirm, effect (a team, a collective, its folder) and every refusal */
	public function testCreatingACollective(): void {
		$flow = (new CriticalFlow('create-collective', 'collectives_create_collective', 'collectives', 'collective.create',
			// After the effect the name is taken: later steps plan a name nobody uses yet.
			static fn (): array => ['name' => 'Projetos ' . count(CollectivesScene::$collectives), 'emoji' => '📘'], fn (): array => $this->founded()))
			->withoutPlanChanged('The plan binds the name and emoji only: a name taken after the plan is refused before the call, and a team '
				. 'adopted in the race is reported by the receipt (C04 C1/D6); both are frozen in CollectivesFoundingTest.')
			->withoutVisibility('A new collective has no file to hide yet; the names it is checked against are those Collectives and Teams list for the actor.');
		(new FlowRunner($this, $this->gate, 'collectives'))->run($flow);
	}

	/** @return array{members: list<array<string, mixed>>, calls: list<string>} the team of collective 7 and the member writes Teams received */
	private function team(): array {
		return ['members' => TeamsScene::$entries['circle-secret'], 'calls' => array_values(array_filter(TeamsScene::$calls,
			static fn (string $call): bool => (bool)preg_match('/^(addMember|levelMember|removeMember):/', $call)))];
	}

	/** @param list<string> $ids accounts @return string the first one that has no membership in collective 7 yet */
	private static function newcomer(array $ids): string {
		$present = array_column(TeamsScene::$entries['circle-secret'], 'userId');
		return array_values(array_diff($ids, $present))[0];
	}

	/** @return void collectives_add_member: plan, strict confirm, PlanChanged when the team starts requiring invitations, effect and refusals */
	public function testAddingAMember(): void {
		$flow = (new CriticalFlow('add-member', 'collectives_add_member', 'collectives', 'members',
			// After the effect the account is a member: later steps plan another newcomer.
			static fn (): array => ['collective_id' => 7, 'type' => 'user', 'id' => self::newcomer(['gina', 'hank', 'ivy', 'jack', 'kim'])], fn (): array => $this->team()))
			->changing(static function (): void {
				TeamsScene::$teams['circle-secret']['config'] = 32;
			})
			->withoutVisibility('Members are accounts and groups, not Files nodes; Teams decides who sees the team.');
		(new FlowRunner($this, $this->gate, 'collectives'))->run($flow);
	}

	/** @return void collectives_set_member_level: plan, strict confirm, PlanChanged when the member's level changed, effect and refusals */
	public function testChangingAMemberLevel(): void {
		$flow = (new CriticalFlow('set-member-level', 'collectives_set_member_level', 'collectives', 'members',
			// Every step asks for a level carol does not have yet, so each plan is a real change.
			static fn (): array => ['collective_id' => 7, 'member_id' => 'm-carol', 'level' => TeamsScene::$entries['circle-secret'][2]['level'] === 4 ? 'member' : 'moderator'],
			fn (): array => $this->team()))
			->changing(static function (): void {
				TeamsScene::$entries['circle-secret'][2]['level'] = 8;
			})
			->withoutVisibility('Members are accounts and groups, not Files nodes; Teams decides who sees the team.');
		(new FlowRunner($this, $this->gate, 'collectives'))->run($flow);
	}

	/** @return void collectives_remove_member: plan, strict confirm, PlanChanged when the member changed, effect and refusals */
	public function testRemovingAMember(): void {
		$flow = (new CriticalFlow('remove-member', 'collectives_remove_member', 'collectives', 'members',
			// After the effect the member is gone: later steps plan the next one still there.
			static fn (): array => ['collective_id' => 7, 'member_id' => array_values(array_intersect(['m-staff', 'm-carol', 'm-dave', 'm-erin'],
				array_column(TeamsScene::$entries['circle-secret'], 'id')))[0]], fn (): array => $this->team()))
			->changing(static function (): void {
				foreach (TeamsScene::$entries['circle-secret'] as &$entry) {
					if ($entry['id'] === 'm-staff') {
						$entry['level'] = 4;
					}
				}
			})
			->withoutVisibility('Members are accounts and groups, not Files nodes; Teams decides who sees the team.');
		(new FlowRunner($this, $this->gate, 'collectives'))->run($flow);
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

	/** @return void collectives_move of a leaf under another parent: plan, strict confirm, PlanChanged, effect and refusals */
	public function testMovingAPage(): void {
		$guide = CollectivesScene::$ids['Guide.md'];
		$team = CollectivesScene::$ids['Team/Readme.md'];
		$flow = (new CriticalFlow('move', 'collectives_move', 'collectives', 'move',
			// After the effect the page is under Team: later steps move it back, so their plan is still a real move.
			static fn (): array => ['collective_id' => 7, 'page_id' => $guide, 'index' => 0, 'parent_id' => isset(CollectivesScene::$tree->nodes[CollectivesScene::ROOT . '/Team/Guide.md'])
				? CollectivesScene::$ids['Readme.md'] : $team], fn (): array => $this->state()))
			// A subpage appears under the destination between the plan and the confirmation: the position meant another place.
			->changing(static function (): void {
				CollectivesScene::$tree->addFile(CollectivesScene::ROOT . '/Team/Late.md', '', 'text/markdown');
			})
			->hiding(fn () => $this->rig->hidden = [$team], fn () => $this->rig->hidden = []);
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
