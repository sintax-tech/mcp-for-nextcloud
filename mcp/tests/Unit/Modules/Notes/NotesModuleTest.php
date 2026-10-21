<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Notes;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Tests\Support\Platform\FileAccess\FakeTree;
use InvalidArgumentException;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Support\Identity\FakeUsers;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCA\Mcp\Modules\Notes\NotesModule;
use OCA\Mcp\Modules\Notes\Storage\NotesFolders;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
final class NotesModuleTest extends TestCase
{
    private FakeTree $tree;
    private InMemoryConfig $config;
    private array $apps = ['notes', 'files_trashbin'];
    private NotesModule $module;
    private int $ata;
    private int $outside;

    protected function setUp(): void
    {
        $this->tree = new FakeTree($this);
        $this->ata = $this->tree->addFile('/alice/files/Notes/Reuniões/Ata.md', 'decisões...', 'text/markdown');
        $this->tree->addFile('/alice/files/Notes/Ideia.txt', 'ideia');
        $this->tree->addFile('/alice/files/Notes/foto.png', 'png', 'image/png');
        $this->outside = $this->tree->addFile('/alice/files/Documentos/segredo.md', 'fora');
        $this->config = new InMemoryConfig();
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn() => $this->tree->rootFolder());
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturnCallback(fn(string $app) => in_array($app, $this->apps, true));
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));
        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
        $this->module = \OCA\Mcp\Tests\Support\Notes\NotesTestFactory::module($this, [\OCA\Mcp\Modules\Notes\Storage\NotesFolders::class => new \OCA\Mcp\Modules\Notes\Storage\NotesFolders($root, $this->config->mock($this)), \OCA\Mcp\Modules\Notes\Storage\NoteLookup::class => new \OCA\Mcp\Modules\Notes\Storage\NoteLookup(null), \OCA\Mcp\Modules\Notes\Storage\NoteMetadata::class => new \OCA\Mcp\Modules\Notes\Storage\NoteMetadata(null), \OCA\Mcp\Modules\Notes\Storage\NoteNames::class => new \OCA\Mcp\Modules\Notes\Storage\NoteNames(), \OCA\Mcp\Modules\Notes\Content\NoteContent::class => new \OCA\Mcp\Modules\Notes\Content\NoteContent(), \OCP\App\IAppManager::class => $apps, \OCP\IUserManager::class => $users, \OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard::class => new SharedWriteGuard($access), \OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo::class => $access, \OCA\Mcp\Access\Visibility\VisibilityGuard::class => null, \OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite::class => null]);
    }

    private function tool(string $name, array $arguments = []): array
    {
        $definition = array_column($this->module->definitions(), null, 'name')[$name];
        $published = WriteGate::publish($definition);
        $result = $this->module->call($name, ArgumentValidator::validate($published['inputSchema'], $arguments), 'alice');
        return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function failure(string $name, array $arguments): string
    {
        try {
            $this->tool($name, $arguments);
        } catch (ToolFailure $e) {
            return $e->getMessage();
        }
        $this->fail("{$name} did not fail");
    }

    private function invalid(string $name, array $arguments): void
    {
        try {
            $this->tool($name, $arguments);
            $this->fail("{$name} accepted " . json_encode($arguments));
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testDefinitionsRequireNotesAppAndMatchGrants(): void
    {
        $defs = $this->module->definitions();
        $this->assertSame(['notes_list', 'notes_search', 'notes_read', 'notes_create', 'notes_edit', 'notes_move', 'notes_delete'], array_column($defs, 'name'));
        $this->assertSame(['read', 'read', 'read', 'create', 'edit', 'move', 'delete'], array_column($defs, 'operation'));
        $this->assertSame(['notes'], array_values(array_unique(array_column($defs, 'app'))));
        $this->assertSame(['query'], $defs[1]['inputSchema']['required']);
        $this->assertSame(['id'], $defs[2]['inputSchema']['required']);
    }

    public function testListReturnsOnlyNotesInsideTheNotesFolder(): void
    {
        $notes = $this->tool('notes_list');
        $this->assertEqualsCanonicalizing(['Ata', 'Ideia'], array_column($notes, 'title'));
        $ata = array_column($notes, null, 'title')['Ata'];
        $this->assertSame(['id' => $this->ata, 'title' => 'Ata', 'category' => 'Reuniões'], array_intersect_key($ata, ['id' => 0, 'title' => 0, 'category' => 0]));
        $this->assertSame(['id', 'title', 'category', 'modified', 'etag', 'access'], array_keys($ata));
        $this->assertSame('personal', $ata['access']['scope']);
    }

    /**
     * A note lives inside the user's own folder by construction, so the guard never fires on the normal
     * path. It only fires when an administrator pointed the notes path at a share or a team folder, and
     * then nothing is written until the user confirms.
     */

    public function testANoteOutsideThePersonalScopeAsksForConfirmationBeforeAnyChange(): void
    {
        $this->config->user['alice']['notes']['notesPath'] = 'Equipe';
        $id = $this->tree->addFile('/alice/files/Equipe/Ata.md', 'decisões', 'text/markdown', ['scope' => 'team']);
        $this->tree->mountPath = '/alice/files/Equipe';
        $edit = $this->tool('notes_edit', ['id' => $id, 'content' => 'novo']);
        $this->assertTrue($edit['requiresConfirmation']);
        $this->assertSame('team', $edit['scope']);
        $this->assertSame('Equipe', $edit['teamFolder']);
        $this->assertSame((string) $id, $edit['resource']);
        $this->assertSame('decisões', $this->tree->nodes['/alice/files/Equipe/Ata.md']['content']);
        $this->assertSame([], $this->tree->ops);
        $this->tree->addFile('/alice/files/Equipe/Outra.md', 'x', 'text/markdown', ['scope' => 'team']);
        $other = $this->tree->nodes['/alice/files/Equipe/Outra.md']['id'];
        $this->assertTrue($this->tool('notes_move', ['id' => $other, 'category' => 'Sub'])['requiresConfirmation']);
        $this->assertTrue($this->tool('notes_delete', ['id' => $other, 'confirm' => true])['requiresConfirmation']);
        $this->assertSame([], $this->tree->ops);
    }

    public function testTheGuardLetsTheChangeThroughOnceTheUserConfirmed(): void
    {
        $this->config->user['alice']['notes']['notesPath'] = 'Equipe';
        $id = $this->tree->addFile('/alice/files/Equipe/Ata.md', 'decisões', 'text/markdown', ['scope' => 'shared']);
        $out = $this->tool('notes_edit', ['id' => $id, 'content' => 'novo', 'confirm_shared' => true]);
        $this->assertArrayNotHasKey('requiresConfirmation', $out);
        $this->assertSame('novo', $this->tree->nodes['/alice/files/Equipe/Ata.md']['content']);
    }

    /** A note Nextcloud will not update stays refused, confirmation or not. */

    public function testANoteWithoutUpdatePermissionIsDeniedEitherWay(): void
    {
        $this->config->user['alice']['notes']['notesPath'] = 'Equipe';
        $id = $this->tree->addFile('/alice/files/Equipe/Ata.md', 'x', 'text/markdown', ['scope' => 'shared', 'permissions' => \OCP\Constants::PERMISSION_READ]);
        foreach ([false, true] as $confirmed) {
            try {
                $this->tool('notes_edit', ['id' => $id, 'content' => 'y', 'confirm_shared' => $confirmed]);
                $this->fail('a read-only note must never be writable');
            } catch (ToolFailure $e) {
                $this->assertSame(ToolFailure::FORBIDDEN, $e->getMessage());
            }
        }
        $this->assertSame([], $this->tree->ops);
    }

    public function testThePersonalPathNeverAsksForConfirmation(): void
    {
        $out = $this->tool('notes_edit', ['id' => $this->ata, 'content' => 'novo']);
        $this->assertArrayNotHasKey('requiresConfirmation', $out);
        $this->assertSame('personal', $out['access']['scope']);
    }

    public function testTheWriteToolsDeclareTheConfirmationArgument(): void
    {
        $defs = array_column($this->module->definitions(), null, 'name');
        foreach (['notes_edit', 'notes_move', 'notes_delete'] as $name) {
            $this->assertArrayHasKey('confirm_shared', $defs[$name]['inputSchema']['properties'], $name);
        }
    }

    public function testListUsesTheConfiguredFolderAndToleratesAMissingOne(): void
    {
        $this->config->user['alice']['notes']['notesPath'] = 'Notas';
        $this->assertSame([], $this->tool('notes_list'));
        $this->tree->addFile('/alice/files/Notas/x.md', 'x');
        $this->assertSame(['x'], array_column($this->tool('notes_list'), 'title'));
    }

    public function testReadByIdStaysInsideTheNotesFolder(): void
    {
        $this->assertSame('decisões...', $this->tool('notes_read', ['id' => $this->ata])['content']);
        $this->assertSame(ToolFailure::NOT_FOUND, $this->failure('notes_read', ['id' => $this->outside]));
        $this->assertSame(ToolFailure::NOT_FOUND, $this->failure('notes_read', ['id' => 99999]));
        $this->invalid('notes_read', ['id' => '1']);
    }

    public function testReadEnforcesSizeLimit(): void
    {
        $id = $this->tree->addFile('/alice/files/Notes/grande.md', 'x', 'text/markdown', ['size' => \OCA\Mcp\Modules\Notes\Content\NoteContent::MAX_BYTES + 1]);
        $this->assertSame(\OCA\Mcp\Modules\Notes\Content\ContentMessages::noteTooLargeForReading(\OCA\Mcp\Modules\Notes\Content\NoteContent::MAX_BYTES), $this->failure('notes_read', ['id' => $id]));
    }

    public function testCreateNeverOverwrites(): void
    {
        $created = $this->tool('notes_create', ['title' => 'Ata', 'content' => 'nova', 'category' => 'Reuniões']);
        $this->assertSame(['Ata (2)', 'Reuniões'], [$created['title'], $created['category']]);
        $this->assertSame('decisões...', $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['content']);
        $this->assertSame('nova', $this->tree->nodes['/alice/files/Notes/Reuniões/Ata (2).md']['content']);
    }

    public function testCreateSanitizesTitleAndCategoryAndCreatesFolders(): void
    {
        $this->config->user['alice']['notes']['notesPath'] = 'NovaPasta';
        $created = $this->tool('notes_create', ['title' => "a/b\\c\n", 'category' => 'Projetos/2026']);
        $this->assertSame(['a b c', 'Projetos/2026'], [$created['title'], $created['category']]);
        $this->assertArrayHasKey('/alice/files/NovaPasta/Projetos/2026/a b c.md', $this->tree->nodes);
        $this->invalid('notes_create', ['title' => '/']);
        $this->invalid('notes_create', ['title' => 'x', 'category' => '../Documentos']);
    }

    public function testEditContentAndTitle(): void
    {
        $etag = $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['etag'];
        $edited = $this->tool('notes_edit', ['id' => $this->ata, 'content' => 'v2', 'title' => 'Ata final', 'etag' => $etag]);
        $this->assertSame(['id' => $this->ata, 'title' => 'Ata final', 'category' => 'Reuniões'], array_intersect_key($edited, ['id' => 0, 'title' => 0, 'category' => 0]));
        $this->assertSame('v2', $this->tree->nodes['/alice/files/Notes/Reuniões/Ata final.md']['content']);
    }

    public function testEditRefusesConflictsAndEmptyChanges(): void
    {
        $this->assertSame(ToolFailure::CONFLICT, $this->failure('notes_edit', ['id' => $this->ata, 'content' => 'x', 'etag' => 'velho']));
        $this->tree->addFile('/alice/files/Notes/Reuniões/Outra.md', 'o');
        $this->assertSame(\OCA\Mcp\Modules\Notes\Storage\NamingMessages::titleExistsInCategory(), $this->failure('notes_edit', ['id' => $this->ata, 'title' => 'Outra']));
        $this->assertSame(ToolFailure::NOT_FOUND, $this->failure('notes_edit', ['id' => $this->outside, 'content' => 'x']));
        $this->invalid('notes_edit', ['id' => $this->ata]);
        $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['updateable'] = false;
        $this->assertSame(ToolFailure::FORBIDDEN, $this->failure('notes_edit', ['id' => $this->ata, 'content' => 'x']));
        $this->assertSame('decisões...', $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['content']);
        $this->assertSame([], array_filter($this->tree->ops, fn($op) => str_starts_with($op, 'write') || str_starts_with($op, 'move')));
    }

    /**
     * P2: the whole call is validated before the first write. A title already taken in the category used
     * to be refused only after the new content had been written over the note, which has no backup.
     */

    public function testATitleThatCollidesRefusesTheWholeEditAndLeavesTheContentIntact(): void
    {
        $this->tree->addFile('/alice/files/Notes/Reuniões/Outra.md', 'o');
        $this->assertSame(\OCA\Mcp\Modules\Notes\Storage\NamingMessages::titleExistsInCategory(), $this->failure('notes_edit', ['id' => $this->ata, 'content' => 'NOVO', 'title' => 'Outra']));
        $this->assertSame('decisões...', $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['content']);
        $this->assertArrayHasKey('/alice/files/Notes/Reuniões/Ata.md', $this->tree->nodes);
        $this->assertSame('o', $this->tree->nodes['/alice/files/Notes/Reuniões/Outra.md']['content']);
        $this->assertSame([], $this->tree->ops, 'nothing may be written, moved or deleted');
    }

    public function testATitleThatSanitizesToNothingRefusesTheWholeEditBeforeWriting(): void
    {
        $this->invalid('notes_edit', ['id' => $this->ata, 'content' => 'NOVO', 'title' => ' ../ ']);
        $this->assertSame('decisões...', $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['content']);
        $this->assertSame([], $this->tree->ops);
    }

    /**
     * M2: a new title is a rename, which Nextcloud allows only with update on the note, delete at the
     * source and create at the destination. The permission is checked in the plan and in the write,
     * before the content is put, so a refusal never leaves the note overwritten.
     *
     * @return array<string, array{0:string}> what is missing on the note or its folder
     */

    public static function missingRenamePermissions(): array
    {
        return ['note cannot be deleted' => ['deletable'], 'folder cannot create' => ['creatable']];
    }

    private function revokeRenamePermission(string $missing): void
    {
        if ($missing === 'deletable') {
            $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['deletable'] = false;
            return;
        }
        $this->tree->nodes['/alice/files/Notes/Reuniões']['permissions'] = \OCP\Constants::PERMISSION_ALL & ~\OCP\Constants::PERMISSION_CREATE;
    }

    /** @dataProvider missingRenamePermissions */

    public function testARenameWithoutPermissionRefusesTheWholeEditAndLeavesTheNoteIntact(string $missing): void
    {
        $this->revokeRenamePermission($missing);
        foreach ([['content' => 'NOVO', 'title' => 'Outra'], ['title' => 'Outra']] as $arguments) {
            $this->assertSame(ToolFailure::FORBIDDEN, $this->failure('notes_edit', ['id' => $this->ata] + $arguments));
            $this->assertSame(ToolFailure::FORBIDDEN, $this->failure('notes_edit', ['id' => $this->ata, 'confirm_shared' => true] + $arguments));
        }
        $this->assertSame('decisões...', $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['content']);
        $this->assertArrayNotHasKey('/alice/files/Notes/Reuniões/Outra.md', $this->tree->nodes);
        $this->assertSame([], $this->tree->ops, 'nothing may be written, moved or deleted');
    }

    /** @dataProvider missingRenamePermissions */

    public function testThePlanOfARenameWithoutPermissionIsRefusedLikeTheWrite(string $missing): void
    {
        $this->revokeRenamePermission($missing);
        try {
            $this->module->preview('notes_edit', ['id' => $this->ata, 'title' => 'Outra'], 'alice');
            $this->fail('the plan accepted a rename the write would refuse');
        } catch (ToolFailure $e) {
            $this->assertSame(ToolFailure::FORBIDDEN, $e->getMessage());
        }
    }

    /** @dataProvider missingRenamePermissions */

    public function testAContentOnlyEditDoesNotNeedTheRenamePermissions(string $missing): void
    {
        $this->revokeRenamePermission($missing);
        $this->tool('notes_edit', ['id' => $this->ata, 'content' => 'v2']);
        $this->tool('notes_edit', ['id' => $this->ata, 'content' => 'v3', 'title' => 'Ata']);
        $this->assertSame('v3', $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['content']);
    }

    /** In a share the refusal comes before the shared-write confirmation: the user is never asked to approve it. */

    public function testARenameWithoutPermissionInASharedNotesFolderIsRefusedBeforeTheConfirmation(): void
    {
        $this->config->user['alice']['notes']['notesPath'] = 'Equipe';
        $id = $this->tree->addFile('/alice/files/Equipe/Ata.md', 'decisões', 'text/markdown', ['scope' => 'shared', 'deletable' => false]);
        $this->tree->mountPath = '/alice/files/Equipe';
        $this->assertSame(ToolFailure::FORBIDDEN, $this->failure('notes_edit', ['id' => $id, 'content' => 'NOVO', 'title' => 'Outra']));
        $this->assertSame('decisões', $this->tree->nodes['/alice/files/Equipe/Ata.md']['content']);
        $this->assertSame([], $this->tree->ops);
    }

    public function testKeepingTheSameTitleIsNotACollisionWithItself(): void
    {
        $out = $this->tool('notes_edit', ['id' => $this->ata, 'content' => 'v2', 'title' => 'Ata']);
        $this->assertSame('Ata', $out['title']);
        $this->assertSame('v2', $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['content']);
    }

    public function testAnOversizedContentWithATitleWritesNothing(): void
    {
        $this->assertSame(\OCA\Mcp\Modules\Notes\Content\ContentMessages::noteTooLarge(\OCA\Mcp\Modules\Notes\Content\NoteContent::MAX_BYTES), $this->failure('notes_edit', ['id' => $this->ata, 'content' => str_repeat('x', \OCA\Mcp\Modules\Notes\Content\NoteContent::MAX_BYTES + 1), 'title' => 'Novo']));
        $this->assertSame('decisões...', $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['content']);
        $this->assertSame([], $this->tree->ops);
    }

    /** P18: notes_create is a write in the notes folder like any other, so a shared folder asks first. */

    public function testCreatingInASharedNotesFolderAsksForConfirmationAndWritesNothing(): void
    {
        $this->config->user['alice']['notes']['notesPath'] = 'Equipe';
        $this->tree->addFolder('/alice/files/Equipe', ['scope' => 'team']);
        $this->tree->mountPath = '/alice/files/Equipe';
        $before = $this->tree->nodes;
        $out = $this->tool('notes_create', ['title' => 'Nova', 'content' => 'x']);
        $this->assertTrue($out['requiresConfirmation']);
        $this->assertSame('team', $out['scope']);
        $this->assertSame('Equipe', $out['teamFolder']);
        $this->assertSame($before, $this->tree->nodes);
        $this->assertSame([], $this->tree->ops);
    }

    public function testCreatingInASharedNotesFolderGoesThroughOnceConfirmed(): void
    {
        $this->config->user['alice']['notes']['notesPath'] = 'Equipe';
        $this->tree->addFolder('/alice/files/Equipe', ['scope' => 'team']);
        $this->tree->mountPath = '/alice/files/Equipe';
        $out = $this->tool('notes_create', ['title' => 'Nova', 'content' => 'x', 'confirm_shared' => true]);
        $this->assertArrayNotHasKey('requiresConfirmation', $out);
        $this->assertSame('x', $this->tree->nodes['/alice/files/Equipe/Nova.md']['content']);
    }

    /** The category that does not exist yet is created inside the nearest folder that does, so that one is what is guarded. */

    public function testANewCategoryInsideASharedNotesFolderAsksBeforeCreatingIt(): void
    {
        $this->config->user['alice']['notes']['notesPath'] = 'Equipe';
        $this->tree->addFolder('/alice/files/Equipe', ['scope' => 'team']);
        $this->tree->mountPath = '/alice/files/Equipe';
        $before = $this->tree->nodes;
        $out = $this->tool('notes_create', ['title' => 'Nova', 'category' => 'Sub/Pasta']);
        $this->assertTrue($out['requiresConfirmation']);
        $this->assertSame($before, $this->tree->nodes);
    }

    public function testCreatingInThePersonalNotesFolderNeedsNoSharedConfirmation(): void
    {
        $out = $this->tool('notes_create', ['title' => 'Pessoal', 'content' => 'x']);
        $this->assertArrayNotHasKey('requiresConfirmation', $out);
        $this->assertSame('personal', $out['access']['scope'] ?? 'personal');
        $this->assertArrayHasKey('/alice/files/Notes/Pessoal.md', $this->tree->nodes);
    }

    public function testMoveBetweenCategoriesWithoutOverwriting(): void
    {
        $moved = $this->tool('notes_move', ['id' => $this->ata, 'category' => 'Arquivo/2026']);
        $this->assertSame('Arquivo/2026', $moved['category']);
        $this->assertArrayHasKey('/alice/files/Notes/Arquivo/2026/Ata.md', $this->tree->nodes);
        $this->tree->addFile('/alice/files/Notes/Ata.md', 'raiz');
        $this->assertSame(\OCA\Mcp\Modules\Notes\Storage\NamingMessages::titleExistsInTargetCategory(), $this->failure('notes_move', ['id' => $this->ata, 'category' => '']));
        $this->invalid('notes_move', ['id' => $this->ata, 'category' => '../../Documentos']);
    }

    public function testDeleteNeedsConfirmationAndTrashbin(): void
    {
        $plan = $this->module->preview('notes_delete', ['id' => $this->ata], 'alice');
        $this->assertSame('notes_delete', $plan['action']);
        $this->assertTrue($plan['recoverable']);
        $this->assertArrayHasKey('/alice/files/Notes/Reuniões/Ata.md', $this->tree->nodes);
        $this->apps = ['notes'];
        $this->assertSame(\OCA\Mcp\Modules\Notes\Execution\WriteMessages::notRecoverable(), $this->failure('notes_delete', ['id' => $this->ata, 'confirm' => true]));
        $this->assertArrayHasKey('/alice/files/Notes/Reuniões/Ata.md', $this->tree->nodes);
        $this->apps = ['notes', 'files_trashbin'];
        $this->assertSame(ToolFailure::NOT_FOUND, $this->failure('notes_delete', ['id' => $this->outside, 'confirm' => true]));
        $this->assertArrayHasKey('/alice/files/Documentos/segredo.md', $this->tree->nodes);
        $this->assertSame(['id' => $this->ata, 'deleted' => true, 'trash' => true], $this->tool('notes_delete', ['id' => $this->ata, 'confirm' => true]));
        $this->assertSame(['delete /alice/files/Notes/Reuniões/Ata.md'], $this->tree->ops);
    }

    public function testDeleteIsBlockedOnStorageWithoutTrashWrapper(): void
    {
        $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['trash'] = false;
        $this->assertSame(\OCA\Mcp\Modules\Notes\Execution\WriteMessages::notRecoverable(), $this->failure('notes_delete', ['id' => $this->ata, 'confirm' => true]));
        $this->assertArrayHasKey('/alice/files/Notes/Reuniões/Ata.md', $this->tree->nodes);
        $this->assertSame([], $this->tree->ops);
    }

    public function testDeleteRespectsAclAndEtag(): void
    {
        $this->assertSame(ToolFailure::CONFLICT, $this->failure('notes_delete', ['id' => $this->ata, 'confirm' => true, 'etag' => 'x']));
        $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['deletable'] = false;
        $this->assertSame(ToolFailure::FORBIDDEN, $this->failure('notes_delete', ['id' => $this->ata, 'confirm' => true]));
        $this->assertSame([], $this->tree->ops);
    }

    public function testSearchNotesByTitleAndContent(): void
    {
        // Match by title
        $hits = $this->tool('notes_search', ['query' => 'Ata']);
        $this->assertCount(1, $hits);
        $this->assertSame('Ata', $hits[0]['title']);
        $this->assertSame('Reuniões', $hits[0]['category']);
        $this->assertArrayHasKey('snippet', $hits[0]);
        // Match by content
        $hits = $this->tool('notes_search', ['query' => 'decisões']);
        $this->assertCount(1, $hits);
        $this->assertSame('Ata', $hits[0]['title']);
        $this->assertStringContainsString('decisões', $hits[0]['snippet']);
        // Scoped to category
        $hits = $this->tool('notes_search', ['query' => 'decisões', 'category' => 'Reuniões']);
        $this->assertCount(1, $hits);
        $this->assertSame('Ata', $hits[0]['title']);
        // Nonexistent category
        $hits = $this->tool('notes_search', ['query' => 'decisões', 'category' => 'Inexistente']);
        $this->assertSame([], $hits);
        // Limit caps results
        $hits = $this->tool('notes_search', ['query' => 'e', 'limit' => 1]);
        $this->assertCount(1, $hits);
    }

    public function testSearchNotesVisibilityGuardFiltersHiddenNotes(): void
    {
        $guard = $this->createMock(\OCA\Mcp\Access\Visibility\VisibilityGuard::class);
        $guard->method('filter')->willReturnCallback(function (iterable $nodes): array {
            $filtered = [];
            foreach ($nodes as $node) {
                // Hide Ata.md
                if (!str_contains($node->getPath(), 'Ata.md')) {
                    $filtered[] = $node;
                }
            }
            return $filtered;
        });
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn() => $this->tree->rootFolder());
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturnCallback(fn(string $app) => in_array($app, $this->apps, true));
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));
        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
        $module = \OCA\Mcp\Tests\Support\Notes\NotesTestFactory::module($this, [\OCA\Mcp\Modules\Notes\Storage\NotesFolders::class => new \OCA\Mcp\Modules\Notes\Storage\NotesFolders($root, $this->config->mock($this)), \OCA\Mcp\Modules\Notes\Storage\NoteLookup::class => new \OCA\Mcp\Modules\Notes\Storage\NoteLookup(null), \OCA\Mcp\Modules\Notes\Storage\NoteMetadata::class => new \OCA\Mcp\Modules\Notes\Storage\NoteMetadata(null), \OCA\Mcp\Modules\Notes\Storage\NoteNames::class => new \OCA\Mcp\Modules\Notes\Storage\NoteNames(), \OCA\Mcp\Modules\Notes\Content\NoteContent::class => new \OCA\Mcp\Modules\Notes\Content\NoteContent(), \OCP\App\IAppManager::class => $apps, \OCP\IUserManager::class => $users, \OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard::class => new SharedWriteGuard($access), \OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo::class => $access, \OCA\Mcp\Access\Visibility\VisibilityGuard::class => $guard, \OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite::class => null]);
        $def = array_column($module->definitions(), null, 'name')['notes_search'];
        $res = $module->call('notes_search', ArgumentValidator::validate($def['inputSchema'], ['query' => 'decisões']), 'alice');
        $hits = json_decode($res['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $hits);
    }

    public function testHiddenNoteIsOmittedFromListAndNotFoundOnGet(): void
    {
        $tagMapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $tagMapper->method('getTagIdsForObjects')->willReturn([(string) $this->ata => ['999']]);
        $guard = new VisibilityGuard($this->config->mock($this), $tagMapper, $this->tree->filesystemRoot());
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn() => $this->tree->rootFolder());
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturnCallback(fn(string $app) => in_array($app, $this->apps, true));
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));
        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
        $repo = new \OCA\Mcp\Modules\Notes\Storage\NotesFolders($root, $this->config->mock($this), $guard);
        $module = \OCA\Mcp\Tests\Support\Notes\NotesTestFactory::module($this, [\OCA\Mcp\Modules\Notes\Storage\NotesFolders::class => $repo, \OCA\Mcp\Modules\Notes\Storage\NoteLookup::class => new \OCA\Mcp\Modules\Notes\Storage\NoteLookup($guard), \OCA\Mcp\Modules\Notes\Storage\NoteMetadata::class => new \OCA\Mcp\Modules\Notes\Storage\NoteMetadata($guard), \OCA\Mcp\Modules\Notes\Storage\NoteNames::class => new \OCA\Mcp\Modules\Notes\Storage\NoteNames(), \OCA\Mcp\Modules\Notes\Content\NoteContent::class => new \OCA\Mcp\Modules\Notes\Content\NoteContent(), \OCP\App\IAppManager::class => $apps, \OCP\IUserManager::class => $users, \OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard::class => new SharedWriteGuard($access), \OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo::class => $access, \OCA\Mcp\Access\Visibility\VisibilityGuard::class => null, \OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite::class => null]);
        $list = json_decode($module->call('notes_list', [], 'alice')['content'][0]['text'], true);
        $ids = array_column($list, 'id');
        $this->assertNotContains($this->ata, $ids);
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(FileAccessMessages::notFound());
        $module->call('notes_read', ['id' => $this->ata], 'alice');
    }

    public function testCreateInACategoryUnderAHiddenFolderIsRefusedBeforeCreatingAnything(): void
    {
        $secretId = $this->tree->addFolder('/alice/files/Notes/Segredo');
        $module = $this->moduleHiding([$secretId]);
        // Plan and write give the very same answer as a category that does not exist, and nothing is built.
        try {
            $module->preview('notes_create', ['title' => 'Roteiro', 'category' => 'Segredo/Nova/Sub'], 'alice');
            $this->fail('notes_create planned a category under a hidden folder');
        } catch (ToolFailure $e) {
            $this->assertSame(FileAccessMessages::notFound(), $e->getMessage());
        }
        try {
            $this->callOn($module, 'notes_create', ['title' => 'Roteiro', 'category' => 'Segredo/Nova/Sub']);
            $this->fail('notes_create accepted a category under a hidden folder');
        } catch (ToolFailure $e) {
            $this->assertSame(FileAccessMessages::notFound(), $e->getMessage());
        }
        $this->assertArrayNotHasKey('/alice/files/Notes/Segredo/Nova', $this->tree->nodes);
        $this->assertArrayNotHasKey('/alice/files/Notes/Segredo/Nova/Sub', $this->tree->nodes);
    }

    public function testMoveToACategoryUnderAHiddenFolderIsRefusedWithoutCreatingAnything(): void
    {
        $secretId = $this->tree->addFolder('/alice/files/Notes/Segredo');
        $module = $this->moduleHiding([$secretId]);
        try {
            $module->preview('notes_move', ['id' => $this->ata, 'category' => 'Segredo/Nova'], 'alice');
            $this->fail('notes_move planned a category under a hidden folder');
        } catch (ToolFailure $e) {
            $this->assertSame(FileAccessMessages::notFound(), $e->getMessage());
        }
        try {
            $this->callOn($module, 'notes_move', ['id' => $this->ata, 'category' => 'Segredo/Nova']);
            $this->fail('notes_move accepted a category under a hidden folder');
        } catch (ToolFailure $e) {
            $this->assertSame(FileAccessMessages::notFound(), $e->getMessage());
        }
        $this->assertSame('/alice/files/Notes/Reuniões/Ata.md', $this->tree->node('/alice/files/Notes/Reuniões/Ata.md')->getPath());
        $this->assertArrayNotHasKey('/alice/files/Notes/Segredo/Nova', $this->tree->nodes);
    }

    public function testACategoryUnderAVisibleFolderIsStillCreated(): void
    {
        $this->tree->addFolder('/alice/files/Notes/Publico');
        $module = $this->moduleHiding([]);
        // nothing is hidden here
        $plan = $module->preview('notes_create', ['title' => 'Roteiro', 'category' => 'Publico/Nova'], 'alice');
        $this->assertSame('Publico/Nova', $plan['note']['category']);
        $note = $this->callOn($module, 'notes_create', ['title' => 'Roteiro', 'category' => 'Publico/Nova']);
        $this->assertSame('Publico/Nova', $note['category']);
        $this->assertArrayHasKey('/alice/files/Notes/Publico/Nova', $this->tree->nodes);
    }

    public function testAnEmptyCategoryWithAHiddenNotesRootIsRefused(): void
    {
        $notesId = $this->tree->node('/alice/files/Notes')->getId();
        $module = $this->moduleHiding([$notesId]);
        try {
            $module->preview('notes_create', ['title' => 'Roteiro'], 'alice');
            $this->fail('notes_create planned at a hidden notes root');
        } catch (ToolFailure $e) {
            $this->assertSame(FileAccessMessages::notFound(), $e->getMessage());
        }
        try {
            $this->callOn($module, 'notes_create', ['title' => 'Roteiro']);
            $this->fail('notes_create accepted a hidden notes root');
        } catch (ToolFailure $e) {
            $this->assertSame(FileAccessMessages::notFound(), $e->getMessage());
        }
    }

    /** A module whose notes repository and guard hide the given file ids with one hidden tag. */

    private function moduleHiding(array $hiddenIds): NotesModule
    {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $tagMapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $tagMapper->method('getTagIdsForObjects')->willReturnCallback(static function (array $ids) use ($hiddenIds): array {
            $out = [];
            foreach ($ids as $id) {
                $out[(string) $id] = in_array((int) $id, $hiddenIds, true) ? ['999'] : [];
            }
            return $out;
        });
        $guard = new VisibilityGuard($this->config->mock($this), $tagMapper);
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn() => $this->tree->rootFolder());
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturnCallback(fn(string $app) => in_array($app, $this->apps, true));
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));
        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
        return \OCA\Mcp\Tests\Support\Notes\NotesTestFactory::module($this, [\OCA\Mcp\Modules\Notes\Storage\NotesFolders::class => new \OCA\Mcp\Modules\Notes\Storage\NotesFolders($root, $this->config->mock($this), $guard), \OCA\Mcp\Modules\Notes\Storage\NoteLookup::class => new \OCA\Mcp\Modules\Notes\Storage\NoteLookup($guard), \OCA\Mcp\Modules\Notes\Storage\NoteMetadata::class => new \OCA\Mcp\Modules\Notes\Storage\NoteMetadata($guard), \OCA\Mcp\Modules\Notes\Storage\NoteNames::class => new \OCA\Mcp\Modules\Notes\Storage\NoteNames(), \OCA\Mcp\Modules\Notes\Content\NoteContent::class => new \OCA\Mcp\Modules\Notes\Content\NoteContent(), \OCP\App\IAppManager::class => $apps, \OCP\IUserManager::class => $users, \OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard::class => new SharedWriteGuard($access), \OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo::class => $access, \OCA\Mcp\Access\Visibility\VisibilityGuard::class => $guard, \OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite::class => null]);
    }

    private function callOn(NotesModule $module, string $name, array $arguments = []): array
    {
        $definition = array_column($module->definitions(), null, 'name')[$name];
        $published = WriteGate::publish($definition);
        $result = $module->call($name, ArgumentValidator::validate($published['inputSchema'], $arguments), 'alice');
        return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }
    // ------------------------------------------------------- a category named "0"

    /** "0" is a name like any other: the note goes where the plan says, never one level up. */

    public function testACategoryNamedZeroIsWrittenWhereThePlanSays(): void
    {
        $module = $this->moduleHiding([]);
        $plan = $module->preview('notes_create', ['title' => 'Roteiro', 'category' => '0/Nova'], 'alice');
        $note = $this->callOn($module, 'notes_create', ['title' => 'Roteiro', 'category' => '0/Nova']);
        $this->assertSame('0/Nova', $plan['note']['category']);
        $this->assertSame('0/Nova', $note['category']);
        $this->assertArrayHasKey('/alice/files/Notes/0/Nova/Roteiro.md', $this->tree->nodes);
        $this->assertArrayNotHasKey('/alice/files/Notes/Nova', $this->tree->nodes);
    }

    public function testACategoryUnderAHiddenFolderNamedZeroIsRefused(): void
    {
        $zeroId = $this->tree->addFolder('/alice/files/Notes/0');
        $module = $this->moduleHiding([$zeroId]);
        try {
            $module->preview('notes_create', ['title' => 'Roteiro', 'category' => '0/Nova'], 'alice');
            $this->fail('notes_create planned a category under the hidden folder 0');
        } catch (ToolFailure $e) {
            $this->assertSame(FileAccessMessages::notFound(), $e->getMessage());
        }
        try {
            $this->callOn($module, 'notes_create', ['title' => 'Roteiro', 'category' => '0/Nova']);
            $this->fail('notes_create wrote under the hidden folder 0');
        } catch (ToolFailure $e) {
            $this->assertSame(FileAccessMessages::notFound(), $e->getMessage());
        }
        $this->assertArrayNotHasKey('/alice/files/Notes/0/Nova', $this->tree->nodes);
        $this->assertArrayNotHasKey('/alice/files/Notes/Nova', $this->tree->nodes);
    }

    public function testAMoveToCategoryZeroLandsInIt(): void
    {
        $module = $this->moduleHiding([]);
        $plan = $module->preview('notes_move', ['id' => $this->ata, 'category' => '0'], 'alice');
        $note = $this->callOn($module, 'notes_move', ['id' => $this->ata, 'category' => '0']);
        $this->assertSame('0', $plan['to']);
        $this->assertTrue($plan['categoryCreated']);
        $this->assertSame('0', $note['category']);
        $this->assertArrayHasKey('/alice/files/Notes/0/Ata.md', $this->tree->nodes);
        $this->assertArrayNotHasKey('/alice/files/Notes/Ata.md', $this->tree->nodes);
    }

    public function testAMoveToAHiddenCategoryZeroIsRefused(): void
    {
        $zeroId = $this->tree->addFolder('/alice/files/Notes/0');
        $module = $this->moduleHiding([$zeroId]);
        try {
            $this->callOn($module, 'notes_move', ['id' => $this->ata, 'category' => '0']);
            $this->fail('notes_move wrote into the hidden folder 0');
        } catch (ToolFailure $e) {
            $this->assertSame(FileAccessMessages::notFound(), $e->getMessage());
        }
        $this->assertArrayHasKey('/alice/files/Notes/Reuniões/Ata.md', $this->tree->nodes);
    }
}
