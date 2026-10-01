<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use InvalidArgumentException;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tools\ArgumentValidator;
use OCA\Mcp\Tools\Notes\NotesMessages;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\Notes\NotesModule;
use OCA\Mcp\Tools\Notes\NotesRepository;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\WriteGate;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class NotesModuleTest extends TestCase {
    private FakeTree $tree;
    private InMemoryConfig $config;
    private array $apps = ['notes', 'files_trashbin'];
    private NotesModule $module;
    private int $ata;
    private int $outside;

    protected function setUp(): void {
        $this->tree = new FakeTree($this);
        $this->ata = $this->tree->addFile('/alice/files/Notes/Reuniões/Ata.md', 'decisões...', 'text/markdown');
        $this->tree->addFile('/alice/files/Notes/Ideia.txt', 'ideia');
        $this->tree->addFile('/alice/files/Notes/foto.png', 'png', 'image/png');
        $this->outside = $this->tree->addFile('/alice/files/Documentos/segredo.md', 'fora');
        $this->config = new InMemoryConfig();

        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn () => $this->tree->rootFolder());
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturnCallback(fn (string $app) => in_array($app, $this->apps, true));
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));
        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
        $this->module = new NotesModule(new NotesRepository($root, $this->config->mock($this)), $apps, $users,
            new SharedWriteGuard($access), $access);
    }

    private function tool(string $name, array $arguments = []): array {
        $definition = array_column($this->module->definitions(), null, 'name')[$name];
        $published = WriteGate::publish($definition);
        $result = $this->module->call($name, ArgumentValidator::validate($published['inputSchema'], $arguments), 'alice');
        return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function failure(string $name, array $arguments): string {
        try {
            $this->tool($name, $arguments);
        } catch (ToolFailure $e) {
            return $e->getMessage();
        }
        $this->fail("$name did not fail");
    }

    private function invalid(string $name, array $arguments): void {
        try {
            $this->tool($name, $arguments);
            $this->fail("$name accepted " . json_encode($arguments));
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testDefinitionsRequireNotesAppAndMatchGrants(): void {
        $defs = $this->module->definitions();
        $this->assertSame(['notes_list', 'notes_read', 'notes_create', 'notes_edit', 'notes_move', 'notes_delete'], array_column($defs, 'name'));
        $this->assertSame(['read', 'read', 'create', 'edit', 'move', 'delete'], array_column($defs, 'operation'));
        $this->assertSame(['notes'], array_values(array_unique(array_column($defs, 'app'))));
        $this->assertSame(['id'], $defs[1]['inputSchema']['required']);
    }

    public function testListReturnsOnlyNotesInsideTheNotesFolder(): void {
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
    public function testANoteOutsideThePersonalScopeAsksForConfirmationBeforeAnyChange(): void {
        $this->config->user['alice']['notes']['notesPath'] = 'Equipe';
        $id = $this->tree->addFile('/alice/files/Equipe/Ata.md', 'decisões', 'text/markdown', ['scope' => 'team']);
        $this->tree->mountPath = '/alice/files/Equipe';

        $edit = $this->tool('notes_edit', ['id' => $id, 'content' => 'novo']);
        $this->assertTrue($edit['requiresConfirmation']);
        $this->assertSame('team', $edit['scope']);
        $this->assertSame('Equipe', $edit['teamFolder']);
        $this->assertSame((string)$id, $edit['resource']);
        $this->assertSame('decisões', $this->tree->nodes['/alice/files/Equipe/Ata.md']['content']);
        $this->assertSame([], $this->tree->ops);

        $this->tree->addFile('/alice/files/Equipe/Outra.md', 'x', 'text/markdown', ['scope' => 'team']);
        $other = $this->tree->nodes['/alice/files/Equipe/Outra.md']['id'];
        $this->assertTrue($this->tool('notes_move', ['id' => $other, 'category' => 'Sub'])['requiresConfirmation']);
        $this->assertTrue($this->tool('notes_delete', ['id' => $other, 'confirm' => true])['requiresConfirmation']);
        $this->assertSame([], $this->tree->ops);
    }

    public function testTheGuardLetsTheChangeThroughOnceTheUserConfirmed(): void {
        $this->config->user['alice']['notes']['notesPath'] = 'Equipe';
        $id = $this->tree->addFile('/alice/files/Equipe/Ata.md', 'decisões', 'text/markdown', ['scope' => 'shared']);
        $out = $this->tool('notes_edit', ['id' => $id, 'content' => 'novo', 'confirm_shared' => true]);
        $this->assertArrayNotHasKey('requiresConfirmation', $out);
        $this->assertSame('novo', $this->tree->nodes['/alice/files/Equipe/Ata.md']['content']);
    }

    /** A note Nextcloud will not update stays refused, confirmation or not. */
    public function testANoteWithoutUpdatePermissionIsDeniedEitherWay(): void {
        $this->config->user['alice']['notes']['notesPath'] = 'Equipe';
        $id = $this->tree->addFile('/alice/files/Equipe/Ata.md', 'x', 'text/markdown',
            ['scope' => 'shared', 'permissions' => \OCP\Constants::PERMISSION_READ]);
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

    public function testThePersonalPathNeverAsksForConfirmation(): void {
        $out = $this->tool('notes_edit', ['id' => $this->ata, 'content' => 'novo']);
        $this->assertArrayNotHasKey('requiresConfirmation', $out);
        $this->assertSame('personal', $out['access']['scope']);
    }

    public function testTheWriteToolsDeclareTheConfirmationArgument(): void {
        $defs = array_column($this->module->definitions(), null, 'name');
        foreach (['notes_edit', 'notes_move', 'notes_delete'] as $name) {
            $this->assertArrayHasKey('confirm_shared', $defs[$name]['inputSchema']['properties'], $name);
        }
    }

    public function testListUsesTheConfiguredFolderAndToleratesAMissingOne(): void {
        $this->config->user['alice']['notes']['notesPath'] = 'Notas';
        $this->assertSame([], $this->tool('notes_list'));
        $this->tree->addFile('/alice/files/Notas/x.md', 'x');
        $this->assertSame(['x'], array_column($this->tool('notes_list'), 'title'));
    }

    public function testReadByIdStaysInsideTheNotesFolder(): void {
        $this->assertSame('decisões...', $this->tool('notes_read', ['id' => $this->ata])['content']);
        $this->assertSame(ToolFailure::NOT_FOUND, $this->failure('notes_read', ['id' => $this->outside]));
        $this->assertSame(ToolFailure::NOT_FOUND, $this->failure('notes_read', ['id' => 99999]));
        $this->invalid('notes_read', ['id' => '1']);
    }

    public function testReadEnforcesSizeLimit(): void {
        $id = $this->tree->addFile('/alice/files/Notes/grande.md', 'x', 'text/markdown', ['size' => NotesModule::MAX_BYTES + 1]);
        $this->assertSame(NotesMessages::noteTooLargeForReading(NotesModule::MAX_BYTES), $this->failure('notes_read', ['id' => $id]));
    }

    public function testCreateNeverOverwrites(): void {
        $created = $this->tool('notes_create', ['title' => 'Ata', 'content' => 'nova', 'category' => 'Reuniões']);
        $this->assertSame(['Ata (2)', 'Reuniões'], [$created['title'], $created['category']]);
        $this->assertSame('decisões...', $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['content']);
        $this->assertSame('nova', $this->tree->nodes['/alice/files/Notes/Reuniões/Ata (2).md']['content']);
    }

    public function testCreateSanitizesTitleAndCategoryAndCreatesFolders(): void {
        $this->config->user['alice']['notes']['notesPath'] = 'NovaPasta';
        $created = $this->tool('notes_create', ['title' => "a/b\\c\n", 'category' => 'Projetos/2026']);
        $this->assertSame(['a b c', 'Projetos/2026'], [$created['title'], $created['category']]);
        $this->assertArrayHasKey('/alice/files/NovaPasta/Projetos/2026/a b c.md', $this->tree->nodes);
        $this->invalid('notes_create', ['title' => '/']);
        $this->invalid('notes_create', ['title' => 'x', 'category' => '../Documentos']);
    }

    public function testEditContentAndTitle(): void {
        $etag = $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['etag'];
        $edited = $this->tool('notes_edit', ['id' => $this->ata, 'content' => 'v2', 'title' => 'Ata final', 'etag' => $etag]);
        $this->assertSame(['id' => $this->ata, 'title' => 'Ata final', 'category' => 'Reuniões'], array_intersect_key($edited, ['id' => 0, 'title' => 0, 'category' => 0]));
        $this->assertSame('v2', $this->tree->nodes['/alice/files/Notes/Reuniões/Ata final.md']['content']);
    }

    public function testEditRefusesConflictsAndEmptyChanges(): void {
        $this->assertSame(ToolFailure::CONFLICT, $this->failure('notes_edit', ['id' => $this->ata, 'content' => 'x', 'etag' => 'velho']));
        $this->tree->addFile('/alice/files/Notes/Reuniões/Outra.md', 'o');
        $this->assertSame(NotesMessages::titleExistsInCategory(), $this->failure('notes_edit', ['id' => $this->ata, 'title' => 'Outra']));
        $this->assertSame(ToolFailure::NOT_FOUND, $this->failure('notes_edit', ['id' => $this->outside, 'content' => 'x']));
        $this->invalid('notes_edit', ['id' => $this->ata]);
        $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['updateable'] = false;
        $this->assertSame(ToolFailure::FORBIDDEN, $this->failure('notes_edit', ['id' => $this->ata, 'content' => 'x']));
        $this->assertSame('decisões...', $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['content']);
        $this->assertSame([], array_filter($this->tree->ops, fn ($op) => str_starts_with($op, 'write') || str_starts_with($op, 'move')));
    }

    public function testMoveBetweenCategoriesWithoutOverwriting(): void {
        $moved = $this->tool('notes_move', ['id' => $this->ata, 'category' => 'Arquivo/2026']);
        $this->assertSame('Arquivo/2026', $moved['category']);
        $this->assertArrayHasKey('/alice/files/Notes/Arquivo/2026/Ata.md', $this->tree->nodes);
        $this->tree->addFile('/alice/files/Notes/Ata.md', 'raiz');
        $this->assertSame(NotesMessages::titleExistsInTargetCategory(), $this->failure('notes_move', ['id' => $this->ata, 'category' => '']));
        $this->invalid('notes_move', ['id' => $this->ata, 'category' => '../../Documentos']);
    }

    public function testDeleteNeedsConfirmationAndTrashbin(): void {
        $plan = $this->module->preview('notes_delete', ['id' => $this->ata], 'alice');
        $this->assertSame('notes_delete', $plan['action']);
        $this->assertTrue($plan['recoverable']);
        $this->assertArrayHasKey('/alice/files/Notes/Reuniões/Ata.md', $this->tree->nodes);
        $this->apps = ['notes'];
        $this->assertSame(NotesMessages::notRecoverable(), $this->failure('notes_delete', ['id' => $this->ata, 'confirm' => true]));
        $this->assertArrayHasKey('/alice/files/Notes/Reuniões/Ata.md', $this->tree->nodes);
        $this->apps = ['notes', 'files_trashbin'];
        $this->assertSame(ToolFailure::NOT_FOUND, $this->failure('notes_delete', ['id' => $this->outside, 'confirm' => true]));
        $this->assertArrayHasKey('/alice/files/Documentos/segredo.md', $this->tree->nodes);
        $this->assertSame(['id' => $this->ata, 'deleted' => true, 'trash' => true], $this->tool('notes_delete', ['id' => $this->ata, 'confirm' => true]));
        $this->assertSame(['delete /alice/files/Notes/Reuniões/Ata.md'], $this->tree->ops);
    }

    public function testDeleteIsBlockedOnStorageWithoutTrashWrapper(): void {
        $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['trash'] = false;
        $this->assertSame(NotesMessages::notRecoverable(), $this->failure('notes_delete', ['id' => $this->ata, 'confirm' => true]));
        $this->assertArrayHasKey('/alice/files/Notes/Reuniões/Ata.md', $this->tree->nodes);
        $this->assertSame([], $this->tree->ops);
    }

    public function testDeleteRespectsAclAndEtag(): void {
        $this->assertSame(ToolFailure::CONFLICT, $this->failure('notes_delete', ['id' => $this->ata, 'confirm' => true, 'etag' => 'x']));
        $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['deletable'] = false;
        $this->assertSame(ToolFailure::FORBIDDEN, $this->failure('notes_delete', ['id' => $this->ata, 'confirm' => true]));
        $this->assertSame([], $this->tree->ops);
    }

    public function testHiddenNoteIsOmittedFromListAndNotFoundOnGet(): void {
        $tagMapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $tagMapper->method('getTagIdsForObjects')->willReturn([(string)$this->ata => ['999']]);
        $guard = new VisibilityGuard($this->config->mock($this), $tagMapper);

        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn () => $this->tree->rootFolder());
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturnCallback(fn (string $app) => in_array($app, $this->apps, true));
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));
        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
        $repo = new NotesRepository($root, $this->config->mock($this), $guard);
        $module = new NotesModule($repo, $apps, $users, new SharedWriteGuard($access), $access);

        $list = json_decode($module->call('notes_list', [], 'alice')['content'][0]['text'], true);
        $ids = array_column($list, 'id');
        $this->assertNotContains($this->ata, $ids);

        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(CommonMessages::notFound());
        $module->call('notes_read', ['id' => $this->ata], 'alice');
    }
}