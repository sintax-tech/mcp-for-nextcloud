<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\Access\Grants\Groups\GroupDirectory;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/** Admin-only by framework defaults, with an explicit guard before any directory or policy resolution. */
class GroupDirectoryController extends Controller {
    public function __construct(string $appName, IRequest $request, private GroupDirectory $directory,
        private IUserSession $session, private IGroupManager $groups) {
        parent::__construct($appName, $request);
    }

    public function index(string $search = '', int $page = 1, string $filter = 'all'): JSONResponse {
        return $this->read(fn (): array => $this->directory->page($search, $page, $filter));
    }

    public function detail(string $gid = ''): JSONResponse {
        return $this->read(fn (): array => $this->directory->detail($gid));
    }

    private function read(callable $read): JSONResponse {
        $user = $this->session->getUser();
        if ($user === null || !$this->groups->isAdmin($user->getUID())) {
            return new JSONResponse(['error' => 'Forbidden'], 403);
        }
        try {
            return new JSONResponse($read());
        } catch (\InvalidArgumentException) {
            return new JSONResponse(['error' => 'Invalid request'], 400);
        } catch (PolicyUnavailable) {
            return new JSONResponse(['error' => 'Unavailable'], 503);
        }
    }
}
