<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Protocol;

/** Builds JSON-RPC envelopes and exposes only safe validation messages. */
final class JsonRpcResponse {
    /**
     * @param array<string, mixed>|\stdClass $result
     * @return array{status:int, body:array<string, mixed>}
     */
    public static function result(int|string $id, array|\stdClass $result): array {
        return ['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]];
    }

    /** @return array{status:int, body:array<string, mixed>} */
    public static function error(int|string|null $id, int $code, string $message, int $status = 200): array {
        return ['status' => $status, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]];
    }

    /** Only the registry's fixed validation messages reach the client. */
    public static function safeMessage(string $message): string {
        // The argument name is a path into the arguments the client itself sent: keys, and the [n] and
        // . separators the validator joins them with. That is why dots and brackets are allowed here — a
        // nested field has to survive to the client as itself, or "Invalid argument: items[1].reply_to"
        // collapses into a message that says nothing about which item to fix.
        //
        // Case is part of a name, not decoration: the Deck tools declare boardId, stackId, cardId and
        // dueBefore, and a lowercase-only charset turned every one of them into "Invalid arguments". The
        // charset stays strict otherwise, so nothing built from server state (a path, a file name, an
        // exception) can slip through: no slash, no space, no quote, no backslash.
        return preg_match('/^(Unknown tool|Invalid arguments|(Unknown|Missing|Invalid) argument: [A-Za-z_0-9]+(?:[.\\[][A-Za-z_0-9]+\\]?)*)$/', $message) === 1
            ? $message
            : 'Invalid arguments';
    }
}
