<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\Access\Grants\OverridePreview;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The preview of a reset to inheritance: admin-only by framework defaults (no attribute that opens it to users, CSRF kept), with an
 * explicit guard so a non-administrator gets 403 before any user lookup or policy resolution. Read only: it writes
 * nothing, takes no mutex and persists no observation. It lives apart from GrantsController, whose commands and undo it
 * only mirrors in shape.
 */
class OverridePreviewController extends Controller {
    public function __construct(string $appName, IRequest $request, private OverridePreview $preview,
        private IUserSession $session, private IGroupManager $groups) {
        parent::__construct($appName, $request);
    }

    /**
     * Body {commands: list<{uid, module: string|null, operation, state: "inherit"}>}, the body of POST
     * /api/grants/commands without expectedRevision.
     *
     * @return JSONResponse {cells: list<{uid, module, operation, before, after}>} where before and after are
     *     {allowed: bool|null, source, groupIds}; null is unknown, never false. 400 for a body the command would refuse,
     *     403 for a non-administrator, 503 when the policy cannot be read.
     */
    public function index(): JSONResponse {
        $user = $this->session->getUser();
        if ($user === null || !$this->groups->isAdmin($user->getUID())) {
            return new JSONResponse(['error' => 'Forbidden'], 403);
        }
        try {
            return new JSONResponse($this->preview->preview($this->request->getParam('commands')));
        } catch (\InvalidArgumentException) {
            return new JSONResponse(['error' => 'Invalid request'], 400);
        } catch (PolicyUnavailable) {
            return new JSONResponse(['error' => 'Unavailable'], 503);
        }
    }
}
