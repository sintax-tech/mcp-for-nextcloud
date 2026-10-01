<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Files_Versions\Versions\FakeVersion;
use OCA\Files_Versions\Versions\FakeVersionManager;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\Files\FilesModule;
use OCA\Mcp\Tools\Files\TextExtractor;
use OCA\Mcp\Tools\Files\VersionTools;
use OCA\Mcp\Tools\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers the content of every Files plan and the guarantee that a plan writes nothing: no operation on
 * the tree, no backup prepared, no checkout token minted, no batch recorded or marked undone.
 */
final class FilesPlanTest extends FilesToolsTestCase {
    /** @var array<string, mixed> */
    private array $nodesBefore;
    /** @var array<int, mixed> */
    private array $batchesBefore;
    private FakeVersionManager $manager;

    protected function setUp(): void {
        parent::setUp();
        require_once __DIR__ . '/Stubs/versions_api.php';
        $this->tree->addFile('/alice/files/Documentos/plano.md', "um\ndois\n", 'text/markdown');
        $this->tree->addFolder('/alice/files/Arquivo');

        // The same module, with a backup that must never be prepared and versions to restore from.
        $backup = $this->createMock(FileBackup::class);
        $backup->expects(self::never())->method('prepare');
        $manager = $this->manager = FakeVersionManager::with([new FakeVersion(1759100000, 1759100000, 4, 'text/markdown', 'ata.md')]);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(fn (string $id) => $id === 'OCA\Files_Versions\Versions\IVersionManager' ? $manager : null);
        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
        $versions = new VersionTools($this->apps, $this->users, new TextExtractor($this->temp), $backup, $access, $container);
        $this->module = $this->rebuilt(['backup' => $backup, 'versions' => $versions]);
    }

    /**
     * @param array<string, object> $replace constructor arguments to swap, by parameter name
     * @return FilesModule the module of the base case with those collaborators replaced
     */
    private function rebuilt(array $replace): FilesModule {
        $args = [];
        foreach ((new \ReflectionClass(FilesModule::class))->getConstructor()->getParameters() as $parameter) {
            $name = $parameter->getName();
            $args[] = $replace[$name] ?? (new \ReflectionProperty(FilesModule::class, $name))->getValue($this->module);
        }
        return new FilesModule(...$args);
    }

    private function snapshot(): void {
        $this->nodesBefore = $this->tree->nodes;
        $this->batchesBefore = $this->batches->all();
    }

    private function assertNothingWritten(): void {
        $this->assertSame([], $this->tree->ops);
        $this->assertSame($this->nodesBefore, $this->tree->nodes);
        $this->assertEquals($this->batchesBefore, $this->batches->all());
        $this->assertSame([], $this->issued, 'no checkout link may be minted by a plan');
    }

    public function testMkdirPlanListsTheLevelsItWouldCreate(): void {
        $this->snapshot();
        $plan = $this->plan('files_mkdir', ['path' => '/Arquivo/2026/Q3']);

        $this->assertSame('/Arquivo/2026/Q3', $plan['path']);
        $this->assertSame(['/Arquivo/2026', '/Arquivo/2026/Q3'], $plan['created']);
        $this->assertSame('/Arquivo', $plan['createdIn']);
        $this->assertSame([], $plan['shared']);
        $this->assertTrue($plan['recoverable']);
        $this->assertNothingWritten();
    }

    public function testCopyPlanShowsOriginDestinationAndSize(): void {
        $this->snapshot();
        $plan = $this->plan('files_copy', ['from' => '/Documentos/ata.md', 'to' => '/Arquivo/ata.md']);

        $this->assertSame(['/Documentos/ata.md', '/Arquivo/ata.md'], [$plan['from'], $plan['to']]);
        $this->assertSame(1, $plan['nodes']);
        $this->assertSame(strlen("# Ata\nolá"), $plan['bytes']);
        $this->assertSame([], $plan['shared']);
        $this->assertTrue($plan['recoverable']);
        $this->assertNothingWritten();
    }

    public function testMovePlanShowsOriginAndDestination(): void {
        $this->snapshot();
        $plan = $this->plan('files_move', ['from' => '/Documentos/ata.md', 'to' => '/Arquivo/ata.md']);

        $this->assertSame(['/Documentos/ata.md', '/Arquivo/ata.md'], [$plan['from'], $plan['to']]);
        $this->assertFalse($plan['isDir']);
        $this->assertSame([], $plan['shared']);
        $this->assertTrue($plan['recoverable']);
        $this->assertNothingWritten();
    }

    public function testMoveBatchPlanListsEveryMoveInOrder(): void {
        $this->snapshot();
        $moves = [
            ['from' => '/Documentos/ata.md', 'to' => '/Arquivo/ata.md'],
            ['from' => '/Documentos/plano.md', 'to' => '/Arquivo/plano.md'],
        ];
        $plan = $this->plan('files_move_batch', ['moves' => $moves]);

        $this->assertSame($moves, $plan['order']);
        $this->assertFalse($plan['requiresSharedConfirmation']);
        $this->assertStringContainsString('files_undo_batch', $plan['undo']);
        $this->assertNothingWritten();
    }

