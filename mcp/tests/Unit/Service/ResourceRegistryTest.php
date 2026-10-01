<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\McpProtocol;
use OCA\Mcp\Service\PromptCatalog;
use OCA\Mcp\Service\ResourceRegistry;
use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Files\FilesModule;
use OCA\Mcp\Tools\Files\TextExtractor;
use OCA\Mcp\Tools\Notes\NotesMessages;
use OCA\Mcp\Tools\Notes\NotesModule;
use OCA\Mcp\Tools\Notes\NotesRepository;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolGuide;
use OCA\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ResourceRegistryTest extends TestCase {
    private FakeTree $tree;
    private InMemoryConfig $config;
    private GrantPolicy $policy;
    private IAppManager $appManager;
    private IUserManager $userManager;
    private IRootFolder $rootFolder;
    private VisibilityGuard $visibilityGuard;
    private TextExtractor $extractor;
    private NotesRepository $notesRepo;
    private ToolRegistry $toolRegistry;
    private ResourceRegistry $registry;
    private array $enabledApps = ['notes'];
    private int $noteId;

    protected function setUp(): void {
        $this->tree = new FakeTree($this);
        $this->tree->addFile('/alice/files/Documentos/texto.txt', 'Ola mundo', 'text/plain');
        $this->tree->addFile('/alice/files/Documentos/codigo.php', '<?php echo 1;', 'text/x-php');
        $this->tree->addFile('/alice/files/Documentos/binario.bin', "\x00\x01\x02\x03", 'application/octet-stream');
        $this->noteId = $this->tree->addFile('/alice/files/Notes/Ideia.md', '# Ideia', 'text/markdown');

        $this->config = new InMemoryConfig();
        $this->policy = new GrantPolicy($this->config->mock($this));
        $this->policy->setGrant('alice', 'files', 'read', true);
        $this->policy->setGrant('alice', 'notes', 'read', true);

        $this->appManager = $this->createMock(IAppManager::class);
        $this->appManager->method('isEnabledForUser')->willReturnCallback(fn (string $app) => in_array($app, $this->enabledApps, true));

        $user = $this->createMock(IUser::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->userManager->method('get')->willReturnCallback(fn (string $uid) => $uid === 'alice' ? $user : null);

        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->rootFolder->method('getUserFolder')->willReturnCallback(fn () => $this->tree->rootFolder());

        $this->visibilityGuard = new VisibilityGuard();

        $temp = $this->createMock(ITempManager::class);
        $temp->method('getTemporaryFile')->willReturnCallback(fn () => tempnam(sys_get_temp_dir(), 'mcp'));
        $this->extractor = new TextExtractor($temp);
        $this->notesRepo = new NotesRepository($this->rootFolder, $this->config->mock($this));

        $this->toolRegistry = new ToolRegistry([], $this->policy, $this->appManager, $this->userManager, $this->createMock(LoggerInterface::class));
        $this->registry = new ResourceRegistry(
            $this->toolRegistry,
            $this->policy,
            $this->appManager,
            $this->userManager,
            $this->rootFolder,
            $this->visibilityGuard,
            $this->extractor,
            $this->notesRepo,
            new ToolGuide([]),
        );
    }

    public function testListOnlyExposesGuideResource(): void {
        $result = $this->registry->list('alice');
        $this->assertArrayHasKey('resources', $result);
        $this->assertCount(1, $result['resources']);
        $guide = $result['resources'][0];
        $this->assertSame(ResourceRegistry::GUIDE_URI, $guide['uri']);
        $this->assertSame('mcp_guide', $guide['name']);
        $this->assertSame('text/markdown', $guide['mimeType']);
        $this->assertNotEmpty($guide['title']);
        $this->assertNotEmpty($guide['description']);

        // Empty cursor is accepted
        $this->assertSame($result, $this->registry->list('alice', ''));

        // Non-empty cursor throws InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $this->registry->list('alice', 'invalid-cursor');
    }

    public function testTemplatesReflectsGrantsAndApps(): void {
        $templates = $this->registry->templates('alice')['resourceTemplates'];
        $this->assertCount(2, $templates);
        $this->assertSame('nc://files/{path}', $templates[0]['uriTemplate']);
        $this->assertSame('files', $templates[0]['name']);
        $this->assertSame('nc://notes/{id}', $templates[1]['uriTemplate']);
        $this->assertSame('notes', $templates[1]['name']);

        // Revoke files.read
        $this->policy->setGrant('alice', 'files', 'read', false);
        $templatesWithoutFiles = $this->registry->templates('alice')['resourceTemplates'];
        $this->assertCount(1, $templatesWithoutFiles);
        $this->assertSame('nc://notes/{id}', $templatesWithoutFiles[0]['uriTemplate']);

        // Revoke notes.read
        $this->policy->setGrant('alice', 'notes', 'read', false);
        $templatesEmpty = $this->registry->templates('alice')['resourceTemplates'];
        $this->assertSame([], $templatesEmpty);

        // Turn notes grant back on, but disable notes app
        $this->policy->setGrant('alice', 'notes', 'read', true);
        $this->enabledApps = [];
        $this->assertSame([], $this->registry->templates('alice')['resourceTemplates']);
    }

    public function testTemplatesRejectsInvalidCursor(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->registry->templates('alice', 'next-page');
    }

    public function testReadGuideReturnsMarkdownContent(): void {
        $result = $this->registry->read(ResourceRegistry::GUIDE_URI, 'alice');
        $this->assertArrayHasKey('contents', $result);
        $this->assertCount(1, $result['contents']);
        $content = $result['contents'][0];
        $this->assertSame(ResourceRegistry::GUIDE_URI, $content['uri']);
        $this->assertSame('text/markdown', $content['mimeType']);
        $this->assertStringContainsString('# Tool guide', $content['text']);
    }

    public function testReadFilesTextContent(): void {
        $result = $this->registry->read('nc://files/Documentos/texto.txt', 'alice');
        $content = $result['contents'][0];
        $this->assertSame('nc://files/Documentos/texto.txt', $content['uri']);
        $this->assertSame('text/plain', $content['mimeType']);
        $this->assertSame('Ola mundo', $content['text']);
    }

    public function testReadFilesTruncatesLongText(): void {
        $huge = str_repeat('A', FilesModule::MAX_CHARS + 500);
        $this->tree->addFile('/alice/files/Documentos/huge.txt', $huge, 'text/plain');
        $result = $this->registry->read('nc://files/Documentos/huge.txt', 'alice');
        $text = $result['contents'][0]['text'];
        $this->assertStringContainsString('[content truncated]', $text);
        $this->assertLessThanOrEqual(FilesModule::MAX_CHARS + 50, mb_strlen($text));
    }

    public function testReadFilesSmallBinaryBlob(): void {
        $result = $this->registry->read('nc://files/Documentos/binario.bin', 'alice');
        $content = $result['contents'][0];
        $this->assertSame('nc://files/Documentos/binario.bin', $content['uri']);
        $this->assertSame('application/octet-stream', $content['mimeType']);
        $this->assertSame(base64_encode("\x00\x01\x02\x03"), $content['blob']);
        $this->assertArrayNotHasKey('text', $content);
    }

    public function testReadFilesLargeBinaryThrowsToolFailure(): void {
        $this->tree->addFile('/alice/files/Documentos/large.bin', 'large', 'application/octet-stream', [
            'size' => ResourceRegistry::MAX_BLOB_BYTES + 1,
        ]);
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage('Resource too large for binary read');
        $this->registry->read('nc://files/Documentos/large.bin', 'alice');
    }

    public function testReadFilesWithoutGrantThrowsNotFound(): void {
        $this->policy->setGrant('alice', 'files', 'read', false);
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(CommonMessages::notFound());
        $this->registry->read('nc://files/Documentos/texto.txt', 'alice');
    }

    public function testReadFilesPathTraversalThrowsNotFound(): void {
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(CommonMessages::notFound());
        $this->registry->read('nc://files/../etc/passwd', 'alice');
    }

    public function testReadFilesMissingNodeThrowsNotFound(): void {
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(CommonMessages::notFound());
        $this->registry->read('nc://files/Documentos/nao_existe.txt', 'alice');
    }

    public function testReadNotesContent(): void {
        $result = $this->registry->read('nc://notes/' . $this->noteId, 'alice');
        $content = $result['contents'][0];
        $this->assertSame('nc://notes/' . $this->noteId, $content['uri']);
        $this->assertSame('text/markdown', $content['mimeType']);
        $this->assertSame('# Ideia', $content['text']);
    }

    public function testReadNotesWithoutGrantThrowsNotFound(): void {
        $this->policy->setGrant('alice', 'notes', 'read', false);
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(CommonMessages::notFound());
        $this->registry->read('nc://notes/' . $this->noteId, 'alice');
    }

    public function testReadNotesWithDisabledAppThrowsNotFound(): void {
        $this->enabledApps = [];
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(CommonMessages::notFound());
        $this->registry->read('nc://notes/' . $this->noteId, 'alice');
    }

    public function testReadNotesWithInvalidIdThrowsNotFound(): void {
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(CommonMessages::notFound());
        $this->registry->read('nc://notes/abc', 'alice');
    }

    public function testReadNotesMissingThrowsNotFound(): void {
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(CommonMessages::notFound());
        $this->registry->read('nc://notes/99999', 'alice');
    }

    public function testReadNotesTooLargeThrowsToolFailure(): void {
        $largeId = $this->tree->addFile('/alice/files/Notes/gigante.md', 'x', 'text/markdown', [
            'size' => NotesModule::MAX_BYTES + 1,
        ]);
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(NotesMessages::noteTooLargeForReading(NotesModule::MAX_BYTES));
        $this->registry->read('nc://notes/' . $largeId, 'alice');
    }

    public function testReadUnknownUriSchemeThrowsNotFound(): void {
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(CommonMessages::notFound());
        $this->registry->read('nc://unknown/resource', 'alice');
    }

    public function testVisibilityGuardStubBehavior(): void {
        $stub = new VisibilityGuard();
        $file = $this->tree->node('/alice/files/Documentos/texto.txt');
        $this->assertTrue($stub->isVisible($file));
        $stub->assertVisible($file);
        $this->assertSame([$file], $stub->filter([$file]));
    }

    public function testVisibilityGuardHidingNodeThrowsNotFound(): void {
        $guard = $this->createMock(VisibilityGuard::class);
        $guard->method('assertVisible')->willThrowException(new ToolFailure(CommonMessages::notFound()));

        $registry = new ResourceRegistry(
            $this->toolRegistry,
            $this->policy,
            $this->appManager,
            $this->userManager,
            $this->rootFolder,
            $guard,
            $this->extractor,
            $this->notesRepo,
            new ToolGuide([]),
        );

        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(CommonMessages::notFound());
        $registry->read('nc://files/Documentos/texto.txt', 'alice');
    }

    public function testDualEraProtocolIntegration(): void {
        $protocol = new McpProtocol(
            $this->toolRegistry,
            new PromptCatalog(),
            $this->policy,
            $this->createMock(LoggerInterface::class),
            $this->registry,
        );

        // 1. Legacy initialize advertises resources capability
        $legacyInit = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => McpProtocol::VERSION,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'client', 'version' => '1.0'],
            ],
        ]), '', 'alice');
        $this->assertSame(200, $legacyInit['status']);
        $this->assertEquals(new \stdClass(), $legacyInit['body']['result']['capabilities']['resources']);

        // 2. Legacy resources/list
        $legacyList = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'resources/list',
        ]), McpProtocol::VERSION, 'alice');
        $this->assertSame(200, $legacyList['status']);
        $this->assertArrayNotHasKey('resultType', $legacyList['body']['result']);
        $this->assertSame(ResourceRegistry::GUIDE_URI, $legacyList['body']['result']['resources'][0]['uri']);

        // 3. Legacy resources/templates/list
        $legacyTemplates = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'resources/templates/list',
        ]), McpProtocol::VERSION, 'alice');
        $this->assertSame(200, $legacyTemplates['status']);
        $this->assertArrayNotHasKey('resultType', $legacyTemplates['body']['result']);
        $this->assertCount(2, $legacyTemplates['body']['result']['resourceTemplates']);

        // 4. Legacy resources/read success
        $legacyRead = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'resources/read',
            'params' => ['uri' => 'nc://files/Documentos/texto.txt'],
        ]), McpProtocol::VERSION, 'alice');
        $this->assertSame(200, $legacyRead['status']);
        $this->assertSame('Ola mundo', $legacyRead['body']['result']['contents'][0]['text']);

        // 5. Legacy resources/read not found -> error -32002, never empty contents
        $legacyReadMissing = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'resources/read',
            'params' => ['uri' => 'nc://files/missing.txt'],
        ]), McpProtocol::VERSION, 'alice');
        $this->assertSame(200, $legacyReadMissing['status']);
        $this->assertArrayNotHasKey('result', $legacyReadMissing['body']);
        $this->assertSame(-32002, $legacyReadMissing['body']['error']['code']);
        $this->assertSame(CommonMessages::notFound(), $legacyReadMissing['body']['error']['message']);

        // 6. Modern server/discover advertises resources capability
        $modernDiscover = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'server/discover',
            'params' => ['_meta' => [McpProtocol::META_VERSION => McpProtocol::MODERN_VERSION]],
        ]), McpProtocol::MODERN_VERSION, 'alice', ['method' => 'server/discover']);
        $this->assertSame(200, $modernDiscover['status']);
        $this->assertEquals(new \stdClass(), $modernDiscover['body']['result']['capabilities']['resources']);

        // 7. Modern resources/list carries complete and cache hints
        $modernList = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 11,
            'method' => 'resources/list',
            'params' => ['_meta' => [McpProtocol::META_VERSION => McpProtocol::MODERN_VERSION]],
        ]), McpProtocol::MODERN_VERSION, 'alice', ['method' => 'resources/list']);
        $this->assertSame(200, $modernList['status']);
        $this->assertSame('complete', $modernList['body']['result']['resultType']);
        $this->assertSame(0, $modernList['body']['result']['ttlMs']);
        $this->assertSame('private', $modernList['body']['result']['cacheScope']);
        $this->assertSame(ResourceRegistry::GUIDE_URI, $modernList['body']['result']['resources'][0]['uri']);

        // 8. Modern resources/templates/list carries complete and cache hints
        $modernTemplates = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 12,
            'method' => 'resources/templates/list',
            'params' => ['_meta' => [McpProtocol::META_VERSION => McpProtocol::MODERN_VERSION]],
        ]), McpProtocol::MODERN_VERSION, 'alice', ['method' => 'resources/templates/list']);
        $this->assertSame(200, $modernTemplates['status']);
        $this->assertSame('complete', $modernTemplates['body']['result']['resultType']);
        $this->assertSame(0, $modernTemplates['body']['result']['ttlMs']);
        $this->assertSame('private', $modernTemplates['body']['result']['cacheScope']);
        $this->assertCount(2, $modernTemplates['body']['result']['resourceTemplates']);

        // 9. Modern resources/read success
        $modernRead = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 13,
            'method' => 'resources/read',
            'params' => [
                'uri' => 'nc://files/Documentos/texto.txt',
                '_meta' => [McpProtocol::META_VERSION => McpProtocol::MODERN_VERSION],
            ],
        ]), McpProtocol::MODERN_VERSION, 'alice', ['method' => 'resources/read']);
        $this->assertSame(200, $modernRead['status']);
        $this->assertSame('complete', $modernRead['body']['result']['resultType']);
        $this->assertSame('Ola mundo', $modernRead['body']['result']['contents'][0]['text']);

        // 10. Modern resources/read not found / ungranted / hidden -> error -32602
        $modernReadMissing = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 14,
            'method' => 'resources/read',
            'params' => [
                'uri' => 'nc://files/missing.txt',
                '_meta' => [McpProtocol::META_VERSION => McpProtocol::MODERN_VERSION],
            ],
        ]), McpProtocol::MODERN_VERSION, 'alice', ['method' => 'resources/read']);
        $this->assertSame(200, $modernReadMissing['status']);
        $this->assertArrayNotHasKey('result', $modernReadMissing['body']);
        $this->assertSame(-32602, $modernReadMissing['body']['error']['code']);
        $this->assertSame(CommonMessages::notFound(), $modernReadMissing['body']['error']['message']);
    }

    public function testModernHiddenResourceReturnsMinus32602(): void {
        $guard = $this->createMock(VisibilityGuard::class);
        $guard->method('assertVisible')->willThrowException(new ToolFailure(CommonMessages::notFound()));

        $registry = new ResourceRegistry(
            $this->toolRegistry,
            $this->policy,
            $this->appManager,
            $this->userManager,
            $this->rootFolder,
            $guard,
            $this->extractor,
            $this->notesRepo,
            new ToolGuide([]),
        );

        $protocol = new McpProtocol(
            $this->toolRegistry,
            new PromptCatalog(),
            $this->policy,
            $this->createMock(LoggerInterface::class),
            $registry,
        );

        $out = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 99,
            'method' => 'resources/read',
            'params' => [
                'uri' => 'nc://files/Documentos/texto.txt',
                '_meta' => [McpProtocol::META_VERSION => McpProtocol::MODERN_VERSION],
            ],
        ]), McpProtocol::MODERN_VERSION, 'alice', ['method' => 'resources/read']);

        $this->assertSame(200, $out['status']);
        $this->assertArrayNotHasKey('result', $out['body']);
        $this->assertSame(-32602, $out['body']['error']['code']);
        $this->assertSame(CommonMessages::notFound(), $out['body']['error']['message']);
    }
}
