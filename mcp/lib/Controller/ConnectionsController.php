<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use InvalidArgumentException;
use OCA\Mcp\Service\ConnectionList;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin JSON API of the active OAuth connections: list every user's connections, revoke one, or revoke all of a
 * user. Admin-only and CSRF-protected by Nextcloud's defaults (no NoAdminRequired/NoCSRFRequired). Token hashes
 * are never returned.
 */
class ConnectionsController extends Controller {
    /** Longest user id accepted when revoking every connection of a user. */
    private const MAX_UID = 64;

    public function __construct(
        string $appName,
        IRequest $request,
        private ConnectionList $connections,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @param string $search part of the user id or client_id
     * @param int $page 1-based page
     * @return JSONResponse one page (see ConnectionList::page), or 400
     */
    public function index(string $search = '', int $page = 1): JSONResponse {
        try {
            return new JSONResponse($this->connections->page(null, $search, $page));
        } catch (InvalidArgumentException) {
            return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @param int $id grant id of the connection
     * @return JSONResponse {revoked: true, counts: {connections}} with the live connection total left, or 404 when no such connection exists
     */
    public function destroy(int $id): JSONResponse {
        if (!$this->connections->revoke($id, null)) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
        return new JSONResponse(['revoked' => true, 'counts' => $this->counts()]);
    }

    /**
     * Also works for a user id that no longer exists, so leftovers can be cleaned up.
     *
     * @param string $uid user whose connections are revoked
     * @return JSONResponse {revoked: true, counts: {connections}}, or 400 for an empty or oversized user id
     */
    public function revokeUser(string $uid): JSONResponse {
        if ($uid === '' || strlen($uid) > self::MAX_UID) {
            return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
        }
        $this->connections->revokeUser($uid);
        return new JSONResponse(['revoked' => true, 'counts' => $this->counts()]);
    }

    /**
     * Revoking never changes eligibility or the personal connection flag, so the live connection total is the only
     * figure of the status summary that moves.
     *
     * @return array{connections:int}
     */
    private function counts(): array {
        return ['connections' => $this->connections->count()];
    }
}
