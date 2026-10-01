<?php
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\Service\ConnectionList;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Personal JSON API of the signed-in user's own OAuth connections. Every call is scoped to the session user, so
 * a user can neither see nor revoke someone else's connection; CSRF stays on (the page sends the requesttoken).
 */
class MyConnectionsController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private ConnectionList $connections,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @param int $page 1-based page
     * @return JSONResponse the user's connections (see ConnectionList::page), or 400
     */
    #[NoAdminRequired]
    public function index(int $page = 1): JSONResponse {
        $uid = $this->uid();
        if ($uid === null || $page < 1 || $page > ConnectionList::MAX_PAGE) {
            return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
        }
        return new JSONResponse($this->connections->page($uid, '', $page));
    }

    /**
     * @param int $id grant id of one of the user's own connections
     * @return JSONResponse {revoked: true}, or 404 when it does not exist or belongs to someone else
     */
    #[NoAdminRequired]
    public function destroy(int $id): JSONResponse {
        $uid = $this->uid();
        if ($uid === null || !$this->connections->revoke($id, $uid)) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
        return new JSONResponse(['revoked' => true]);
    }

    /** @return string|null uid of the signed-in, enabled user */
    private function uid(): ?string {
        $user = $this->userSession->getUser();
        return $user !== null && $user->isEnabled() ? $user->getUID() : null;
    }
}
