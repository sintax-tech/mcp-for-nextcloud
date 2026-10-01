<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Contacts;

use Closure;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\Calendar\CapturingSapi;
use OCA\Mcp\Tools\Calendar\DavResult;
use OCA\Mcp\Tools\Calendar\Session;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\ToolFailure;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Exception as DavException;
use Sabre\DAV\Server;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use Throwable;

/** Native CardDAV request dispatch, with fresh plugin state and the acting session pinned. */
class EmbeddedCardDavDispatcher implements ContactDav {
    /** Principal prefix shared by the authenticated user and native DAV ACL. */
    private const PRINCIPAL_PREFIX = 'principals/users/';

    /**
     * Receives a fresh-server factory, authenticated session, size policy and DAV logger.
     *
     * @param Closure(): Server $serverFactory factory providing a fresh native DAV server for every request
     * @param Session $session authenticated Nextcloud session
     * @param IAppConfig $config Nextcloud configuration
     * @param LoggerInterface $logger logger for DAV refusal diagnostics
     * @return void
     */
    public function __construct(
        private Closure $serverFactory,
        private Session $session,
        private IAppConfig $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * Creates a contact through native CardDAV without overwriting an existing object.
     *
     * @param string $userId authenticated user UID
     * @param string $bookUri address-book URI in the acting user home
     * @param string $uri contact object URI within the address book
     * @param string $vcard complete serialized vCard
     * @return DavResult
     * @throws ToolFailure when native DAV rejects the path, ACL, data or precondition
     * @throws RuntimeException when the authenticated session or native response is unexpected
     */
    public function put(string $userId, string $bookUri, string $uri, string $vcard): DavResult {
        return $this->dispatch('PUT', $userId, $bookUri, $uri, ['If-None-Match' => '*'], $vcard, 201);
    }

    /**
     * Updates a contact through native CardDAV using its current ETag.
     *
     * @param string $userId authenticated user UID
     * @param string $bookUri address-book URI in the acting user home
     * @param string $uri contact object URI within the address book
     * @param string $etag current object ETag required by If-Match
     * @param string $vcard complete serialized vCard
     * @return DavResult
     * @throws ToolFailure when native DAV rejects the path, ACL, data or precondition
     * @throws RuntimeException when the authenticated session or native response is unexpected
     */
    public function update(string $userId, string $bookUri, string $uri, string $etag, string $vcard): DavResult {
        return $this->dispatch('PUT', $userId, $bookUri, $uri, ['If-Match' => $etag], $vcard, 204);
    }

    /**
     * Permanently deletes a contact through native CardDAV after the caller verifies its backup.
     *
     * @param string $userId authenticated user UID
     * @param string $bookUri address-book URI in the acting user home
     * @param string $uri contact object URI within the address book
     * @param string $etag current object ETag required by If-Match
     * @return DavResult
     * @throws ToolFailure when native DAV rejects the path, ACL, data or precondition
     * @throws RuntimeException when the authenticated session or native response is unexpected
     */
    public function delete(string $userId, string $bookUri, string $uri, string $etag): DavResult {
        return $this->dispatch('DELETE', $userId, $bookUri, $uri, ['If-Match' => $etag], null, 204);
    }

    /**
     * Dispatches one native request with the acting principal pinned and fresh plugin state.
     *
     * @param string $method HTTP write method
     * @param string $userId authenticated UID
     * @param string $bookUri address-book URI
     * @param string $uri contact object URI
     * @param array<string, string> $headers HTTP preconditions
     * @param string|null $body complete vCard, or null for deletion
     * @param int $status expected native response status
     * @return DavResult
     * @throws ToolFailure when size, path, native ACL, validation or precondition checks refuse the write
     * @throws RuntimeException when the session or native server response is unexpected
     */
    private function dispatch(
        string $method,
        string $userId,
        string $bookUri,
        string $uri,
        array $headers,
        ?string $body,
        int $status,
    ): DavResult {
        if ($this->session->uid() !== $userId) {
            throw new RuntimeException('CardDAV session mismatch');
        }
        foreach ([$userId, $bookUri, $uri] as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $segment)) {
                throw new ToolFailure(CommonMessages::notFound());
            }
        }
        if ($body !== null && strlen($body) > $this->config->getValueInt('dav', 'card_size_limit', 5242880)) {
            throw new ToolFailure(Translator::t('The contact exceeds the server vCard size limit.'));
        }
        CapturingSapi::reset();
        $server = ($this->serverFactory)();
        $auth = $server->getPlugin('auth');
        if (!$auth instanceof AuthPlugin || !method_exists($auth, 'setCurrentPrincipal')) {
            throw new RuntimeException('CardDAV principal cannot be pinned');
        }
        $principal = self::PRINCIPAL_PREFIX . $userId;
        $auth->setCurrentPrincipal($principal);
        if ($auth->getCurrentPrincipal() !== $principal) {
            throw new RuntimeException('CardDAV principal mismatch');
        }
        $base = $server->getBaseUri();
        $path = 'addressbooks/users/' . rawurlencode($userId) . '/' . rawurlencode($bookUri) . '/' . rawurlencode($uri);
        if ($body !== null) {
            $headers += [
                'Content-Type' => 'text/vcard; charset=utf-8',
                'Content-Length' => (string) strlen($body),
            ];
        }
        $request = new Request($method, $base . $path, $headers, $body);
        $request->setBaseUrl($base);
        $response = new Response();
        $server->httpRequest = $request;
        $server->httpResponse = $response;
        $server->sapi = new CapturingSapi();
        try {
            $server->invokeMethod($request, $response, false);
        } catch (DavException $error) {
            $this->logger->error(
                'MCP CardDAV write refused',
                [
                    'app' => 'mcp',
                    'exception_class' => $error::class,
                    'http_code' => $error->getHTTPCode(),
                ]
            );
            $message = match ($error->getHTTPCode()) {
                403 => CommonMessages::forbidden(),
                404 => CommonMessages::notFound(),
                409, 412 => CommonMessages::conflict(),
                400, 415 => Translator::t('The server refused the contact data.'),
                default => Translator::t('Unexpected error while accessing Nextcloud.'),
            };
            throw new ToolFailure($message, $error->getHTTPCode(), $error);
        } catch (Throwable $error) {
            $this->logger->error(
                'MCP CardDAV dispatch failed',
                ['app' => 'mcp', 'exception_class' => $error::class]
            );
            throw new RuntimeException('CardDAV dispatch failed', 0, $error);
        }
        $actual = $response->getStatus() ?? CapturingSapi::$lastStatus;
        if ($actual !== $status) {
            throw new RuntimeException('Unexpected CardDAV status');
        }
        return new DavResult($actual, $response->getHeader('ETag'));
    }
}
