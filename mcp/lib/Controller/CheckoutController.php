<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Tools\Files\Handler\CheckoutWriteHandler;
use OCA\Mcp\Checkout\CheckoutToken;
use OCA\Mcp\Checkout\CheckoutTokenStore;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\L10n\UserL10n;
use OCA\Mcp\OAuth\Storage\TokenHasher;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\LockMessages;
use OCA\Mcp\Platform\FileAccess\LockWriteFailure;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Tools\Files\CheckoutService;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\Files\FileCreation;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
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
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The two routes a checkout hands to an agent: GET downloads the file, PUT/POST uploads the edited one. The
 * PUT/POST route also spends the create link of files_upload, which creates one new file at the path it remembers.
 *
 * Both are public pages without CSRF because the 256-bit token in the path IS the credential: it is
 * single use, expires in minutes and is bound to one user, one file id and one ETag. Nothing about the
 * session is trusted — a logged-in user other than the one the token was issued to gets 404 — and the
 * service switch, the eligibility, the connection and the files.edit grant are re-read on every request,
 * so revoking access takes effect on the next request even for a token issued earlier.
 *
 * The order of the checks is itself the contract: refuse what the headers and the token alone can refuse before a
 * byte of the body reaches the disk, keep the body, re-read the policy, resolve the node, spend the token, re-check
 * the ETag, take the backup and only then write. A create link follows the same order with its own checks.
 *
 * A file locked by files_lock (open in Text or Office, locked by another person or a WebDAV client) is a 423. When the
 * lock is there before the link is spent, the link stays usable; a lock met after that asks for a new checkout.
 */
class CheckoutController extends Controller {
	/** Extra byte read past the limit, so an oversized body is detected without reading all of it. */
	private const BODY_SLACK = 1;
	/** Grant the whole checkout flow needs; checkout, upload and replace all use files.edit. */
	private const MODULE = 'files';
	private const OPERATION = 'edit';
	/** Grant the create link of files_upload needs, re-read when it is spent. */
	public const OPERATION_CREATE = 'create';

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
		private ?UserL10n $l10n = null,
		private ?IUserManager $userManager = null,
		private ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard = null,
		private ?FileCreation $creation = null,
		private ?LockAwareWrite $locks = null,
		private ?GuestRestrictions $guests = null,
		private ?\OCA\Mcp\Access\FileTypes\FileTypeGuard $fileTypes = null,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Streams the file the checkout was issued for, spending the download token.
	 *
	 * @return Response the raw bytes, or 404/410/403 without a body
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function download(): Response {
		try {
			$this->configureUserL10n();
			$found = $this->store->find($this->hasher->hash($this->routeToken() ?? ''));
			return $this->asOwner($found, fn (): Response => $this->send($found));
		} finally {
			Translator::reset();
		}
	}

	/**
	 * The download itself, inside the operation of the link's owner.
	 *
	 * @param CheckoutToken|null $found the row of the presented token, null when there is none
	 * @return Response the raw bytes, or a refusal without a body
	 */
	private function send(?CheckoutToken $found): Response {
		$opened = $this->resolve($found, CheckoutToken::KIND_DOWNLOAD);
		if ($opened instanceof Response) {
			return $opened;
		}
		[$row, $file] = $opened;
		try {
			NodeAccess::checkEtag($file, $row->etag);
		} catch (ToolFailure) {
			return $this->refuse(Http::STATUS_CONFLICT, CommonMessages::conflict());
		}
		if ($failure = $this->spend($row)) {
			return $failure;
		}
		$handle = $file->fopen('r');
		if ($handle === false) {
			return $this->refuse(Http::STATUS_NOT_FOUND, FilesMessages::tokenInvalid());
		}
		// filename*=UTF-8'' is the form that survives a name with spaces or accents; the plain filename
		// is the percent-encoded fallback for clients that only understand RFC 6266.
		$name = $file->getName();
		return new StreamResponse($handle, Http::STATUS_OK, [
			'Content-Type' => (string)$file->getMimetype(),
			'Content-Disposition' => sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', rawurlencode($name), rawurlencode($name)),
			'Cache-Control' => 'no-store',
		]);
	}

