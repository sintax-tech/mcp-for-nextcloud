<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

/**
 * A tool module that describes a write before performing it.
 *
 * The registry asks for the plan of every tool whose operation is not `read` and whose call arrives
 * without `confirm: true`, so the model can show the plan to the user and repeat the same call once
 * the user has said yes. Nothing is stored between the two calls: there is no approval id, no token
 * and no record, so the plan is only ever a description of what the confirmed call would do.
 *
 * A preview may read whatever it needs — the current ETag, the owner of a board, the content before
 * and after — and must never write. Nextcloud permissions remain the real barrier; this is the
 * conversation that happens before them.
 */
interface PreviewsWrites {
    /**
     * @param string $name tool name of a writing definition of this module
     * @param array<string, mixed> $arguments arguments already validated against the tool schema
     * @param string $userId authenticated user
     * @return array<string, mixed> the plan as the structured content of the result
     * @throws \InvalidArgumentException for a semantic problem the JSON Schema cannot express (-32602)
     * @throws ToolFailure for a client-safe failure (not found, forbidden, conflict, precondition)
     */
    public function preview(string $name, array $arguments, string $userId): array;
}