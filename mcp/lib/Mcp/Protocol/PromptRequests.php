<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Protocol;

use OCA\Mcp\Mcp\Prompts\PromptCatalog;
use OCA\Mcp\Access\Grants\GrantPolicy;

/** Adapts granted prompts without changing argument or envelope semantics. */
final class PromptRequests {
    public function __construct(private PromptCatalog $prompts, private GrantPolicy $policy) {}

    /** Returns the prompts currently granted to this user. */
    public function list(string $userId): array {
        return $this->prompts->list($this->policy, $userId);
    }

    /** Preserves prompt argument acceptance and era-specific result wrapping. */
    public function get(int|string $id, array $params, string $userId, bool $modern): array {
        $prompt = $this->prompt($params, $userId);
        if ($prompt === null) {
            return JsonRpcResponse::error($id, -32602, 'Invalid params');
        }
        return JsonRpcResponse::result($id, $modern
            ? ['resultType' => 'complete'] + $prompt + ['_meta' => [ProtocolMetadata::META_SERVER_INFO => ProtocolMetadata::SERVER_INFO]]
            : $prompt);
    }

    /**
     * @param array<string, mixed> $params JSON-RPC params
     * @param string $userId authenticated user
     * @return array{description:string, messages:list<array{role:string, content:array{type:string, text:string}}>}|null
     *   the rendered prompt, or null for an unknown or ungranted name
     */
    private function prompt(array $params, string $userId): ?array {
        $name = $params['name'] ?? null;
        if (!is_string($name) || (isset($params['arguments']) && $params['arguments'] !== [])) {
            return null;
        }
        return $this->prompts->get($name, $this->policy, $userId);
    }
}
