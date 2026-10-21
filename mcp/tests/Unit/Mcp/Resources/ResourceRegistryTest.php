<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Resources;

use OCA\Mcp\Access\Guests\GuestRestrictions;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Protocol\McpProtocol;
use OCA\Mcp\Mcp\Prompts\PromptCatalog;
use OCA\Mcp\Mcp\Resources\ResourceRegistry;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Support\Platform\FileAccess\FakeTree;
use OCA\Mcp\Modules\Files\FilesModule;
use OCA\Mcp\Modules\Files\Reading\TextExtractor;
use OCA\Mcp\Modules\Notes\NotesModule;
use OCA\Mcp\Modules\Notes\Storage\NotesFolders;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Guide\ToolGuide;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
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
    private \OCP\IConfig $policyConfig;
    private GrantPolicy $policy;
    private IAppManager $appManager;
    private IUserManager $userManager;
    private IRootFolder $rootFolder;
    private VisibilityGuard $visibilityGuard;
    private TextExtractor $extractor;
    private NotesFolders $notesFolders;
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
        $this->policyConfig = $this->config->mock($this);
        $this->policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy($this->policyConfig, new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        $this->policy->setGrant('alice', 'files', 'read', true);
        $this->policy->setGrant('alice', 'notes', 'read', true);

        $this->appManager = $this->createMock(IAppManager::class);
        $this->appManager->method('isEnabledForUser')->willReturnCallback(fn (string $app) => in_array($app, $this->enabledApps, true));

        $user = $this->createMock(IUser::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->userManager->method('get')->willReturnCallback(fn (string $uid) => $uid === 'alice' ? $user : null);

        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->rootFolder->method('getUserFolder')->willReturnCallback(fn () => $this->tree->rootFolder());

        $this->visibilityGuard = $this->openGuard();

        $temp = $this->createMock(ITempManager::class);
        $temp->method('getTemporaryFile')->willReturnCallback(fn () => tempnam(sys_get_temp_dir(), 'mcp'));
        $this->extractor = new TextExtractor($temp);
        $this->notesFolders = new \OCA\Mcp\Modules\Notes\Storage\NotesFolders($this->rootFolder, $this->config->mock($this));

        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($this->policy, $this->appManager, $this->userManager);
        $this->toolRegistry = new ToolRegistry([], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, $this->createMock(LoggerInterface::class)));
        $this->registry = new ResourceRegistry($this->toolRegistry, $this->policy, $this->appManager, $this->userManager, new \OCA\Mcp\Modules\Files\Reading\NextcloudFileResourceReader($this->rootFolder, $this->visibilityGuard, $this->extractor), new \OCA\Mcp\Modules\Notes\Resources\NextcloudNoteResourceReader($this->notesFolders, new \OCA\Mcp\Modules\Notes\Storage\NoteLookup(), new \OCA\Mcp\Modules\Notes\Content\NoteContent(), $this->visibilityGuard), new ToolGuide([]));
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

    public function testAGroupInNeedsConfirmNeverHidesTheReadTemplates(): void {
        unset($this->config->user['alice']['mcp']['grant_files_read'], $this->config->user['alice']['mcp']['grant_notes_read']);
        $this->config->groupBackends = [new \OCA\Mcp\Tests\Unit\ArrayGroupBackend(static fn (): array => ['paused' => ['alice']])];
        InMemoryConfig::groupPolicy($this->policyConfig, 'paused', ['files' => ['read'], 'notes' => ['read']], 'needs_confirm');
        $this->assertSame(['files', 'notes'], array_column($this->registry->templates('alice')['resourceTemplates'], 'name'));
        $this->assertSame('Ola mundo', $this->registry->read('nc://files/Documentos/texto.txt', 'alice')['contents'][0]['text']);
        $this->policy->setGrant('alice', 'files', 'read', false);
        $this->assertSame(['notes'], array_column($this->registry->templates('alice')['resourceTemplates'], 'name'));
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
        $huge = str_repeat('A', \OCA\Mcp\Modules\Files\Reading\TextExtractor::MAX_CHARS + 500);
        $this->tree->addFile('/alice/files/Documentos/huge.txt', $huge, 'text/plain');
        $result = $this->registry->read('nc://files/Documentos/huge.txt', 'alice');
        $text = $result['contents'][0]['text'];
        $this->assertStringContainsString('[content truncated]', $text);
        $this->assertLessThanOrEqual(\OCA\Mcp\Modules\Files\Reading\TextExtractor::MAX_CHARS + 50, mb_strlen($text));
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
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Files\Reading\ReadingMessages::binaryResourceTooLarge(ResourceRegistry::MAX_BLOB_BYTES));
        $this->registry->read('nc://files/Documentos/large.bin', 'alice');
    }

    public function testReadFilesTextOverLimitThrowsToolFailure(): void {
        $this->tree->addFile('/alice/files/Documentos/over_limit.txt', 'x', 'text/plain', [
            'size' => TextExtractor::MAX_BYTES + 1,
        ]);
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Files\Reading\ReadingMessages::readTooLarge(TextExtractor::MAX_BYTES));
        $this->registry->read('nc://files/Documentos/over_limit.txt', 'alice');
    }

    public function testReadFilesPdfExtractsAsTextPlain(): void {
        $this->tree->addFile('/alice/files/Documentos/doc.pdf', '%PDF-1.4 sample', 'application/pdf');
        $result = $this->registry->read('nc://files/Documentos/doc.pdf', 'alice');
        $content = $result['contents'][0];
        $this->assertSame('text/plain', $content['mimeType']);
    }

    public function testReadFilesWithoutGrantThrowsNotFound(): void {
        $this->policy->setGrant('alice', 'files', 'read', false);
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(FileAccessMessages::notFound());
        $this->registry->read('nc://files/Documentos/texto.txt', 'alice');
    }

    public function testReadFilesPathTraversalThrowsNotFound(): void {
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(FileAccessMessages::notFound());
        $this->registry->read('nc://files/../etc/passwd', 'alice');
    }

    public function testReadFilesMissingNodeThrowsNotFound(): void {
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(FileAccessMessages::notFound());
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
        $this->expectExceptionMessage(FileAccessMessages::notFound());
        $this->registry->read('nc://notes/' . $this->noteId, 'alice');
    }

    public function testReadNotesWithDisabledAppThrowsNotFound(): void {
        $this->enabledApps = [];
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(FileAccessMessages::notFound());
        $this->registry->read('nc://notes/' . $this->noteId, 'alice');
    }

    /** A guest whose Guests list lacks Notes gets neither the notes template nor a note; files stay. */
    public function testAGuestWithoutNotesOnTheGuestsListGetsNoNotesResource(): void {
        $guests = $this->createMock(GuestRestrictions::class);
        $guests->method('allowsApp')->willReturnCallback(static fn (string $uid, string $app): bool => $app !== 'notes');
        $registry = new ResourceRegistry($this->toolRegistry, $this->policy, $this->appManager, $this->userManager, new \OCA\Mcp\Modules\Files\Reading\NextcloudFileResourceReader($this->rootFolder, $this->visibilityGuard, $this->extractor), new \OCA\Mcp\Modules\Notes\Resources\NextcloudNoteResourceReader($this->notesFolders, new \OCA\Mcp\Modules\Notes\Storage\NoteLookup(), new \OCA\Mcp\Modules\Notes\Content\NoteContent(), $this->visibilityGuard), new ToolGuide([]), $guests);
        $this->assertSame(['nc://files/{path}'], array_column($registry->templates('alice')['resourceTemplates'], 'uriTemplate'));
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(FileAccessMessages::notFound());
        $registry->read('nc://notes/' . $this->noteId, 'alice');
    }

    public function testReadNotesWithInvalidIdThrowsNotFound(): void {
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(FileAccessMessages::notFound());
        $this->registry->read('nc://notes/abc', 'alice');
    }

    public function testReadNotesMissingThrowsNotFound(): void {
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(FileAccessMessages::notFound());
        $this->registry->read('nc://notes/99999', 'alice');
    }

    public function testReadNotesTooLargeThrowsToolFailure(): void {
        $largeId = $this->tree->addFile('/alice/files/Notes/gigante.md', 'x', 'text/markdown', [
            'size' => \OCA\Mcp\Modules\Notes\Content\NoteContent::MAX_BYTES + 1,
        ]);
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Notes\Content\ContentMessages::noteTooLargeForReading(\OCA\Mcp\Modules\Notes\Content\NoteContent::MAX_BYTES));
        $this->registry->read('nc://notes/' . $largeId, 'alice');
    }

    public function testReadUnknownUriSchemeThrowsNotFound(): void {
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(FileAccessMessages::notFound());
        $this->registry->read('nc://unknown/resource', 'alice');
    }

    public function testOpenVisibilityGuardShowsEverything(): void {
        $stub = $this->openGuard();
        $file = $this->tree->node('/alice/files/Documentos/texto.txt');
        $this->assertTrue($stub->isVisible($file));
        $stub->assertVisible($file);
        $this->assertSame([$file], $stub->filter([$file]));
    }

    public function testVisibilityGuardHidingNodeThrowsNotFound(): void {
        $guard = $this->createMock(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class);
        $guard->method('assertVisible')->willThrowException(new ToolFailure(FileAccessMessages::notFound()));

        $registry = new ResourceRegistry($this->toolRegistry, $this->policy, $this->appManager, $this->userManager, new \OCA\Mcp\Modules\Files\Reading\NextcloudFileResourceReader($this->rootFolder, $guard, $this->extractor), new \OCA\Mcp\Modules\Notes\Resources\NextcloudNoteResourceReader($this->notesFolders, new \OCA\Mcp\Modules\Notes\Storage\NoteLookup(), new \OCA\Mcp\Modules\Notes\Content\NoteContent(), $guard), new ToolGuide([]));

        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(FileAccessMessages::notFound());
        $registry->read('nc://files/Documentos/texto.txt', 'alice');
    }

    public function testDualEraProtocolIntegration(): void {
        $protocol = new McpProtocol(new \OCA\Mcp\Mcp\Protocol\ToolRequests($this->toolRegistry),
            new \OCA\Mcp\Mcp\Protocol\PromptRequests(new PromptCatalog(), $this->policy),
            new \OCA\Mcp\Mcp\Protocol\ResourceRequests($this->registry), $this->createMock(LoggerInterface::class));

        // 1. Legacy initialize advertises resources capability
        $legacyInit = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::VERSION,
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
        ]), \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::VERSION, 'alice');
        $this->assertSame(200, $legacyList['status']);
        $this->assertArrayNotHasKey('resultType', $legacyList['body']['result']);
        $this->assertSame(ResourceRegistry::GUIDE_URI, $legacyList['body']['result']['resources'][0]['uri']);

        // 3. Legacy resources/templates/list
        $legacyTemplates = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'resources/templates/list',
        ]), \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::VERSION, 'alice');
        $this->assertSame(200, $legacyTemplates['status']);
        $this->assertArrayNotHasKey('resultType', $legacyTemplates['body']['result']);
        $this->assertCount(2, $legacyTemplates['body']['result']['resourceTemplates']);

        // 4. Legacy resources/read success
        $legacyRead = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'resources/read',
            'params' => ['uri' => 'nc://files/Documentos/texto.txt'],
        ]), \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::VERSION, 'alice');
        $this->assertSame(200, $legacyRead['status']);
        $this->assertSame('Ola mundo', $legacyRead['body']['result']['contents'][0]['text']);

        // 5. Legacy resources/read not found -> error -32002, never empty contents
        $legacyReadMissing = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'resources/read',
            'params' => ['uri' => 'nc://files/missing.txt'],
        ]), \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::VERSION, 'alice');
        $this->assertSame(200, $legacyReadMissing['status']);
        $this->assertArrayNotHasKey('result', $legacyReadMissing['body']);
        $this->assertSame(-32002, $legacyReadMissing['body']['error']['code']);
        $this->assertSame(FileAccessMessages::notFound(), $legacyReadMissing['body']['error']['message']);

        // 6. Modern server/discover advertises resources capability
        $modernDiscover = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'server/discover',
            'params' => ['_meta' => [\OCA\Mcp\Mcp\Protocol\ProtocolMetadata::META_VERSION => \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::MODERN_VERSION]],
        ]), \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::MODERN_VERSION, 'alice', ['method' => 'server/discover']);
        $this->assertSame(200, $modernDiscover['status']);
        $this->assertEquals(new \stdClass(), $modernDiscover['body']['result']['capabilities']['resources']);

        // 7. Modern resources/list carries complete and cache hints
        $modernList = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 11,
            'method' => 'resources/list',
            'params' => ['_meta' => [\OCA\Mcp\Mcp\Protocol\ProtocolMetadata::META_VERSION => \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::MODERN_VERSION]],
        ]), \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::MODERN_VERSION, 'alice', ['method' => 'resources/list']);
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
            'params' => ['_meta' => [\OCA\Mcp\Mcp\Protocol\ProtocolMetadata::META_VERSION => \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::MODERN_VERSION]],
        ]), \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::MODERN_VERSION, 'alice', ['method' => 'resources/templates/list']);
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
                '_meta' => [\OCA\Mcp\Mcp\Protocol\ProtocolMetadata::META_VERSION => \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::MODERN_VERSION],
            ],
        ]), \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::MODERN_VERSION, 'alice', ['method' => 'resources/read']);
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
                '_meta' => [\OCA\Mcp\Mcp\Protocol\ProtocolMetadata::META_VERSION => \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::MODERN_VERSION],
            ],
        ]), \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::MODERN_VERSION, 'alice', ['method' => 'resources/read']);
        $this->assertSame(200, $modernReadMissing['status']);
        $this->assertArrayNotHasKey('result', $modernReadMissing['body']);
        $this->assertSame(-32602, $modernReadMissing['body']['error']['code']);
        $this->assertSame(FileAccessMessages::notFound(), $modernReadMissing['body']['error']['message']);
    }

    public function testModernHiddenResourceReturnsMinus32602(): void {
        $guard = $this->createMock(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class);
        $guard->method('assertVisible')->willThrowException(new ToolFailure(FileAccessMessages::notFound()));

        $registry = new ResourceRegistry($this->toolRegistry, $this->policy, $this->appManager, $this->userManager, new \OCA\Mcp\Modules\Files\Reading\NextcloudFileResourceReader($this->rootFolder, $guard, $this->extractor), new \OCA\Mcp\Modules\Notes\Resources\NextcloudNoteResourceReader($this->notesFolders, new \OCA\Mcp\Modules\Notes\Storage\NoteLookup(), new \OCA\Mcp\Modules\Notes\Content\NoteContent(), $guard), new ToolGuide([]));

        $protocol = new McpProtocol(new \OCA\Mcp\Mcp\Protocol\ToolRequests($this->toolRegistry),
            new \OCA\Mcp\Mcp\Protocol\PromptRequests(new PromptCatalog(), $this->policy),
            new \OCA\Mcp\Mcp\Protocol\ResourceRequests($registry), $this->createMock(LoggerInterface::class));

        $out = $protocol->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 99,
            'method' => 'resources/read',
            'params' => [
                'uri' => 'nc://files/Documentos/texto.txt',
                '_meta' => [\OCA\Mcp\Mcp\Protocol\ProtocolMetadata::META_VERSION => \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::MODERN_VERSION],
            ],
        ]), \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::MODERN_VERSION, 'alice', ['method' => 'resources/read']);

        $this->assertSame(200, $out['status']);
        $this->assertArrayNotHasKey('result', $out['body']);
        $this->assertSame(-32602, $out['body']['error']['code']);
        $this->assertSame(FileAccessMessages::notFound(), $out['body']['error']['message']);
    }

    /**
     * A real guard with one hidden tag, assigned to the given file ids. In production the resource lookup is unfiltered; the reader must assert visibility before reading content.
     *
     * @param list<int> $hiddenIds file ids carrying the hidden tag
     */
    private function guardHiding(array $hiddenIds): VisibilityGuard {
        $config = new InMemoryConfig();
        $config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $mapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $mapper->method('getTagIdsForObjects')->willReturnCallback(static function (array $ids) use ($hiddenIds): array {
            $out = [];
            foreach ($ids as $id) {
                $out[(string)$id] = in_array((int)$id, $hiddenIds, true) ? ['999'] : [];
            }
            return $out;
        });
        $tag = $this->createMock(\OCP\SystemTag\ISystemTag::class);
        $tag->method('getId')->willReturn('999');
        $manager = $this->createMock(\OCP\SystemTag\ISystemTagManager::class);
        $manager->method('getTagsByIds')->willReturn(['999' => $tag]);
        return \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this, $config->mock($this), $mapper, $this->rootFolder, $manager);
    }

    private function registryWith(VisibilityGuard $guard): ResourceRegistry {
        return new ResourceRegistry($this->toolRegistry, $this->policy, $this->appManager, $this->userManager, new \OCA\Mcp\Modules\Files\Reading\NextcloudFileResourceReader($this->rootFolder, $guard, $this->extractor), new \OCA\Mcp\Modules\Notes\Resources\NextcloudNoteResourceReader($this->notesFolders, new \OCA\Mcp\Modules\Notes\Storage\NoteLookup(), new \OCA\Mcp\Modules\Notes\Content\NoteContent(), $guard), new ToolGuide([]));
    }

    private function assertNotFound(callable $read): void {
        try {
            $read();
            $this->fail('the resource was served');
        } catch (ToolFailure $e) {
            $this->assertSame(FileAccessMessages::notFound(), $e->getMessage());
        }
    }

    public function testANoteWithAHiddenTagIsNotReadableAsAResource(): void {
        $registry = $this->registryWith($this->guardHiding([$this->noteId]));

        $this->assertNotFound(fn () => $registry->read('nc://notes/' . $this->noteId, 'alice'));
    }

    public function testANoteInAHiddenCategoryIsNotReadableAsAResource(): void {
        $folderId = $this->tree->addFolder('/alice/files/Notes/Segredos');
        $inside = $this->tree->addFile('/alice/files/Notes/Segredos/Plano.md', '# plano secreto', 'text/markdown');
        $registry = $this->registryWith($this->guardHiding([$folderId]));

        $this->assertNotFound(fn () => $registry->read('nc://notes/' . $inside, 'alice'));
    }

    public function testAVisibleNoteStaysReadableWhileAnotherIsHidden(): void {
        $hidden = $this->tree->addFile('/alice/files/Notes/Oculta.md', '# oculta', 'text/markdown');
        $registry = $this->registryWith($this->guardHiding([$hidden]));

        $this->assertSame('# Ideia', $registry->read('nc://notes/' . $this->noteId, 'alice')['contents'][0]['text']);
        $this->assertNotFound(fn () => $registry->read('nc://notes/' . $hidden, 'alice'));
    }

    public function testAFileWithAHiddenTagIsNotReadableAsAResource(): void {
        $id = $this->tree->addFile('/alice/files/Documentos/oculto.txt', 'segredo', 'text/plain');
        $registry = $this->registryWith($this->guardHiding([$id]));

        $this->assertNotFound(fn () => $registry->read('nc://files/Documentos/oculto.txt', 'alice'));
        $this->assertSame('Ola mundo', $registry->read('nc://files/Documentos/texto.txt', 'alice')['contents'][0]['text']);
    }

    public function testAFolderWithAHiddenTagAnswersAsAMissingResource(): void {
        $id = $this->tree->addFolder('/alice/files/Documentos/Segredo');
        $this->tree->addFile('/alice/files/Documentos/Segredo/plano.txt', 'sigilo', 'text/plain');
        $registry = $this->registryWith($this->guardHiding([$id]));

        // Existence and type of a hidden item stay private: protected and missing give the very same answer.
        $this->assertNotFound(fn () => $registry->read('nc://files/Documentos/Segredo', 'alice'));
        $this->assertNotFound(fn () => $registry->read('nc://files/Documentos/Segredo/plano.txt', 'alice'));
        $this->assertSame('Ola mundo', $registry->read('nc://files/Documentos/texto.txt', 'alice')['contents'][0]['text']);
    }

    public function testAVisibleFolderStillAnswersNotAFile(): void {
        $registry = $this->registryWith($this->guardHiding([]));

        try {
            $registry->read('nc://files/Documentos', 'alice');
            $this->fail('the resource was served');
        } catch (ToolFailure $e) {
            $this->assertSame(FileAccessMessages::notAFile(), $e->getMessage());
        }
    }

    /** @return array<string, array{int, string}> the code of each era for a resource the guard hides */
    public static function erasProvider(): array {
        return ['legacy' => [-32002, \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::VERSION], 'modern' => [-32602, \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::MODERN_VERSION]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('erasProvider')]
    public function testAHiddenNoteAnswersAsAMissingResourceInBothEras(int $code, string $version): void {
        $protocol = new McpProtocol(new \OCA\Mcp\Mcp\Protocol\ToolRequests($this->toolRegistry),
            new \OCA\Mcp\Mcp\Protocol\PromptRequests(new PromptCatalog(), $this->policy),
            new \OCA\Mcp\Mcp\Protocol\ResourceRequests($this->registryWith($this->guardHiding([$this->noteId]))), $this->createMock(LoggerInterface::class));
        $params = ['uri' => 'nc://notes/' . $this->noteId]
            + ($version === \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::MODERN_VERSION ? ['_meta' => [\OCA\Mcp\Mcp\Protocol\ProtocolMetadata::META_VERSION => \OCA\Mcp\Mcp\Protocol\ProtocolMetadata::MODERN_VERSION]] : []);

        $out = $protocol->handle(json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'resources/read', 'params' => $params]),
            $version, 'alice', ['method' => 'resources/read']);

        $this->assertArrayNotHasKey('result', $out['body']);
        $this->assertSame($code, $out['body']['error']['code']);
        $this->assertSame(FileAccessMessages::notFound(), $out['body']['error']['message']);
        $this->assertStringNotContainsString('# Ideia', json_encode($out['body']));
    }

    /** A real guard with no hidden tag configured: every node is visible. */
    private function openGuard(): VisibilityGuard {
        $config = $this->createMock(\OCP\IConfig::class);
        $config->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default = '') => $default);
        return \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this, $config, $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class));
    }

    public function testFilesResourcesFollowTheFilesScopeAndNotesDoNot(): void {
        $items = [(string)$this->tree->addFolder('/alice/files/Projects')];
        $plain = $this->openGuard();
        $scope = \OCA\Mcp\Tests\Support\Access\ResourceScope\FilesScopes::over($this, $plain, $this->tree->filesystemRoot(),
            static function () use (&$items): array|false { return $items; });
        $files = new \OCA\Mcp\Access\ResourceScope\FilesAccessGuard($scope, $this->config->mock($this),
            $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class), $this->tree->filesystemRoot());
        $registry = new ResourceRegistry($this->toolRegistry, $this->policy, $this->appManager, $this->userManager,
            new \OCA\Mcp\Modules\Files\Reading\NextcloudFileResourceReader($this->rootFolder, $files, $this->extractor),
            new \OCA\Mcp\Modules\Notes\Resources\NextcloudNoteResourceReader($this->notesFolders, new \OCA\Mcp\Modules\Notes\Storage\NoteLookup(), new \OCA\Mcp\Modules\Notes\Content\NoteContent(), $plain), new ToolGuide([]));
        $this->tree->addFile('/alice/files/Projects/plan.txt', 'inside', 'text/plain');

        $this->assertSame('inside', $registry->read('nc://files/Projects/plan.txt', 'alice')['contents'][0]['text']);
        $outside = null;
        try {
            $registry->read('nc://files/Documentos/texto.txt', 'alice');
        } catch (ToolFailure $e) {
            $outside = json_encode([$e::class, $e->getCode(), $e->getMessage()]);
        }
        try {
            $registry->read('nc://files/Documentos/missing.txt', 'alice');
            $this->fail('a missing file was served');
        } catch (ToolFailure $e) {
            $this->assertSame(json_encode([$e::class, $e->getCode(), $e->getMessage()]), $outside);
        }
        $this->assertSame('# Ideia', $registry->read('nc://notes/' . $this->noteId, 'alice')['contents'][0]['text'], 'Notes is never scoped (D4)');

        $items = false;
        $this->expectException(\OCA\Mcp\Access\Policy\PolicyUnavailable::class);
        $registry->read('nc://files/Projects/plan.txt', 'alice');
    }

    public function testV8ExcludedAndMissingResourcesAreByteIdentical(): void {
        foreach ([
            ['nc://files/Documentos/texto.txt', '/alice/files/Documentos/texto.txt'],
            ['nc://files/Documentos', '/alice/files/Documentos'],
            ['nc://notes/' . $this->noteId, '/alice/files/Notes/Ideia.md'],
        ] as [$uri, $path]) {
            $guard = $this->guardHiding([$this->tree->nodes[$path]['id']]);
            $registry = $this->registryWith($guard);
            $failure = static function () use ($registry, $uri): string {
                try {
                    $registry->read($uri, 'alice');
                    return 'unexpected success';
                } catch (ToolFailure $e) {
                    return json_encode([$e::class, $e->getCode(), $e->getMessage()]);
                }
            };
            $excluded = $failure();
            $this->assertStringContainsString(FileAccessMessages::notFound(), $excluded);
            $node = $this->tree->nodes[$path];
            unset($this->tree->nodes[$path]);
            $guard->clearCache();
            $this->assertSame($failure(), $excluded, $uri);
            $this->tree->nodes[$path] = $node;
        }
    }
}
