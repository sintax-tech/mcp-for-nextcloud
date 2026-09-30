<?php
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\Checkout\CheckoutToken;
use OCA\Mcp\Checkout\CheckoutTokenStore;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\Files\CheckoutService;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\ToolFailure;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The two routes a checkout hands to an agent: GET downloads the file, PUT/POST uploads the edited one.
 *
 * Both are public pages without CSRF because the 256-bit token in the path IS the credential: it is
 * single use, expires in minutes and is bound to one user, one file id and one ETag. Nothing about the
 * session is trusted — a logged-in user other than the one the token was issued to gets 404 — and the
 * service switch, the eligibility, the connection and the files.edit grant are re-read on every request,
 * so revoking access takes effect on the next request even for a token issued earlier.
 *
 * The order of the checks is itself the contract: spend the token, re-read the policy, resolve the node,
 * check the size, re-check the ETag, take the backup and only then write.
 */
class CheckoutController extends Controller {
    /** Extra byte read past the limit, so an oversized body is detected without reading all of it. */
    private const BODY_SLACK = 1;
    /** Grant the whole checkout flow needs; checkout, upload and replace all use files.edit. */
    private const MODULE = 'files';
    private const OPERATION = 'edit';

    public function __construct(
        string $appName,
        IRequest $request,
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private ITempManager $tempManager,
        private ITimeFactory $time,
        private GrantPolicy $policy,
        private CheckoutTokenStore $store,
        private TokenHasher $hasher,
        private CheckoutService $checkout,
        private FileBackup $backup,
        private SharedWriteGuard $guard,
        private NodeAccessInfo $accessInfo,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Streams the file the checkout was issued for, spending the download token.
     *
     * @param string $token single-use token from the download URL
     * @return Response the raw bytes, or 404/410/403 without a body
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function download(string $token): Response {
        $opened = $this->open($token, CheckoutToken::KIND_DOWNLOAD);
        if ($opened instanceof Response) {
            return $opened;
        }
        [, $file] = $opened;
        $handle = $file->fopen('r');
        if ($handle === false) {
            return $this->refuse(Http::STATUS_NOT_FOUND, FilesMessages::tokenInvalid());
        }
        return new StreamResponse($handle, Http::STATUS_OK, [
            'Content-Type' => (string)$file->getMimetype(),
            'Content-Disposition' => 'attachment; filename="' . rawurlencode($file->getName()) . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Replaces the file with the uploaded bytes, spending the upload token.
     *
     * The token is spent on arrival, so every answer other than the receipt is final: an agent that has to
     * try again must make a new files_checkout, which is what the message says. A 2xx here would be read as
     * "it worked" and the retry would be a 410.
     *
     * @param string $token single-use token from the upload URL
     * @return Response the JSON receipt, or 409 for a file that changed, 403 for a refused write, and
     *         404/410/413/500 for the rest, all without a body
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function upload(string $token): Response {
        $opened = $this->open($token, CheckoutToken::KIND_UPLOAD);
        if ($opened instanceof Response) {
            return $opened;
        }
        [$row, $file] = $opened;
        $limit = $this->checkout->maxBytes();
        $body = $this->storeBody($limit);
        if ($body === null) {
            return $this->refuse(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, FilesMessages::uploadTooLarge($limit));
        }
        try {
            return $this->write($row, $file, $body);
        } finally {
            @unlink($body);
        }
    }

    /**
     * POST alias of upload(): the same raw body, for clients that do not use PUT.
     *
     * @param string $token single-use token from the upload URL
     * @return Response the JSON receipt, or a refusal
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function uploadPost(string $token): Response {
        return $this->upload($token);
    }

    /**
     * The write itself, after the token and the policy were already accepted.
     *
     * @param CheckoutToken $row the spent token, carrying the user, path and ETag to enforce
     * @param File $file the node the token points at
     * @param string $body temporary file holding the uploaded bytes
     * @return Response the JSON receipt, the confirmation payload or a refusal
     */
    private function write(CheckoutToken $row, File $file, string $body): Response {
        $root = $this->rootFolder->getUserFolder($row->userId);
        $access = $this->accessInfo->describe($file, $row->userId);
        // The scope recorded at checkout can be stale: a file may have moved into a share in between.
        if ($access['scope'] !== NodeAccessInfo::PERSONAL && !$row->sharedConfirmed) {
            return $this->json(Http::STATUS_CONFLICT, $this->guard->request($access, $row->path, CommonMessages::CONFIRM_ADVICE_CHECKOUT));
        }
        if (!$file->isUpdateable()) {
            return $this->refuse(Http::STATUS_FORBIDDEN, ToolFailure::FORBIDDEN);
        }
        try {
            NodeAccess::checkEtag($file, $row->etag);
            $copy = $this->backup->prepare($root, $file, $row->path, $row->userId, $row->etag);
            $file->putContent((string)file_get_contents($body));
            $node = NodeAccess::requireFile(NodeAccess::get($root, $row->path));
        } catch (ToolFailure $e) {
            $conflict = $e->getMessage() === ToolFailure::CONFLICT;
            $message = $conflict ? FilesMessages::uploadConflict() : $e->getMessage();
            return $this->refuse($conflict ? Http::STATUS_CONFLICT : Http::STATUS_BAD_REQUEST, $message);
        } catch (\Throwable) {
            $this->logger->error('MCP checkout upload failed', ['app' => 'mcp']);
            return $this->refuse(Http::STATUS_INTERNAL_SERVER_ERROR, FilesMessages::uploadFailed());
        }
        return $this->json(Http::STATUS_OK, [
            'path' => $row->path,
            'size' => (int)filesize($body),
            'etag' => (string)$node->getEtag(),
            'access' => $this->accessInfo->describe($node, $row->userId),
            'backup' => $copy,
        ]);
    }

    /**
     * Validates the token, spends it and re-reads the policy and the node it points at.
     *
     * @param string $token token as presented in the URL
     * @param string $kind CheckoutToken::KIND_DOWNLOAD or KIND_UPLOAD
     * @return array{0: CheckoutToken, 1: File}|Response the spent token with its node, or the refusal
     */
    private function open(string $token, string $kind): array|Response {
        $row = $this->store->find($this->hasher->hash($token));
        if ($row === null || $row->kind !== $kind) {
            return $this->refuse(Http::STATUS_NOT_FOUND, $kind === CheckoutToken::KIND_UPLOAD ? FilesMessages::tokenWrongKind() : FilesMessages::tokenInvalid());
        }
        // Spending before anything else is what makes a token single use even under concurrent requests.
        if (!$this->store->consume($row, $this->time->getTime())) {
            return $this->refuse(Http::STATUS_GONE, FilesMessages::tokenSpent());
        }
        $uid = $row->userId;
        if (!$this->policy->canConnect($uid) || !$this->policy->granted($uid, self::MODULE, self::OPERATION)) {
            return $this->refuse(Http::STATUS_FORBIDDEN, FilesMessages::checkoutRevoked());
        }
        // A link is the credential, so the session may only ever agree with it.
        $session = $this->userSession->getUser();
        if ($session !== null && $session->getUID() !== $uid) {
            return $this->refuse(Http::STATUS_NOT_FOUND, FilesMessages::tokenInvalid());
        }
        try {
            $file = NodeAccess::requireFile(NodeAccess::get($this->rootFolder->getUserFolder($uid), $row->path));
        } catch (\Throwable) {
            return $this->refuse(Http::STATUS_NOT_FOUND, FilesMessages::tokenInvalid());
        }
        if ((int)$file->getId() !== $row->fileId) {
            return $this->refuse(Http::STATUS_NOT_FOUND, FilesMessages::tokenInvalid());
        }
        return [$row, $file];
    }

    /**
     * Copies the request body into a temporary file without ever holding more than the limit in memory.
     *
     * @param int $limit effective upload limit in bytes
     * @return string|null the temporary path, or null when the body is over the limit or unreadable
     */
    private function storeBody(int $limit): ?string {
        $path = $this->tempManager->getTemporaryFile('.mcp-upload');
        $input = $this->inputStream();
        if ($path === false || $input === false) {
            return null;
        }
        $output = @fopen($path, 'wb');
        if ($output === false) {
            fclose($input);
            return null;
        }
        try {
            $copied = stream_copy_to_stream($input, $output, $limit + self::BODY_SLACK);
        } finally {
            fclose($input);
            fclose($output);
        }
        if ($copied === false || $copied > $limit) {
            @unlink($path);
            return null;
        }
        return $path;
    }

    /**
     * IRequest does not expose the raw body of a PUT, so php://input is the only source; it is a seam
     * so a unit test can stage the bytes.
     *
     * @return resource|false the request body
     */
    protected function inputStream() {
        return @fopen('php://input', 'rb');
    }

    /**
     * @param int $status HTTP status
     * @param string $message client-safe reason, never a token, a path on disk or an internal name
     * @return Response the refusal, logged at debug level with the status only
     */
    private function refuse(int $status, string $message): Response {
        $this->logger->debug('MCP checkout request refused', ['app' => 'mcp', 'status' => $status]);
        return new DataDisplayResponse($message, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * @param int $status HTTP status
     * @param array<string, mixed> $payload receipt or confirmation
     * @return Response the payload as indented JSON
     */
    private function json(int $status, array $payload): Response {
        return new DataDisplayResponse(
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json'],
        );
    }
}
