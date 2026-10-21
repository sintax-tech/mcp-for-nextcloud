<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools\Discovery;

use OCA\Mcp\Access\Guests\GuestRestrictions;
use InvalidArgumentException;
use OCA\Mcp\Mcp\Tools\ToolModule;
use OCA\Mcp\Mcp\Tools\RestrictedModule;
use OCA\Mcp\Access\AppAvailability\AppEnablement;

/** Rechecks role, grants and optional app availability for the current user. */
final class ToolAvailability {
    public function __construct(
        private \OCA\Mcp\Access\Grants\GrantPolicy $policy,
        private \OCP\App\IAppManager $appManager,
        private \OCP\IUserManager $userManager,
        private ?GuestRestrictions $guests = null,
    ) {}

    /**
     * The role of a {@see RestrictedModule} comes before everything else: outside it the module does not exist.
     *
     * @param ToolModule $module module whose tools are about to be listed or called
     * @param string $userId authenticated user
     */
    public function permits(ToolModule $module, string $userId): bool {
        return !$module instanceof RestrictedModule || $module->permits($userId);
    }

    /**
     * The grant decides first: the tool's operation, or any of the alternatives it lists in `grantAnyOf` when the kind
     * of write depends on the arguments (files_share shares with a person under `share` and creates a public link
     * under `link`). A tool listing alternatives must check the one each call needs itself, before planning and again
     * before writing; the registry only lets the call in.
     *
     * @param array{module:string, operation:string, grantAnyOf?:list<string>, app?:string} $definition
     */
    public function allowed(array $definition, string $userId): bool {
        try {
            // Three-valued "any of": a known Allow wins over an unknown alternative, whatever their order.
            $granted = $this->policy->grantedAny($userId, $definition['module'], $definition['grantAnyOf'] ?? [$definition['operation']]);
        } catch (InvalidArgumentException) {
            return false;
        }
        if (!$granted) {
            return false;
        }
        if ($this->guests !== null && (!$this->guests->allowsApp($userId, \OCA\Mcp\Access\Grants\GrantMatrix::MODULE_APPS[$definition['module']] ?? '')
            || !$this->guests->allowsApp($userId, $definition['app'] ?? ''))) {
            return false;
        }
        if (isset($definition['app'])) {
            $user = $this->userManager->get($userId);
            // An optional app in a version older than MCP supports counts as off (AppEnablement::MINIMUM_VERSIONS).
            return $user !== null && AppEnablement::forUser($this->appManager, $definition['app'], $user);
        }
        return true;
    }
}