	/**
	 * Runs one use of a link as one policy operation of its owner (GrantPolicy::within): the grant, the Files scope
	 * and every node check of this request read the same snapshot, and the Files gate knows whose scope applies. A
	 * token emitted before a scope change is judged by the scope of now.
	 *
	 * @param CheckoutToken|null $row the row of the presented token; without one nothing is read and no operation opens
	 * @param \Closure(): Response $work the use of the link
	 * @return Response what the use answered, or 503 when the policy cannot be read now
	 */
	private function asOwner(?CheckoutToken $row, \Closure $work): Response {
		if ($row === null) {
			return $work();
		}
		try {
			return $this->policy->within($row->userId, $work);
		} catch (PolicyUnavailable) {
			return $this->refuse(Http::STATUS_SERVICE_UNAVAILABLE, FilesMessages::checkoutUnavailable());
		}
	}

	/**
	 * Replaces the file with the uploaded bytes, spending the upload token.
	 *
	 * The body is checked before the token is spent, so a call the agent got wrong — a multipart body, an
	 * empty body, a body over the limit — still leaves the link usable. Everything from the guard on leaves
	 * it spent, because from there the write may or may not have happened and a retry would be ambiguous.
	 *
	 * A create link of files_upload takes the same route and the same body checks, except that an empty body is
	 * a valid empty file: it cannot erase anything, because the link never writes over an existing file.
	 *
	 * The token is read from the route only, never as a method argument: Request::decodeContent() merges a
	 * JSON or urlencoded body into the same parameter array (lib/private/AppFramework/Http/Request.php:388-425),
	 * so an argument would let a body choose which file it writes.
	 *
	 * @return Response the JSON receipt (201 for a created file), or 409 for a conflict or a taken name, 403 for a
	 *         refused write, and 400/404/410/413/500 for the rest
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function upload(): Response {
		try {
			$this->configureUserL10n();
			if ($failure = $this->checkBodyType()) {
				return $failure;
			}
			$token = $this->routeToken();
			if ($token === null) {
				return $this->refuse(Http::STATUS_NOT_FOUND, FilesMessages::tokenInvalid());
			}
			// Decided from the prefix because the store is not read before the headers pass; the stored kind still rules.
			$create = CheckoutService::isCreateToken($token);
			$limit = $this->checkout->maxBytes();
			// The route is public: everything that can be refused from the headers and the token is refused before a
			// byte of the body reaches the disk, so a request without a valid link cannot fill the temporary folder.
			if ($failure = $this->checkBody($limit, $create)) {
				return $failure;
			}
			$row = $this->store->find($this->hasher->hash($token));
			if ($failure = $this->checkLink($row, $create ? CheckoutToken::KIND_CREATE : CheckoutToken::KIND_UPLOAD)) {
				return $failure;
			}
			$body = $this->storeBody($limit);
			// A body that could not be kept (larger than declared, or unreadable) is never written, and costs no link.
			if ($body === null) {
				return $this->refuse(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, FilesMessages::uploadTooLarge($limit));
			}
			try {
				return $this->asOwner($row, fn (): Response => $create ? $this->create($row, $body) : $this->replace($row, $body));
			} finally {
				@unlink($body);
			}
		} finally {
			Translator::reset();
		}
	}

	/**
	 * Accept raw file bytes regardless of the advertised MIME type, including a missing header.
	 * Multipart forms still need a targeted refusal because their envelope is not file content.
	 *
	 * @return Response|null the refusal, or null when the body type is acceptable
	 */
	private function checkBodyType(): ?Response {
		$type = strtolower(trim(explode(';', $this->request->getHeader('Content-Type'))[0]));
		if ($type === 'multipart/form-data') {
			return $this->refuse(Http::STATUS_BAD_REQUEST, FilesMessages::uploadMultipart());
		}
		return null;
	}

