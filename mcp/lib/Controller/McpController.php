<?php
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\Http\McpResponse;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\L10n\UserL10n;
use OCA\Mcp\OAuth\AccessTokenAuthenticator;
use OCA\Mcp\OAuth\ResourceUrl;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\McpProtocol;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;

/**
 * The MCP Streamable HTTP endpoint (stateless, no SSE). Authentication happens inside the controller so that
 * anonymous callers get a 401 with WWW-Authenticate instead of a login redirect; the connection policy is
 * re-checked on every request. Identity comes from Nextcloud (Basic + app password, Nextcloud token) or from an
 * OAuth access token issued by this app; without credentials the 401 carries the OAuth Bearer challenge.
 */
class McpController extends Controller {
    /** @var IUser|null owner of the Bearer token written into the session by this request, removed when it ends */
    private ?IUser $boundUser = null;

    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private IURLGenerator $urlGenerator,
        private GrantPolicy $policy,
        private McpProtocol $protocol,
        private ResourceUrl $resourceUrl,
        private AccessTokenAuthenticator $tokens,
        private UserL10n $l10n,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Receives one JSON-RPC message.
     *
     * @return McpResponse 200/202 with the JSON-RPC result, or 400/401/403/406/413 without a body
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function post(): McpResponse {
        try {
            if ($failure = $this->preflight()) {
                return $failure;
            }
            $contentType = strtolower(trim(explode(';', $this->request->getHeader('Content-Type'))[0]));
            $accept = strtolower($this->request->getHeader('Accept'));
            if ($contentType !== 'application/json' || !str_contains($accept, 'application/json') || !str_contains($accept, 'text/event-stream')) {
                return new McpResponse('', 406);
            }
            $raw = $this->readBody();
            if (!is_string($raw) || strlen($raw) > 1048576) {
                return new McpResponse('', 413);
            }
            $result = $this->protocol->handle($raw, $this->request->getHeader('MCP-Protocol-Version'), $this->userSession->getUser()->getUID(), [
                'method' => $this->request->getHeader('Mcp-Method'),
                'name' => $this->request->getHeader('Mcp-Name'),
            ]);
            return new McpResponse($result['body'] === null ? '' : json_encode($result['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $result['status']);
        } finally {
            $this->releaseSession();
            Translator::reset();
        }
    }

    /** @return McpResponse 405: no SSE stream is offered (401/403 first when access is denied) */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function get(): McpResponse {
        try {
            return $this->preflight() ?? new McpResponse('', 405);
        } finally {
            $this->releaseSession();
        }
    }

    /** @return McpResponse 405: there are no sessions to terminate (401/403 first when access is denied) */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function delete(): McpResponse {
        try {
            return $this->preflight() ?? new McpResponse('', 405);
        } finally {
            $this->releaseSession();
        }
    }

    /**
     * IRequest does not expose the raw body; php://input stays readable after Nextcloud parsed JSON params.
     *
     * @return string|false at most 1 MiB + 1 byte of the body, false when unreadable
     */
    protected function readBody(): string|false {
        return file_get_contents('php://input', false, null, 0, 1048577);
    }

    /** @return McpResponse|null a rejection for foreign Origin, missing identity or denied policy; null to proceed, with the translator set to the user's language */
    private function preflight(): ?McpResponse {
        Translator::reset();
        $origin = $this->request->getHeader('Origin');
        if ($origin !== '') {
            $endpoint = $this->urlGenerator->linkToRouteAbsolute('mcp.mcp.post');
            $expected = parse_url($endpoint);
            $actual = parse_url($origin);
            if (!is_array($actual) || !is_array($expected)
                || strtolower($actual['scheme'] ?? '') !== strtolower($expected['scheme'] ?? '')
                || strtolower($actual['host'] ?? '') !== strtolower($expected['host'] ?? '')
                || ($actual['port'] ?? null) !== ($expected['port'] ?? null)
                || isset($actual['user']) || isset($actual['pass']) || isset($actual['path'])) {
                return new McpResponse('', 403);
            }
        }
        $user = $this->userSession->getUser();
        $sessionUser = $user;
        $authorization = $this->request->getHeader('Authorization');
        $bearer = AccessTokenAuthenticator::isOwnBearer($authorization);
        if ($bearer) {
            $user = $this->tokens->authenticate($authorization, $this->resourceUrl->base());
            if ($user === null || ($sessionUser !== null && $sessionUser->getUID() !== $user->getUID())) {
                return $this->unauthorized('Bearer error="invalid_token", ' . $this->bearerParameters());
            }
        }
        if ($user === null || !$user->isEnabled()) {
            // Basic stays the challenge for clients that sent Basic; everyone else is pointed to OAuth discovery.
            return $this->unauthorized(str_starts_with($authorization, 'Basic ')
                ? 'Basic realm="Nextcloud MCP"'
                : 'Bearer ' . $this->bearerParameters());
        }
        if (!$this->policy->canConnect($user->getUID())) {
            return new McpResponse('', 403);
        }
        if ($bearer) {
            $this->bindSession($user, $sessionUser);
        }
        // Both the Basic and the OAuth path end here with the authenticated user, whose account language wins.
        Translator::use($this->l10n->forUser($user));
        return null;
    }

    /**
     * Makes the owner of a validated Bearer token the user of this request for every app, not only for IUserSession.
     *
     * The DI container of each app hands out `userId` as `ISession::get('user_id')` (core 33,
     * `DIContainer.php:133`), and so does `OC_User::getUser()`. `setVolatileActiveUser()` only changes the active
     * user, so Deck's `CardService` and `ActivityManager` got a null `userId` and failed after writing.
     * `IUserSession::setUser()` writes `user_id` and the active user and nothing else: no event, no token, no cookie.
     * The session cookie already exists, since the core starts a session for every request, and it never becomes a
     * login: a later request carrying it is validated against an auth token for that session id, which a Bearer
     * request never creates, and is logged out. {@see releaseSession()} still removes `user_id` when the request
     * ends, so the stored session holds no uid afterwards.
     *
     * A session that already belongs to the same account (Basic with an app password, browser) keeps its own
     * `user_id`, written by the core login, and is never rewritten nor removed here.
     *
     * @param IUser $user owner of the validated token, enabled and allowed to connect
     * @param IUser|null $sessionUser user the session had before the token was checked, null for a plain Bearer request
     */
    private function bindSession(IUser $user, ?IUser $sessionUser): void {
        if ($sessionUser !== null) {
            $this->userSession->setVolatileActiveUser($user);
            return;
        }
        $this->userSession->setUser($user);
        $this->boundUser = $user;
    }

    /**
     * Removes the `user_id` written by {@see bindSession()}, so the session ends this request without a uid, while
     * the active user stays the token owner for whatever still runs in this request (logging, middlewares).
     */
    private function releaseSession(): void {
        if ($this->boundUser === null) {
            return;
        }
        $user = $this->boundUser;
        $this->boundUser = null;
        $this->userSession->setUser(null);
        $this->userSession->setVolatileActiveUser($user);
    }

    /** @return McpResponse a 401 without body carrying the given WWW-Authenticate challenge */
    private function unauthorized(string $challenge): McpResponse {
        $response = new McpResponse('', 401);
        $response->addHeader('WWW-Authenticate', $challenge);
        return $response;
    }

    /** @return string resource_metadata and scope parameters of the Bearer challenge (RFC 9728 section 5.1) */
    private function bearerParameters(): string {
        return 'resource_metadata="' . $this->resourceUrl->resourceMetadata() . '", scope="mcp"';
    }
}
