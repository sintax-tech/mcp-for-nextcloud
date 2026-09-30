<?php
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\Http\McpResponse;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\McpProtocol;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class McpController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private IURLGenerator $urlGenerator,
        private GrantPolicy $policy,
        private McpProtocol $protocol,
    ) {
        parent::__construct($appName, $request);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function post(): McpResponse {
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
        $result = $this->protocol->handle($raw, $this->request->getHeader('MCP-Protocol-Version'));
        return new McpResponse($result['body'] === null ? '' : json_encode($result['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $result['status']);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function get(): McpResponse {
        return $this->preflight() ?? new McpResponse('', 405);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function delete(): McpResponse {
        return $this->preflight() ?? new McpResponse('', 405);
    }

    /** IRequest does not expose the raw body; php://input stays readable after Nextcloud parsed JSON params. */
    protected function readBody(): string|false {
        return file_get_contents('php://input', false, null, 0, 1048577);
    }

    private function preflight(): ?McpResponse {
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
        if ($user === null || !$user->isEnabled()) {
            $response = new McpResponse('', 401);
            $response->addHeader('WWW-Authenticate', 'Basic realm="Nextcloud MCP"');
            return $response;
        }
        if (!$this->policy->canConnect($user->getUID())) {
            return new McpResponse('', 403);
        }
        return null;
    }
}
