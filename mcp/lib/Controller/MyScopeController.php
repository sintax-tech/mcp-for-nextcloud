<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\ResourceScope\NativeResourceEnumerator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Personal, read-only view of the signed-in user's own resource scope (decision D2: only an administrator configures
 * it). Every call is scoped to the session user: a uid naming anyone else is refused, never resolved. CSRF stays on.
 * The answer names the user's own folders and calendars; whether one of them is excluded by tag is admin information
 * and is not part of it.
 */
class MyScopeController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private ResourceScopeMapper $scopes,
        private NativeResourceEnumerator $resources,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @param string $uid optional; when given it must be the session user's own uid
     * @return JSONResponse {modules: {files|calendar|tasks: {mode, items: list<{id, found, name, path}>}}}, 403 for
     *     another uid, 401 without an enabled session user, or 503 when the scope cannot be read
     */
    #[NoAdminRequired]
    public function index(string $uid = ''): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null || !$user->isEnabled()) {
            return new JSONResponse(['error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }
        $own = $user->getUID();
        if ($uid !== '' && $uid !== $own) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }
        try {
            $saved = $this->scopes->forUser($own);
            $modules = [];
            foreach (ResourceScopeMapper::MODULES as $module) {
                $scope = $saved[$module] ?? null;
                $modules[$module] = $scope === null ? ['mode' => 'unrestricted', 'items' => []] : [
                    'mode' => 'restricted',
                    'items' => array_map(static fn (array $item): array => array_diff_key($item, ['excluded' => true]),
                        $this->resources->describe($own, $module, $scope['items'])),
                ];
            }
        } catch (Throwable) {
            // Never shown as unrestricted by default.
            return new JSONResponse(['error' => 'Unavailable', 'uncertain' => false], Http::STATUS_SERVICE_UNAVAILABLE);
        }
        return new JSONResponse(['modules' => $modules]);
    }
}
