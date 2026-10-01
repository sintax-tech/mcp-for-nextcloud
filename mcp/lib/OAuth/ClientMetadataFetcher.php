<?php
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use Throwable;

/**
 * Resolves a URL-formatted client_id (OAuth Client ID Metadata Document) into ClientMetadata.
 * Only HTTPS client_ids whose host is in the app config allowlist `oauth_client_hosts` (comma separated, default
 * claude.ai,chatgpt.com) are fetched. IClientService refuses local/private addresses (SSRF); documents are size-limited and
 * cached for up to an hour.
 */
class ClientMetadataFetcher {
    public const HOSTS_KEY = 'oauth_client_hosts';
    public const DEFAULT_HOSTS = 'claude.ai,chatgpt.com';
    private const MAX_BYTES = 65536;
    private const CACHE_TTL = 3600;

    private ICache $cache;

    public function __construct(
        private IClientService $clientService,
        private IConfig $config,
        ICacheFactory $cacheFactory,
    ) {
        $this->cache = $cacheFactory->createDistributed('mcp_oauth_cimd');
    }

    /**
     * @param string $clientId client_id from the authorization request
     * @return ClientMetadata the validated document
     * @throws OAuthException invalid_client (never redirectable) when the id is not allowed or the document is invalid
     */
    public function fetch(string $clientId): ClientMetadata {
        if (!$this->allowed($clientId)) {
            throw new OAuthException('invalid_client', 'Unknown or not allowed client');
        }
        $key = hash('sha256', $clientId);
        $cached = $this->cache->get($key);
        $document = is_string($cached) ? $cached : $this->download($clientId);
        $metadata = $this->parse($clientId, $document);
        if (!is_string($cached)) {
            $this->cache->set($key, $document, self::CACHE_TTL);
        }
        return $metadata;
    }

    /**
     * Runs before the cache lookup, so removing a host from the allowlist blocks it even with a cached document.
     *
     * @param string $clientId client_id from the authorization request
     * @return bool true for an https URL with a path, no credentials/fragment, and an allowlisted host
     */
    private function allowed(string $clientId): bool {
        $parts = parse_url($clientId);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || trim($parts['path'] ?? '', '/') === '') {
            return false;
        }
        $hosts = array_filter(array_map('trim', explode(',', strtolower(
            $this->config->getAppValue('mcp', self::HOSTS_KEY, self::DEFAULT_HOSTS)))));
        return in_array(strtolower($parts['host'] ?? ''), $hosts, true);
    }

    /**
     * Downloads an allowlisted client metadata document without following redirects.
     *
     * @param string $clientId validated HTTPS metadata document URL
     * @return string metadata document body within the configured size limit
     * @throws OAuthException when the document cannot be fetched or exceeds the size limit
     */
    private function download(string $clientId): string {
        $stream = null;
        try {
            $response = $this->clientService->newClient()->get($clientId, [
                'timeout' => 5,
                'allow_redirects' => false,
                // OCP IResponse returns a resource with stream enabled, avoiding eager buffering.
                'stream' => true,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $stream = $response->getBody();
            $length = $response->getHeader('Content-Length');
            if ($response->getStatusCode() !== 200 || !is_resource($stream)
                || ($length !== '' && (!ctype_digit($length) || (float)$length > self::MAX_BYTES))) {
                throw new \RuntimeException('Invalid metadata response');
            }
            $body = stream_get_contents($stream, self::MAX_BYTES + 1);
            if ($body === false || strlen($body) > self::MAX_BYTES) {
                throw new \RuntimeException('Metadata exceeds size limit');
            }
        } catch (Throwable) {
            throw new OAuthException('invalid_client', 'Client metadata document unavailable');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        return $body;
    }

    /**
     * Checks that the client can authenticate as a public client (`none`). The plural list wins when present
     * (a non-empty list of strings containing `none`); the singular is only consulted when the plural is missing.
     *
     * @param array<string,mixed> $data decoded metadata document
     * @return bool true when the document is compatible with a public client
     */
    private static function publicClientMethods(array $data): bool {
        if (array_key_exists('token_endpoint_auth_methods_supported', $data)) {
            $methods = $data['token_endpoint_auth_methods_supported'];
            return is_array($methods) && array_is_list($methods) && $methods !== [] && $methods === array_filter($methods, 'is_string')
                && in_array('none', $methods, true);
        }
        return !isset($data['token_endpoint_auth_method']) || $data['token_endpoint_auth_method'] === 'none';
    }

    /**
     * Validates the metadata document identity, redirect list, and public-client authentication method.
     *
     * @param string $clientId expected client identifier from the authorization request
     * @param string $document downloaded JSON metadata document
     * @return ClientMetadata validated client metadata
     * @throws OAuthException when the document is malformed or does not describe this client
     */
    private function parse(string $clientId, string $document): ClientMetadata {
        $data = json_decode($document, true);
        if (!is_array($data) || ($data['client_id'] ?? null) !== $clientId
            || !is_array($data['redirect_uris'] ?? null) || $data['redirect_uris'] === []
            || !self::publicClientMethods($data)) {
            throw new OAuthException('invalid_client', 'Invalid client metadata document');
        }
        $uris = array_values(array_filter($data['redirect_uris'], 'is_string'));
        $name = is_string($data['client_name'] ?? null) && trim($data['client_name']) !== ''
            ? mb_substr(trim($data['client_name']), 0, 100) : (string)parse_url($clientId, PHP_URL_HOST);
        return new ClientMetadata($clientId, $name, $uris);
    }
}