	/**
	 * @param int $limit effective upload limit in bytes
	 * @param bool $allowEmpty whether an empty body is a valid upload, true only for a create link
	 * @return Response|null the refusal, or null when the body is usable
	 */
	private function checkBody(int $limit, bool $allowEmpty = false): ?Response {
		$declared = (int)$this->request->getHeader('Content-Length');
		if ($declared === 0 && !$allowEmpty) {
			return $this->refuse(Http::STATUS_BAD_REQUEST, FilesMessages::uploadEmpty());
		}
		if ($declared > $limit) {
			return $this->refuse(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, FilesMessages::uploadTooLarge($limit));
		}
		return null;
	}

	/**
	 * @return string|null the token as the route delivered it, and nothing a body could have injected
	 */
	protected function routeToken(): ?string {
		$token = $this->request->urlParams['token'] ?? null;
		return is_string($token) && $token !== '' ? $token : null;
	}

	/**
	 * POST alias of upload(): the same raw body, for clients that do not use PUT.
	 *
	 * @return Response the JSON receipt, or a refusal
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function uploadPost(): Response {
		return $this->upload();
	}

	/**
	 * The upload of a checkout, once the body is kept: the policy and the node, then the link is spent and the file
	 * is replaced.
	 *
	 * @param CheckoutToken $row the upload token, already found, of the right kind, unspent and of this session
	 * @param string $body temporary file holding the uploaded bytes
	 * @return Response the JSON receipt, the confirmation payload or a refusal
	 */
	private function replace(CheckoutToken $row, string $body): Response {
		return $this->writes()->replace($row, $body);
	}

	/**
	 * What makes a link worth reading the body for: it exists, it is of the kind the route expects, the session agrees
	 * with it, and it is neither spent nor expired. Nothing is spent here; consume() still decides the single use.
	 *
	 * @param CheckoutToken|null $row the row of the presented token, null when there is none
	 * @param string $kind CheckoutToken::KIND_UPLOAD or KIND_CREATE, as the prefix of the token announced
	 * @return Response|null the refusal, or null when the body may be read
	 */
	private function checkLink(?CheckoutToken $row, string $kind): ?Response {
		if ($row === null || $row->kind !== $kind) {
			return $this->refuse(Http::STATUS_NOT_FOUND, FilesMessages::tokenWrongKind());
		}
		$session = $this->userSession->getUser();
		if ($session !== null && $session->getUID() !== $row->userId) {
			return $this->refuse(Http::STATUS_NOT_FOUND, FilesMessages::tokenInvalid());
		}
		// The account is asked before the body is read, so a disabled one costs neither a temporary file nor the link.
		if (!$this->ownerUsable($row->userId)) {
			return $this->refuse(Http::STATUS_FORBIDDEN, FilesMessages::checkoutRevoked());
		}
		// A spent or expired link says so before anything else, even when the file of a create link now exists.
		if ($row->used || $row->expiresAt <= $this->time->getTime()) {
			return $this->refuse(Http::STATUS_GONE, $kind === CheckoutToken::KIND_CREATE ? FilesMessages::createTokenSpent() : FilesMessages::tokenSpent());
		}
		return null;
	}

	/**
	 * Creates the new file a files_upload link names, after the body was kept.
	 *
	 * The same order as an upload: the files.create grant, then where the file goes — the name is still one Nextcloud
	 * accepts, the folder still exists, is visible, still lets the user add a file and the name is still free — then
	 * the shared scope and the size the plan declared. None of these writes anything, so none of them costs the link.
	 * Only then is the link spent and the file written, never over a file with content that appeared in the meantime.
	 *
	 * @param CheckoutToken $row the create token, already found, unspent and of this session
	 * @param string $body temporary file holding the uploaded bytes, possibly empty
	 * @return Response 201 with the receipt, 409 for a taken name or a missing shared confirmation, or a refusal
	 */
	private function create(CheckoutToken $row, string $body): Response {
		return $this->writes()->create($row, $body);
	}

