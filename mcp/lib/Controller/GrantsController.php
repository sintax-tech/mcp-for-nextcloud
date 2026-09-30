<?php
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use InvalidArgumentException;
use OCA\Mcp\Service\GrantMatrix;
use OCA\Mcp\Service\GrantPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;

/**
 * JSON API of the admin permission matrix. Every endpoint is admin-only and CSRF-protected by
 * Nextcloud's defaults (no NoAdminRequired/NoCSRFRequired); the page sends the requesttoken header.
 * Invalid input answers 400 {"error":"Invalid request"} without saying which part was wrong.
 */
class GrantsController extends Controller {
    /** Pseudo-operation of the bulk endpoint that toggles eligibility instead of a grant. */
    public const ELIGIBLE = 'eligible';

    public function __construct(
        string $appName,
        IRequest $request,
        private GrantMatrix $matrix,
        private GrantPolicy $policy,
        private IUserManager $userManager,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @param string $search term matched against uid, display name and e-mail
     * @param string $group group id, '' for every user
     * @param int $page 1-based page
     * @return JSONResponse one matrix page (see GrantMatrix::page), or 400
     */
    public function index(string $search = '', string $group = '', int $page = 1): JSONResponse {
        return $this->guard(fn () => $this->matrix->page($search, $group, $page));
    }

    /**
     * Body {eligible: bool} or {module, operation, granted: bool}, never both.
     *
     * @param string $uid existing Nextcloud user id
     * @return JSONResponse the user's updated row, or 400
     */
    public function update(string $uid): JSONResponse {
        return $this->guard(function () use ($uid): array {
            $user = $this->user($uid);
            $eligible = $this->request->getParam('eligible');
            $hasGrant = $this->request->getParam('module') !== null || $this->request->getParam('operation') !== null || $this->request->getParam('granted') !== null;
            if ($eligible !== null && !$hasGrant) {
                $this->policy->setEligible($uid, self::bool($eligible));
            } elseif ($eligible === null && $hasGrant) {
                [$module, $operation] = self::grant($this->request->getParam('module'), $this->request->getParam('operation'));
                $this->policy->setGrant($uid, $module, $operation, self::bool($this->request->getParam('granted')));
            } else {
                throw new InvalidArgumentException('Invalid request');
            }
            return $this->matrix->rows([$user])[0];
        });
    }

    /**
     * Body {uids: list<string> (1..PAGE_SIZE), module: string|null, operation: string, granted: bool}; operation
     * "eligible" with module null toggles eligibility. Every uid is validated before anything is written.
     *
     * @return JSONResponse {updated: int}, or 400
     */
    public function bulk(): JSONResponse {
        return $this->guard(function (): array {
            $uids = $this->request->getParam('uids');
            if (!is_array($uids) || $uids === [] || count($uids) > GrantMatrix::PAGE_SIZE || !array_is_list($uids)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $module = $this->request->getParam('module');
            $operation = $this->request->getParam('operation');
            $granted = self::bool($this->request->getParam('granted'));
            $eligibility = $operation === self::ELIGIBLE && $module === null;
            if (!$eligibility) {
                [$module, $operation] = self::grant($module, $operation);
            }
            $uids = array_values(array_unique(array_map(fn ($uid) => $this->user(is_string($uid) ? $uid : '')->getUID(), $uids)));
            foreach ($uids as $uid) {
                $eligibility ? $this->policy->setEligible($uid, $granted) : $this->policy->setGrant($uid, $module, $operation, $granted);
            }
            return ['updated' => count($uids)];
        });
    }

    /**
     * Body {enabled: bool}.
     *
     * @return JSONResponse {enabled: bool}, or 400
     */
    public function service(): JSONResponse {
        return $this->guard(function (): array {
            $this->policy->setGlobalEnabled(self::bool($this->request->getParam('enabled')));
            return ['enabled' => $this->policy->globalEnabled()];
        });
    }

    /** @param callable():array<string, mixed> $action */
    private function guard(callable $action): JSONResponse {
        try {
            return new JSONResponse($action());
        } catch (InvalidArgumentException) {
            return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
        }
    }

    /** @throws InvalidArgumentException for an unknown user */
    private function user(string $uid): IUser {
        $user = $uid === '' ? null : $this->userManager->get($uid);
        if ($user === null) {
            throw new InvalidArgumentException('Invalid request');
        }
        return $user;
    }

    /**
     * @return array{0:string, 1:string}
     * @throws InvalidArgumentException when the pair is not in GrantPolicy::CATALOG
     */
    private static function grant(mixed $module, mixed $operation): array {
        if (!is_string($module) || !is_string($operation) || !GrantPolicy::inCatalog($module, $operation)) {
            throw new InvalidArgumentException('Invalid request');
        }
        return [$module, $operation];
    }

    /** @throws InvalidArgumentException unless the value is a JSON boolean */
    private static function bool(mixed $value): bool {
        if (!is_bool($value)) {
            throw new InvalidArgumentException('Invalid request');
        }
        return $value;
    }
}
