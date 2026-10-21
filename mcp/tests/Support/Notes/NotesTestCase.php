<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Notes;

use OCA\Mcp\Tests\Support\Platform\FileAccess\FakeTree;
use OCA\Mcp\Tests\Support\Identity\FakeUsers;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Modules\Notes\NotesModule;
use OCA\Mcp\Modules\Notes\Storage\NotesFolders;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\L10n\Translator;
use OCP\Files\IRootFolder;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
abstract class NotesTestCase extends TestCase
{
    protected FakeTree $tree;
    protected InMemoryConfig $config;
    protected IRootFolder $root;
    protected IAppManager $apps;
    protected IUserManager $users;
    protected NotesModule $module;
    protected int $id;
    protected array $enabledApps = ['notes', 'files_trashbin'];
    protected \OCA\Mcp\Tests\Support\Platform\FileAccess\Locks\FakeLockManager $locks;
    protected array $userTrees = [];
    protected int $rootReads = 0;
    protected ?\Throwable $rootFailure = null;

    protected function setUp(): void
    {
        Translator::reset();
        $this->userTrees = [];
        $this->rootReads = 0;
        $this->rootFailure = null;
        $this->enabledApps = ['notes', 'files_trashbin'];
        $this->tree = new FakeTree($this);
        $this->id = $this->tree->addFile('/alice/files/Notes/Work/Before.md', '# Original', 'text/markdown');
        $this->tree->addFolder('/alice/files/Notes/Target');
        foreach ($this->tree->nodes as &$node) {
            $node['mtime'] = 1700000000;
        }
        $this->config = new InMemoryConfig();
        $this->root = $this->createMock(IRootFolder::class);
        $this->root->method('getUserFolder')->willReturnCallback(function (string $userId): \OCP\Files\Folder {
            $this->rootReads++;
            if ($this->rootFailure !== null) {
                throw $this->rootFailure;
            }
            return ($this->userTrees[$userId] ?? $this->tree)->rootFolder();
        });
        $this->apps = $this->createMock(IAppManager::class);
        $this->apps->method('isEnabledForUser')->willReturnCallback(fn(string $app) => in_array($app, $this->enabledApps, true));
        $this->users = $this->createMock(IUserManager::class);
        $this->users->method('get')->willReturn($this->createMock(IUser::class));
        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
        $this->locks = new \OCA\Mcp\Tests\Support\Platform\FileAccess\Locks\FakeLockManager();
        $this->module = \OCA\Mcp\Tests\Support\Notes\NotesTestFactory::module($this, [\OCA\Mcp\Modules\Notes\Storage\NotesFolders::class => new \OCA\Mcp\Modules\Notes\Storage\NotesFolders($this->root, $this->config->mock($this)), \OCA\Mcp\Modules\Notes\Storage\NoteLookup::class => new \OCA\Mcp\Modules\Notes\Storage\NoteLookup(null), \OCA\Mcp\Modules\Notes\Storage\NoteMetadata::class => new \OCA\Mcp\Modules\Notes\Storage\NoteMetadata(null), \OCA\Mcp\Modules\Notes\Storage\NoteNames::class => new \OCA\Mcp\Modules\Notes\Storage\NoteNames(), \OCA\Mcp\Modules\Notes\Content\NoteContent::class => new \OCA\Mcp\Modules\Notes\Content\NoteContent(), \OCP\App\IAppManager::class => $this->apps, \OCP\IUserManager::class => $this->users, \OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard::class => new SharedWriteGuard($access), \OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo::class => $access, \OCA\Mcp\Access\Visibility\VisibilityGuard::class => null, \OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite::class => $this->locks->service($this)]);
    }

    protected function tearDown(): void
    {
        Translator::reset();
    }

    protected function registry(): ToolRegistry
    {
        $policy = InMemoryConfig::policy($this->config->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        foreach (GrantPolicy::CATALOG['notes'] as $op) {
            $policy->setGrant('alice', 'notes', $op, true);
        }
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($policy, $this->apps, $this->users);
        return new ToolRegistry([$this->module], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, new \Psr\Log\NullLogger()));
    }

    protected function writes(): array
    {
        return ['notes_create' => ['title' => 'Created', 'content' => '# New', 'category' => 'Target'], 'notes_edit' => ['id' => $this->id, 'content' => '# Changed', 'title' => 'After'], 'notes_move' => ['id' => $this->id, 'category' => 'Target'], 'notes_delete' => ['id' => $this->id]];
    }

    /** Build a focused capability from host doubles, keeping all Notes policies real. */

    protected function capability(string $class): object
    {
        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
        $services = [\OCA\Mcp\Modules\Notes\Storage\NotesFolders::class => new \OCA\Mcp\Modules\Notes\Storage\NotesFolders($this->root, $this->config->mock($this)), \OCA\Mcp\Modules\Notes\Storage\NoteLookup::class => new \OCA\Mcp\Modules\Notes\Storage\NoteLookup(), NodeAccessInfo::class => $access, SharedWriteGuard::class => new SharedWriteGuard($access), IAppManager::class => $this->apps, IUserManager::class => $this->users, \OCA\Mcp\Access\Visibility\VisibilityGuard::class => null, \OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite::class => $this->locks->service($this)];
        $build = function (string $name) use (&$build, &$services) {
            if (array_key_exists($name, $services)) {
                return $services[$name];
            }
            $r = new \ReflectionClass($name);
            $args = [];
            foreach ($r->getConstructor()?->getParameters() ?? [] as $p) {
                $args[] = $build($p->getType()->getName());
            }
            return $services[$name] = $r->newInstanceArgs($args);
        };
        return $build($class);
    }
}
