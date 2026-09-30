<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Checkout;

use OCA\Mcp\Controller\CheckoutController;
use OCA\Mcp\Checkout\CheckoutToken;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\Files\CheckoutService;
use OCA\Mcp\Tools\Files\FileBackup;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The controller with the request body staged, since php://input is not readable from a unit test.
 */
final class TestableCheckoutController extends CheckoutController {
    /** @var string the body the next upload will read */
    public string $staged = '';

    /** @return resource a readable stream over the staged body */
    protected function inputStream() {
        $handle = fopen('php://memory', 'w+');
        fwrite($handle, $this->staged);
        rewind($handle);
        return $handle;
    }
}

/**
 * The two checkout routes. The token is the credential, so what matters here is the order of the checks:
 * a token is spent before anything else is trusted, the policy is re-read on every request, and a file
 * that changed since the checkout is refused before any backup is taken.
 */
final class CheckoutControllerTest extends TestCase {
    private const FILE = '/alice/files/Documentos/ata.md';
    private const TOKEN = 'ncmcp_co_u_ABCdefghijklmnopqrstuvwxyz0123456789ABCDEFG';
    private const UPLOAD_LIMIT = 64;

    private FakeTree $tree;
    private InMemoryCheckoutTokenStore $store;
    private InMemoryConfig $config;
    private TestableCheckoutController $controller;
    private GrantPolicy $policy;
    private IAppManager $apps;
    private IUserManager $users;
    private IUserSession $session;
    private ITimeFactory $time;
    private ITempManager $temp;
    private TokenHasher $hasher;
    private string $body = 'conteúdo novo';

    /** Stages the request body the controller will stream into its temporary file. */
    private function stage(string $body): void {
        $this->body = $body;
        $this->controller->staged = $body;
    }

