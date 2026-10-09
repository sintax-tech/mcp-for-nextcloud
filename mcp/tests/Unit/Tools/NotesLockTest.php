<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\Common\FakeLock;
use OCA\Mcp\Tests\Unit\Tools\Common\FakeLockManager;
use OCA\Mcp\Tools\ArgumentValidator;
use OCA\Mcp\Tools\Common\LockWriteFailure;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\Notes\NotesModule;
use OCA\Mcp\Tools\Notes\NotesRepository;
use OCA\Mcp\Tools\WriteGate;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\Files\Lock\ILock;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * A note open in Text (directly, or in Notes in rich mode) is locked by files_lock. The incident of 08/10/2026: an
 * edit through the MCP met that lock and got a generic error. Now the plan warns, the write is refused before
 * anything changes, and the refusal names the app and what to do.
 */
final class NotesLockTest extends TestCase {
    private const NOTE = '/alice/files/Notes/CHAMADOS/CHAMADOS OUTUBRO.md';

    private FakeTree $tree;
    private FakeLockManager $locks;
    private NotesModule $module;
    private int $id;

    protected function setUp(): void {
        $this->tree = new FakeTree($this);
        $this->id = $this->tree->addFile(self::NOTE, '# Chamados', 'text/markdown');
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn () => $this->tree->rootFolder());
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));
        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
        $this->locks = new FakeLockManager();
        $this->module = new NotesModule(new NotesRepository($root, (new InMemoryConfig())->mock($this)), $apps, $users,
            new SharedWriteGuard($access), $access, null, $this->locks->service($this));
    }

    private function call(string $name, array $arguments): array {
        $definition = array_column($this->module->definitions(), null, 'name')[$name];
        $result = $this->module->call($name, ArgumentValidator::validate(WriteGate::publish($definition)['inputSchema'], $arguments), 'alice');
        return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function refused(string $name, array $arguments): LockWriteFailure {
        try {
            $this->call($name, $arguments);
        } catch (LockWriteFailure $e) {
            return $e;
        }
        $this->fail("$name was not refused");
    }

    private function openInText(): FakeLock {
        return $this->locks->put(new FakeLock($this->id, ILock::TYPE_APP, 'text'));
    }

    public function testAnEditOfANoteOpenInTextIsRefusedBeforeAnythingIsWritten(): void {
        $this->openInText();
        $before = $this->tree->nodes;
        $e = $this->refused('notes_edit', ['id' => $this->id, 'content' => 'novo', 'title' => 'Outro']);
        $this->assertStringStartsWith('The file “/Notes/CHAMADOS/CHAMADOS OUTUBRO.md” is open in Text', $e->getMessage());
        $this->assertStringContainsString('Nothing was changed.', $e->getMessage());
        $this->assertSame([], $this->tree->ops);
        $this->assertSame($before, $this->tree->nodes);
    }

    public function testAMoveAndADeleteOfALockedNoteAreRefusedBeforeTextsHooksCouldRun(): void {
        $this->locks->put(new FakeLock($this->id, ILock::TYPE_USER, 'pedro'));
        $this->assertStringContainsString('is locked by Pedro Almeida.', $this->refused('notes_move', ['id' => $this->id, 'category' => 'Arquivo'])->getMessage());
        $this->assertStringContainsString('is locked by Pedro Almeida.', $this->refused('notes_delete', ['id' => $this->id])->getMessage());
        $this->assertSame([], $this->tree->ops);
        $this->assertArrayHasKey(self::NOTE, $this->tree->nodes);
    }

    public function testTheOwnManualLockKeepsTheEditAllowed(): void {
        $this->locks->put(new FakeLock($this->id, ILock::TYPE_USER, 'alice'));
        $this->call('notes_edit', ['id' => $this->id, 'content' => 'novo']);
        $this->assertSame('novo', $this->tree->nodes[self::NOTE]['content']);
    }

    public function testALockThatAppearsBetweenTheCheckAndTheWriteIsExplainedToo(): void {
        $this->tree->writeFailure = FakeLockManager::refusal(new FakeLock($this->id, ILock::TYPE_APP, 'text'));
        // The lock shows up only for the storage: the check saw a free note.
        $e = $this->refused('notes_edit', ['id' => $this->id, 'content' => 'novo']);
        $this->assertStringContainsString('is open in Text', $e->getMessage());
        $this->assertStringNotContainsString('Nothing was changed.', $e->getMessage());
        $this->assertSame('# Chamados', $this->tree->nodes[self::NOTE]['content']);
    }

    public function testThePlanWarnsAboutTheLockAndStillDescribesTheChange(): void {
        $this->openInText();
        foreach (['notes_edit' => ['id' => $this->id, 'content' => 'novo'], 'notes_move' => ['id' => $this->id, 'category' => 'Arquivo'],
            'notes_delete' => ['id' => $this->id]] as $tool => $arguments) {
            $plan = $this->module->preview($tool, $arguments, 'alice');
            $this->assertSame($tool, $plan['action']);
            $this->assertTrue($plan['lock']['blocking'], $tool);
            $this->assertSame('file_locked', $plan['warnings'][0]['type']);
            $this->assertStringContainsString('While the lock lasts the change is refused, even after confirmation.', $plan['warnings'][0]['message']);
        }
        $this->assertSame([], $this->tree->ops);
    }

    public function testAPlanOfAFreeNoteCarriesNoLock(): void {
        $plan = $this->module->preview('notes_edit', ['id' => $this->id, 'content' => 'novo'], 'alice');
        $this->assertArrayNotHasKey('lock', $plan);
        $this->assertArrayNotHasKey('warnings', $plan);
    }
}
