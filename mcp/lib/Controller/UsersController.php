<?php
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;

/** Admin-only by default: no #[NoAdminRequired] attribute on purpose. */
class UsersController extends Controller {
    private const LIMIT = 20;

    public function __construct(
        string $appName,
        IRequest $request,
        private IUserManager $userManager,
    ) {
        parent::__construct($appName, $request);
    }

    public function index(string $search = ''): JSONResponse {
        $pattern = trim($search);
        if ($pattern === '') {
            return new JSONResponse([]);
        }
        $result = [];
        foreach ($this->userManager->searchDisplayName($pattern, self::LIMIT) as $user) {
            $result[] = ['uid' => $user->getUID(), 'displayName' => $user->getDisplayName()];
        }
        return new JSONResponse($result);
    }
}
