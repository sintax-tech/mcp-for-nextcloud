<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Protocol;

use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Tools\Discovery\ToolPresentation;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use InvalidArgumentException;

/** Adapts tool discovery and execution to the supported protocol eras. */
final class ToolRequests {
    public function __construct(private ToolRegistry $tools) {}

    /** @return list<array<string, mixed>> diagnostic tool followed by the tools the user may call now */
    public function list(string $userId): array {
        return [[
            'name' => ProtocolMetadata::TOOL,
            'title' => ToolPresentation::title(ProtocolMetadata::TOOL),
            'description' => 'Reports whether the MCP diagnostic endpoint is running; does not access user data.',
            'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            // The diagnostic tool only reads its own endpoint, hence the read-only annotations.
            'annotations' => ToolPresentation::annotations(ProtocolMetadata::TOOL, 'read'),
        ], ...$this->tools->list($userId)];
    }

    /**
     * @param array<string, mixed> $params
     * @param bool $modern add resultType and serverInfo _meta to the result
     * @return array{status:int, body:array<string, mixed>}
     */
    public function call(int|string $id, array $params, string $userId, bool $modern): array {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if (!is_string($name) || !is_array($arguments)) {
            return JsonRpcResponse::error($id, -32602, 'Invalid params');
        }
        if ($name === ProtocolMetadata::TOOL) {
            if ($arguments !== []) {
                return JsonRpcResponse::error($id, -32602, 'Invalid arguments');
            }
            $result = ['content' => [['type' => 'text', 'text' => 'MCP endpoint available']]];
        } else {
            try {
                $result = $this->tools->call($name, $arguments, $userId);
            } catch (ArgumentValidationException $e) {
                if ($e->details()['rule'] !== '') {
                    $response = JsonRpcResponse::error($id, -32602, $e->clientMessage());
                    $response['body']['error']['data'] = $e->details();
                    return $response;
                }
                return JsonRpcResponse::error($id, -32602, JsonRpcResponse::safeMessage($e->getMessage()));
            } catch (InvalidArgumentException $e) {
                return JsonRpcResponse::error($id, -32602, JsonRpcResponse::safeMessage($e->getMessage()));
            }
        }
        if ($modern) {
            $result = ['resultType' => 'complete'] + $result + ['_meta' => [ProtocolMetadata::META_SERVER_INFO => ProtocolMetadata::SERVER_INFO]];
        }
        return JsonRpcResponse::result($id, $result);
    }
}
