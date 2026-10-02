<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Checkout;

use OCA\Mcp\Checkout\CheckoutToken;
use OCA\Mcp\Controller\CheckoutController;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tests\Unit\Tools\Files\FakeFilenameValidator;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\Files\CheckoutService;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\Files\FileCreation;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** The checkout controller with the body and the route token staged, for the create link of files_upload. */
final class StagedCreateController extends CheckoutController {
    /** @var string the body the next request reads */
    public string $staged = '';
    /** @var array<string, string> parameters the router put in the URL */
    public array $route = [];

    /** @return resource a readable stream over the staged body */
    protected function inputStream() {
        $handle = fopen('php://memory', 'w+');
        fwrite($handle, $this->staged);
        rewind($handle);
        return $handle;
    }

    protected function routeToken(): ?string {
        return $this->route['token'] ?? null;
    }
}

/**
 * The upload link of files_upload, spent on the same route as the checkout upload.
 *
 * It creates one file at the path the link remembers and nothing else: never over an existing file, never in a
 * folder that became hidden or read-only, never under a grant that was revoked. What the agent can fix without a
 * new link (the body, a name that appeared, a folder that went away) leaves the link usable.
 */
final class CheckoutCreateTest extends TestCase {
    private const TOKEN = 'ncmcp_co_c_ABCdefghijklmnopqrstuvwxyz0123456789ABCDEFG';
    private const PATH = '/Documentos/Relatório.docx';
    private const FILE = '/alice/files/Documentos/Relatório.docx';
    private const LIMIT = 64;

    private FakeTree $tree;
    private InMemoryCheckoutTokenStore $store;
    private InMemoryConfig $config;
    private GrantPolicy $policy;
    private TokenHasher $hasher;
    private IUserSession $session;
    private ?VisibilityGuard $guard = null;
    private FakeFilenameValidator $filenames;
    /** @var array<string, string> headers the fake request answers */
    private array $headers = [];
    private StagedCreateController $controller;

    protected function setUp(): void {
        $this->tree = new FakeTree($this);
        $this->tree->addFolder('/alice/files/Documentos');
        $this->store = new InMemoryCheckoutTokenStore();
        $this->config = new InMemoryConfig();
        $this->config->app['mcp'][CheckoutService::MAX_BYTES_KEY] = (string)self::LIMIT;
        $this->policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        $this->policy->setGlobalEnabled(true);
        $this->policy->setEligible('alice', true);
        $this->policy->setConnected('alice', true);
        $this->policy->setGrant('alice', 'files', 'create', true);
        $this->hasher = new TokenHasher($this->config->mock($this));
        $this->session = $this->createMock(IUserSession::class);
        $this->session->method('getUser')->willReturn(null);
        $this->filenames = new FakeFilenameValidator();
        $this->rebuild();
        $this->stage('PK docx bytes');
    }

