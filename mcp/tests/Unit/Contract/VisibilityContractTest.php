<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\AppInfo\Application;
use OCA\Mcp\Controller\CheckoutController;
use OCA\Mcp\Checkout\CheckoutToken;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tests\Unit\Checkout\InMemoryCheckoutTokenStore;
use OCA\Mcp\Tests\Unit\Checkout\TestableCheckoutController;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\Files\CheckoutService;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\Notes\NotesModule;
use OCA\Mcp\Tools\Notes\NotesRepository;
use OCA\Mcp\Tools\Talk\AttachmentAccess;
use OCA\Mcp\Tools\Talk\Conversation;
use OCA\Mcp\Tools\Talk\ConversationAccessException;
use OCA\Mcp\Tools\Talk\FileAccessException;
use OCA\Mcp\Tools\Talk\UserFileResolver;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolModule;
use OCA\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Tools\ToolResult;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class VisibilityContractTest extends TestCase {
    private InMemoryConfig $config;
    private $tagMapper;
    private array $tagAssignments = [];
    private FakeTree $tree;
    private VisibilityGuard $guard;

    protected function setUp(): void {
        parent::setUp();
        $this->config = new InMemoryConfig();
        $this->tree = new FakeTree($this, '/alice/files');
        $this->tagAssignments = [];

        $this->tagMapper = $this->createMock(ISystemTagObjectMapper::class);
        $this->tagMapper->method('getTagIdsForObjects')->willReturnCallback(function (array $objIds, string $objectType = 'files'): array {
            $result = [];
            foreach ($objIds as $id) {
                $result[(string)$id] = $this->tagAssignments[(string)$id] ?? [];
            }
            return $result;
        });
        $this->tagMapper->method('assignTags')->willReturnCallback(function (string $objId, string $objectType, array $tagIds): void {
            $existing = $this->tagAssignments[$objId] ?? [];
            $this->tagAssignments[$objId] = array_values(array_unique(array_merge($existing, array_map(strval(...), $tagIds))));
        });

        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $this->guard = new VisibilityGuard($this->config->mock($this), $this->tagMapper);
    }

    /** Builds a concrete app class with real app collaborators, tree and guard. */
    private function build(string $class): object {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        $args = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            if ($name === null || $type->isBuiltin()) {
                $args[] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : ($name === 'string' ? 'mcp' : null);
                continue;
            }
            if ($name === VisibilityGuard::class) {
                $args[] = $this->guard;
                continue;
            }
            if ($name === \OCP\IConfig::class) {
                $args[] = $this->config->mock($this);
                continue;
            }
            if ($name === ISystemTagObjectMapper::class) {
                $args[] = $this->tagMapper;
                continue;
            }
            if ($name === IDBConnection::class) {
                $db = $this->createMock(IDBConnection::class);
                $db->method('escapeLikeParameter')->willReturnCallback(fn ($s) => addcslashes($s, '%_\\'));
                $args[] = $db;
                continue;
            }
            if ($name === IRootFolder::class) {
                $rootFolder = $this->createMock(IRootFolder::class);
                $rootFolder->method('getUserFolder')->willReturn($this->tree->rootFolder());
                $args[] = $rootFolder;
                continue;
            }
            $reflection = new \ReflectionClass($name);
            $args[] = str_starts_with($name, 'OCA\\Mcp\\') && $reflection->isInstantiable() ? $this->build($name) : $this->createMock($name);
        }
        return new $class(...$args);
    }

    private function registry(array $modules): ToolRegistry {
        $policy = new GrantPolicy($this->config->mock($this));
        foreach (GrantPolicy::CATALOG as $module => $operations) {
            foreach ($operations as $operation) {
                $policy->setGrant('alice', $module, $operation, true);
            }
        }
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $users = $this->createMock(IUserManager::class);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $users->method('get')->willReturn($user);

        return new ToolRegistry($modules, $policy, $apps, $users, $this->createMock(LoggerInterface::class));
    }

    public function testAllRegisteredModulesInstantiateAndExposeDefinitions(): void {
        $totalTools = 0;
        foreach (Application::MODULES as $moduleClass) {
            $module = $this->build($moduleClass);
            $this->assertInstanceOf(ToolModule::class, $module);
            $defs = $module->definitions();
            $this->assertNotEmpty($defs);
            $totalTools += count($defs);
        }
        $this->assertGreaterThan(20, $totalTools);
    }

    public function testFictitiousToolRespectsVisibilityGuard(): void {
        $fakeToolModule = new class($this->guard, $this->tree) implements ToolModule {
            public function __construct(private VisibilityGuard $guard, private FakeTree $tree) {}

            public function definitions(): array {
                return [[
                    'name' => 'fake_read_tool',
                    'description' => 'Reads a file checking visibility',
                    'module' => 'files',
                    'operation' => 'read',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => ['path' => ['type' => 'string']],
                        'required' => ['path'],
                    ],
                ]];
            }

            public function call(string $name, array $arguments, string $userId): array {
                $node = $this->tree->node('/alice/files' . $arguments['path']);
                $this->guard->assertVisible($node);
                return ToolResult::json(['ok' => true]);
            }
        };

        $registry = $this->registry([$fakeToolModule]);

        // Visible file succeeds
        $this->tree->addFile('/alice/files/visible.txt', 'visible');
        $res = $registry->call('fake_read_tool', ['path' => '/visible.txt'], 'alice');
        $this->assertArrayNotHasKey('isError', $res);

        // Tagged hidden file fails with notFound()
        $hiddenId = $this->tree->addFile('/alice/files/hidden.txt', 'secret');
        $this->tagAssignments[(string)$hiddenId] = ['999'];
        $resHidden = $registry->call('fake_read_tool', ['path' => '/hidden.txt'], 'alice');
        $this->assertTrue($resHidden['isError'] ?? false);
        $this->assertStringContainsString(CommonMessages::notFound(), $resHidden['content'][0]['text']);
    }

    public function testFilesModuleHidesTaggedFilesFromReadListAndSearch(): void {
        /** @var ToolModule $filesModule */
        $filesModule = $this->build(\OCA\Mcp\Tools\Files\FilesModule::class);
        $registry = $this->registry([$filesModule]);

        // Add visible and hidden files
        $this->tree->addFile('/alice/files/visible.txt', 'visible content');
        $hiddenId = $this->tree->addFile('/alice/files/hidden.txt', 'secret content');
        $this->tagAssignments[(string)$hiddenId] = ['999'];

        // files_read on hidden file returns not found
        $res = $registry->call('files_read', ['path' => '/hidden.txt'], 'alice');
        $this->assertTrue($res['isError'] ?? false);
        $this->assertStringContainsString(CommonMessages::notFound(), $res['content'][0]['text']);

        // files_list on root omits hidden file
        $listRes = $registry->call('files_list', ['path' => '/'], 'alice');
        $this->assertArrayNotHasKey('isError', $listRes);
        $items = json_decode($listRes['content'][0]['text'], true);
        $paths = array_column($items, 'path');
        $this->assertContains('/visible.txt', $paths);
        $this->assertNotContains('/hidden.txt', $paths);

        // files_search omits hidden file
        $searchRes = $registry->call('files_search', ['query' => 'hidden'], 'alice');
        $this->assertArrayNotHasKey('isError', $searchRes);
        $searchItems = json_decode($searchRes['content'][0]['text'], true);
        $this->assertEmpty($searchItems);
    }

    public function testReorganizationHidesTaggedDestinationsAndTree(): void {
        /** @var ToolModule $filesModule */
        $filesModule = $this->build(\OCA\Mcp\Tools\Files\FilesModule::class);
        $registry = $this->registry([$filesModule]);

        $this->tree->addFolder('/alice/files/Folder');
        $this->tree->addFile('/alice/files/Folder/visible.txt', 'visible');
        $hiddenId = $this->tree->addFile('/alice/files/Folder/hidden.txt', 'secret');
        $this->tagAssignments[(string)$hiddenId] = ['999'];

        // files_tree omits hidden files
        $treeRes = $registry->call('files_tree', ['path' => '/Folder'], 'alice');
        $treeData = json_decode($treeRes['content'][0]['text'], true);
        $paths = array_column($treeData['entries'], 'path');
        $this->assertContains('/Folder/visible.txt', $paths);
        $this->assertNotContains('/Folder/hidden.txt', $paths);

        // Destination collision on hidden node fails with forbidden (not leaking existence)
        $mkdirRes = $registry->call('files_mkdir', ['path' => '/Folder/hidden.txt', 'confirm' => true], 'alice');
        $this->assertTrue($mkdirRes['isError'] ?? false);
        $this->assertStringContainsString(CommonMessages::forbidden(), $mkdirRes['content'][0]['text']);
    }

    public function testNotesModuleHidesTaggedNotesFromAllOperations(): void {
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn () => $this->tree->rootFolder());
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));
        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());

        $repo = new NotesRepository($root, $this->config->mock($this), $this->guard);
        $notesModule = new NotesModule($repo, $apps, $users, new SharedWriteGuard($access), $access);

        $visibleNoteId = $this->tree->addFile('/alice/files/Notes/visible.md', 'visible note');
        $hiddenNoteId = $this->tree->addFile('/alice/files/Notes/secret.md', 'secret note');
        $this->tagAssignments[(string)$hiddenNoteId] = ['999'];

        // notes_list omits hidden note
        $listRes = $notesModule->call('notes_list', [], 'alice');
        $list = json_decode($listRes['content'][0]['text'], true);
        $ids = array_column($list, 'id');
        $this->assertContains($visibleNoteId, $ids);
        $this->assertNotContains($hiddenNoteId, $ids);

        // notes_read on hidden note throws not found
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(CommonMessages::notFound());
        $notesModule->call('notes_read', ['id' => $hiddenNoteId], 'alice');
    }

    public function testTalkModuleHidesTaggedFilesFromAttachmentAndResolve(): void {
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn () => $this->tree->rootFolder());

        $fileId = $this->tree->addFile('/alice/files/secret.pdf', 'secret pdf');
        $this->tagAssignments[(string)$fileId] = ['999'];

        $resolver = new UserFileResolver($root, $this->guard);

        $this->expectException(FileAccessException::class);
        $resolver->resolveShareableFile('alice', '/secret.pdf');
    }
}
