<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\AppInfo\Application;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\Files\FilesModule;
use OCA\Mcp\Tools\Files\Reorganization;
use OCA\Mcp\Tools\Notes\NotesModule;
use OCA\Mcp\Tools\Notes\NotesRepository;
use OCA\Mcp\Tools\Talk\AttachmentAccess;
use OCA\Mcp\Tools\Talk\Conversation;
use OCA\Mcp\Tools\Talk\ConversationAccessException;
use OCA\Mcp\Tools\Talk\FileAccessException;
use OCA\Mcp\Tools\Talk\Messages as TalkMessages;
use OCA\Mcp\Tools\Talk\TalkModule;
use OCA\Mcp\Tools\Talk\UserFileResolver;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolModule;
use OCA\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Tools\ToolResult;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class VisibilityContractTest extends TestCase {
    private const HIDDEN_TAG = '999';

    /**
     * Explicit registry mapping every tool that touches files to its hidden scenarios.
     * Any tool added to files/notes (or talk file tools) MUST be registered here.
     */
    public const FILE_TOUCHING_TOOLS = [
        'files_list' => 'list',
        'files_search' => 'search',
        'files_tree' => 'tree',
        'files_mkdir' => 'mkdir',
        'files_copy' => 'copy',
        'files_move' => 'move',
        'files_move_batch' => 'move_batch',
        'files_undo_batch' => 'undo_batch',
        'files_read' => 'read',
        'files_edit' => 'edit',
        'files_replace' => 'replace',
        'files_checkout' => 'checkout',
        'files_versions_list' => 'versions_list',
        'files_version_read' => 'version_read',
        'files_version_restore' => 'version_restore',
        'files_image_view' => 'image_view',
        'files_images_view' => 'images_view',
        'files_image_search' => 'image_search',
        'files_list_shares' => 'list_shares',
        'files_share' => 'share',
        'notes_list' => 'notes_list',
        'notes_search' => 'notes_search',
        'notes_read' => 'notes_read',
        'notes_create' => 'notes_create',
        'notes_edit' => 'notes_edit',
        'notes_move' => 'notes_move',
        'notes_delete' => 'notes_delete',
        'talk_attach_file' => 'talk_attach',
        'talk_quote_file' => 'talk_quote',
    ];

    private InMemoryConfig $config;
    private $tagMapper;
    private $tagManager;
    private array $tagAssignments = [];
    private FakeTree $tree;
    private VisibilityGuard $guard;
    /** @var list<\OCP\Share\IShare> what getSharesBy() answers for a listing of every share (no node) */
    private array $listedShares = [];

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

        $this->tagManager = $this->createMock(ISystemTagManager::class);
        $hiddenTag = $this->createMock(ISystemTag::class);
        $hiddenTag->method('getId')->willReturn(self::HIDDEN_TAG);
        $this->tagManager->method('getTagsByIds')->willReturn([self::HIDDEN_TAG => $hiddenTag]);

        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode([self::HIDDEN_TAG]);
        $this->guard = new VisibilityGuard($this->config->mock($this), $this->tagMapper, null, $this->tagManager);
    }

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
            if ($name === ISystemTagManager::class) {
                $args[] = $this->tagManager;
                continue;
            }
            if ($name === IDBConnection::class) {
                $db = $this->createMock(IDBConnection::class);
                $db->method('escapeLikeParameter')->willReturnCallback(fn ($s) => addcslashes($s, '%_\\'));
                $args[] = $db;
                continue;
            }
            if ($name === IAppManager::class) {
                $apps = $this->createMock(IAppManager::class);
                $apps->method('isEnabledForUser')->willReturn(true);
                $args[] = $apps;
                continue;
            }
            if ($name === IUserManager::class) {
                $users = $this->createMock(IUserManager::class);
                $user = $this->createMock(IUser::class);
                $user->method('getUID')->willReturn('alice');
                $users->method('get')->willReturn($user);
                $args[] = $users;
                continue;
            }
            if ($name === \OCP\Share\IManager::class) {
                // Only a listing without node gets shares, so the move reports and NodeAccessInfo stay as before.
                $shares = $this->createMock(\OCP\Share\IManager::class);
                $shares->method('getSharesBy')->willReturnCallback(fn (string $uid, int $type, ?\OCP\Files\Node $node = null): array
                    => $node === null && $type === \OCP\Share\IShare::TYPE_USER ? $this->listedShares : []);
                $args[] = $shares;
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
        $policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy($this->config->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
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

    /**
     * Requirement 1: The contract FAILS if ANY tool in Application::MODULES that touches files
     * is missing from the explicit FILE_TOUCHING_TOOLS audit list.
     */
    public function testContractFailsIfAnyFileTouchingToolIsMissingFromExplicitMatrix(): void {
        $fileTouchingDiscovered = [];

        foreach (Application::MODULES as $moduleClass) {
            $module = $this->build($moduleClass);
            $this->assertInstanceOf(ToolModule::class, $module);
            foreach ($module->definitions() as $def) {
                $name = $def['name'];
                $mod = $def['module'] ?? '';

                // Identify tools that touch Nextcloud files/folders
                $isFilesModule = ($mod === 'files');
                $isNotesModule = ($mod === 'notes');
                $isTalkFileTool = in_array($name, ['talk_attach_file', 'talk_quote_file'], true);

                if ($isFilesModule || $isNotesModule || $isTalkFileTool) {
                    $fileTouchingDiscovered[$name] = true;
                }
            }
        }

        // 1. Every discovered tool MUST be explicitly in the matrix
        foreach (array_keys($fileTouchingDiscovered) as $toolName) {
            $this->assertArrayHasKey(
                $toolName,
                self::FILE_TOUCHING_TOOLS,
                "Tool '{$toolName}' touches files but has NO scenario registered in FILE_TOUCHING_TOOLS matrix!"
            );
        }

        // 2. The matrix must match exactly the set of file-touching tools
        $matrixKeys = array_keys(self::FILE_TOUCHING_TOOLS);
        sort($matrixKeys);
        $discoveredKeys = array_keys($fileTouchingDiscovered);
        sort($discoveredKeys);

        $this->assertSame($discoveredKeys, $matrixKeys, 'Audit matrix and discovered file tools must have 100% parity.');
    }

    /**
     * Requirement 1: Each tool scenario must hide nodes with DIRECT tag AND ANCESTRAL tag.
     * In both cases it must return notFound/forbidden and NEVER leak the secret name in error/result.
     */
    public function testEveryFileTouchingToolHidesTaggedNodeDirectlyAndViaAncestralFolder(): void {
        // Setup direct tagged file and ancestor folder with tagged ancestral file
        $directFileId = $this->tree->addFile('/alice/files/secret_direct.txt', 'secret direct content');
        $this->tagAssignments[(string)$directFileId] = [self::HIDDEN_TAG];

        $ancestorFolderId = $this->tree->addFolder('/alice/files/SecretFolder');
        $this->tagAssignments[(string)$ancestorFolderId] = [self::HIDDEN_TAG];
        $ancestralFileId = $this->tree->addFile('/alice/files/SecretFolder/secret_ancestral.txt', 'ancestral content');

        // Images
        $directImgId = $this->tree->addFile('/alice/files/secret_direct.jpg', 'img direct', 'image/jpeg');
        $this->tagAssignments[(string)$directImgId] = [self::HIDDEN_TAG];
        $ancestralImgId = $this->tree->addFile('/alice/files/SecretFolder/secret_ancestral.jpg', 'img ancestral', 'image/jpeg');

        // Notes
        $this->tree->addFolder('/alice/files/Notes');
        $directNoteId = $this->tree->addFile('/alice/files/Notes/secret_direct.md', 'note direct');
        $this->tagAssignments[(string)$directNoteId] = [self::HIDDEN_TAG];
        $secretNoteCatId = $this->tree->addFolder('/alice/files/Notes/SecretFolder');
        $this->tagAssignments[(string)$secretNoteCatId] = [self::HIDDEN_TAG];
        $ancestralNoteId = $this->tree->addFile('/alice/files/Notes/SecretFolder/secret_ancestral.md', 'note ancestral');

        /** @var FilesModule $filesModule */
        $filesModule = $this->build(FilesModule::class);
        /** @var NotesModule $notesModule */
        $notesModule = $this->build(NotesModule::class);
        $registry = $this->registry([$filesModule, $notesModule]);

        $secretTokens = ['secret_direct', 'secret_ancestral', 'SecretFolder'];

        // Helper to assert no leaked secret names
        $assertNoLeak = function (string $text) use ($secretTokens): void {
            foreach ($secretTokens as $token) {
                $this->assertStringNotContainsString(
                    $token,
                    $text,
                    "Output leaked hidden token '{$token}': {$text}"
                );
            }
        };

        // 1. files_read
        foreach (['/secret_direct.txt', '/SecretFolder/secret_ancestral.txt'] as $p) {
            $res = $registry->call('files_read', ['path' => $p], 'alice');
            $this->assertTrue($res['isError'] ?? false);
            $this->assertStringContainsString(CommonMessages::notFound(), $res['content'][0]['text']);
            $assertNoLeak($res['content'][0]['text']);
        }

        // 2. files_edit
        foreach (['/secret_direct.txt', '/SecretFolder/secret_ancestral.txt'] as $p) {
            $res = $registry->call('files_edit', ['path' => $p, 'content' => 'new', 'confirm_shared' => true], 'alice');
            $this->assertTrue($res['isError'] ?? false);
            $this->assertStringContainsString(CommonMessages::notFound(), $res['content'][0]['text']);
            $assertNoLeak($res['content'][0]['text']);
        }

        // 3. files_replace
        foreach (['/secret_direct.txt', '/SecretFolder/secret_ancestral.txt'] as $p) {
            $res = $registry->call('files_replace', ['path' => $p, 'old' => 'foo', 'new' => 'bar', 'confirm_shared' => true], 'alice');
            $this->assertTrue($res['isError'] ?? false);
            $this->assertStringContainsString(CommonMessages::notFound(), $res['content'][0]['text']);
            $assertNoLeak($res['content'][0]['text']);
        }

        // 4. files_checkout
        foreach (['/secret_direct.txt', '/SecretFolder/secret_ancestral.txt'] as $p) {
            $res = $registry->call('files_checkout', ['path' => $p, 'confirm_shared' => true], 'alice');
            $this->assertTrue($res['isError'] ?? false);
            $this->assertStringContainsString(CommonMessages::notFound(), $res['content'][0]['text']);
            $assertNoLeak($res['content'][0]['text']);
        }

        // 5. files_versions_list
        foreach (['/secret_direct.txt', '/SecretFolder/secret_ancestral.txt'] as $p) {
            $res = $registry->call('files_versions_list', ['path' => $p], 'alice');
            $this->assertTrue($res['isError'] ?? false);
            $this->assertStringContainsString(CommonMessages::notFound(), $res['content'][0]['text']);
            $assertNoLeak($res['content'][0]['text']);
        }

        // 6. files_version_read
        foreach (['/secret_direct.txt', '/SecretFolder/secret_ancestral.txt'] as $p) {
            $res = $registry->call('files_version_read', ['path' => $p, 'version' => '1'], 'alice');
            $this->assertTrue($res['isError'] ?? false);
            $this->assertStringContainsString(CommonMessages::notFound(), $res['content'][0]['text']);
            $assertNoLeak($res['content'][0]['text']);
        }

        // 7. files_version_restore
        foreach (['/secret_direct.txt', '/SecretFolder/secret_ancestral.txt'] as $p) {
            $res = $registry->call('files_version_restore', ['path' => $p, 'version' => '1', 'confirm_shared' => true], 'alice');
            $this->assertTrue($res['isError'] ?? false);
            $this->assertStringContainsString(CommonMessages::notFound(), $res['content'][0]['text']);
            $assertNoLeak($res['content'][0]['text']);
        }

        // 8. files_image_view
        foreach (['/secret_direct.jpg', '/SecretFolder/secret_ancestral.jpg'] as $p) {
            $res = $registry->call('files_image_view', ['path' => $p], 'alice');
            $this->assertTrue($res['isError'] ?? false);
            $this->assertStringContainsString(CommonMessages::notFound(), $res['content'][0]['text']);
            $assertNoLeak($res['content'][0]['text']);
        }

        // 9. files_images_view
        $res = $registry->call('files_images_view', ['paths' => ['/secret_direct.jpg', '/SecretFolder/secret_ancestral.jpg']], 'alice');
        $this->assertArrayNotHasKey('isError', $res);
        $last = end($res['content']);
        $summary = json_decode($last['text'], true);
        $this->assertSame(0, $summary['returned']);
        foreach ($summary['skipped'] as $s) {
            $this->assertSame(CommonMessages::notFound(), $s['reason']);
        }

        // 10. files_image_search
        $res = $registry->call('files_image_search', ['query' => 'secret'], 'alice');
        $this->assertArrayNotHasKey('isError', $res);
        $assertNoLeak($res['content'][0]['text']);

        // 11. files_list
        $res = $registry->call('files_list', ['path' => '/'], 'alice');
        $assertNoLeak($res['content'][0]['text']);
        $res = $registry->call('files_list', ['path' => '/SecretFolder'], 'alice');
        $this->assertTrue($res['isError'] ?? false);
        $this->assertStringContainsString(CommonMessages::notFound(), $res['content'][0]['text']);
        $assertNoLeak($res['content'][0]['text']);

        // 12. files_search
        $res = $registry->call('files_search', ['query' => 'secret'], 'alice');
        $this->assertArrayNotHasKey('isError', $res);
        $this->assertSame([], json_decode($res['content'][0]['text'], true)['files']);
        $assertNoLeak($res['content'][0]['text']);

        // 13. files_tree
        $res = $registry->call('files_tree', ['path' => '/'], 'alice');
        $assertNoLeak($res['content'][0]['text']);
        $res = $registry->call('files_tree', ['path' => '/SecretFolder'], 'alice');
        $this->assertTrue($res['isError'] ?? false);
        $this->assertStringContainsString(CommonMessages::notFound(), $res['content'][0]['text']);
        $assertNoLeak($res['content'][0]['text']);

        // 14. files_mkdir (collision and inside hidden folder)
        $res = $registry->call('files_mkdir', ['path' => '/secret_direct.txt', 'confirm_shared' => true], 'alice');
        $this->assertTrue($res['isError'] ?? false);
        $this->assertSame(CommonMessages::forbidden(), $res['content'][0]['text']);
        $res = $registry->call('files_mkdir', ['path' => '/SecretFolder/newdir', 'confirm_shared' => true], 'alice');
        $this->assertTrue($res['isError'] ?? false);
        $this->assertSame(CommonMessages::notFound(), $res['content'][0]['text']);
        $assertNoLeak($res['content'][0]['text']);

        // 15. files_copy (source hidden, destination hidden, destination collision)
        foreach (['/secret_direct.txt', '/SecretFolder/secret_ancestral.txt'] as $p) {
            $res = $registry->call('files_copy', ['from' => $p, 'to' => '/dest.txt', 'confirm_shared' => true], 'alice');
            $this->assertTrue($res['isError'] ?? false);
            $this->assertSame(CommonMessages::notFound(), $res['content'][0]['text']);
            $assertNoLeak($res['content'][0]['text']);
        }
        $this->tree->addFile('/alice/files/visible.txt', 'visible');
        $res = $registry->call('files_copy', ['from' => '/visible.txt', 'to' => '/SecretFolder/dest.txt', 'confirm_shared' => true], 'alice');
        $this->assertTrue($res['isError'] ?? false);
        $this->assertSame(CommonMessages::notFound(), $res['content'][0]['text']);
        $res = $registry->call('files_copy', ['from' => '/visible.txt', 'to' => '/secret_direct.txt', 'confirm_shared' => true], 'alice');
        $this->assertTrue($res['isError'] ?? false);
        $this->assertSame(CommonMessages::forbidden(), $res['content'][0]['text']);

        // 16. files_move (source hidden, destination hidden, destination collision)
        foreach (['/secret_direct.txt', '/SecretFolder/secret_ancestral.txt'] as $p) {
            $res = $registry->call('files_move', ['from' => $p, 'to' => '/dest_move.txt', 'confirm_shared' => true], 'alice');
            $this->assertTrue($res['isError'] ?? false);
            $this->assertSame(CommonMessages::notFound(), $res['content'][0]['text']);
            $assertNoLeak($res['content'][0]['text']);
        }
        $res = $registry->call('files_move', ['from' => '/visible.txt', 'to' => '/SecretFolder/dest_move.txt', 'confirm_shared' => true], 'alice');
        $this->assertTrue($res['isError'] ?? false);
        $this->assertSame(CommonMessages::notFound(), $res['content'][0]['text']);
        $res = $registry->call('files_move', ['from' => '/visible.txt', 'to' => '/secret_direct.txt', 'confirm_shared' => true], 'alice');
        $this->assertTrue($res['isError'] ?? false);
        $this->assertSame(CommonMessages::forbidden(), $res['content'][0]['text']);

        // 17. files_move_batch
        $res = $registry->call('files_move_batch', [
            'moves' => [
                ['from' => '/secret_direct.txt', 'to' => '/other.txt'],
                ['from' => '/SecretFolder/secret_ancestral.txt', 'to' => '/other2.txt'],
                ['from' => '/visible.txt', 'to' => '/secret_direct.txt'],
            ],
            'mkdirs' => ['/SecretFolder'],
            'confirm' => true,
            'confirm_shared' => true,
        ], 'alice');
        $this->assertTrue($res['isError'] ?? false);
        // Batch denied/conflict reasons must be generic notFound / forbidden
        $assertNoLeak($res['content'][0]['text']);

        // 18. files_undo_batch: unknown or hidden batch id throws batchNotFound
        $res = $registry->call('files_undo_batch', ['batch_id' => 9999], 'alice');
        $this->assertTrue($res['isError'] ?? false);
        $assertNoLeak($res['content'][0]['text']);

        // 18b. files_list_shares: a hidden node is not found by path and absent from the listing of every share
        foreach (['/secret_direct.txt', '/SecretFolder/secret_ancestral.txt'] as $p) {
            $res = $registry->call('files_list_shares', ['path' => $p], 'alice');
            $this->assertTrue($res['isError'] ?? false);
            $this->assertSame(CommonMessages::notFound(), $res['content'][0]['text']);
            $assertNoLeak($res['content'][0]['text']);
        }
        foreach ([$directFileId, $ancestralFileId, $this->tree->addFile('/alice/files/shared_visible.txt', 'v')] as $nodeId) {
            $share = $this->createMock(\OCP\Share\IShare::class);
            $share->method('getShareType')->willReturn(\OCP\Share\IShare::TYPE_USER);
            $share->method('getSharedWith')->willReturn('bruno');
            $share->method('getSharedBy')->willReturn('alice');
            $share->method('getShareOwner')->willReturn('alice');
            $share->method('getNodeId')->willReturn($nodeId);
            $share->method('getFullId')->willReturn('ocinternal:' . $nodeId);
            $this->listedShares[] = $share;
        }
        $res = $registry->call('files_list_shares', [], 'alice');
        $this->assertArrayNotHasKey('isError', $res);
        $this->assertSame(['/shared_visible.txt'], array_column(json_decode($res['content'][0]['text'], true)['shares'], 'path'));
        $assertNoLeak($res['content'][0]['text']);

        // 18c. files_share: a hidden node cannot be shared, neither in the plan nor in the confirmed call
        foreach (['/secret_direct.txt', '/SecretFolder/secret_ancestral.txt'] as $p) {
            foreach ([[], ['confirm' => true]] as $confirm) {
                $res = $registry->call('files_share', ['path' => $p, 'with' => 'user:alice-colleague'] + $confirm, 'alice');
                $this->assertTrue($res['isError'] ?? false);
                $this->assertSame(CommonMessages::notFound(), $res['content'][0]['text']);
                $assertNoLeak($res['content'][0]['text']);
            }
        }

        // 19. notes_list
        $res = $registry->call('notes_list', [], 'alice');
        $assertNoLeak($res['content'][0]['text']);

        // 19b. notes_search: a query broad enough to match every note never returns a hidden one
        foreach (['e', 'a', 'secret'] as $query) {
            $res = $registry->call('notes_search', ['query' => $query], 'alice');
            $this->assertArrayNotHasKey('isError', $res);
            $assertNoLeak($res['content'][0]['text']);
        }

        // 20. notes_read
        foreach ([$directNoteId, $ancestralNoteId] as $id) {
            $res = $registry->call('notes_read', ['id' => $id], 'alice');
            $this->assertTrue($res['isError'] ?? false);
            $this->assertStringContainsString(CommonMessages::notFound(), $res['content'][0]['text']);
            $assertNoLeak($res['content'][0]['text']);
        }

        // 21. notes_create with hidden category: plan or run refuses with notFound, no leakage
        $resPlan = $registry->call('notes_create', ['title' => 'NewNote', 'category' => 'SecretFolder', 'content' => 'hello'], 'alice');
        $this->assertTrue($resPlan['isError'] ?? false);
        $this->assertSame(CommonMessages::notFound(), $resPlan['content'][0]['text']);
        $assertNoLeak($resPlan['content'][0]['text']);

        $resRun = $registry->call('notes_create', ['title' => 'NewNote', 'category' => 'SecretFolder', 'content' => 'hello', 'confirm' => true], 'alice');
        $this->assertTrue($resRun['isError'] ?? false);
        $this->assertSame(CommonMessages::notFound(), $resRun['content'][0]['text']);
        $assertNoLeak($resRun['content'][0]['text']);

        // 22. notes_edit
        foreach ([$directNoteId, $ancestralNoteId] as $id) {
            $res = $registry->call('notes_edit', ['id' => $id, 'content' => 'updated'], 'alice');
            $this->assertTrue($res['isError'] ?? false);
            $this->assertStringContainsString(CommonMessages::notFound(), $res['content'][0]['text']);
            $assertNoLeak($res['content'][0]['text']);
        }

        // 23. notes_move
        foreach ([$directNoteId, $ancestralNoteId] as $id) {
            $res = $registry->call('notes_move', ['id' => $id, 'category' => 'General'], 'alice');
            $this->assertTrue($res['isError'] ?? false);
            $this->assertStringContainsString(CommonMessages::notFound(), $res['content'][0]['text']);
            $assertNoLeak($res['content'][0]['text']);
        }

        // 24. notes_delete
        foreach ([$directNoteId, $ancestralNoteId] as $id) {
            $res = $registry->call('notes_delete', ['id' => $id], 'alice');
            $this->assertTrue($res['isError'] ?? false);
            $this->assertStringContainsString(CommonMessages::notFound(), $res['content'][0]['text']);
            $assertNoLeak($res['content'][0]['text']);
        }

        // 25. talk_attach_file
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->willReturnCallback(fn () => $this->tree->rootFolder());
        $resolver = new UserFileResolver($rootFolder, $this->guard);
        foreach (['/secret_direct.txt', '/SecretFolder/secret_ancestral.txt'] as $p) {
            try {
                $resolver->resolveShareableFile('alice', $p);
                $this->fail("talk_attach_file did not hide $p");
            } catch (FileAccessException $e) {
                $this->assertSame(TalkMessages::fileNotFound(), $e->getMessage());
                $assertNoLeak($e->getMessage());
            }
        }

        // 26. talk_quote_file
        $directNode = $this->tree->node('/alice/files/secret_direct.txt');
        $ancestralNode = $this->tree->node('/alice/files/SecretFolder/secret_ancestral.txt');

        foreach ([$directNode, $ancestralNode] as $node) {
            $share = $this->createMock(\OCP\Share\IShare::class);
            $share->method('getShareType')->willReturn(\OCP\Share\IShare::TYPE_ROOM);
            $share->method('getSharedWith')->willReturn('token123');
            $share->method('getNode')->willReturn($node);

            $shareManager = $this->createMock(\OCP\Share\IManager::class);
            $shareManager->method('getShareById')->willReturn($share);

            $conv = new Conversation(new ContractTalkRoomStub('token123'), new ContractTalkParticipantStub());

            $attachmentAccess = new AttachmentAccess($shareManager, $this->guard);

            try {
                $attachmentAccess->requireRoomShareOf($conv, 'alice', 1);
                $this->fail("talk_quote_file did not hide attachment");
            } catch (ConversationAccessException $e) {
                $this->assertSame(TalkMessages::attachmentNotFound(), $e->getMessage());
                $assertNoLeak($e->getMessage());
            }
        }
    }

    /**
     * Requirement 3: Write plans (WriteGate preview) of files and notes NEVER cite hidden nodes.
     * The response must be generic notFound/forbidden with no secret name.
     */
    public function testWritePlansNeverCiteHiddenNodes(): void {
        $hiddenFileId = $this->tree->addFile('/alice/files/secret_file.txt', 'secret');
        $this->tagAssignments[(string)$hiddenFileId] = [self::HIDDEN_TAG];

        $hiddenFolderId = $this->tree->addFolder('/alice/files/SecretFolder');
        $this->tagAssignments[(string)$hiddenFolderId] = [self::HIDDEN_TAG];

        $this->tree->addFile('/alice/files/visible.txt', 'visible');

        /** @var FilesModule $filesModule */
        $filesModule = $this->build(FilesModule::class);

        // Preview files_mkdir: collision on hidden folder -> forbidden
        try {
            $filesModule->preview('files_mkdir', ['path' => '/SecretFolder'], 'alice');
            $this->fail('plan files_mkdir collision did not fail');
        } catch (ToolFailure $e) {
            $this->assertSame(CommonMessages::forbidden(), $e->getMessage());
        }

        // Preview files_mkdir: inside hidden folder -> notFound
        try {
            $filesModule->preview('files_mkdir', ['path' => '/SecretFolder/newdir'], 'alice');
            $this->fail('plan files_mkdir inside hidden folder did not fail');
        } catch (ToolFailure $e) {
            $this->assertSame(CommonMessages::notFound(), $e->getMessage());
        }

        // Preview files_move: destination inside hidden folder -> notFound
        try {
            $filesModule->preview('files_move', ['from' => '/visible.txt', 'to' => '/SecretFolder/dest.txt'], 'alice');
            $this->fail('plan files_move into hidden folder did not fail');
        } catch (ToolFailure $e) {
            $this->assertSame(CommonMessages::notFound(), $e->getMessage());
        }

        // Preview files_move: destination collides with hidden file -> forbidden
        try {
            $filesModule->preview('files_move', ['from' => '/visible.txt', 'to' => '/secret_file.txt'], 'alice');
            $this->fail('plan files_move collision with hidden file did not fail');
        } catch (ToolFailure $e) {
            $this->assertSame(CommonMessages::forbidden(), $e->getMessage());
        }

        // Preview files_move_batch: hidden item listed in denied with generic reason
        $batchPlan = $filesModule->preview('files_move_batch', [
            'moves' => [
                ['from' => '/secret_file.txt', 'to' => '/other.txt'],
                ['from' => '/visible.txt', 'to' => '/SecretFolder/dest.txt'],
            ],
            'mkdirs' => ['/SecretFolder'],
        ], 'alice');

        $this->assertCount(2, $batchPlan['denied']);
        foreach ($batchPlan['denied'] as $d) {
            $this->assertSame(CommonMessages::notFound(), $d['reason']);
        }
        // mkdirs of hidden folder in batch plan reports exists: false so it does not leak existence
        $this->assertFalse($batchPlan['mkdirs'][0]['exists']);
        $this->assertTrue($batchPlan['mkdirs'][0]['willCreate']);

        // Notes preview
        $this->tree->addFolder('/alice/files/Notes');
        $hiddenNoteId = $this->tree->addFile('/alice/files/Notes/secret_note.md', 'note');
        $this->tagAssignments[(string)$hiddenNoteId] = [self::HIDDEN_TAG];

        /** @var NotesModule $notesModule */
        $notesModule = $this->build(NotesModule::class);

        // Preview notes_edit on hidden note -> notFound
        try {
            $notesModule->preview('notes_edit', ['id' => $hiddenNoteId, 'content' => 'new'], 'alice');
            $this->fail('plan notes_edit on hidden note did not fail');
        } catch (ToolFailure $e) {
            $this->assertSame(CommonMessages::notFound(), $e->getMessage());
        }

        // Preview notes_move on hidden note -> notFound
        try {
            $notesModule->preview('notes_move', ['id' => $hiddenNoteId, 'category' => 'General'], 'alice');
            $this->fail('plan notes_move on hidden note did not fail');
        } catch (ToolFailure $e) {
            $this->assertSame(CommonMessages::notFound(), $e->getMessage());
        }

        // Preview notes_delete on hidden note -> notFound
        try {
            $notesModule->preview('notes_delete', ['id' => $hiddenNoteId], 'alice');
            $this->fail('plan notes_delete on hidden note did not fail');
        } catch (ToolFailure $e) {
            $this->assertSame(CommonMessages::notFound(), $e->getMessage());
        }
    }

    /**
     * Requirement 4: Collision with hidden node answers forbidden() identical to standard forbidden.
     */
    public function testCollisionWithHiddenNodeReturnsIdenticalMessageToStandardForbidden(): void {
        $hiddenFileId = $this->tree->addFile('/alice/files/existing_secret.txt', 'secret');
        $this->tagAssignments[(string)$hiddenFileId] = [self::HIDDEN_TAG];

        $reorg = $this->build(Reorganization::class);
        $root = $this->tree->rootFolder();

        // 1. mkdir collision with hidden node
        try {
            $reorg->mkdir($root, 'alice', '/existing_secret.txt', true);
            $this->fail('mkdir on hidden node did not fail');
        } catch (ToolFailure $e) {
            // Must match CommonMessages::forbidden() exactly, no extra hint
            $this->assertSame(CommonMessages::forbidden(), $e->getMessage());
        }

        // 2. copy collision with hidden node
        $this->tree->addFile('/alice/files/normal.txt', 'normal');
        try {
            $reorg->copy($root, 'alice', '/normal.txt', '/existing_secret.txt', null, true);
            $this->fail('copy on hidden node did not fail');
        } catch (ToolFailure $e) {
            $this->assertSame(CommonMessages::forbidden(), $e->getMessage());
        }

        // 3. move collision with hidden node
        try {
            $reorg->move($root, 'alice', '/normal.txt', '/existing_secret.txt', null, true);
            $this->fail('move on hidden node did not fail');
        } catch (ToolFailure $e) {
            $this->assertSame(CommonMessages::forbidden(), $e->getMessage());
        }
    }
}

final class ContractTalkRoomStub {
    public function __construct(private string $token) {}
    public function getToken(): string { return $this->token; }
}

final class ContractTalkParticipantStub {
    public function getPermissions(): int { return 128; }
}
