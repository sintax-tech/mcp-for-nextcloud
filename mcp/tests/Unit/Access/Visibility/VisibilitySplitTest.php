<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\Visibility;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\WriteVisibility;
use OCA\Mcp\Tests\Unit\Platform\FileAccess\FakeLock;
use OCA\Mcp\Tests\Unit\Platform\FileAccess\FakeLockManager;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Storage\ISharedStorage;
use OCP\Files\Storage\IStorage;
use OCP\IConfig;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// Only the differential process loads the untouched PREP class from an owned Git extraction.
if ($source = getenv('MCP_RE07_GUARD_SOURCE')) {
	require $source;
}

final class VisibilitySplitTest extends TestCase {
	private array $trace = [];
	private array $tags = [];
	private string $raw = '["42"]';
	private bool $configFails = false;
	private bool $assignFails = false;
	private bool $mapperFails = false;
	private bool $badBatch = false;
	private array $listeners = [];
	private FakeTree $tree;

	private function start(): SplitVisibilityProbe {
		$this->trace = $this->tags = $this->listeners = [];
		$this->raw = '["42"]';
		$this->configFails = $this->assignFails = $this->mapperFails = $this->badBatch = false;
		$this->tree = new FakeTree($this);
		$this->tree->addFile('/alice/files/Docs/open.txt', 'plain');
		$this->tree->addFile('/alice/files/Secret/private.txt', 'secret');
		$this->tree->addFile('/alice/files/MCP backups/Secret/private.txt.20261001-120000.bak', 'backup');
		return $this->guard($this->tree->filesystemRoot());
	}