	/** @return CheckoutWriteHandler the focused adapter for this controller's existing checks and responses */
	private function writes(): CheckoutWriteHandler {
		return new CheckoutWriteHandler(
			$this->rootFolder, $this->creation, $this->backup, $this->guard, $this->accessInfo,
			$this->logger, $this->visibilityGuard, $this->locks, $this->l10n, $this->userManager, $this->fileTypes,
			$this->resolve(...), $this->admit(...), $this->scopeReadable(...), $this->actsAsUser(...),
			$this->spend(...), $this->json(...), $this->refuse(...), $this->lockRefusal(...),
		);
	}

	/**
	 * @param LockWriteFailure $e the lock that refused an upload whose link is already spent
	 * @param string $copy user-relative path of the backup taken before, '' when there is none
	 * @return Response 423 with the explanation, where the original is, and the advice to run a new checkout
	 */
	private function lockRefusal(LockWriteFailure $e, string $copy): Response {
		return $this->refuse(Http::STATUS_LOCKED, implode(' ', array_filter([
			$e->getMessage(),
			$copy === '' ? '' : FilesMessages::originalKept($copy),
			LockMessages::newCheckoutNeeded(),
		])));
	}

	/**
	 * Whether this request writes as the owner of the link. A capability request has no user session, and files_lock
	 * then refuses even the owner's own manual lock, so the check has to refuse it too.
	 *
	 * @param string $uid owner of the link
	 * @return bool true when the session user is that owner
	 */
	private function actsAsUser(string $uid): bool {
		return $this->userSession->getUser()?->getUID() === $uid;
	}

	/**
	 * Whether the account the link was issued to still exists and is enabled. The policy only knows the app's switches:
	 * an administrator disabling or deleting the account does not touch them, so without this a link would outlive it
	 * until it expires. A controller that cannot ask the account refuses, because it cannot tell.
	 *
	 * @param string $uid owner of the link
	 * @return bool true when the account is there and enabled
	 */
	/**
	 * The policy of the link's owner, read again on every use of an issued link (contracts §4.3): the Guests
	 * restrictions of a guest owner, enabled account, connection allowed and the files grant of this step, resolved
	 * with groups like any MCP request, in one snapshot.
	 *
	 * @return Response|null 404 for a guest Guests refuses, 403 when the policy refuses, 503 when it cannot be resolved
	 *     now; null to proceed
	 */
	private function admit(string $uid, string $operation): ?Response {
		// The link carries no session, so Guests never set up its restrictions for its owner: the app list and the
		// filter of external storage are applied here, before a file is resolved. A guest refused is answered like an
		// unknown link, so the answer tells nothing about the link.
		$owner = $this->userManager?->get($uid);
		if ($owner !== null && $this->guests !== null && !$this->guests->applyToOwner($owner)) {
			return $this->refuse(Http::STATUS_NOT_FOUND, FilesMessages::tokenInvalid());
		}
		try {
			$allowed = $this->ownerUsable($uid) && $this->policy->within($uid,
				fn (): bool => $this->policy->canConnect($uid) && $this->policy->granted($uid, self::MODULE, $operation));
		} catch (PolicyUnavailable) {
			return $this->refuse(Http::STATUS_SERVICE_UNAVAILABLE, FilesMessages::checkoutUnavailable());
		}
		return $allowed ? null : $this->refuse(Http::STATUS_FORBIDDEN, FilesMessages::checkoutRevoked());
	}