    /** Builds the controller again, for the tests that swap the session or the visibility guard. */
    private function rebuild(): void {
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn (string $uid) => $uid === 'alice'
            ? $this->tree->rootFolder()
            : throw new \LogicException('other user'));
        $config = $this->config->mock($this);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1790000000);
        $temp = $this->createMock(ITempManager::class);
        $temp->method('getTemporaryFile')->willReturnCallback(fn () => tempnam(sys_get_temp_dir(), 'mcp'));
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(fn (string $uid) => $uid === 'alice' ? $alice : null);
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
        $checkout = new CheckoutService($this->createMock(IURLGenerator::class), $config, $time, $this->hasher, $this->store, $apps, $users);
        $guard = $this->guard ?? new VisibilityGuard($config, $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class));
        $this->controller = new StagedCreateController('mcp', $request, $root, $this->session, $temp, $time, $this->policy,
            $this->store, $this->hasher, $checkout, new FileBackup($apps, $users, $time, $config),
            new SharedWriteGuard($access), $access, $this->createMock(LoggerInterface::class), null, $users, $this->guard,
            new FileCreation($access, new SharedWriteGuard($access), $checkout, $this->filenames, $guard));
        $this->controller->route = ['token' => self::TOKEN];
    }

    /** Stages the request body, with the length curl -T declares for it. */
    private function stage(string $body, string $type = 'application/octet-stream'): void {
        $this->controller->staged = $body;
        $this->headers = ['Content-Type' => $type, 'Content-Length' => (string)strlen($body)];
    }

    /** Stores the link files_upload would have minted for a path. */
    private function issue(string $path = self::PATH, string $scope = 'personal', bool $confirmed = false, string $kind = CheckoutToken::KIND_CREATE): void {
        $this->store->insert([
            'token_hash' => $this->hasher->hash(self::TOKEN),
            'kind' => $kind,
            'user_id' => 'alice',
            'file_id' => 0,
            'path' => $path,
            'etag' => CheckoutToken::NO_ETAG,
            'scope' => $scope,
            'shared_confirmed' => $confirmed ? 1 : 0,
            'created_at' => 1790000000,
            'expires_at' => 1790000000 + CheckoutService::UPLOAD_TTL,
        ], 1790000000);
    }

    /** @return array<string, mixed> the JSON body of a response */
    private function payload(Response $response): array {
        $this->assertInstanceOf(DataDisplayResponse::class, $response);
        return json_decode((string)$response->render(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return string the plain body of a refusal */
    private function text(Response $response): string {
        return (string)$response->render();
    }

    private function spent(): bool {
        return in_array('consume', $this->store->ops, true);
    }

    public function testTheLinkCreatesTheFileAndReturnsTheReceipt(): void {
        $this->issue();
        $response = $this->controller->upload();

        $this->assertSame(201, $response->getStatus());
        $out = $this->payload($response);
        $this->assertSame(['path', 'size', 'etag', 'fileId', 'access'], array_keys($out));
        $this->assertSame(self::PATH, $out['path']);
        $this->assertSame(strlen('PK docx bytes'), $out['size']);
        $this->assertSame('PK docx bytes', $this->tree->nodes[self::FILE]['content']);
        $this->assertSame($this->tree->nodes[self::FILE]['id'], $out['fileId']);
        $this->assertSame($this->tree->nodes[self::FILE]['etag'], $out['etag']);
        $this->assertSame(['create ' . self::FILE, 'write ' . self::FILE], $this->tree->ops, 'a new file has no backup');
        $this->assertTrue($this->spent());
    }

    /** POST is the same upload, for a client that does not send PUT. */
    public function testThePostAliasCreatesTheFileToo(): void {
        $this->issue();
        $this->assertSame(201, $this->controller->uploadPost()->getStatus());
        $this->assertSame('PK docx bytes', $this->tree->nodes[self::FILE]['content']);
    }

    /** An empty body is a valid empty file, unlike on a checkout link where it would erase an existing file. */
    public function testAnEmptyBodyCreatesAnEmptyFile(): void {
        $this->issue('/Documentos/vazio.txt');
        $this->stage('');
        $response = $this->controller->upload();

        $this->assertSame(201, $response->getStatus());
        $this->assertSame(0, $this->payload($response)['size']);
        $this->assertSame('', $this->tree->nodes['/alice/files/Documentos/vazio.txt']['content']);
        $this->assertSame(['create /alice/files/Documentos/vazio.txt'], $this->tree->ops);
    }

    /** Any MIME type the client sends is just bytes: a JSON body cannot choose another path or another link. */
    public function testAJsonBodyIsWrittenAsBytesAtThePathOfTheLink(): void {
        $this->issue();
        $this->stage('{"token":"ncmcp_co_c_outro","path":"/Documentos/outro.docx"}', 'application/json');
        $this->assertSame(201, $this->controller->upload()->getStatus());
        $this->assertSame('{"token":"ncmcp_co_c_outro","path":"/Documentos/outro.docx"}', $this->tree->nodes[self::FILE]['content']);
        $this->assertArrayNotHasKey('/alice/files/Documentos/outro.docx', $this->tree->nodes);
    }

    public function testAMultipartBodyIsRefusedAndTheLinkStaysUsable(): void {
        $this->issue();
        $this->stage('--x', 'multipart/form-data; boundary=x');
        $response = $this->controller->upload();
        $this->assertSame(400, $response->getStatus());
        $this->assertSame(FilesMessages::uploadMultipart(), $this->text($response));
        $this->assertFalse($this->spent());
        $this->assertSame([], $this->tree->ops);
    }

    public function testABodyOverTheLimitIsA413AndTheLinkStaysUsable(): void {
        $this->issue();
        $this->stage(str_repeat('x', self::LIMIT + 1));
        $this->assertSame(413, $this->controller->upload()->getStatus());
        $this->assertFalse($this->spent());
        $this->assertSame([], $this->tree->ops);

        $this->stage(str_repeat('x', self::LIMIT));
        $this->assertSame(201, $this->controller->upload()->getStatus(), 'the same link still works at the limit');
    }

    /** A name taken since the link was minted is refused before the link is spent: nothing was touched. */
    public function testAnExistingFileIsNeverOverwritten(): void {
        $this->issue();
        $this->tree->addFile(self::FILE, 'original', 'application/octet-stream');
        $response = $this->controller->upload();

        $this->assertSame(409, $response->getStatus());
        $this->assertSame(FilesMessages::fileExists(), $this->text($response));
        $this->assertSame('original', $this->tree->nodes[self::FILE]['content']);
        $this->assertSame([], $this->tree->ops);
        $this->assertFalse($this->spent());
    }

    /** Another client creates the name between the check and the write: its bytes stay and the answer is a 409. */
    public function testARaceAtWriteTimeKeepsTheOtherFile(): void {
        $this->issue();
        $this->tree->beforeCreate = function (string $path): void {
            $this->tree->addFile($path, 'do outro', 'text/plain');
        };
        $response = $this->controller->upload();

        $this->assertSame(409, $response->getStatus());
        $this->assertSame(FilesMessages::fileExists(), $this->text($response));
        $this->assertSame('do outro', $this->tree->nodes[self::FILE]['content']);
        $this->assertNotContains('write ' . self::FILE, $this->tree->ops);
    }

    public function testAFolderRemovedAfterTheLinkIsA404ThatKeepsTheLink(): void {
        $this->issue('/Sumiu/novo.docx');
        $response = $this->controller->upload();
        $this->assertSame(404, $response->getStatus());
        $this->assertSame(FilesMessages::createFolderMissing(), $this->text($response));
        $this->assertFalse($this->spent());
        $this->assertSame([], $this->tree->ops);
    }

    /** A folder hidden after the link answers exactly like a missing one, and names nothing of it. */
    public function testAFolderHiddenAfterTheLinkIsNotFound(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $folder = (string)$this->tree->nodes['/alice/files/Documentos']['id'];
        $mapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $mapper->method('getTagIdsForObjects')->willReturnCallback(fn (array $ids): array => [$folder => ['999']]
            + array_fill_keys($ids, []));
        $this->guard = new VisibilityGuard($this->config->mock($this), $mapper);
        $this->rebuild();
        $this->stage('PK docx bytes');
        $this->issue();

        $response = $this->controller->upload();
        $this->assertSame(404, $response->getStatus());
        $this->assertSame(FilesMessages::createFolderMissing(), $this->text($response));
        $this->assertStringNotContainsString('Documentos', $this->text($response));
        $this->assertFalse($this->spent());
        $this->assertSame([], $this->tree->ops);
    }

    public function testAFolderThatNoLongerAcceptsFilesIsForbidden(): void {
        $this->issue();
        $this->tree->nodes['/alice/files/Documentos']['permissions'] = \OCP\Constants::PERMISSION_READ;
        $response = $this->controller->upload();
        $this->assertSame(403, $response->getStatus());
        $this->assertSame(CommonMessages::forbidden(), $this->text($response));
        $this->assertSame([], $this->tree->ops);
    }

    /** The create link needs files.create, re-read on the request; files.edit is not enough and not needed. */
    public function testRevokingTheCreateGrantBlocksTheLink(): void {
        $this->issue();
        $this->policy->setGrant('alice', 'files', 'create', false);
        $this->policy->setGrant('alice', 'files', 'edit', true);
        $this->assertSame(403, $this->controller->upload()->getStatus());
        $this->assertSame([], $this->tree->ops);
        $this->assertFalse($this->spent());
    }

    public function testDisconnectingBlocksTheLink(): void {
        $this->issue();
        $this->policy->setConnected('alice', false);
        $this->assertSame(403, $this->controller->upload()->getStatus());
        $this->assertSame([], $this->tree->ops);
    }

    public function testAnotherUsersSessionIsRefusedWithoutSpendingTheLink(): void {
        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        $this->session = $this->createMock(IUserSession::class);
        $this->session->method('getUser')->willReturn($bob);
        $this->rebuild();
        $this->stage('PK docx bytes');
        $this->issue();

        $this->assertSame(404, $this->controller->upload()->getStatus());
        $this->assertFalse($this->spent());
        $this->assertSame([], $this->tree->ops);
    }

    public function testAnUnknownLinkIsRefused(): void {
        $this->issue();
        $this->controller->route = ['token' => 'ncmcp_co_c_desconhecido'];
        $this->assertSame(404, $this->controller->upload()->getStatus());
        $this->assertSame([], $this->tree->ops);
    }

    /** The kind is in the stored row, not in the prefix: a checkout row behind a create-looking token is no create. */
    public function testTheStoredKindDecidesNotThePrefix(): void {
        $this->issue(self::PATH, 'personal', false, CheckoutToken::KIND_UPLOAD);
        $this->stage('');
        $this->assertSame(404, $this->controller->upload()->getStatus());
        $this->assertArrayNotHasKey(self::FILE, $this->tree->nodes);
    }

    public function testTheLinkIsSingleUse(): void {
        $this->issue();
        $this->assertSame(201, $this->controller->upload()->getStatus());
        $this->tree->ops = [];
        $response = $this->controller->upload();
        $this->assertSame(410, $response->getStatus());
        $this->assertSame(FilesMessages::createTokenSpent(), $this->text($response));
        $this->assertStringContainsString('files_upload', FilesMessages::createTokenSpent());
        $this->assertSame([], $this->tree->ops);
    }

    public function testAnExpiredLinkWritesNothing(): void {
        $this->issue();
        $this->store->rows[array_key_first($this->store->rows)]['expires_at'] = 1789999999;
        $this->assertSame(410, $this->controller->upload()->getStatus());
        $this->assertArrayNotHasKey(self::FILE, $this->tree->nodes);
    }

    /** The create link is never a download link. */
    public function testTheCreateLinkDoesNotDownload(): void {
        $this->issue();
        $this->assertSame(404, $this->controller->download()->getStatus());
    }

    /** A folder that became shared since the link asks for a new files_upload, because this link is spent. */
    public function testAFolderSharedWithoutConfirmationAsksAgainWithoutWriting(): void {
        $this->tree->addFolder('/alice/files/Compartilhado', ['scope' => 'shared']);
        $this->issue('/Compartilhado/novo.docx');
        $response = $this->controller->upload();

        $this->assertSame(409, $response->getStatus());
        $out = $this->payload($response);
        $this->assertTrue($out['requiresConfirmation']);
        $this->assertSame('/Compartilhado/novo.docx', $out['resource']);
        $this->assertStringContainsString('files_upload', $out['message']);
        $this->assertStringContainsString('confirm_shared: true', $out['message']);
        $this->assertArrayNotHasKey('/alice/files/Compartilhado/novo.docx', $this->tree->nodes);
    }

    public function testAConfirmedSharedLinkWrites(): void {
        $this->tree->addFolder('/alice/files/Compartilhado', ['scope' => 'shared']);
        $this->issue('/Compartilhado/novo.docx', 'shared', true);
        $this->assertSame(201, $this->controller->upload()->getStatus());
        $this->assertSame('PK docx bytes', $this->tree->nodes['/alice/files/Compartilhado/novo.docx']['content']);
    }

    /** A name the rules of Nextcloud refuse by now (an administrator changed them) is a 400 that writes nothing. */
    public function testANameNextcloudNowRefusesIsA400(): void {
        $this->issue();
        $this->filenames->forbiddenExtensions[] = '.docx';
        $response = $this->controller->upload();
        $this->assertSame(400, $response->getStatus());
        $this->assertStringNotContainsString('Relatório', $this->text($response));
        $this->assertSame([], $this->tree->ops);
    }

    /** A write that fails after the empty file appeared says so: nothing is deleted to hide it. */
    public function testAFailedWriteSaysAnEmptyFileMayRemain(): void {
        $this->issue();
        $this->tree->failWrite = true;
        $response = $this->controller->upload();
        $this->assertSame(500, $response->getStatus());
        $this->assertSame(FilesMessages::createFailed(), $this->text($response));
        $this->assertNotContains('delete ' . self::FILE, $this->tree->ops);
    }
}
