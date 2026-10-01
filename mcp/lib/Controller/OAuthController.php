<?php
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\OAuth\AuthorizationRequest;
use OCA\Mcp\OAuth\AuthorizationValidator;
use OCA\Mcp\OAuth\OAuthException;
use OCA\Mcp\OAuth\RedirectUriMatcher;
use OCA\Mcp\OAuth\ResourceUrl;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Service\GrantPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * OAuth authorization and token endpoints. The consent page requires a Nextcloud login (unauthenticated users are
 * sent to the login page and back); the pending request lives in the session and the consent POST is protected by
 * the Nextcloud CSRF token. Allowing counts as the user's personal MCP activation.
 */
class OAuthController extends Controller {
    private const PENDING_KEY = 'mcp_oauth_pending';

    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private ISession $session,
        private IURLGenerator $urlGenerator,
        private AuthorizationValidator $validator,
        private TokenService $tokens,
        private GrantPolicy $policy,
        private ResourceUrl $urls,
    ) {
        parent::__construct($appName, $request);
    }

    /** @return TemplateResponse|RedirectResponse the consent page, a local error page, or a redirectable error */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UseSession]
    public function authorize(): TemplateResponse|RedirectResponse {
        try {
            $authRequest = $this->validator->validate($this->request->getParams(), $this->urls->base());
        } catch (OAuthException $e) {
            if ($e->redirectable) {
                return new RedirectResponse($this->redirectableError($e));
            }
            return $this->page(['error' => $e->getMessage()]);
        }
        $user = $this->userSession->getUser();
        $uid = $user->getUID();
        if (!$this->policy->globalEnabled() || !$this->policy->eligible($uid)) {
            return $this->page(['blocked' => true, 'client' => $authRequest->client->name]);
        }
        $pendingId = bin2hex(random_bytes(16));
        $this->session->set(self::PENDING_KEY, [$pendingId => $authRequest->toArray()]);
        $response = $this->page([
            'pending' => $pendingId,
            'client' => $authRequest->client->name,
            'clientHost' => $authRequest->client->host(),
            'redirectHost' => (string)parse_url($authRequest->redirectUri, PHP_URL_HOST),
            'loopback' => RedirectUriMatcher::isLoopback($authRequest->redirectUri),
            'account' => $user->getDisplayName(),
            'action' => $this->urlGenerator->linkToRoute('mcp.o_auth.consent'),
        ]);
        // The consent POST answers with a redirect to the client; form-action must allow its origin.
        $csp = new ContentSecurityPolicy();
        $csp->addAllowedFormActionDomain($this->origin($authRequest->redirectUri));
        $response->setContentSecurityPolicy($csp);
        return $response;
    }

    /**
     * @param string $pending id of the pending request shown on the consent page
     * @param string $decision allow or deny
     * @return TemplateResponse|RedirectResponse redirect to the client with code or access_denied; local error page
     */
    #[NoAdminRequired]
    #[UseSession]
    public function consent(string $pending = '', string $decision = ''): TemplateResponse|RedirectResponse {
        $stored = $this->session->get(self::PENDING_KEY);
        $this->session->remove(self::PENDING_KEY);
        if (!is_array($stored) || !is_array($stored[$pending] ?? null)) {
            return $this->page(['error' => 'The authorization request expired. Start the connection again.']);
        }
        $authRequest = AuthorizationRequest::fromArray($stored[$pending]);
        $uid = $this->userSession->getUser()->getUID();
        if ($decision !== 'allow') {
            return new RedirectResponse($authRequest->redirectWith(['error' => 'access_denied']));
        }
        if (!$this->policy->globalEnabled() || !$this->policy->eligible($uid)) {
            return $this->page(['blocked' => true, 'client' => $authRequest->client->name]);
        }
        $this->policy->setConnected($uid, true);
        return new RedirectResponse($authRequest->redirectWith(['code' => $this->tokens->createCode($authRequest, $uid)]));
    }

    /** @return JSONResponse token response (no-store) or an RFC 6749 error; failed grants are throttled */
    #[PublicPage]
    #[NoCSRFRequired]
    #[BruteForceProtection(action: 'mcp_oauth_token')]
    public function token(): JSONResponse {
        $params = $this->request->getParams();
        try {
            $body = match ($params['grant_type'] ?? '') {
                'authorization_code' => $this->tokens->exchangeCode($params),
                'refresh_token' => $this->tokens->refresh($params),
                default => throw new OAuthException('unsupported_grant_type', 'Unsupported grant_type'),
            };
            $response = new JSONResponse($body);
        } catch (OAuthException $e) {
            $response = new JSONResponse(['error' => $e->error, 'error_description' => $e->getMessage()], 400);
            if ($e->error === 'invalid_grant') {
                $response->throttle(['action' => 'mcp_oauth_token']);
            }
        }
        $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('Pragma', 'no-cache');
        return $response;
    }

    /** @param array<string,mixed> $params */
    private function page(array $params): TemplateResponse {
        return new TemplateResponse($this->appName, 'consent', $params + [
            'error' => null, 'blocked' => false, 'pending' => null,
        ], TemplateResponse::RENDER_AS_GUEST);
    }

    /**
     * Builds an OAuth error redirect after the authorization request has validated its redirect URI.
     *
     * @param OAuthException $e safe protocol error to return to the client
     * @return string redirect URI with error details and any supplied state
     */
    private function redirectableError(OAuthException $e): string {
        $params = $this->request->getParams();
        $redirectUri = (string)$params['redirect_uri'];
        $query = ['error' => $e->error, 'error_description' => $e->getMessage()];
        if (is_string($params['state'] ?? null) && $params['state'] !== '') {
            $query['state'] = $params['state'];
        }
        return $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($query);
    }

    /**
     * Extracts the origin used to scope consent and authorization decisions.
     *
     * @param string $uri absolute URI whose scheme, host, and port form the origin
     * @return string URI origin, defaulting to HTTPS when the scheme is absent
     */
    private function origin(string $uri): string {
        $parts = parse_url($uri);
        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
