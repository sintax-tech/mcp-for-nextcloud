<?php
declare(strict_types=1);

namespace OCA\Mcp\Service;

use OCA\Mcp\Tools\ToolRegistry;

/**
 * Stateless MCP 2025-06-18 JSON-RPC handling for a single POSTed message.
 * Authentication and connection policy are enforced by the controller before this runs.
 */
class McpProtocol {
    public const VERSION = '2025-06-18';
    public const TOOL = 'mcp_status';

    public function __construct(private ToolRegistry $tools) {}

    /** @return array{status:int,body:?array} */
    public function handle(string $raw, string $headerVersion, string $userId): array {
        try {
            $message = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->error(null, -32700, 'Parse error', 400);
        }
        if (!is_array($message) || array_is_list($message) || ($message['jsonrpc'] ?? null) !== '2.0'
            || !isset($message['method']) || !is_string($message['method'])
            || (array_key_exists('id', $message) && !is_int($message['id']) && !is_string($message['id']))) {
            return $this->error(null, -32600, 'Invalid Request', 400);
        }
        $method = $message['method'];
        // Every request after initialize must carry the negotiated version header.
        if ($method !== 'initialize' && $headerVersion !== self::VERSION) {
            return ['status' => 400, 'body' => null];
        }
        if ($method === 'initialize' && $headerVersion !== '' && $headerVersion !== self::VERSION) {
            return ['status' => 400, 'body' => null];
        }
        $id = $message['id'] ?? null;
        if ($id === null) {
            return $method === 'notifications/initialized'
                ? ['status' => 202, 'body' => null]
                : $this->error(null, -32600, 'Invalid Request', 400);
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
                'serverInfo' => ['name' => 'nextcloud-mcp', 'version' => '0.1.0'],
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

    /** Only the registry's fixed validation messages reach the client. */
    private function safeMessage(string $message): string {
        return preg_match('/^(Unknown tool|Invalid arguments|(Unknown|Missing|Invalid) argument: [a-z_]{1,64})$/', $message) === 1 ? $message : 'Invalid arguments';
    }

    private function result(int|string $id, array|\stdClass $result): array {
        return ['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]];
    }

    private function error(int|string|null $id, int $code, string $message, int $status = 200): array {
        return ['status' => $status, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]];
    }
}
