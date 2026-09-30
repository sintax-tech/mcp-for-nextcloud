<?php
declare(strict_types=1);

namespace OCA\Mcp\Service;

use OCA\Mcp\Tools\ToolRegistry;
use Psr\Log\LoggerInterface;

/**
 * Stateless MCP 2025-06-18 JSON-RPC handling for a single POSTed message.
 * Authentication and connection policy are enforced by the controller before this runs.
 */
class McpProtocol {
    /** The single MCP protocol version implemented and answered in initialize. */
    public const VERSION = '2025-06-18';
    /** Built-in diagnostic tool that reads no user data. */
    public const TOOL = 'mcp_status';
    /** MCP-Protocol-Version values accepted after initialize. */
    public const KNOWN_VERSIONS = ['2025-03-26', '2025-06-18', '2025-11-25'];
    /** Version assumed when the header is absent, as the Streamable HTTP transport specifies. */
    public const DEFAULT_HEADER_VERSION = '2025-03-26';

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
     * @return array{status:int, body:array<string, mixed>|null} HTTP status and JSON-RPC envelope (null for no body)
     */
    public function handle(string $raw, string $headerVersion, string $userId): array {
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
        // initialize negotiates through params.protocolVersion, so its header is ignored; later requests
        // may carry any known version, and a missing header means the transport's default.
        $version = $headerVersion === '' ? self::DEFAULT_HEADER_VERSION : $headerVersion;
        if ($method !== 'initialize' && !in_array($version, self::KNOWN_VERSIONS, true)) {
            return $this->reject('unsupported MCP-Protocol-Version', ['status' => 400, 'body' => null], [
                'method' => mb_substr($method, 0, 64),
                'version' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $headerVersion) === 1 ? $headerVersion : 'malformed',
            ]);
        }
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
        if ($method === 'initialize') {
            if (!is_string($params['protocolVersion'] ?? null) || !isset($params['clientInfo']['name'], $params['clientInfo']['version'])) {
                return $this->error($id, -32602, 'Invalid initialize params');
            }
            // Version negotiation: always answer with the single supported version.
            return $this->result($id, [
                'protocolVersion' => self::VERSION,
                'capabilities' => ['tools' => new \stdClass()],
                'serverInfo' => ['name' => 'nextcloud-mcp', 'version' => '0.6.1'],
            ]);
        }
        if ($method === 'ping') {
            return $this->result($id, new \stdClass());
        }
        if ($method === 'tools/list') {
            return $this->result($id, ['tools' => [[
                'name' => self::TOOL,
                'description' => 'Reports whether the MCP diagnostic endpoint is running; does not access user data.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            ], ...$this->tools->list($userId)]]);
        }
        if ($method === 'tools/call') {
            $name = $params['name'] ?? null;
            $arguments = $params['arguments'] ?? [];
            if (!is_string($name) || !is_array($arguments)) {
                return $this->error($id, -32602, 'Invalid params');
            }
            if ($name === self::TOOL) {
                return $arguments === []
                    ? $this->result($id, ['content' => [['type' => 'text', 'text' => 'MCP endpoint available']]])
                    : $this->error($id, -32602, 'Invalid arguments');
            }
            try {
                return $this->result($id, $this->tools->call($name, $arguments, $userId));
            } catch (\InvalidArgumentException $e) {
                return $this->error($id, -32602, $this->safeMessage($e->getMessage()));
            }
        }
        return $this->error($id, -32601, 'Method not found');
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
