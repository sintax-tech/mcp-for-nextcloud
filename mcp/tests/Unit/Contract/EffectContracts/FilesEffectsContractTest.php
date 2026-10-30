<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\Files\Sharing\FakeShares;
use OCP\Constants;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of the Files module (RE-01): the inline creation of a text file and the share of an own file with a
 * user, frozen through the endpoint with a fake file tree and a fake share store as the observable effects, and the
 * real FilesAccessGuard behind a hidden system tag.
 */
final class FilesEffectsContractTest extends TestCase {
	/** Folder the new file is created in, as alice sees it. */
	private const FOLDER = '/Documentos';
	/** Absolute path of that folder in the fake tree. */
	private const FOLDER_NODE = '/alice/files/Documentos';
	/** Absolute path of the file alice shares. */
	private const SHARED_NODE = '/alice/files/Documentos/ata.md';

	private FakeTree $tree;
	private FakeShares $shares;
	private EffectsGate $gate;
	private FilesContractWiring $wiring;

	/** @return void builds the tree, the share store and the endpoint serving the Files module */
	protected function setUp(): void {
		$this->tree = new FakeTree($this);
		$this->tree->addFolder(self::FOLDER_NODE);
		$this->tree->addFile(self::SHARED_NODE, "# Ata\nolá", 'text/markdown');
		$this->shares = new FakeShares($this);
		$this->gate = new EffectsGate($this);
		$this->wiring = new FilesContractWiring($this, $this->gate, $this->tree, $this->shares);
		$this->gate->serve(fn (): array => [$this->wiring->module()]);
	}

	/** @return void files_create writing a new text file: plan, strict confirm, effect and every refusal */
	public function testCreateOfATextFile(): void {
		$folder = $this->tree->nodes[self::FOLDER_NODE]['id'];
		$flow = (new CriticalFlow('create-text', 'files_create', 'files', 'create',
			// The next free name: once a refusal step's target exists, the next step plans a new file again.
			fn (): array => ['path' => $this->freeName(self::FOLDER . '/novo', '.txt'), 'content' => "primeira linha\nsegunda linha\n"],
			fn (): array => $this->files()))
			->withoutPlanChanged('files_create carries no plan_state: the plan only shows a name that is free, and a file '
				. 'that takes the name between plan and confirmation is refused as FileExists, never answered with a new plan')
			->hiding(function () use ($folder): void {
				$this->wiring->hidden = [$folder];
			}, function (): void {
				$this->wiring->hidden = [];
			});
		(new FlowRunner($this, $this->gate, 'files'))->run($flow);
	}

	/** @return void files_share with a user: plan, strict confirm, PlanChanged, effect and every refusal */
	public function testShareOfAnOwnFileWithAUser(): void {
		// files_share is admitted by share OR link; alice holds share only, so revoking share removes the tool.
		$this->gate->grant('files', 'link', false);
		$file = $this->tree->nodes[self::SHARED_NODE]['id'];
		$flow = (new CriticalFlow('share-user', 'files_share', 'files', 'share',
			['path' => self::FOLDER . '/ata.md', 'with' => 'user:bruno'],
			fn (): array => $this->shareState()))
			// A web share for bruno with reshare appears: the planned creation became an update that drops reshare.
			->changing(function () use ($file): void {
				$this->shares->add(['node' => $file, 'with' => 'bruno', 'nodeObject' => $this->tree->node(self::SHARED_NODE),
					'permissions' => Constants::PERMISSION_READ | Constants::PERMISSION_SHARE]);
			})
			->hiding(function () use ($file): void {
				$this->wiring->hidden = [$file];
			}, function (): void {
				$this->wiring->hidden = [];
			});
		(new FlowRunner($this, $this->gate, 'files'))->run($flow);
	}

	/** @return array{files: array<string, string>, ops: list<string>} contents of every file and the write log */
	private function files(): array {
		$files = [];
		foreach ($this->tree->nodes as $path => $node) {
			if ($node['type'] === 'file') {
				$files[$path] = $node['content'];
			}
		}
		return ['files' => $files, 'ops' => $this->tree->ops];
	}

	/** @return array{shares: list<array<string, mixed>>, writes: list<string>} the stored shares and the write log */
	private function shareState(): array {
		return [
			'shares' => array_map(fn (IShare $share): array => $this->shareRow($share), $this->shares->shares),
			'writes' => array_map(static fn (array $write): string => $write[0] . ' ' . $write[1]->getId(), $this->shares->writes),
		];
	}

	/**
	 * @param IShare $share a stored share
	 * @return array<string, mixed> what the share grants, to whom, on which node
	 */
	private function shareRow(IShare $share): array {
		$expires = $share->getExpirationDate();
		return [
			'id' => $share->getId(),
			'node' => $share->getNodeId(),
			'type' => $share->getShareType(),
			'with' => $share->getSharedWith(),
			'by' => $share->getSharedBy(),
			'owner' => $share->getShareOwner(),
			'permissions' => $share->getPermissions(),
			'expires' => $expires instanceof \DateTimeInterface ? $expires->format('Y-m-d') : null,
			'note' => $share->getNote(),
		];
	}

	/**
	 * @param string $stem path without extension, relative to the home of alice
	 * @param string $extension extension with its dot
	 * @return string `<stem><extension>`, or `<stem>-<n><extension>` with the first n whose node does not exist yet
	 */
	private function freeName(string $stem, string $extension): string {
		$name = $stem . $extension;
		for ($n = 2; isset($this->tree->nodes['/alice/files' . $name]); $n++) {
			$name = $stem . '-' . $n . $extension;
		}
		return $name;
	}
}
