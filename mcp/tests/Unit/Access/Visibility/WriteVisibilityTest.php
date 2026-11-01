<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\Visibility;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\LockWriteFailure;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\WriteVisibility;
use OCA\Mcp\Tests\Unit\AppInfo\BoundaryFactories;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Platform\FileAccess\FakeLock;
use OCA\Mcp\Tests\Unit\Platform\FileAccess\FakeLockManager;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCP\Files\Lock\ILock;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

/** A real registered visibility guard preserves hidden-path refusals and Files virtual dispatch. */
final class WriteVisibilityTest extends TestCase {
	/** @return void Binding the configured guard cannot expose hidden paths, names or lock metadata. */
	public function testBoundHiddenTagGuardRefusesPathsAndDoesNotNameLockedChildren(): void {
		$tree = new FakeTree($this, '/alice/files');
		$id = $tree->addFile('/alice/files/Hidden/private.txt', 'private');
		$config = new InMemoryConfig();
		$config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
		$tags = $this->createMock(ISystemTagObjectMapper::class);
		$tags->method('getTagIdsForObjects')->willReturnCallback(static fn (array $ids): array => array_fill_keys($ids, ['42']));
		$guard = new VisibilityGuard($config->mock($this), $tags, $tree->filesystemRoot());
		$bound = BoundaryFactories::resolve($this, WriteVisibility::class, $guard);
		self::assertSame($guard, $bound);
		foreach ([
			fn () => NodeAccess::get($tree->rootFolder(), '/Hidden/private.txt', $bound),
			fn () => NodeAccess::ensureFolder($tree->rootFolder(), 'Hidden/new', $bound),
			fn () => NodeAccess::getBase($tree->rootFolder(), '/Hidden', $bound),
		] as $operation) {
			try { $operation(); self::fail('Hidden resource accepted'); }
			catch (ToolFailure $error) { self::assertSame(CommonMessages::notFound(), $error->getMessage()); }
		}
		$manager = new FakeLockManager();
		$manager->put(new FakeLock($id, ILock::TYPE_APP, 'text'));
		try {
			$manager->service($this, null, $bound)->assertWritable($tree->node('/alice/files/Hidden'), 'alice', '/Hidden');
			self::fail('Hidden locked child accepted');
		} catch (LockWriteFailure $error) {
			self::assertStringNotContainsString('private.txt', $error->getMessage());
			self::assertSame(['blocking' => true], $error->lock);
		}
	}

	/** @return void Static shared helpers keep dispatching through the actual Files guard's overrides. */
	public function testFilesOverridesSurviveTheInterfaceBoundary(): void {
		$tree = new FakeTree($this, '/alice/files');
		$tree->addFolder('/alice/files/Visible');
		$guard = new ObservedFilesVisibility((new InMemoryConfig())->mock($this), $this->createMock(ISystemTagObjectMapper::class), $tree->filesystemRoot());
		$visibility = BoundaryFactories::resolve($this, WriteVisibility::class, $guard);
		NodeAccess::get($tree->rootFolder(), '/Visible', $visibility);
		NodeAccess::ensureFolder($tree->rootFolder(), 'Visible', $visibility);
		NodeAccess::getBase($tree->rootFolder(), '/', $visibility);
		NodeAccess::mutate(static fn (): bool => true, $visibility);
		self::assertGreaterThan(0, $guard->calls['visible']);
		self::assertGreaterThan(0, $guard->calls['traversable']);
		self::assertSame(1, $guard->calls['clear']);
	}
}
