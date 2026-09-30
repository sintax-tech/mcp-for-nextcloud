<?php
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * Backs the admin page UID picker with a bounded user lookup.
 *
 * Exposes GET /apps/mcp/api/users?search=<term>. The route is admin-only on
 * purpose: the controller carries no #[NoAdminRequired] attribute, so the
 * AppFramework rejects non-administrators before this code runs, and the
 * requesttoken header is required by the same middleware. Only uid and
 * displayName are ever serialized, never e-mail or any other field.
 */
class UsersController extends Controller {
    /** Hard cap on how many users a single lookup may return. */
    private const LIMIT = 20;

    /**
     * @param string $appName application id, always "mcp"
     * @param IRequest $request incoming request, read by the admin/CSRF middleware
     * @param IUserManager $userManager source of display-name and uid matches
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserManager $userManager,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Matches the term against display names and UIDs, merging both sources.
     *
     * A blank (or whitespace-only) term returns an empty list without querying
     * the user manager; a one-character term is a normal query. Matches are
     * deduplicated by uid, display-name matches are listed first, and the total
     * never exceeds LIMIT.
     *
     * @param string $search raw term typed into the picker; trimmed before use
     * @return JSONResponse<Http::STATUS_OK, list<array{uid: string, displayName: string}>, array{}>
     */
    public function index(string $search = ''): JSONResponse {
        $pattern = trim($search);
        if ($pattern === '') {
            return new JSONResponse([]);
        }
        $matches = [];
        $sources = [
            $this->userManager->searchDisplayName($pattern, self::LIMIT),
            $this->userManager->search($pattern, self::LIMIT),
        ];
        foreach ($sources as $candidates) {
            foreach ($candidates as $user) {
                $uid = $user->getUID();
                if (isset($matches[$uid])) {
                    continue;
                }
                $matches[$uid] = ['uid' => $uid, 'displayName' => $user->getDisplayName()];
                if (count($matches) >= self::LIMIT) {
                    break 2;
                }
            }
        }
        return new JSONResponse(array_values($matches));
    }
}
