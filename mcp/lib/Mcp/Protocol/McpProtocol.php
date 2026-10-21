<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Protocol;

use OCA\Mcp\Mcp\Tools\Discovery\ToolPresentation;
use Psr\Log\LoggerInterface;

/**
 * Dual-era MCP JSON-RPC handling for a single POSTed message.
 *
 * - Legacy era (2025-03-26 … 2025-11-25): an `initialize` handshake negotiates 2025-06-18, later requests
 *   carry a known MCP-Protocol-Version header (absent means 2025-03-26).
 * - Modern era (2026-07-28): stateless; every request carries its version in the header and in
 *   params._meta, `server/discover` advertises versions and capabilities, results carry `resultType`
 *   and serverInfo in `_meta`, and list results carry cache hints.
 *
 * Authentication and connection policy are enforced by the controller before this runs.
 */
class McpProtocol {
    public function __construct(
        private ToolRequests $tools,
        private PromptRequests $prompts,
        private ResourceRequests $resources,
        private LoggerInterface $logger,
    ) {}

    /**
     * Handles one JSON-RPC message.
     *
     * @param string $raw request body as received
     * @param string $headerVersion value of MCP-Protocol-Version ('' when absent)
     * @param string $userId authenticated Nextcloud user the tools run as
     * @param array{method?:string, name?:string} $headers values of the Mcp-Method and Mcp-Name headers ('' when absent)
     * @return array{status:int, body:array<string, mixed>|null} HTTP status and JSON-RPC envelope (null for no body)
     */
    public function handle(string $raw, string $headerVersion, string $userId, array $headers = []): array {
        try {
            $message = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->reject('parse error', JsonRpcResponse::error(null, -32700, 'Parse error', 400));
        }
        if (!is_array($message) || array_is_list($message) || ($message['jsonrpc'] ?? null) !== '2.0'
            || !isset($message['method']) || !is_string($message['method'])
            || (array_key_exists('id', $message) && !is_int($message['id']) && !is_string($message['id']))) {
            return $this->reject('invalid JSON-RPC envelope', JsonRpcResponse::error(null, -32600, 'Invalid Request', 400));
        }
        $method = $message['method'];
        $id = $message['id'] ?? null;
        if ($id === null) {
            // Every client notification is acknowledged; none of them changes server state here.
            return str_starts_with($method, 'notifications/')
                ? ['status' => 202, 'body' => null]
                : $this->reject('unsupported notification', JsonRpcResponse::error(null, -32600, 'Invalid Request', 400));
        }
        $params = $message['params'] ?? [];
        if (!is_array($params) || array_is_list($params) && $params !== []) {
            return JsonRpcResponse::error($id, -32602, 'Invalid params');
        }
        $metaVersion = $params['_meta'][ProtocolMetadata::META_VERSION] ?? null;
        if ($method !== 'initialize' && ($headerVersion === ProtocolMetadata::MODERN_VERSION || is_string($metaVersion) || $method === 'server/discover')) {
            return $this->modern($id, $method, $params, $headerVersion, is_string($metaVersion) ? $metaVersion : null, $headers, $userId);
        }
        return $this->legacy($id, $method, $params, $headerVersion, $userId);
    }

    /**
     * Legacy era: initialize handshake, then requests with a known version header.
     *
     * @param array<string, mixed> $params
     * @return array{status:int, body:array<string, mixed>|null}
     */
    private function legacy(int|string $id, string $method, array $params, string $headerVersion, string $userId): array {
        // initialize negotiates through params.protocolVersion, so its header is ignored; later requests
        // may carry any known version, and a missing header means the transport's default.
        $version = $headerVersion === '' ? ProtocolMetadata::DEFAULT_HEADER_VERSION : $headerVersion;
        if ($method !== 'initialize' && !in_array($version, ProtocolMetadata::KNOWN_VERSIONS, true)) {
            return $this->unsupportedVersion($id, $method, $headerVersion);
        }
        if ($method === 'initialize') {
            if (!is_string($params['protocolVersion'] ?? null) || !isset($params['clientInfo']['name'], $params['clientInfo']['version'])) {
                return JsonRpcResponse::error($id, -32602, 'Invalid initialize params');
            }
            // Version negotiation: always answer with the single supported legacy version.
            return JsonRpcResponse::result($id, [
                'protocolVersion' => ProtocolMetadata::VERSION,
                'capabilities' => ['tools' => new \stdClass(), 'prompts' => new \stdClass(), 'resources' => new \stdClass()],
                'serverInfo' => ProtocolMetadata::SERVER_INFO,
                'instructions' => ToolPresentation::INSTRUCTIONS,
            ]);
        }
        if ($method === 'ping') {
            return JsonRpcResponse::result($id, new \stdClass());
        }
        if ($method === 'tools/list') {
            return JsonRpcResponse::result($id, ['tools' => $this->tools->list($userId)]);
        }
        if ($method === 'tools/call') {
            return $this->tools->call($id, $params, $userId, false);
        }
        if ($method === 'prompts/list') {
            return JsonRpcResponse::result($id, ['prompts' => $this->prompts->list($userId)]);
        }
        if ($method === 'prompts/get') {
            return $this->prompts->get($id, $params, $userId, false);
        }
        if ($method === 'resources/list') {
            return $this->resources->list($id, $params, $userId, false);
        }
        if ($method === 'resources/templates/list') {
            return $this->resources->templates($id, $params, $userId, false);
        }
        if ($method === 'resources/read') {
            return $this->resources->read($id, $params, $userId, false);
        }
        return JsonRpcResponse::error($id, -32601, 'Method not found');
    }

