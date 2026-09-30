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

    /** @var array<string, string> parameters the router put in the URL */
    public array $route = [];

    /** @return resource a readable stream over the staged body */
    protected function inputStream() {
        $handle = fopen('php://memory', 'w+');
        fwrite($handle, $this->staged);
        rewind($handle);
        return $handle;
    }

    /** The token as the URL carried it; a body could never reach this. */
    protected function routeToken(): ?string {
        return $this->route['token'] ?? null;
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
    /** @var array<string, string> headers the fake request answers */
    private array $headers = ['Content-Type' => 'application/octet-stream', 'Content-Length' => '12'];
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
        $this->headers['Content-Length'] = (string)strlen($body);
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

        $controller = $this->build($root, $session ?? $this->session, $config, $urls, $backup, $access);
        $controller->route = ['token' => self::TOKEN];
        $this->controller = $controller;
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
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
        $request->method('getMethod')->willReturn('PUT');
        return new TestableCheckoutController('mcp', $request, $root, $session,
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

    /** Presents a token the store has never seen, by putting it in the route. */
    private function unknown(): \OCP\AppFramework\Http\Response {
        $this->controller->route = ['token' => 'ncmcp_co_u_desconhecido'];
        return $this->controller->upload();
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
        $response = $this->controller->upload();
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
        $this->assertSame(404, $this->code($this->unknown()));
        $this->assertSame([], $this->tree->ops);
    }

    public function testADownloadTokenIsNotAcceptedOnTheUploadRoute(): void {
        $this->issue(CheckoutToken::KIND_DOWNLOAD);
        $this->assertSame(404, $this->code($this->controller->upload()));
        $this->assertSame([], $this->tree->ops);
    }

    public function testATokenIsSpentOnFirstUseAndRefusedAfterwards(): void {
        $this->issue();
        $this->assertSame(200, $this->code($this->controller->upload()));
        $this->tree->ops = [];
        $this->assertSame(410, $this->code($this->controller->upload()));
        $this->assertSame([], $this->tree->ops);
    }

    public function testAnExpiredTokenIsRefusedAndWritesNothing(): void {
        $this->issue();
        $this->store->rows[array_key_first($this->store->rows)]['expires_at'] = 1789999999;
        $this->assertSame(410, $this->code($this->controller->upload()));
        $this->assertSame([], $this->tree->ops);
    }

    public function testAFileThatChangedSinceTheCheckoutIsAConflictWithoutABackup(): void {
        $this->issue();
        $this->store->moveEtag('e-outro');
        $this->assertSame(409, $this->code($this->controller->upload()));
        $this->assertSame('# Ata', $this->tree->nodes[self::FILE]['content']);
        $this->assertSame([], $this->tree->ops);
    }

    public function testRevokingTheGrantBetweenCheckoutAndUploadBlocksIt(): void {
        $this->issue();
        $this->policy->setGrant('alice', 'files', 'edit', false);
        $this->assertSame(403, $this->code($this->controller->upload()));
        $this->assertSame([], $this->tree->ops);
    }

    public function testTurningTheServiceOffBetweenCheckoutAndUploadBlocksIt(): void {
        $this->issue();
        $this->policy->setGlobalEnabled(false);
        $this->assertSame(403, $this->code($this->controller->upload()));
        $this->assertSame([], $this->tree->ops);
    }

    public function testLosingEligibilityBlocksTheUpload(): void {
        $this->issue();
        $this->policy->setEligible('alice', false);
        $this->assertSame(403, $this->code($this->controller->upload()));
    }

    public function testDisconnectingBlocksTheUpload(): void {
        $this->issue();
        $this->policy->setConnected('alice', false);
        $this->assertSame(403, $this->code($this->controller->upload()));
    }

    public function testALinkOfAnotherUserIsRefusedEvenWithThatUsersSession(): void {
        $this->issue();
        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        $this->session = $this->createMock(IUserSession::class);
        $this->session->method('getUser')->willReturn($bob);
        $this->refresh();
        $this->assertSame(404, $this->code($this->controller->upload()));
        $this->assertSame([], $this->tree->ops);
    }

    public function testAPathOutsideTheTokenOwnersFolderIsRefused(): void {
        // The file exists for someone else, so it does not resolve in alice's view of her own folder.
        $this->tree->addFile('/bob/files/Documentos/alheio.md', 'alheio', 'text/markdown');
        $this->issue(CheckoutToken::KIND_UPLOAD, 'personal', false, '/Documentos/alheio.md');
        $this->assertSame(404, $this->code($this->controller->upload()));
        $this->assertSame([], $this->tree->ops);
    }

    public function testAFileWhoseIdChangedIsRefused(): void {
        $this->issue();
        $this->store->rows[array_key_first($this->store->rows)]['file_id'] = 999999;
        $this->assertSame(404, $this->code($this->controller->upload()));
        $this->assertSame([], $this->tree->ops);
    }

    public function testAFileNextcloudWillNotUpdateIsRefused(): void {
        $this->issue();
        $this->tree->nodes[self::FILE]['updateable'] = false;
        $this->assertSame(403, $this->code($this->controller->upload()));
        $this->assertSame([], $this->tree->ops);
    }

    public function testABodyOverTheLimitIsRefusedAndTheTokenIsAlreadySpent(): void {
        $this->issue();
        $this->stage(str_repeat('x', self::UPLOAD_LIMIT + 1));
        $this->assertSame(413, $this->code($this->controller->upload()));
        $this->assertSame([], $this->tree->ops);
    }

    /** A body exactly at the limit is accepted, so the check is inclusive. */
    public function testABodyExactlyAtTheLimitIsAccepted(): void {
        $this->issue();
        $this->stage(str_repeat('x', self::UPLOAD_LIMIT));
        $this->assertSame(200, $this->code($this->controller->upload()));
        $this->assertSame(self::UPLOAD_LIMIT, strlen($this->tree->nodes[self::FILE]['content']));
    }

    /** Without versioning the upload is refused, exactly as files_edit is. */
    public function testUploadIsBlockedWhenVersioningIsOff(): void {
        $this->issue();
        $this->apps = $this->createMock(IAppManager::class);
        $this->apps->method('isEnabledForUser')->willReturn(false);
        $this->refresh();
        $this->assertSame(400, $this->code($this->controller->upload()));
        $this->assertSame([], $this->tree->ops);
    }

    public function testTheTokenIsSpentBeforeAnythingElseIsChecked(): void {
        $this->issue();
        $this->controller->upload();
        $this->assertSame(['insert', 'find', 'consume'], array_slice($this->store->ops, 0, 3));
    }

    /** A shared file whose checkout was never confirmed must come back as a confirmation, not a write. */
    public function testASharedFileWithoutConfirmationAsksAgainWithoutWriting(): void {
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', 'plano', 'text/markdown', ['scope' => 'shared']);
        $this->issue(CheckoutToken::KIND_UPLOAD, 'personal', false, '/Compartilhado/plano.md');
        $response = $this->controller->upload();
        // Not a 2xx: the link is spent, so "try again" would be a 410. The status has to say so.
        $this->assertSame(409, $this->code($response));
        $out = $this->payload($response);
        $this->assertTrue($out['requiresConfirmation']);
        $this->assertSame('shared', $out['scope']);
        $this->assertSame('/Compartilhado/plano.md', $out['resource']);
        $this->assertSame('plano', $this->tree->nodes['/alice/files/Compartilhado/plano.md']['content']);
        $this->assertSame([], $this->tree->ops);
    }

    /**
     * The token is spent before the guards run, so the message cannot tell the agent to repeat the call:
     * it has to ask for a new checkout. Wording and status are asserted together, because a 200 with this
     * text is exactly the bug the review found.
     */
    public function testTheConfirmationAsksForANewCheckoutBecauseTheLinkIsSpent(): void {
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', 'plano', 'text/markdown', ['scope' => 'shared']);
        $this->issue(CheckoutToken::KIND_UPLOAD, 'personal', false, '/Compartilhado/plano.md');
        $message = $this->payload($this->controller->upload())['message'];
        $this->assertStringContainsString('novo files_checkout', $message);
        $this->assertStringContainsString('confirm_shared: true', $message);
        $this->assertStringNotContainsString('repite a chamada', $message);
        $this->assertSame(410, $this->code($this->controller->upload()), 'o link já foi gasto');
    }

    /** Same for the conflict: the file moved on, so the agent rereads and checks out again. */
    public function testTheConflictSaysToCheckOutAgainInsteadOfRetryingTheLink(): void {
        $this->issue();
        $this->store->moveEtag('e-outro');
        $response = $this->controller->upload();
        $this->assertSame(409, $this->code($response));
        $this->assertStringContainsString('novo files_checkout', (string)$response->render());
        $this->assertStringNotContainsString('etag', (string)$response->render());
    }

    public function testAConfirmedSharedCheckoutWrites(): void {
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', 'plano', 'text/markdown', ['scope' => 'shared']);
        $this->issue(CheckoutToken::KIND_UPLOAD, 'shared', true, '/Compartilhado/plano.md');
        $response = $this->controller->upload();
        $this->assertSame(200, $this->code($response));
        $this->assertContains('write /alice/files/Compartilhado/plano.md', $this->tree->ops);
    }

    public function testDownloadStreamsTheFileAndSpendsItsToken(): void {
        $this->issue(CheckoutToken::KIND_DOWNLOAD);
        $response = $this->controller->download();
        $this->assertSame(200, $this->code($response));
        $headers = $this->headers($response);
        $this->assertSame('text/markdown', $headers['Content-Type']);
        $this->assertStringContainsString('attachment; filename="ata.md"', $headers['Content-Disposition']);
        $this->assertSame('no-store', $headers['Cache-Control']);
        $this->assertSame(410, $this->code($this->controller->download()));
    }

    /**
     * The token must never be a method argument: Request::decodeContent() merges a JSON or urlencoded body
     * into the same parameter array (lib/private/AppFramework/Http/Request.php:388-425), so an argument
     * would let a body choose which file it writes.
     */
    public function testNeitherRouteTakesTheTokenAsAnArgument(): void {
        foreach (['download', 'upload', 'uploadPost'] as $method) {
            $this->assertSame([], (new \ReflectionMethod(CheckoutController::class, $method))->getParameters(),
                "$method() must read the token from the route, not from a parameter");
        }
        $source = (string)file_get_contents((string)(new \ReflectionClass(CheckoutController::class))->getFileName());
        $this->assertStringContainsString("urlParams['token']", $source, 'the token has to come from urlParams');
        $this->assertStringNotContainsString('getParam(', $source, 'getParam() also reads the decoded body');
    }

    /** A body that is not raw bytes is refused before anything is spent, written or read. */
    public function testAMultipartBodyIsRefusedWithoutSpendingTheToken(): void {
        $this->issue();
        $this->stage('qualquer coisa');
        $this->headers['Content-Type'] = 'multipart/form-data; boundary=x';
        $response = $this->controller->upload();
        $this->assertSame(400, $this->code($response));
        $this->assertSame('Envie os bytes do arquivo como corpo bruto (curl -T), não como formulário multipart.', (string)$response->render());
        $this->assertSame([], $this->tree->ops);
        $this->assertNotContains('consume', $this->store->ops, 'o link tem que continuar valendo');
    }

    /** Any other interpreted content type is 415, and the link survives. */
    public function testAnInterpretedContentTypeIsRefusedWithoutSpendingTheToken(): void {
        foreach (['application/json', 'application/x-www-form-urlencoded'] as $type) {
            $this->refresh();
            $this->issue();
            $this->stage('{"token":"outro"}');
            $this->headers['Content-Type'] = $type;
            $response = $this->controller->upload();
            $this->assertSame(415, $this->code($response), $type);
            $this->assertSame([], $this->tree->ops, $type);
            $this->assertNotContains('consume', $this->store->ops, 'o link tem que continuar valendo');
        }
    }

    /** A JSON body cannot redirect the write: the token comes from the route whatever the body says. */
    public function testAJsonBodyCannotChooseTheToken(): void {
        $this->issue();
        $this->stage('{"token":"ncmcp_co_u_atacante","path":"/Documentos/outro.md"}');
        $this->headers['Content-Type'] = 'application/json';
        $this->assertSame(415, $this->code($this->controller->upload()));
        $this->assertSame([], $this->tree->ops);
    }

    /** An empty body is refused without writing and without spending the link. */
    public function testAnEmptyBodyIsRefusedWithoutSpendingTheToken(): void {
        $this->issue();
        $this->stage('');
        $response = $this->controller->upload();
        $this->assertSame(400, $this->code($response));
        $this->assertSame('O corpo enviado está vazio; nada foi gravado.', (string)$response->render());
        $this->assertSame([], $this->tree->ops);
        $this->assertNotContains('consume', $this->store->ops, 'o link tem que continuar valendo');
    }

    /** A declared length over the limit is caught before the body is even copied. */
    public function testADeclaredLengthOverTheLimitIsRefusedWithoutSpendingTheToken(): void {
        $this->issue();
        $this->headers['Content-Length'] = (string)(self::UPLOAD_LIMIT + 1);
        $this->controller->staged = str_repeat('x', self::UPLOAD_LIMIT + 1);
        $this->assertSame(413, $this->code($this->controller->upload()));
        $this->assertSame([], $this->tree->ops);
        $this->assertNotContains('consume', $this->store->ops, 'o link tem que continuar valendo');
    }

    /** A session belonging to somebody else is refused before the token is spent, so the rightful owner can still use it. */
    public function testAnotherUsersSessionDoesNotSpendTheToken(): void {
        $this->issue();
        $this->controller->route = ['token' => self::TOKEN];
        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        $this->session = $this->createMock(IUserSession::class);
        $this->session->method('getUser')->willReturn($bob);
        $this->refresh();
        $this->assertSame(404, $this->code($this->controller->upload()));
        $this->assertSame([], $this->tree->ops);
        $this->assertNotContains('consume', $this->store->ops, 'outro usuário não pode gastar o token');
    }

    /** Every rejection that leaves the token usable is one the agent can fix without a new checkout. */
    public function testTheRejectionsThatKeepTheLinkAreExactlyTheOnesBeforeTheGuard(): void {
        $this->issue();
        foreach ([
            'sem tipo de corpo' => ['Content-Type' => 'text/plain'],
            'corpo vazio' => ['Content-Length' => '0'],
            'acima do limite' => ['Content-Length' => (string)(self::UPLOAD_LIMIT + 1)],
        ] as $label => $headers) {
            $this->refresh();
            $this->store->rows = [];
            $this->store->ops = [];
            $this->issue();
            $this->controller->staged = str_repeat('x', self::UPLOAD_LIMIT + 1);
            $this->headers = array_merge(['Content-Type' => 'application/octet-stream', 'Content-Length' => '12'], $headers);
            $this->assertGreaterThanOrEqual(400, $this->code($this->controller->upload()), $label);
            $this->assertNotContains('consume', $this->store->ops, "$label não pode gastar o token");
            $this->assertSame([], $this->tree->ops, $label);
            // All three are refused on the body itself, before the token is even looked up.
            $this->assertSame(['insert'], $this->store->ops, $label);
        }
    }

    /** A failed write names the copy, so the user knows where the original still is. */
    public function testAFailedWriteNamesTheBackupCopy(): void {
        $this->issue();
        $this->tree->failWrite = true;
        $response = $this->controller->upload();
        $this->assertSame(500, $this->code($response));
        $this->assertStringContainsString('/MCP backups/Documentos/ata.md.', (string)$response->render());
    }

    /** The download offers the RFC 6266 form so a name with spaces survives. */
    public function testTheDownloadEncodesTheFileNameBothWays(): void {
        $this->tree->addFile('/alice/files/Documentos/ata da reunião.md', '# Ata', 'text/markdown');
        $this->issue(CheckoutToken::KIND_DOWNLOAD, 'personal', false, '/Documentos/ata da reunião.md');
        $this->controller->route = ['token' => self::TOKEN];
        $headers = $this->headers($this->controller->download());
        $this->assertStringContainsString("filename*=UTF-8''ata%20da%20reuni%C3%A3o.md", $headers['Content-Disposition']);
        $this->assertStringNotContainsString('filename="ata da', $headers['Content-Disposition']);
    }

    public function testAnUploadTokenIsNotAcceptedOnTheDownloadRoute(): void {
        $this->issue(CheckoutToken::KIND_UPLOAD);
        $this->assertSame(404, $this->code($this->controller->download()));
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
        $controller = $this->build($root, $this->session, $config, $urls,
            new FileBackup($this->apps, $this->users, $this->time, $config), $access);
        $controller->route = ['token' => self::TOKEN];
        $this->controller = $controller;
    }
}
