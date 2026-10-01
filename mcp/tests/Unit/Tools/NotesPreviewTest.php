<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\Notes\NotesModule;
use OCA\Mcp\Tools\Notes\NotesRepository;
use OCA\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the content of the Notes plans and the guarantee that a plan leaves the tree untouched: no
 * operation recorded by {@see FakeTree} and every node exactly as it was.
 */
final class NotesPreviewTest extends TestCase {
    private FakeTree $tree;
    private InMemoryConfig $config;
    private NotesModule $module;
    private IUserManager $users;
    private int $ata;
    /** @var array<string, mixed> */
    private array $before;

    protected function setUp(): void {
        $this->tree = new FakeTree($this);
        $this->ata = $this->tree->addFile('/alice/files/Notes/Reuniões/Ata.md', 'decisões', 'text/markdown');
        $this->tree->addFile('/alice/files/Notes/Ideia.txt', 'ideia');
        $this->tree->addFolder('/alice/files/Notes/Projetos');
        $this->config = new InMemoryConfig();

        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn () => $this->tree->rootFolder());
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $this->users = $this->createMock(IUserManager::class);
        $this->users->method('get')->willReturn($this->createMock(IUser::class));
        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
        $this->module = new NotesModule(new NotesRepository($root, $this->config->mock($this)), $apps, $this->users,
            new SharedWriteGuard($access), $access);
        $this->before = $this->tree->nodes;
    }

    private function assertNothingWritten(): void {
        $this->assertSame([], $this->tree->ops);
        $this->assertSame($this->before, $this->tree->nodes);
    }

    public function testCreatePlanNamesTheFileInAnExistingCategory(): void {
        $plan = $this->module->preview('notes_create', ['title' => 'Roteiro', 'content' => 'passos', 'category' => 'Projetos'], 'alice');

        $this->assertSame('notes_create', $plan['action']);
        $this->assertSame('Roteiro', $plan['note']['title']);
        $this->assertSame('Roteiro.md', $plan['note']['fileName']);
        $this->assertSame('Projetos', $plan['note']['category']);
        $this->assertFalse($plan['note']['categoryCreated']);
        $this->assertSame('passos', $plan['note']['content']);
        $this->assertSame(6, $plan['note']['bytes']);
        $this->assertTrue($plan['recoverable']);
        $this->assertNothingWritten();
    }

    public function testCreatePlanSaysAMissingCategoryWouldBeCreatedWithoutCreatingIt(): void {
        $plan = $this->module->preview('notes_create', ['title' => 'Roteiro', 'category' => 'Novas/Sub'], 'alice');

        $this->assertSame('Novas/Sub', $plan['note']['category']);
        $this->assertTrue($plan['note']['categoryCreated']);
        $this->assertNothingWritten();
    }

    public function testEditPlanShowsBeforeAndAfter(): void {
        $plan = $this->module->preview('notes_edit', ['id' => $this->ata, 'content' => 'novas decisões', 'title' => 'Ata final'], 'alice');

        $this->assertSame($this->ata, $plan['note']['id']);
        $this->assertSame(['Ata', 'decisões'], [$plan['note']['before']['title'], $plan['note']['before']['content']]);
        $this->assertSame(['Ata final', 'novas decisões'], [$plan['note']['after']['title'], $plan['note']['after']['content']]);
        $this->assertSame(['title', 'content'], $plan['changed']);
        $this->assertSame('personal', $plan['access']['scope']);
        $this->assertSame([], $plan['shared']);
        $this->assertTrue($plan['recoverable']);
        $this->assertNothingWritten();
    }

    public function testEditPlanOfANoteInATeamFolderListsItAsShared(): void {
        $this->config->user['alice']['notes']['notesPath'] = 'Equipe';
        $id = $this->tree->addFile('/alice/files/Equipe/Ata.md', 'x', 'text/markdown', ['scope' => 'team']);
        $this->tree->mountPath = '/alice/files/Equipe';
        $this->before = $this->tree->nodes;

        $plan = $this->module->preview('notes_edit', ['id' => $id, 'content' => 'y'], 'alice');

        $this->assertSame(['content'], $plan['changed']);
        $this->assertCount(1, $plan['shared']);
        $this->assertSame('team', $plan['shared'][0]['scope']);
        $this->assertNothingWritten();
    }

    public function testMovePlanShowsOriginAndDestination(): void {
        $plan = $this->module->preview('notes_move', ['id' => $this->ata, 'category' => 'Projetos'], 'alice');

        $this->assertSame('Reuniões', $plan['from']);
        $this->assertSame('Projetos', $plan['to']);
        $this->assertFalse($plan['categoryCreated']);
        $this->assertFalse($plan['titleTaken']);
        $this->assertSame($this->ata, $plan['note']['id']);
        $this->assertSame([], $plan['shared']);
        $this->assertTrue($plan['recoverable']);
        $this->assertNothingWritten();
    }

    public function testMovePlanToAMissingCategory(): void {
        $plan = $this->module->preview('notes_move', ['id' => $this->ata, 'category' => 'Arquivo'], 'alice');

        $this->assertTrue($plan['categoryCreated']);
        $this->assertNothingWritten();
    }

    public function testDeletePlanSendsTheNoteToTheTrash(): void {
        $plan = $this->module->preview('notes_delete', ['id' => $this->ata], 'alice');

        $this->assertSame($this->ata, $plan['note']['id']);
        $this->assertSame('Reuniões', $plan['note']['category']);
        $this->assertTrue($plan['trash']);
        $this->assertTrue($plan['recoverable']);
        $this->assertNotSame('', $plan['consequence']);
        $this->assertSame([], $plan['shared']);
        $this->assertNothingWritten();
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unconfirmedProvider(): array {
        return ['absent' => [[]], 'false' => [['confirm' => false]]];
    }

    #[DataProvider('unconfirmedProvider')]
    public function testRegistryAnswersWithThePlanUnlessConfirmed(array $confirm): void {
        $result = $this->registry()->call('notes_edit', ['id' => $this->ata, 'content' => 'novo'] + $confirm, 'alice');

        $out = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($out['requiresConfirmation']);
        $this->assertSame('notes_edit', $out['action']);
        $this->assertNothingWritten();
    }

    public function testRegistryRunsTheWriteOnceConfirmed(): void {
        $result = $this->registry()->call('notes_edit', ['id' => $this->ata, 'content' => 'novo', 'confirm' => true], 'alice');

        $out = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('requiresConfirmation', $out);
        $this->assertSame('novo', $this->tree->nodes['/alice/files/Notes/Reuniões/Ata.md']['content']);
    }

    private function registry(): ToolRegistry {
        $policy = new GrantPolicy((new InMemoryConfig())->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        foreach (GrantPolicy::CATALOG['notes'] as $operation) {
            $policy->setGrant('alice', 'notes', $operation, true);
        }
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);

        return new ToolRegistry([$this->module], $policy, $apps, $this->users, $this->createMock(LoggerInterface::class));
    }
}
