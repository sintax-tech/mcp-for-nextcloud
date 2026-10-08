<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

/**
 * Optional companion of ToolModule for a module that only some users may see at all, whatever their grants: the
 * registry asks it before reading the grant, on tools/list and again on every tools/call, so a user outside the role
 * neither lists nor reaches its tools.
 */
interface RestrictedModule {
    /**
     * @param string $userId authenticated user
     * @return bool whether this user may see the module's tools; the grant still decides after it
     */
    public function permits(string $userId): bool;
}
