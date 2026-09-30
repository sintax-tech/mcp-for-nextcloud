<?php
declare(strict_types=1);

namespace OCA\Mcp\Service;

use OCA\Mcp\Tools\ToolPresentation;
use OCA\Mcp\Tools\ToolRegistry;
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
    /** Legacy version answered in initialize. */
    public const VERSION = '2025-06-18';
    /** Modern (stateless) protocol version. */
    public const MODERN_VERSION = '2026-07-28';
    /** Built-in diagnostic tool that reads no user data. */
    public const TOOL = 'mcp_status';
    /** MCP-Protocol-Version values accepted after a legacy initialize. */
    public const KNOWN_VERSIONS = ['2025-03-26', '2025-06-18', '2025-11-25'];
    /** Versions advertised by server/discover and UnsupportedProtocolVersionError, newest first. */
    public const SUPPORTED_VERSIONS = ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'];
    /** Version assumed when the header is absent, as the Streamable HTTP transport specifies. */
    public const DEFAULT_HEADER_VERSION = '2025-03-26';
    /** _meta key of the version a modern request uses. */
    public const META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    /** _meta key of the server identity in modern results. */
    public const META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';
    /** Server identity reported in initialize and modern results. */
    public const SERVER_INFO = ['name' => 'nextcloud-mcp', 'version' => '0.6.3'];
    /** HeaderMismatch error code of the 2026-07-28 specification. */
    public const HEADER_MISMATCH = -32020;
    /** UnsupportedProtocolVersion error code of the 2026-07-28 specification. */
    public const UNSUPPORTED_PROTOCOL_VERSION = -32022;

    public function __construct(
        private ToolRegistry $tools,
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
            return $this->reject('parse error', $this->error(null, -32700, 'Parse error', 400));
        }
        if (!is_array($message) || array_is_list($message) || ($message['jsonrpc'] ?? null) !== '2.0'
            || !isset($message['method']) || !is_string($message['method'])
            || (array_key_exists('id', $message) && !is_int($message['id']) && !is_string($message['id']))) {
            return $this->reject('invalid JSON-RPC envelope', $this->error(null, -32600, 'Invalid Request', 400));
        }
        $method = $message['method'];
        $id = $message['id'] ?? null;
        if ($id === null) {
            // Every client notification is acknowledged; none of them changes server state here.
            return str_starts_with($method, 'notifications/')
                ? ['status' => 202, 'body' => null]
                : $this->reject('unsupported notification', $this->error(null, -32600, 'Invalid Request', 400));
        }
        $params = $message['params'] ?? [];
        if (!is_array($params) || array_is_list($params) && $params !== []) {
            return $this->error($id, -32602, 'Invalid params');
        }
        $metaVersion = $params['_meta'][self::META_VERSION] ?? null;
        if ($method !== 'initialize' && ($headerVersion === self::MODERN_VERSION || is_string($metaVersion) || $method === 'server/discover')) {
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
        $version = $headerVersion === '' ? self::DEFAULT_HEADER_VERSION : $headerVersion;
        if ($method !== 'initialize' && !in_array($version, self::KNOWN_VERSIONS, true)) {
            return $this->unsupportedVersion($id, $method, $headerVersion);
        }
        if ($method === 'initialize') {
            if (!is_string($params['protocolVersion'] ?? null) || !isset($params['clientInfo']['name'], $params['clientInfo']['version'])) {
                return $this->error($id, -32602, 'Invalid initialize params');
            }
            // Version negotiation: always answer with the single supported legacy version.
            return $this->result($id, [
                'protocolVersion' => self::VERSION,
                'capabilities' => ['tools' => new \stdClass()],
                'serverInfo' => self::SERVER_INFO,
                'instructions' => ToolPresentation::INSTRUCTIONS,
            ]);
        }
        if ($method === 'ping') {
            return $this->result($id, new \stdClass());
        }
        if ($method === 'tools/list') {
            return $this->result($id, ['tools' => $this->toolList($userId)]);
        }
        if ($method === 'tools/call') {
            return $this->callTool($id, $params, $userId, false);
        }
        return $this->error($id, -32601, 'Method not found');
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
        if ($requested !== self::MODERN_VERSION && !($method === 'server/discover' && ($requested === '' || in_array($requested, self::KNOWN_VERSIONS, true)))) {
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
        $meta = [self::META_SERVER_INFO => self::SERVER_INFO];
        return match ($method) {
            'server/discover' => $this->result($id, [
                'resultType' => 'complete',
                'supportedVersions' => self::SUPPORTED_VERSIONS,
                'capabilities' => ['tools' => new \stdClass()],
                // DiscoverResult carries the same display guidance the legacy initialize does.
                'instructions' => ToolPresentation::INSTRUCTIONS,
                // Discovery is only answered to an authenticated user, so shared caches must not keep it.
                'ttlMs' => 0,
                'cacheScope' => 'private',
                '_meta' => $meta,
            ]),
            'tools/list' => $this->result($id, [
                'resultType' => 'complete',
                'tools' => $this->toolList($userId),
                // Grants change the list per user and apply on the next request.
                'ttlMs' => 0,
                'cacheScope' => 'private',
                '_meta' => $meta,
            ]),
            'tools/call' => $this->callTool($id, $params, $userId, true),
            default => $this->error($id, -32601, 'Method not found', 404),
        };
    }

    /** @return list<array<string, mixed>> diagnostic tool followed by the tools the user may call now */
    private function toolList(string $userId): array {
        return [[
            'name' => self::TOOL,
            'title' => ToolPresentation::title(self::TOOL),
            'description' => 'Reports whether the MCP diagnostic endpoint is running; does not access user data.',
            'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            // The diagnostic tool only reads its own endpoint, hence the read-only annotations.
            'annotations' => ToolPresentation::annotations(self::TOOL, 'read'),
        ], ...$this->tools->list($userId)];
    }

    /**
     * @param array<string, mixed> $params
     * @param bool $modern add resultType and serverInfo _meta to the result
     * @return array{status:int, body:array<string, mixed>}
     */
    private function callTool(int|string $id, array $params, string $userId, bool $modern): array {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if (!is_string($name) || !is_array($arguments)) {
            return $this->error($id, -32602, 'Invalid params');
        }
        if ($name === self::TOOL) {
            if ($arguments !== []) {
                return $this->error($id, -32602, 'Invalid arguments');
            }
            $result = ['content' => [['type' => 'text', 'text' => 'MCP endpoint available']]];
        } else {
            try {
                $result = $this->tools->call($name, $arguments, $userId);
            } catch (\InvalidArgumentException $e) {
                return $this->error($id, -32602, $this->safeMessage($e->getMessage()));
            }
        }
        if ($modern) {
            $result = ['resultType' => 'complete'] + $result + ['_meta' => [self::META_SERVER_INFO => self::SERVER_INFO]];
        }
        return $this->result($id, $result);
    }

    /** @return array{status:int, body:array<string, mixed>} 400 UnsupportedProtocolVersionError listing the supported versions */
    private function unsupportedVersion(int|string $id, string $method, string $requested): array {
        $shown = preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested) === 1 ? $requested : 'malformed';
        return $this->reject('unsupported MCP-Protocol-Version', [
            'status' => 400,
            'body' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => [
                'code' => self::UNSUPPORTED_PROTOCOL_VERSION,
                'message' => 'Unsupported protocol version',
                'data' => ['supported' => self::SUPPORTED_VERSIONS, 'requested' => $shown],
            ]],
        ], ['method' => mb_substr($method, 0, 64), 'version' => $shown]);
    }

    /** @return array{status:int, body:array<string, mixed>} 400 HeaderMismatch naming the header */
    private function headerMismatch(int|string $id, string $method, string $header): array {
        return $this->reject('header mismatch', $this->error($id, self::HEADER_MISMATCH, 'Header mismatch: ' . $header, 400),
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

    /** Only the registry's fixed validation messages reach the client. */
    private function safeMessage(string $message): string {
        return preg_match('/^(Unknown tool|Invalid arguments|(Unknown|Missing|Invalid) argument: [a-z_]{1,64})$/', $message) === 1 ? $message : 'Invalid arguments';
    }

    /**
     * @param array<string, mixed>|\stdClass $result
     * @return array{status:int, body:array<string, mixed>}
     */
    private function result(int|string $id, array|\stdClass $result): array {
        return ['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]];
    }

    /** @return array{status:int, body:array<string, mixed>} */
    private function error(int|string|null $id, int $code, string $message, int $status = 200): array {
        return ['status' => $status, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]];
    }
}
