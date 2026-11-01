<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

/** Three independent visibility boundaries, with visible controls and the actual refusal frozen. */
final class VisibilityBoundaryEffectsContractTest extends TestCase {
	/** @return void a nonexistent descendant and an existing target each consult their own visibility boundary */
	public function testWritePathChecksAncestorsAndExistingTarget(): void {
		foreach (['ancestor' => '/Docs/Private/new/child.txt', 'existing' => '/Docs/open.txt'] as $case => $target) {
			$gate = new EffectsGate($this);
			$files = new HiddenTagFiles($this, $gate);
			$files->tree->addFolder('/alice/files/Docs/Private');
			$files->tree->addFile('/alice/files/Docs/open.txt', 'original', 'text/plain');
			$root = $files->tree->rootFolder();
			$t = new EffectTranscript($this, 'visibility.write-' . $case);
			$before = FilesEffectState::tree($files->tree);
			$allowed = $files->visibility()->isPathAllowedForWrite($root, $target);
			$t->record('visible-control', $target, ['allowed' => $allowed], $before);
			$this->assertTrue($allowed);
			$path = $case === 'ancestor' ? '/alice/files/Docs/Private' : '/alice/files/Docs/open.txt';
			$files->hidden = [$files->tree->nodes[$path]['id']];
			$allowed = $files->visibility()->isPathAllowedForWrite($root, $target);
			$t->record('hidden-refusal', $target, ['allowed' => $allowed], FilesEffectState::tree($files->tree));
			$this->assertFalse($allowed, $case . ' must refuse the write path');
			$this->assertEquals($before, FilesEffectState::tree($files->tree));
			$t->assertGolden();
		}
	}

	/** @return void a clean received alias is refused when its source ancestor is tagged only in the owner's tree */
	public function testTheShareOriginIsCheckedInItsOwnersTree(): void {
		$gate = new EffectsGate($this);
		$gate->data()->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["999"]';
		$actor = new FakeTree($this);
		$id = $actor->addFile('/alice/files/received.txt', 'private bytes', 'text/plain', ['scope' => 'shared']);
		$actor->mountPath = '/alice/files/received.txt';
		$owner = new FakeTree($this, '/pedro/files');
		$owner->addFile('/pedro/files/Private/source.txt', 'private bytes', 'text/plain');
		$owner->nodes['/pedro/files']['id'] = 200;
		$owner->nodes['/pedro/files/Private']['id'] = 201;
		$owner->nodes['/pedro/files/Private/source.txt']['id'] = $id;
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(fn (string $uid) => ($uid === 'pedro' ? $owner : $actor)->rootFolder());
		$root->method('get')->willReturnCallback(fn (string $path) => (str_starts_with($path, '/pedro/') ? $owner : $actor)->node($path));
		$hidden = false;
		$tags = $this->createMock(ISystemTagObjectMapper::class);
		$tags->method('getTagIdsForObjects')->willReturnCallback(function (array $ids) use (&$hidden): array {
			return array_combine(array_map('strval', $ids), array_map(static fn ($id): array => $hidden && (int)$id === 201 ? ['999'] : [], $ids));
		});
		$guard = fn (): VisibilityGuard => new VisibilityGuard($gate->config(), $tags, $root);
		$node = $actor->node('/alice/files/received.txt');
		$t = new EffectTranscript($this, 'visibility.share-origin');
		$visible = $guard()->isVisible($node);
		$t->record('clean-origin-control', ['alias' => $node->getPath()], ['visible' => $visible], FilesEffectState::tree($actor));
		$this->assertTrue($visible);
		$hidden = true;
		$visible = $guard()->isVisible($node);
		$t->record('hidden-owner-ancestor', ['alias' => $node->getPath()], ['visible' => $visible], FilesEffectState::tree($actor));
		$this->assertFalse($visible);
		$this->assertSame([], $actor->ops);
		$this->assertSame([], $owner->ops);
		$t->assertGolden();
	}
}
