<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools;

/**
 * A group of MCP tools registered explicitly through DI in Application.
 * The registry filters tools/list and re-checks the grant (module, operation) on every tools/call;
 * when "app" is set, the tool is also hidden and uncallable unless that Nextcloud app is enabled for the user.
 */
interface ToolModule {
    /** @return list<array{name:string, description:string, inputSchema:array, module:string, operation:string, app?:string}> */
    public function definitions(): array;

    /**
     * The grant is already verified by the registry; the handler verifies identity and the resource ACL.
     * @param string $name tool name as listed by definitions()
     * @param array<string, mixed> $arguments arguments already validated against the tool's input schema
     * @param string $userId authenticated user id
     * @return array{content: list<array{type:string, text:string}>, isError?: bool} MCP tool result
     * @throws \InvalidArgumentException when arguments are invalid (-32602)
     */
    public function call(string $name, array $arguments, string $userId): array;
}
