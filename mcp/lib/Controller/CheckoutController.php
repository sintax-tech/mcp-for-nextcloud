<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;
use OCA\Mcp\Access\Policy\PolicyUnavailable;

use OCA\Mcp\Modules\Files\Checkout\CheckoutToken;
use OCA\Mcp\Modules\Files\Checkout\CheckoutTokenStore;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\L10n\UserL10n;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Modules\Files\Checkout\CheckoutService;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;
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
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private CheckoutTokenStore $store,
        private TokenHasher $hasher,
        private CheckoutService $checkout,
        private \OCA\Mcp\Modules\Files\Checkout\CheckoutPolicy $checkoutPolicy,
        private \OCA\Mcp\Modules\Files\Checkout\CheckoutWriter $writer,
        private \OCA\Mcp\Modules\Files\Checkout\Http\BodyReader $bodyReader,
        private LoggerInterface $logger,
        private ?UserL10n $l10n = null,
        private ?IUserManager $userManager = null,
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
            $row = $this->store->find($this->hasher->hash($this->routeToken() ?? ''));
            return $this->checkoutPolicy->within($row, function () use ($row): Response {
                $opened = $this->checkoutPolicy->resolve($row, CheckoutToken::KIND_DOWNLOAD, $this->configureOwnerLanguage(...));
                $row = $opened->token;
                $file = $opened->file;
                try {
                    NodeAccess::checkEtag($file, $row->etag);
                } catch (ToolFailure) {
                    return $this->refuse(Http::STATUS_CONFLICT, FileAccessMessages::conflict());
                }
                $this->checkoutPolicy->spend($row);
                $handle = $file->fopen('r');
                if ($handle === false) {
                    return $this->refuse(Http::STATUS_NOT_FOUND, \OCA\Mcp\Modules\Files\Checkout\CheckoutMessages::tokenInvalid());
                }
                // filename*=UTF-8'' is the form that survives a name with spaces or accents; the plain filename
                // is the percent-encoded fallback for clients that only understand RFC 6266.
                $name = $file->getName();
                return new StreamResponse($handle, Http::STATUS_OK, [
                    'Content-Type' => (string)$file->getMimetype(),
                    'Content-Disposition' => sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', rawurlencode($name), rawurlencode($name)),
                    'Cache-Control' => 'no-store',
                ]);
            });
        } catch (PolicyUnavailable) {
            $response = $this->refuse(Http::STATUS_SERVICE_UNAVAILABLE, \OCA\Mcp\Modules\Files\Checkout\CheckoutMessages::checkoutUnavailable());
            $response->addHeader('Retry-After', '1');
            return $response;
        } catch (\OCA\Mcp\Modules\Files\Checkout\CheckoutFailure $e) {
            return $this->failure($e);
        } finally {
            Translator::reset();
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
                return $this->refuse(Http::STATUS_NOT_FOUND, \OCA\Mcp\Modules\Files\Checkout\CheckoutMessages::tokenInvalid());
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
            $this->checkoutPolicy->assertLink($row, $create ? CheckoutToken::KIND_CREATE : CheckoutToken::KIND_UPLOAD);
            // Storage failures do not spend a link; the body owner releases staged bytes on every exit.
            return $this->bodyReader->withStoredBody($limit, fn (string $body): Response => $this->checkoutPolicy->within($row, function () use ($create, $row, $body): Response {
                $opened = $this->checkoutPolicy->resolve($row, $create ? CheckoutToken::KIND_CREATE : CheckoutToken::KIND_UPLOAD, $this->configureOwnerLanguage(...));
                return $create
                    ? $this->json(Http::STATUS_CREATED, $this->writer->create($opened->token, $body))
                    : $this->json(Http::STATUS_OK, $this->writer->replace($opened->token, $opened->file, $body));
            })) ?? $this->refuse(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, \OCA\Mcp\Modules\Files\Creation\CreationMessages::uploadTooLarge($limit));
        } catch (PolicyUnavailable) {
            $response = $this->refuse(Http::STATUS_SERVICE_UNAVAILABLE, \OCA\Mcp\Modules\Files\Checkout\CheckoutMessages::checkoutUnavailable());
            $response->addHeader('Retry-After', '1');
            return $response;
        } catch (\OCA\Mcp\Modules\Files\Checkout\CheckoutFailure $e) {
            return $this->failure($e);
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
            return $this->refuse(Http::STATUS_BAD_REQUEST, \OCA\Mcp\Modules\Files\Checkout\CheckoutMessages::uploadMultipart());
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
            return $this->refuse(Http::STATUS_BAD_REQUEST, \OCA\Mcp\Modules\Files\Checkout\CheckoutMessages::uploadEmpty());
        }
        if ($declared > $limit) {
            return $this->refuse(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, \OCA\Mcp\Modules\Files\Creation\CreationMessages::uploadTooLarge($limit));
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
    private function configureOwnerLanguage(string $uid): void {
        if ($this->l10n !== null && $this->userManager !== null && ($user = $this->userManager->get($uid)) !== null) {
            Translator::use($this->l10n->forUser($user));
        }
    }

    private function failure(\OCA\Mcp\Modules\Files\Checkout\CheckoutFailure $failure): Response {
        return $failure->payload !== []
            ? $this->json($failure->status, $failure->payload)
            : $this->refuse($failure->status, $failure->getMessage());
    }
}
