<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Platform\FileAccess\FakeLockManager;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tests\Unit\Access\FileTypes\FileTypePolicyFixture;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Modules\Notes\NotesModule;
use OCA\Mcp\Modules\Notes\Locating\NotesRepository;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of the Notes module (RE-01): the partial edit of a note, frozen through the endpoint with a fake
 * file tree as the observable effect and the real VisibilityGuard behind a hidden tag.
 */
final class NotesEffectsContractTest extends TestCase {
	/** Id of the hidden system tag. */
	private const HIDDEN_TAG = '999';
	/** Path of the note every flow edits. */
	private const NOTE = '/alice/files/Notes/Ata.md';

	private FakeTree $tree;
	private EffectsGate $gate;
	/** @var list<int> file ids carrying the hidden tag */
	private array $hidden = [];

	/** @return void builds the tree and the endpoint */
	protected function setUp(): void {
		$this->tree = new FakeTree($this);
		$this->tree->addFile(self::NOTE, "decisões\n", 'text/markdown');
		$this->gate = new EffectsGate($this);
		$this->gate->data()->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode([self::HIDDEN_TAG]);
		$this->gate->serve(fn (): array => [$this->module()]);
	}

	/** @return NotesModule the module of one request, every collaborator present as the container autowires it */
	private function module(): NotesModule {
		$tags = $this->createMock(ISystemTagObjectMapper::class);
		$tags->method('getTagIdsForObjects')->willReturnCallback(fn (array $ids): array => array_combine(
			array_map('strval', $ids),
			array_map(fn ($id): array => in_array((int)$id, $this->hidden, true) ? [self::HIDDEN_TAG] : [], $ids),
		));
		$guard = new VisibilityGuard($this->gate->config(), $tags);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(fn () => $this->tree->rootFolder());
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
		return new NotesModule(new NotesRepository($root, $this->gate->config(), $guard), $apps, $users,
			new SharedWriteGuard($access), $access, $guard, (new FakeLockManager())->service($this),
			new PlanState($this->gate->config()), FileTypePolicyFixture::off($this));
	}

	/** @return void notes_edit appending to a note: plan, strict confirm, PlanChanged, effect and every refusal */
	public function testPartialEditOfANote(): void {
		$id = $this->tree->nodes[self::NOTE]['id'];
		$flow = (new CriticalFlow('edit-append', 'notes_edit', 'notes', 'edit',
			fn (): array => ['id' => $id, 'append' => 'próximos passos', 'etag' => $this->tree->nodes[self::NOTE]['etag']],
			fn (): array => $this->state()))
			// The content changes under the same ETag: only the plan fingerprint can tell the confirmation is stale.
			->changing(function (): void {
				$this->tree->nodes[self::NOTE]['content'] .= "nova linha\n";
			})
			->hiding(function () use ($id): void {
				$this->hidden = [$id];
			}, function (): void {
				$this->hidden = [];
			});
		(new FlowRunner($this, $this->gate, 'notes'))->run($flow);
	}

	/** @return array{files: array<string, string>, ops: list<string>} contents of every file and the write log */
	private function state(): array {
		$files = [];
		foreach ($this->tree->nodes as $path => $node) {
			if ($node['type'] === 'file') {
				$files[$path] = $node['content'];
			}
		}
		return ['files' => $files, 'ops' => $this->tree->ops];
	}
}
