<?php
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
     * Returns an MCP result {content:[...], isError?:bool}. Throws \InvalidArgumentException for -32602.
     */
    public function call(string $name, array $arguments, string $userId): array;
}