	/**
	 * The Files scope of the owner, read once after the grant admitted the step (F3 of the B4 review), so a scope
	 * that cannot be read answers 503 instead of the 404 a later node check would turn it into.
	 *
	 * @return Response|null 503 when it cannot be read; null to proceed
	 */
	private function scopeReadable(): ?Response {
		try {
			$this->visibilityGuard?->assertScopeReadable();
		} catch (PolicyUnavailable) {
			return $this->refuse(Http::STATUS_SERVICE_UNAVAILABLE, FilesMessages::checkoutUnavailable());
		}
		return null;
	}

	private function ownerUsable(string $uid): bool {
		$user = $this->userManager?->get($uid);
		return $user !== null && $user->isEnabled();
	}

	/**
	 * Configures the translator for the token owner before body or permission checks,
	 * so early refusals are delivered in the user language when the token is known.
	 */
	private function configureUserL10n(): void {
		if ($this->l10n === null || $this->userManager === null) {
			return;
		}
		$token = $this->routeToken();
		if ($token === null) {
			return;
		}
		$row = $this->store->find($this->hasher->hash($token));
		if ($row === null) {
			return;
		}
		$session = $this->userSession->getUser();
		if ($session !== null && $session->getUID() !== $row->userId) {
			return;
		}
		$user = $this->userManager->get($row->userId);
		if ($user !== null) {
			Translator::use($this->l10n->forUser($user));
		}
	}

	/**
	 * Everything a request has to pass before the link stops being reusable: the token exists, the session
	 * agrees with it, the policy still allows it, and the node it names is still there with the same id.
	 * None of these has touched the file, so none of them may cost the agent its link.
	 *
	 * Each refusal maps to the HTTP status the agent can act on (404 unknown or foreign token, 403 revoked).
	 *
	 * @param CheckoutToken|null $row the row of the presented token, null when there is none
	 * @param string $kind CheckoutToken::KIND_DOWNLOAD or KIND_UPLOAD
	 * @return array{0: CheckoutToken, 1: File}|Response the token with its node, or the refusal
	 */
	private function resolve(?CheckoutToken $row, string $kind): array|Response {
		if ($row === null || $row->kind !== $kind) {
			return $this->refuse(Http::STATUS_NOT_FOUND, $kind === CheckoutToken::KIND_UPLOAD ? FilesMessages::tokenWrongKind() : FilesMessages::tokenInvalid());
		}
		// A link is the credential, so the session may only ever agree with it.
		$session = $this->userSession->getUser();
		if ($session !== null && $session->getUID() !== $row->userId) {
			return $this->refuse(Http::STATUS_NOT_FOUND, FilesMessages::tokenInvalid());
		}
		$uid = $row->userId;
		if (($refusal = $this->admit($uid, self::OPERATION) ?? $this->scopeReadable()) !== null) {
			return $refusal;
		}
		if ($this->l10n !== null && $this->userManager !== null) {
			$user = $this->userManager->get($uid);
			if ($user !== null) {
				Translator::use($this->l10n->forUser($user));
			}
		}
		try {
			$file = NodeAccess::requireFile(NodeAccess::get($this->rootFolder->getUserFolder($uid), $row->path, $this->visibilityGuard));
		} catch (\Throwable) {
			return $this->refuse(Http::STATUS_NOT_FOUND, CommonMessages::notFound());
		}
		if ((int)$file->getId() !== $row->fileId) {
			return $this->refuse(Http::STATUS_NOT_FOUND, FilesMessages::tokenInvalid());
		}
		if ($this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($file)) {
			return $this->refuse(Http::STATUS_NOT_FOUND, CommonMessages::notFound());
		}
		return [$row, $file];
	}

	/**
	 * @param CheckoutToken $row the token about to be used
	 * @param string|null $spent the refusal of a spent token, the checkout one when null
	 * @return Response|null 410 when another request spent it first, which is what makes the token single use
	 */
	private function spend(CheckoutToken $row, ?string $spent = null): ?Response {
		return $this->store->consume($row, $this->time->getTime()) ? null : $this->refuse(Http::STATUS_GONE, $spent ?? FilesMessages::tokenSpent());
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
