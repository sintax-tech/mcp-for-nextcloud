<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Protocol;

use OCA\Mcp\Mcp\Resources\ResourceRegistry;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use InvalidArgumentException;

/** Adapts resources while retaining unavailable and unreadable resource semantics. */
final class ResourceRequests {
    public function __construct(private ?ResourceRegistry $resources) {}

    /** Preserves availability precedence, native validation and both protocol envelopes. */
    public function list(int|string $id, array $params, string $userId, bool $modern): array {
        if ($this->resources === null) {
            return JsonRpcResponse::error($id, -32601, 'Method not found', $modern ? 404 : 200);
        }
        try {
            $cursor = isset($params['cursor']) ? (string)$params['cursor'] : null;
            $list = $this->resources->list($userId, $cursor);
            return JsonRpcResponse::result($id, $modern ? [
                'resultType' => 'complete',
                'resources' => $list['resources'],
                'ttlMs' => 0,
                'cacheScope' => 'private',
                '_meta' => [ProtocolMetadata::META_SERVER_INFO => ProtocolMetadata::SERVER_INFO],
            ] : $list);
        } catch (InvalidArgumentException $e) {
            return JsonRpcResponse::error($id, -32602, JsonRpcResponse::safeMessage($e->getMessage()));
        }
    }

    /** Preserves availability precedence, native validation and both protocol envelopes. */
    public function templates(int|string $id, array $params, string $userId, bool $modern): array {
        if ($this->resources === null) {
            return JsonRpcResponse::error($id, -32601, 'Method not found', $modern ? 404 : 200);
        }
        try {
            $cursor = isset($params['cursor']) ? (string)$params['cursor'] : null;
            $templates = $this->resources->templates($userId, $cursor);
            return JsonRpcResponse::result($id, $modern ? [
                'resultType' => 'complete',
                'resourceTemplates' => $templates['resourceTemplates'],
                'ttlMs' => 0,
                'cacheScope' => 'private',
                '_meta' => [ProtocolMetadata::META_SERVER_INFO => ProtocolMetadata::SERVER_INFO],
            ] : $templates);
        } catch (InvalidArgumentException $e) {
            return JsonRpcResponse::error($id, -32602, JsonRpcResponse::safeMessage($e->getMessage()));
        }
    }

    /** Preserves availability precedence, native validation and both protocol envelopes. */
    public function read(int|string $id, array $params, string $userId, bool $modern): array {
        if ($this->resources === null) {
            return JsonRpcResponse::error($id, -32601, 'Method not found', $modern ? 404 : 200);
        }
        if (!isset($params['uri']) || !is_string($params['uri'])) {
            return JsonRpcResponse::error($id, -32602, 'Invalid argument: uri');
        }
        try {
            $read = $this->resources->read($params['uri'], $userId);
            return JsonRpcResponse::result($id, $modern ? [
                'resultType' => 'complete',
                'contents' => $read['contents'],
                'ttlMs' => 0,
                'cacheScope' => 'private',
                '_meta' => [ProtocolMetadata::META_SERVER_INFO => ProtocolMetadata::SERVER_INFO],
            ] : $read);
        } catch (ToolFailure $e) {
            return JsonRpcResponse::error($id, $modern ? -32602 : -32002, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return JsonRpcResponse::error($id, -32602, JsonRpcResponse::safeMessage($e->getMessage()));
        }
    }
}