    public function testUndoBatchPlanShowsWhatGoesBackWithoutMarkingTheBatch(): void {
        $id = $this->json('files_move_batch', [
            'moves' => [['from' => '/Documentos/ata.md', 'to' => '/Arquivo/ata.md']],
            'confirm' => true,
        ])['batch_id'];
        $this->tree->ops = [];
        $this->snapshot();

        $plan = $this->plan('files_undo_batch', ['batch_id' => $id]);

        $this->assertSame($id, $plan['batch_id']);
        $this->assertSame(1, $plan['count']);
        $this->assertSame([['to' => '/Arquivo/ata.md', 'from' => '/Documentos/ata.md']], $plan['undo']);
        $this->assertSame([], $plan['conflicts']);
        $this->assertTrue($plan['ok']);
        $this->assertTrue($plan['recoverable']);
        $this->assertNothingWritten();
    }

    public function testEditPlanShowsTheDiffAndTheBackupItWouldTake(): void {
        $this->snapshot();
        $plan = $this->plan('files_edit', ['path' => '/Documentos/plano.md', 'content' => "um\ntrês\n"]);

        $this->assertSame('/Documentos/plano.md', $plan['path']);
        $this->assertStringContainsString('-dois', $plan['diff']);
        $this->assertStringContainsString('+três', $plan['diff']);
        $this->assertSame(['before' => 8, 'after' => 9], $plan['size']);
        $this->assertSame('/' . FilesModule::BACKUP_FOLDER, $plan['backup']);
        $this->assertSame([], $plan['shared']);
        $this->assertFalse($plan['requiresSharedConfirmation']);
        $this->assertTrue($plan['recoverable']);
        $this->assertNothingWritten();
    }

    public function testReplacePlanShowsTheSingleOccurrenceAndTheDiff(): void {
        $this->snapshot();
        $plan = $this->plan('files_replace', ['path' => '/Documentos/plano.md', 'old' => 'dois', 'new' => 'quatro']);

        $this->assertSame(['occurrences' => 1, 'replaced' => 1], $plan['snippet']);
        $this->assertStringContainsString('+quatro', $plan['diff']);
        $this->assertSame(['before' => 8, 'after' => 10], $plan['size']);
        $this->assertSame('/' . FilesModule::BACKUP_FOLDER, $plan['backup']);
        $this->assertTrue($plan['recoverable']);
        $this->assertNothingWritten();
    }

    public function testCheckoutPlanPromisesLinksWithoutMintingThem(): void {
        $this->snapshot();
        $plan = $this->plan('files_checkout', ['path' => '/Documentos/ata.md']);

        $this->assertSame('/Documentos/ata.md', $plan['path']);
        $this->assertTrue($plan['linksAfterConfirmation']);
        $this->assertArrayNotHasKey('download', $plan);
        $this->assertArrayNotHasKey('upload', $plan);
        $this->assertSame('/' . FilesModule::BACKUP_FOLDER, $plan['backup']);
        $this->assertTrue($plan['recoverable']);
        $this->assertNothingWritten();
        $this->assertSame([], $this->store->rows, "no checkout token may be stored by a plan");
        $this->assertSame([], $this->store->ops);
    }

    /** The plan refuses a checkout without versioning the same way the mint does, instead of crashing. */
    public function testCheckoutPlanWithoutVersioningIsRefusedLikeTheMint(): void {
        $this->enabled = [];
        $this->snapshot();

        try {
            $this->plan('files_checkout', ['path' => '/Documentos/ata.md']);
            $this->fail('a checkout without files_versions must not be planned');
        } catch (\OCA\Mcp\Tools\ToolFailure $e) {
            $this->assertSame(\OCA\Mcp\Tools\Files\FilesMessages::versionsOff(), $e->getMessage());
        }
        $this->assertNothingWritten();
    }

    public function testVersionRestorePlanShowsTheVersionAndTheCurrentFile(): void {
        $this->snapshot();
        $plan = $this->plan('files_version_restore', ['path' => '/Documentos/ata.md', 'version' => '1759100000']);

        $this->assertSame('/Documentos/ata.md', $plan['path']);
        $this->assertSame('1759100000', $plan['version']['revision']);
        $this->assertSame(['list'], $this->manager->ops, 'a plan reads the versions and never rolls back');
        $this->assertArrayHasKey('etag', $plan['current']);
        $this->assertSame('/' . FilesModule::BACKUP_FOLDER, $plan['backup']);
        $this->assertTrue($plan['recoverable']);
        $this->assertNothingWritten();
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unconfirmedProvider(): array {
        return ['absent' => [[]], 'false' => [['confirm' => false]]];
    }

    #[DataProvider('unconfirmedProvider')]
    public function testRegistryAnswersWithThePlanUnlessConfirmed(array $confirm): void {
        $this->snapshot();
        $result = $this->registry()->call('files_move', ['from' => '/Documentos/ata.md', 'to' => '/Arquivo/ata.md'] + $confirm, 'alice');

        $out = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($out['requiresConfirmation']);
        $this->assertSame('files_move', $out['action']);
        $this->assertNothingWritten();
    }

    public function testRegistryRunsTheWriteOnceConfirmed(): void {
        $result = $this->registry()->call('files_move', ['from' => '/Documentos/ata.md', 'to' => '/Arquivo/ata.md', 'confirm' => true], 'alice');

        $out = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('requiresConfirmation', $out);
        $this->assertSame(['move /alice/files/Documentos/ata.md /alice/files/Arquivo/ata.md'], $this->tree->ops);
    }

    private function registry(): ToolRegistry {
        $policy = new GrantPolicy((new InMemoryConfig())->mock($this));
        foreach (GrantPolicy::CATALOG['files'] as $operation) {
            $policy->setGrant('alice', 'files', $operation, true);
        }

        return new ToolRegistry([$this->module], $policy, $this->apps, $this->users, $this->createMock(LoggerInterface::class));
    }
}
