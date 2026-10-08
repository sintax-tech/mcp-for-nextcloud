<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

/**
 * Optional companion of ToolModule for a module that only some users may see at all, whatever their grants: the
 * registry asks it before reading the grant, on tools/list and again on every tools/call, so a user outside the role
 * neither lists nor reaches its tools.
 *
 * Every tools/call to such a module is handed back to audit() with its outcome, the refused ones included, so the
 * module records who tried what: DENIED by the role or the grant (before the module runs), INVALID arguments,
 * READ_ERROR when the call failed and SUCCESS. Listing the tools is not audited.
 */
interface RestrictedModule {
    /** The role or the grant refused the call; the module did not run. */
    public const DENIED = 'denied';
    /** The arguments were refused, by the schema or by the module. */
    public const INVALID = 'invalid';
    /** The module ran and failed. */
    public const READ_ERROR = 'read_error';
    /** The module ran and answered. */
    public const SUCCESS = 'success';

    /**
     * @param string $userId authenticated user
     * @return bool whether this user may see the module's tools; the grant still decides after it
     */
    public function permits(string $userId): bool;

    /**
     * Records one call. Called by the registry after the outcome is known. When it throws, the registry logs the failure
     * without the arguments; a call that read nothing keeps its answer, and a SUCCESS is answered with a generic error
     * instead of the data, so nothing leaves without its record.
     *
     * @param string $tool technical name of the tool called
     * @param string $userId authenticated user
     * @param string $outcome DENIED, INVALID, READ_ERROR or SUCCESS
     * @param array<string, mixed> $arguments the arguments of the call: as sent when denied or refused by the schema,
     *     validated afterwards; untrusted, so the module picks and bounds what it records
     */
    public function audit(string $tool, string $userId, string $outcome, array $arguments): void;
}