    protected function setUp(): void {
        $this->tree = new FakeTree($this);
        $this->tree->addFile(self::FILE, '# Ata', 'text/markdown');
        $this->store = new InMemoryCheckoutTokenStore();
        $this->config = new InMemoryConfig();
        $this->policy = new GrantPolicy((new InMemoryConfig())->mock($this));
        $this->policy->setGlobalEnabled(true);
        $this->policy->setEligible('alice', true);
        $this->policy->setConnected('alice', true);
        $this->policy->setGrant('alice', 'files', 'edit', true);

        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn (string $uid) => $uid === 'alice'
            ? $this->tree->rootFolder()
            : throw new \LogicException('other user'));
        $this->session = $this->createMock(IUserSession::class);
        $this->session->method('getUser')->willReturn(null);
        $this->temp = $this->createMock(ITempManager::class);
        $this->temp->method('getTemporaryFile')->willReturnCallback(fn () => tempnam(sys_get_temp_dir(), 'mcp'));
        $this->time = $this->createMock(ITimeFactory::class);
        $this->time->method('getTime')->willReturn(1790000000);
        // Alice's timezone stamps the backup copy, exactly as in the Files tool tests; the small upload
        // limit keeps the 413 boundary testable.
        $this->config->user['alice']['core']['timezone'] = 'America/Sao_Paulo';
        $this->config->app['mcp'][CheckoutService::MAX_BYTES_KEY] = (string)self::UPLOAD_LIMIT;
        $config = $this->config->mock($this);
        $this->hasher = new TokenHasher($config);
        $this->apps = $this->createMock(IAppManager::class);
        $this->apps->method('isEnabledForUser')->willReturn(true);
        $this->users = $this->createMock(IUserManager::class);
        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $alice->method('isEnabled')->willReturn(true);
        $this->users->method('get')->willReturnCallback(fn (string $uid) => $uid === 'alice' ? $alice : null);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturnCallback(fn (string $route, array $args = []) => '/apps/mcp/' . ($args['token'] ?? ''));
        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS));
        $backup = new FileBackup($this->apps, $this->users, $this->time, $config);

        $this->controller = $this->build($root, $session ?? $this->session, $config, $urls, $backup, $access);
    }

    /**
     * @param \OCP\Files\IRootFolder $root the user's folder
     * @param IUserSession $session the logged-in user, if any
     * @param IConfig $config instance config, carrying the upload limit
     * @param IURLGenerator $urls route builder for the two links
     * @param FileBackup $backup the backup preparation
     * @param NodeAccessInfo $access ownership description
     * @return TestableCheckoutController the controller under test
     */
    private function build($root, $session, $config, $urls, FileBackup $backup, NodeAccessInfo $access): TestableCheckoutController {
        return new TestableCheckoutController('mcp', $this->createMock(IRequest::class), $root, $session,
            $this->temp, $this->time, $this->policy, $this->store, $this->hasher,
            new CheckoutService($urls, $config, $this->time, $this->hasher, $this->store), $backup,
            new SharedWriteGuard($access), $access, $this->createMock(LoggerInterface::class));
    }

    /** Stores an upload token for the file, standing for a checkout the agent already made. */
    private function issue(string $kind = CheckoutToken::KIND_UPLOAD, string $scope = 'personal', bool $confirmed = false, ?string $path = null): void {
        $path ??= '/Documentos/ata.md';
        $this->store->insert([
            'token_hash' => $this->hasher->hash(self::TOKEN),
            'kind' => $kind,
            'user_id' => 'alice',
            // A path that no longer resolves for this user keeps whatever id it had, so the row is
            // still a realistic leftover and the controller has to refuse it on the node, not on the id.
            'file_id' => (int)($this->tree->nodes['/alice/files' . $path]['id'] ?? 0),
            'path' => $path,
            'etag' => (string)($this->tree->nodes['/alice/files' . $path]['etag'] ?? 'e0'),
            'scope' => $scope,
            'shared_confirmed' => $confirmed ? 1 : 0,
            'created_at' => 1790000000,
            'expires_at' => 1790000000 + 900,
        ], 1790000000);
    }

    /**
     * @param \OCP\AppFramework\Http\Response $response response to read
     * @return array<string, string> its headers, read without the server container OCP needs
     */
    private function headers(\OCP\AppFramework\Http\Response $response): array {
        $property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
        $property->setAccessible(true);
        return $property->getValue($response);
    }

    /** @return int the HTTP status of a response */
    private function code(\OCP\AppFramework\Http\Response $response): int {
        return $response->getStatus();
    }

    private function payload(\OCP\AppFramework\Http\Response $response): array {
        $this->assertInstanceOf(\OCP\AppFramework\Http\DataDisplayResponse::class, $response);
        return json_decode((string)$response->render(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheRoutesArePublicAndWithoutCsrfBecauseTheTokenIsTheCredential(): void {
        foreach (['download', 'upload', 'uploadPost'] as $method) {
            $attributes = (new \ReflectionMethod(CheckoutController::class, $method))->getAttributes();
            $names = array_map(static fn (\ReflectionAttribute $a) => $a->getName(), $attributes);
            $this->assertContains(PublicPage::class, $names, $method);
            $this->assertContains(NoCSRFRequired::class, $names, $method);
        }
    }

    public function testHappyPathBacksUpThenWritesAndReturnsTheReceipt(): void {
        $this->issue();
        $response = $this->controller->upload(self::TOKEN);
        $this->assertSame(200, $this->code($response));
        $out = $this->payload($response);
        $this->assertSame(['path', 'size', 'etag', 'access', 'backup'], array_keys($out));
        $this->assertSame('/Documentos/ata.md', $out['path']);
        $this->assertSame('# Ata', $this->tree->nodes['/alice/files' . $out['backup']]['content']);
        $this->assertSame(['mkdir /alice/files/MCP backups', 'mkdir /alice/files/MCP backups/Documentos',
            'copy /alice/files/Documentos/ata.md /alice/files' . $out['backup'],
            'write /alice/files/Documentos/ata.md'], $this->tree->ops);
    }

    public function testAnUnknownTokenIsRefused(): void {
        $this->assertSame(404, $this->code($this->controller->upload('ncmcp_co_u_desconhecido')));
        $this->assertSame([], $this->tree->ops);
    }

    public function testADownloadTokenIsNotAcceptedOnTheUploadRoute(): void {
        $this->issue(CheckoutToken::KIND_DOWNLOAD);
        $this->assertSame(404, $this->code($this->controller->upload(self::TOKEN)));
        $this->assertSame([], $this->tree->ops);
    }

    public function testATokenIsSpentOnFirstUseAndRefusedAfterwards(): void {
        $this->issue();
        $this->assertSame(200, $this->code($this->controller->upload(self::TOKEN)));
        $this->tree->ops = [];
        $this->assertSame(410, $this->code($this->controller->upload(self::TOKEN)));
        $this->assertSame([], $this->tree->ops);
    }

    public function testAnExpiredTokenIsRefusedAndWritesNothing(): void {
        $this->issue();
        $this->store->rows[array_key_first($this->store->rows)]['expires_at'] = 1789999999;
        $this->assertSame(410, $this->code($this->controller->upload(self::TOKEN)));
        $this->assertSame([], $this->tree->ops);
    }

    public function testAFileThatChangedSinceTheCheckoutIsAConflictWithoutABackup(): void {
        $this->issue();
        $this->store->moveEtag('e-outro');
        $this->assertSame(409, $this->code($this->controller->upload(self::TOKEN)));
        $this->assertSame('# Ata', $this->tree->nodes[self::FILE]['content']);
        $this->assertSame([], $this->tree->ops);
    }

    public function testRevokingTheGrantBetweenCheckoutAndUploadBlocksIt(): void {
        $this->issue();
        $this->policy->setGrant('alice', 'files', 'edit', false);
        $this->assertSame(403, $this->code($this->controller->upload(self::TOKEN)));
        $this->assertSame([], $this->tree->ops);
    }

    public function testTurningTheServiceOffBetweenCheckoutAndUploadBlocksIt(): void {
        $this->issue();
        $this->policy->setGlobalEnabled(false);
        $this->assertSame(403, $this->code($this->controller->upload(self::TOKEN)));
        $this->assertSame([], $this->tree->ops);
    }

    public function testLosingEligibilityBlocksTheUpload(): void {
        $this->issue();
        $this->policy->setEligible('alice', false);
        $this->assertSame(403, $this->code($this->controller->upload(self::TOKEN)));
    }

    public function testDisconnectingBlocksTheUpload(): void {
        $this->issue();
        $this->policy->setConnected('alice', false);
        $this->assertSame(403, $this->code($this->controller->upload(self::TOKEN)));
    }

    public function testALinkOfAnotherUserIsRefusedEvenWithThatUsersSession(): void {
        $this->issue();
        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        $this->session = $this->createMock(IUserSession::class);
        $this->session->method('getUser')->willReturn($bob);
        $this->refresh();
        $this->assertSame(404, $this->code($this->controller->upload(self::TOKEN)));
        $this->assertSame([], $this->tree->ops);
    }

    public function testAPathOutsideTheTokenOwnersFolderIsRefused(): void {
        // The file exists for someone else, so it does not resolve in alice's view of her own folder.
        $this->tree->addFile('/bob/files/Documentos/alheio.md', 'alheio', 'text/markdown');
        $this->issue(CheckoutToken::KIND_UPLOAD, 'personal', false, '/Documentos/alheio.md');
        $this->assertSame(404, $this->code($this->controller->upload(self::TOKEN)));
        $this->assertSame([], $this->tree->ops);
    }

    public function testAFileWhoseIdChangedIsRefused(): void {
        $this->issue();
        $this->store->rows[array_key_first($this->store->rows)]['file_id'] = 999999;
        $this->assertSame(404, $this->code($this->controller->upload(self::TOKEN)));
        $this->assertSame([], $this->tree->ops);
    }

    public function testAFileNextcloudWillNotUpdateIsRefused(): void {
        $this->issue();
        $this->tree->nodes[self::FILE]['updateable'] = false;
        $this->assertSame(403, $this->code($this->controller->upload(self::TOKEN)));
        $this->assertSame([], $this->tree->ops);
    }

    public function testABodyOverTheLimitIsRefusedAndTheTokenIsAlreadySpent(): void {
        $this->issue();
        $this->stage(str_repeat('x', self::UPLOAD_LIMIT + 1));
        $this->assertSame(413, $this->code($this->controller->upload(self::TOKEN)));
        $this->assertSame([], $this->tree->ops);
    }

    /** A body exactly at the limit is accepted, so the check is inclusive. */
    public function testABodyExactlyAtTheLimitIsAccepted(): void {
        $this->issue();
        $this->stage(str_repeat('x', self::UPLOAD_LIMIT));
        $this->assertSame(200, $this->code($this->controller->upload(self::TOKEN)));
        $this->assertSame(self::UPLOAD_LIMIT, strlen($this->tree->nodes[self::FILE]['content']));
    }

    /** Without versioning the upload is refused, exactly as files_edit is. */
    public function testUploadIsBlockedWhenVersioningIsOff(): void {
        $this->issue();
        $this->apps = $this->createMock(IAppManager::class);
        $this->apps->method('isEnabledForUser')->willReturn(false);
        $this->refresh();
        $this->assertSame(400, $this->code($this->controller->upload(self::TOKEN)));
        $this->assertSame([], $this->tree->ops);
    }

    public function testTheTokenIsSpentBeforeAnythingElseIsChecked(): void {
        $this->issue();
        $this->controller->upload(self::TOKEN);
        $this->assertSame(['insert', 'find', 'consume'], array_slice($this->store->ops, 0, 3));
    }

    /** A shared file whose checkout was never confirmed must come back as a confirmation, not a write. */
    public function testASharedFileWithoutConfirmationAsksAgainWithoutWriting(): void {
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', 'plano', 'text/markdown', ['scope' => 'shared']);
        $this->issue(CheckoutToken::KIND_UPLOAD, 'personal', false, '/Compartilhado/plano.md');
        $response = $this->controller->upload(self::TOKEN);
        $this->assertSame(200, $this->code($response));
        $out = $this->payload($response);
        $this->assertTrue($out['requiresConfirmation']);
        $this->assertSame('shared', $out['scope']);
        $this->assertSame('/Compartilhado/plano.md', $out['resource']);
        $this->assertSame('plano', $this->tree->nodes['/alice/files/Compartilhado/plano.md']['content']);
        $this->assertSame([], $this->tree->ops);
    }

    public function testAConfirmedSharedCheckoutWrites(): void {
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', 'plano', 'text/markdown', ['scope' => 'shared']);
        $this->issue(CheckoutToken::KIND_UPLOAD, 'shared', true, '/Compartilhado/plano.md');
        $response = $this->controller->upload(self::TOKEN);
        $this->assertSame(200, $this->code($response));
        $this->assertContains('write /alice/files/Compartilhado/plano.md', $this->tree->ops);
    }

    public function testDownloadStreamsTheFileAndSpendsItsToken(): void {
        $this->issue(CheckoutToken::KIND_DOWNLOAD);
        $response = $this->controller->download(self::TOKEN);
        $this->assertSame(200, $this->code($response));
        $headers = $this->headers($response);
        $this->assertSame('text/markdown', $headers['Content-Type']);
        $this->assertStringContainsString('attachment; filename="ata.md"', $headers['Content-Disposition']);
        $this->assertSame('no-store', $headers['Cache-Control']);
        $this->assertSame(410, $this->code($this->controller->download(self::TOKEN)));
    }

    public function testAnUploadTokenIsNotAcceptedOnTheDownloadRoute(): void {
        $this->issue(CheckoutToken::KIND_UPLOAD);
        $this->assertSame(404, $this->code($this->controller->download(self::TOKEN)));
    }

    /** Rebuilds the controller, for the tests that swap a collaborator. */
    private function refresh(): void {
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn (string $uid) => $uid === 'alice'
            ? $this->tree->rootFolder()
            : throw new \LogicException('other user'));
        $config = $this->config->mock($this);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturn('/apps/mcp/x');
        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS));
        $this->controller = $this->build($root, $this->session, $config, $urls,
            new FileBackup($this->apps, $this->users, $this->time, $config), $access);
    }
}