    /**
     * Modern era (2026-07-28): stateless requests validated against the mirrored headers.
     *
     * @param array<string, mixed> $params
     * @param array{method?:string, name?:string} $headers
     * @return array{status:int, body:array<string, mixed>|null}
     */
    private function modern(int|string $id, string $method, array $params, string $headerVersion, ?string $metaVersion, array $headers, string $userId): array {
        $requested = $metaVersion ?? $headerVersion;
        // Discovery answers any caller, so a legacy or unversioned probe still learns the supported versions.
        if ($requested !== ProtocolMetadata::MODERN_VERSION && !($method === 'server/discover' && ($requested === '' || in_array($requested, ProtocolMetadata::KNOWN_VERSIONS, true)))) {
            return $this->unsupportedVersion($id, $method, $requested);
        }
        if ($headerVersion !== '' && $metaVersion !== null && $headerVersion !== $metaVersion) {
            return $this->headerMismatch($id, $method, 'MCP-Protocol-Version');
        }
        // Mirrored headers are compared when present; a missing one is tolerated (and logged) for interoperability.
        $methodHeader = $headers['method'] ?? '';
        if ($methodHeader !== '' && $methodHeader !== $method) {
            return $this->headerMismatch($id, $method, 'Mcp-Method');
        }
        if ($method === 'tools/call') {
            $nameHeader = self::decodeHeader($headers['name'] ?? '');
            if ($nameHeader !== '' && $nameHeader !== ($params['name'] ?? null)) {
                return $this->headerMismatch($id, $method, 'Mcp-Name');
            }
        }
        if ($methodHeader === '') {
            $this->logger->debug('MCP modern request without Mcp-Method header', ['app' => 'mcp', 'method' => mb_substr($method, 0, 64)]);
        }
        $meta = [ProtocolMetadata::META_SERVER_INFO => ProtocolMetadata::SERVER_INFO];
        return match ($method) {
            'server/discover' => JsonRpcResponse::result($id, [
                'resultType' => 'complete',
                'supportedVersions' => ProtocolMetadata::SUPPORTED_VERSIONS,
                // The prompt set is static, so no listChanged notification is ever needed.
                'capabilities' => ['tools' => new \stdClass(), 'prompts' => ['listChanged' => false], 'resources' => new \stdClass()],
                // DiscoverResult carries the same display guidance the legacy initialize does.
                'instructions' => ToolPresentation::INSTRUCTIONS,
                // Discovery is only answered to an authenticated user, so shared caches must not keep it.
                'ttlMs' => 0,
                'cacheScope' => 'private',
                '_meta' => $meta,
            ]),
            'tools/list' => JsonRpcResponse::result($id, [
                'resultType' => 'complete',
                'tools' => $this->tools->list($userId),
                // Grants change the list per user and apply on the next request.
                'ttlMs' => 0,
                'cacheScope' => 'private',
                '_meta' => $meta,
            ]),
            'tools/call' => $this->tools->call($id, $params, $userId, true),
            'prompts/list' => JsonRpcResponse::result($id, [
                'resultType' => 'complete',
                'prompts' => $this->prompts->list($userId),
                // Prompts follow the user's grants, so a shared cache must not keep them.
                'ttlMs' => 0,
                'cacheScope' => 'private',
                '_meta' => $meta,
            ]),
            'prompts/get' => $this->prompts->get($id, $params, $userId, true),
            'resources/list' => $this->resources->list($id, $params, $userId, true),
            'resources/templates/list' => $this->resources->templates($id, $params, $userId, true),
            'resources/read' => $this->resources->read($id, $params, $userId, true),
            default => JsonRpcResponse::error($id, -32601, 'Method not found', 404),
        };
    }

    /** @return array{status:int, body:array<string, mixed>} 400 UnsupportedProtocolVersionError listing the supported versions */
    private function unsupportedVersion(int|string $id, string $method, string $requested): array {
        $shown = preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested) === 1 ? $requested : 'malformed';
        return $this->reject('unsupported MCP-Protocol-Version', [
            'status' => 400,
            'body' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => [
                'code' => ProtocolMetadata::UNSUPPORTED_PROTOCOL_VERSION,
                'message' => 'Unsupported protocol version',
                'data' => ['supported' => ProtocolMetadata::SUPPORTED_VERSIONS, 'requested' => $shown],
            ]],
        ], ['method' => mb_substr($method, 0, 64), 'version' => $shown]);
    }

    /** @return array{status:int, body:array<string, mixed>} 400 HeaderMismatch naming the header */
    private function headerMismatch(int|string $id, string $method, string $header): array {
        return $this->reject('header mismatch', JsonRpcResponse::error($id, ProtocolMetadata::HEADER_MISMATCH, 'Header mismatch: ' . $header, 400),
            ['method' => mb_substr($method, 0, 64), 'header' => $header]);
    }

    /** Decodes the `=?base64?…?=` sentinel of mirrored header values. */
    private static function decodeHeader(string $value): string {
        if (preg_match('/^=\?base64\?([A-Za-z0-9+\/=]*)\?=$/', $value, $m) === 1) {
            $decoded = base64_decode($m[1], true);
            return $decoded === false ? "\0invalid" : $decoded;
        }
        return $value;
    }

    /**
     * Logs why a request got HTTP 400 at debug level: the reason and method only, never body or auth headers.
     *
     * @param string $reason fixed description of the rejection
     * @param array{status:int, body:array<string, mixed>|null} $response response being returned
     * @param array<string, string> $context extra safe context
     * @return array{status:int, body:array<string, mixed>|null} the same response
     */
    private function reject(string $reason, array $response, array $context = []): array {
        $this->logger->debug('MCP request rejected: ' . $reason, ['app' => 'mcp'] + $context);
        return $response;
    }
}