	private function guard(?IRootFolder $root): SplitVisibilityProbe {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(function (string $app, string $key, string $default): string {
			$this->trace[] = ['config-read', $app, $key, $default];
			if ($this->configFails) throw new \RuntimeException('/private/config failure');
			return $this->raw;
		});
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
			$this->trace[] = ['config-write', $app, $key, $value];
			$this->raw = $value;
		});
		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->method('getTagIdsForObjects')->willReturnCallback(function (array $ids, string $type): array {
			$this->trace[] = ['tags-read', array_map('strval', $ids), $type];
			if ($this->mapperFails) throw new \RuntimeException('/private/mapper failure');
			$result = [];
			foreach ($ids as $id) $result[(string)$id] = $this->tags[(string)$id] ?? [];
			if ($this->badBatch && count($ids) > 1) $result[(string)$ids[1]] = new \stdClass();
			return $result;
		});
		$mapper->method('assignTags')->willReturnCallback(function (string $id, string $type, array $tags): void {
			$this->trace[] = ['tags-assign', $id, $type, $tags];
			if ($this->assignFails) throw new \RuntimeException('/private/assign failure');
			$this->tags[$id] = array_values(array_unique([ ...($this->tags[$id] ?? []), ...$tags]));
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function ($message, array $context): void { $this->trace[] = ['warning', $message, $context]; });
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('addListener')->willReturnCallback(function (string $event, callable $listener, int $priority): void {
			$this->trace[] = ['listener', $event, $priority];
			$this->listeners[$event] = $listener;
		});
		$guard = new SplitVisibilityProbe($config, $mapper, $root, null, $logger, $events);
		$guard->observed =& $this->trace;
		self::assertCount(7, $this->listeners);
		self::assertSame(array_fill(0, 7, PHP_INT_MIN), array_column(array_slice($this->trace, -7), 2));
		return $guard;
	}

	private function call(string $name, callable $action): mixed {
		$this->trace[] = ['call', $name];
		try {
			$value = $action();
			$this->trace[] = ['return', $name, self::value($value)];
			return $value;
		} catch (\Throwable $error) {
			$causes = [];
			for ($e = $error; $e !== null; $e = $e->getPrevious()) $causes[] = [$e::class, $e->getMessage(), $e->getCode()];
			$this->trace[] = ['throw', $name, $causes];
			return $error;
		}
	}

	private static function value(mixed $value): mixed {
		if ($value instanceof Node) return ['id' => $value->getId(), 'path' => $value->getPath()];
		if (is_array($value)) return array_map(self::value(...), $value);
		return $value;
	}

	public function testSequentialContractBeforeAndAfterTheSplit(): void {
		$all = [];
		$guard = $this->start();
		self::assertCount(7, $this->trace, 'Constructors perform no configuration or mapper reads');
		$open = $this->tree->node('/alice/files/Docs/open.txt');
		$secret = $this->tree->node('/alice/files/Secret/private.txt');
		$backup = $this->tree->node('/alice/files/MCP backups/Secret/private.txt.20261001-120000.bak');
		self::assertTrue($this->call('cold-visible', fn () => $guard->isVisible($open)));
		self::assertTrue($this->call('warm-visible', fn () => $guard->isVisible($open)));
		$this->tags[(string)$secret->getId()] = ['42'];
		self::assertSame(VisibilityGuard::EXPLAINED_TAG_DIRECT, $this->call('explain-direct', fn () => $guard->explain($secret))['reason']);
		self::assertFalse($this->call('warm-hidden', fn () => $guard->isVisible($secret)));
		self::assertSame([$open], $this->call('filter', fn () => $guard->filter([$secret, 'not a node', $open])));
		self::assertInstanceOf(ToolFailure::class, $this->call('assert-visible-hidden', fn () => $guard->assertVisible($secret)));
		self::assertTrue($this->call('clean-backup-parent', fn () => $guard->isVisible($this->tree->node('/alice/files/MCP backups/Secret'))));
		self::assertFalse($this->call('backup-after-clean-ancestor', fn () => $guard->isVisible($backup)));
		self::assertSame(VisibilityGuard::EXPLAINED_BACKUP, $this->call('explain-backup', fn () => $guard->explain($backup))['reason']);
		$guard->decisions[$secret->getPath()] = false;
		self::assertSame(VisibilityGuard::EXPLAINED_NOT_EVALUATED, $this->call('backup-unknown-original', fn () => $guard->explain($backup))['reason']);
		unset($guard->decisions[$secret->getPath()]);
		$clear = $guard->clears;
		$this->call('copy-hidden-tags', fn () => $guard->copyHiddenTags($secret, $backup));
		self::assertSame($clear + 1, $guard->clears);
		self::assertContains('42', $this->tags[(string)$backup->getId()]);
		$this->assignFails = true;
		$clear = $guard->clears;
		$error = $this->call('copy-assignment-fails', fn () => $guard->copyHiddenTags($secret, $backup));
		self::assertInstanceOf(ToolFailure::class, $error);
		self::assertSame(CommonMessages::backupFailed(), $error->getMessage());
		self::assertInstanceOf(\RuntimeException::class, $error->getPrevious());
		self::assertSame($clear + 1, $guard->clears);
		$this->assignFails = false;
		$this->mapperFails = true;
		$clear = $guard->clears;
		self::assertInstanceOf(ToolFailure::class, $this->call('copy-lookup-fails', fn () => $guard->copyHiddenTags($secret, $backup)));
		self::assertSame($clear, $guard->clears, 'No assignment means no finally invalidation');
		$this->mapperFails = false;
		$guard->decisions[$open->getPath()] = false;
		self::assertFalse($this->call('write-target-virtual', fn () => $guard->isPathAllowedForWrite($this->tree->rootFolder(), '/Docs/open.txt')));
		self::assertFalse($this->call('write-ancestor-virtual', fn () => $guard->isPathAllowedForWrite($this->tree->rootFolder(), '/Docs/open.txt/missing')));
		unset($guard->decisions[$open->getPath()]);
		self::assertTrue($this->call('write-positive', fn () => $guard->isPathAllowedForWrite($this->tree->rootFolder(), '/Docs/new')));
		$clear = $guard->clears;
		$this->call('setter-does-not-clear-subclass', fn () => $guard->setHiddenTagIds(['42', 42]));
		self::assertSame($clear, $guard->clears);
		$this->configFails = true;
		self::assertSame(['42'], $this->call('setter-keeps-loaded-value', fn () => $guard->getHiddenTagIds()));
		$this->call('explicit-clear', fn () => $guard->clearCache());
		self::assertSame([], $this->call('unavailable-read', fn () => $guard->getHiddenTagIds()));
		self::assertFalse($this->call('unavailable-config', fn () => $guard->isConfigurationValid()));
		self::assertInstanceOf(\OCA\Mcp\Access\Visibility\HiddenTagsUnavailableException::class, $this->call('unavailable-setter', fn () => $guard->setHiddenTagIds([])));
		$this->configFails = false;
		self::assertTrue($this->call('read-retried', fn () => $guard->isConfigurationValid()));
		$clear = $guard->clears;
		foreach ($this->listeners as $event => $listener) $this->call('event:' . $event, fn () => $listener());
		self::assertSame($clear + 7, $guard->clears);
		$this->trace[] = ['tree', $this->tree->nodes, $this->tree->ops];
		$all['state-writes-backup'] = $this->trace;

		$guard = $this->start();
		$this->raw = '{}';
		$open = $this->tree->node('/alice/files/Docs/open.txt');
		self::assertFalse($this->call('invalid-cold', fn () => $guard->isVisible($open)));
		self::assertFalse($this->call('invalid-warm', fn () => $guard->isVisible($open)));
		$this->call('invalid-clear', fn () => $guard->clearCache());
		self::assertSame(VisibilityGuard::EXPLAINED_INVALID_CONFIGURATION, $this->call('invalid-explanation', fn () => $guard->explain($open))['reason']);
		self::assertCount(1, array_filter($this->trace, fn ($row) => $row[0] === 'warning'));
		$this->call('repair-empty', fn () => $guard->setHiddenTagIds([]));
		self::assertTrue($this->call('repaired-empty-visible', fn () => $guard->isVisible($open)));
		$all['invalid-and-repair'] = $this->trace;

		$guard = $this->start();
		$open = $this->tree->node('/alice/files/Docs/open.txt');
		$this->badBatch = true;
		self::assertFalse($this->call('partial-batch-failure', fn () => $guard->isVisible($open)));
		$this->badBatch = false;
		$this->tags[(string)$open->getId()] = ['42'];
		self::assertFalse($this->call('retry-not-tag-clean', fn () => $guard->isVisible($open)));
		$all['batch-publication'] = $this->trace;

		$guard = $this->start();
		$open = $this->tree->node('/alice/files/Docs/open.txt');
		$guard->decisions[$this->tree->root] = false;
		$guard->traversable[$this->tree->root] = true;
		/** @var WriteVisibility $visibility */
		$visibility = $guard;
		self::assertSame($this->tree->root, $this->call('port-get-base', fn () => NodeAccess::getBase($this->tree->rootFolder(), '/', $visibility))->getPath());
		self::assertInstanceOf(ToolFailure::class, $this->call('port-get-hidden-root', fn () => NodeAccess::get($this->tree->rootFolder(), '/', $visibility)));
		self::assertSame($open->getPath(), $this->call('port-get-open', fn () => NodeAccess::get($this->tree->rootFolder(), '/Docs/open.txt', $visibility))->getPath());
		self::assertInstanceOf(Folder::class, $this->call('port-ensure-folder', fn () => NodeAccess::ensureFolder($this->tree->rootFolder(), 'Docs/new', $visibility)));
		self::assertNull($this->call('assert-traversable-distinct', fn () => $guard->assertTraversable($this->tree->rootFolder())));
		$clear = $guard->clears;
		self::assertSame('done', $this->call('port-mutate', fn () => NodeAccess::mutate(fn () => 'done', $visibility)));
		self::assertSame($clear + 1, $guard->clears);
		$manager = new FakeLockManager();
		$manager->put(new FakeLock((int)$open->getId(), \OCP\Files\Lock\ILock::TYPE_APP, 'text'));
		$guard->decisions[$open->getPath()] = false;
		$error = $this->call('port-lock-hidden', fn () => $manager->service($this, null, $visibility)->assertWritable($this->tree->node('/alice/files/Docs'), 'alice', '/Docs'));
		self::assertInstanceOf(\OCA\Mcp\Platform\FileAccess\LockWriteFailure::class, $error);
		self::assertSame(['blocking' => true], $error->lock);
		self::assertGreaterThan(0, $guard->parents);
		$this->trace[] = ['tree', $this->tree->nodes, $this->tree->ops];
		$all['virtual-interface'] = $this->trace;

		$all['aliases-and-shares'] = $this->shares();
		$guard = $this->start();
		$zero = $this->node(0, '/unresolved', $this->tree->filesystemRoot());
		self::assertFalse($this->call('unresolved-id-first', fn () => $guard->isVisible($zero)));
		self::assertFalse($this->call('unresolved-id-retried', fn () => $guard->isVisible($zero)));
		self::assertInstanceOf(ToolFailure::class, $this->call('unresolved-copy', fn () => $guard->copyHiddenTags($zero, $zero)));
		$all['unresolved-id'] = $this->trace;
		$constructor = (new \ReflectionClass(VisibilityGuard::class))->getConstructor();
		$all['constructor'] = array_map(fn ($p) => [$p->getName(), (string)$p->getType(), $p->isDefaultValueAvailable(), $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null], $constructor->getParameters());
		$all['public-methods'] = [];
		foreach ((new \ReflectionClass(VisibilityGuard::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			$all['public-methods'][$method->getName()] = [(string)$method->getReturnType(), array_map(fn ($p) => [$p->getName(), (string)$p->getType(), $p->isDefaultValueAvailable() ? $p->getDefaultValue() : 'required'], $method->getParameters())];
		}
		ksort($all['public-methods']);
		$all['constants'] = (new \ReflectionClass(VisibilityGuard::class))->getConstants();
		self::assertFalse((new \ReflectionClass(VisibilityGuard::class))->isFinal());
		self::assertInstanceOf(WriteVisibility::class, $guard);
		if ($capture = getenv('MCP_RE07_TRACE')) file_put_contents($capture, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		self::assertSame(json_decode((string)file_get_contents(__DIR__ . '/visibility-split-before.json'), true, flags: JSON_THROW_ON_ERROR), $all);
	}

	private function shares(): array {
		$this->start();
		$root = $this->createMock(IRootFolder::class);
		$plain = $this->node(10, '/plain', $root);
		$hidden = $this->node(20, '/hidden', $root);
		$a = $this->node(30, '/plain/item', $plain);
		$b = $this->node(30, '/hidden/item', $hidden);
		$this->tags['20'] = ['42'];
		$guard = $this->guard(null);
		self::assertTrue($this->call('alias-visible', fn () => $guard->isVisible($a)));
		self::assertTrue($this->call('alias-visible-warm', fn () => $guard->isVisible($a)));
		self::assertFalse($this->call('alias-hidden', fn () => $guard->isVisible($b)));
		self::assertSame('/hidden', $this->call('alias-reason', fn () => $guard->explain($b))['folder']->getPath());
		$all = $this->trace;
		$this->start();
		$ownerRoot = $this->node(1000, '/owner/files', $root);
		$ownerHidden = $this->node(1001, '/owner/files/hidden', $ownerRoot);
		$source = $this->node(1002, '/owner/files/hidden/source', $ownerHidden);
		$recipientRoot = $this->node(2000, '/recipient/files', $root);
		$alias = $this->node(1002, '/recipient/files/received', $recipientRoot, true);
		$root->method('get')->willReturnCallback(fn (string $path): Node => match ($path) {
			'/recipient/files' => $recipientRoot,
			default => throw new \RuntimeException('Unexpected mounted parent'),
		});
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('owner');
		$alias->method('getOwner')->willReturn($user);
		$cleanOrigin = $this->node(1002, '/owner/files/plain/source', $this->node(1003, '/owner/files/plain', $ownerRoot));
		$ownerRoot->method('getById')->willReturnCallback(function () use ($cleanOrigin, $source): array { $this->trace[] = ['owner-aliases']; return [$cleanOrigin, $source]; });
		$lookupFails = true;
		$root->method('getUserFolder')->willReturnCallback(function () use ($ownerRoot, &$lookupFails): Folder {
			$this->trace[] = ['owner-root'];
			if ($lookupFails) throw new \RuntimeException('/private/owner missing');
			return $ownerRoot;
		});
		$guard = $this->guard($root);
		self::assertFalse($this->call('owner-unavailable', fn () => $guard->isVisible($alias)));
		self::assertFalse($this->call('owner-unavailable-retry', fn () => $guard->isVisible($alias)));
		$lookupFails = false;
		self::assertTrue($this->call('owner-available-positive', fn () => $guard->isVisible($alias)));
		$this->tags['1001'] = ['42'];
		self::assertSame(VisibilityGuard::EXPLAINED_TAG_SHARE_ORIGIN, $this->call('owner-hidden', fn () => $guard->explain($alias))['reason']);
		self::assertFalse($this->call('owner-hidden-warm', fn () => $guard->isVisible($alias)));
		$this->trace[] = ['alias-trace', $all];
		return $this->trace;
	}

	private function node(int $id, string $path, Node $parent, bool $shared = false): Folder {
		$node = $this->createMock(Folder::class);
		$node->method('getId')->willReturnCallback(function () use ($id, $path): int { $this->trace[] = ['node-id', $path]; return $id; });
		$node->method('getPath')->willReturn($path);
		$node->method('getParent')->willReturn($parent);
		$storage = $this->createMock(IStorage::class);
		$storage->method('instanceOfStorage')->willReturnCallback(fn (string $type): bool => $shared && $type === ISharedStorage::class);
		$node->method('getStorage')->willReturn($storage);
		$mount = $this->createMock(\OCP\Files\Mount\IMountPoint::class);
		$mount->method('getMountPoint')->willReturn($shared ? $path : '/other-mount');
		$node->method('getMountPoint')->willReturn($mount);
		return $node;
	}
}

final class SplitVisibilityProbe extends FilesAccessGuard {
	public array $observed = [];
	public array $decisions = [];
	public array $traversable = [];
	public int $clears = 0;
	public int $parents = 0;
	public function isVisible(Node $node): bool {
		$this->observed[] = ['virtual-visible', $node->getPath()];
		return $this->decisions[$node->getPath()] ?? parent::isVisible($node);
	}
	public function isTraversable(Node $node): bool {
		$this->observed[] = ['virtual-traversable', $node->getPath()];
		return $this->traversable[$node->getPath()] ?? parent::isTraversable($node);
	}
	public function parentOf(Node $node): Node {
		$this->parents++;
		$this->observed[] = ['virtual-parent', $node->getPath()];
		return parent::parentOf($node);
	}
	public function clearCache(): void {
		$this->clears++;
		$this->observed[] = ['virtual-clear'];
		parent::clearCache();
	}
}
