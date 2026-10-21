<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use InvalidArgumentException;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\Transactions\MissingRevision;
use OCA\Mcp\Access\Policy\Transactions\PolicyConflict;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\ResourceScopeWriter;
use OCA\Mcp\Access\ResourceScope\ScopeInactive;
use OCA\Mcp\Access\ResourceScope\ScopeItemsInaccessible;
use OCA\Mcp\Access\ResourceScope\ScopeTooLarge;
use OCA\Mcp\Access\ResourceScope\NativeResourceEnumerator;
use OCA\Mcp\Access\ResourceScope\ScopeActivation;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Admin JSON API of the per-user resource scope (contracts B.6). Every endpoint is admin-only and CSRF-protected by
 * Nextcloud's defaults (no NoAdminRequired/NoCSRFRequired), so a non-administrator is refused by the framework before
 * the target user is resolved. Invalid input answers 400 {"error":"Invalid request"} without saying which part.
 *
 * - GET  /api/scope/{uid}/{module}: the saved scope with display names, the module's activation and the revision.
 * - GET  /api/scope/{uid}/{module}/candidates: the target user's native resources, 50 per page without truncation,
 *   from NativeResourceEnumerator (never the MCP view); folders excluded by tag are marked 'excluded'.
 * - PUT  /api/scope/{uid}/{module}: body {expectedRevision, mode: "unrestricted"|"restricted", items?: list<string>}.
 *   428 without expectedRevision, 409 {error, revision, state} when stale, 422 {"error":"ScopeInactive"} while the
 *   module's activation barrier is closed, 422 {"error":"Inaccessible", items} for a resource the user cannot reach,
 *   422 {"error":"TooManyResources", limit} above the limit, 503 {"error":"Unavailable", uncertain}. Nothing is
 *   written on any refusal. A save whose summary cannot be read back after the commit answers 503 with
 *   uncertain=true plus its revision, changed and receiptId. Undo goes through POST /api/grants/undo with the
 *   receipt id.
 *
 * Every call is audited in the app log with structured context only: administrator, target uid, action, result,
 * timestamp and, when it applies, module, page and count. Never a resource name, path or title.
 */
class ScopeController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private ResourceScopeMapper $scopes,
        private NativeResourceEnumerator $resources,
        private ResourceScopeWriter $writer,
        private PolicyStateMapper $state,
        private ScopeActivation $activation,
        private IUserManager $userManager,
        private IUserSession $session,
        private LoggerInterface $logger,
        private ITimeFactory $time,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @param string $uid existing Nextcloud user id
     * @param string $module one of ResourceScopeMapper::MODULES
     * @return JSONResponse {uid, module, active, mode, items, limit, revision}, or 400/503
     */
    public function show(string $uid, string $module): JSONResponse {
        return $this->guard('scope.show', $uid, $module, function () use ($uid, $module): array {
            $this->target($uid, $module);
            // Read before the scope: a write in between makes the next save a conflict, never a stale accept.
            $revision = $this->revision();
            $scope = $this->readScope($uid, $module);
            return [
                'uid' => $uid,
                'module' => $module,
                'active' => $this->activation->isActive($module),
                'mode' => $scope === null ? 'unrestricted' : 'restricted',
                'items' => $scope === null ? [] : $this->resources->describe($uid, $module, $scope['items']),
                'limit' => ResourceScopeMapper::MAX_ITEMS,
                'revision' => $revision,
            ];
        }, static fn (array $body): array => ['count' => count($body['items'])]);
    }

    /**
     * @param string $uid existing Nextcloud user id
     * @param string $module one of ResourceScopeMapper::MODULES
     * @param string $search term matched against the resource name
     * @param int $page 1-based page
     * @param string $parent Files only: id of the folder to list, '' for the user's root
     * @return JSONResponse one page of candidates (see NativeResourceEnumerator::candidates), or 400/503
     */
    public function candidates(string $uid, string $module, string $search = '', int $page = 1, string $parent = ''): JSONResponse {
        return $this->guard('scope.candidates', $uid, $module, function () use ($uid, $module, $search, $page, $parent): array {
            $this->target($uid, $module);
            return $this->resources->candidates($uid, $module, $search, $page, $parent === '' ? null : $parent);
        }, static fn (array $body): array => ['page' => $body['page'], 'count' => count($body['items'])]);
    }

    /**
     * @param string $uid existing Nextcloud user id
     * @param string $module one of ResourceScopeMapper::MODULES
     * @return JSONResponse {revision, changed, receiptId, scope: {mode, count}}, or 400/409/422/428/503
     */
    public function update(string $uid, string $module): JSONResponse {
        return $this->guard('scope.update', $uid, $module, function () use ($uid, $module): array|JSONResponse {
            $this->target($uid, $module);
            $items = $this->items();
            $expected = $this->expectedRevision();
            try {
                $result = $this->writer->setResourceScope($expected, $this->actor(), $uid, $module, $items);
            } catch (ScopeInactive) {
                return new JSONResponse(['error' => 'ScopeInactive'], Http::STATUS_UNPROCESSABLE_ENTITY);
            } catch (ScopeItemsInaccessible $e) {
                return new JSONResponse(['error' => 'Inaccessible', 'items' => $e->items], Http::STATUS_UNPROCESSABLE_ENTITY);
            } catch (ScopeTooLarge) {
                return new JSONResponse(['error' => 'TooManyResources', 'limit' => ResourceScopeMapper::MAX_ITEMS], Http::STATUS_UNPROCESSABLE_ENTITY);
            }
            // Committed: a failing re-read of the summary answers uncertain, never "nothing was written", and keeps
            // the revision and the receiptId of the commit so the undo stays reachable.
            try {
                return $result + ['scope' => $this->summary($uid, $module)];
            } catch (Throwable) {
                return new JSONResponse(['error' => 'Unavailable', 'uncertain' => true] + $result, Http::STATUS_SERVICE_UNAVAILABLE);
            }
        }, static fn (array $body): array => ['count' => (int)($body['scope']['count'] ?? 0)], fn (): array => ['scope' => $this->summary($uid, $module)]);
    }

    /**
     * Runs one route, maps the policy errors and writes the audit line; nothing falls back to defaults.
     *
     * @param string $action audit name of the route
     * @param callable():(array<string, mixed>|JSONResponse) $body the route body
     * @param callable(array<string, mixed>):array<string, int> $counted audit fields of a successful body
     * @param (callable():array<string, mixed>)|null $state fresh state for a 409 body, read after the rollback
     */
    private function guard(string $action, string $uid, string $module, callable $body, callable $counted, ?callable $state = null): JSONResponse {
        try {
            $result = $body();
            if ($result instanceof JSONResponse) {
                $uncertain = $result->getStatus() === Http::STATUS_SERVICE_UNAVAILABLE;
                $this->audit($action, $uid, $module, $uncertain ? 'uncertain' : 'refused', ['status' => $result->getStatus()]);
                return $result;
            }
            $this->audit($action, $uid, $module, 'ok', $counted($result));
            return new JSONResponse($result);
        } catch (MissingRevision) {
            $this->audit($action, $uid, $module, 'refused', ['status' => Http::STATUS_PRECONDITION_REQUIRED]);
            return new JSONResponse(['error' => 'PreconditionRequired'], Http::STATUS_PRECONDITION_REQUIRED);
        } catch (PolicyConflict) {
            $this->audit($action, $uid, $module, 'conflict', []);
            try {
                return new JSONResponse(['error' => 'Conflict', 'revision' => $this->revision(), 'state' => $state === null ? null : $state()],
                    Http::STATUS_CONFLICT);
            } catch (PolicyUnavailable $e) {
                return self::unavailable($e);
            }
        } catch (PolicyUnavailable $e) {
            $this->audit($action, $uid, $module, 'unavailable', []);
            return self::unavailable($e);
        } catch (InvalidArgumentException) {
            $this->audit($action, $uid, $module, 'invalid', []);
            return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * One audit line per call. Uid and module are only logged once validated: an invalid request logs neither.
     *
     * @param array<string, int> $extra page, count or status
     */
    private function audit(string $action, string $uid, string $module, string $result, array $extra): void {
        $valid = $this->userManager->get($uid) !== null && in_array($module, ResourceScopeMapper::MODULES, true);
        try {
            $this->logger->info('MCP resource scope admin request', [
                'app' => 'mcp',
                'admin' => $this->session->getUser()?->getUID(),
                'target' => $valid ? $uid : null,
                'action' => $action,
                'result' => $result,
                'module' => $valid ? $module : null,
                'timestamp' => $this->time->getTime(),
            ] + $extra);
        } catch (Throwable) {
            // The log is evidence, not a gate: a failing log backend must not change the answer.
        }
    }

    /** @return array{mode:string, count:?int} the saved scope of one module, for the response of a save */
    private function summary(string $uid, string $module): array {
        $scope = $this->readScope($uid, $module);
        return $scope === null ? ['mode' => 'unrestricted', 'count' => null] : ['mode' => 'restricted', 'count' => count($scope['items'])];
    }

    /**
     * @return array{policyRev:string, items:list<string>}|null
     * @throws PolicyUnavailable when the scope cannot be read: it is never reported as unrestricted by default
     */
    private function readScope(string $uid, string $module): ?array {
        try {
            return $this->scopes->forModule($uid, $module);
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Resource scope unavailable', previous: $e);
        }
    }

    /**
     * @return list<string>|null the items of the body: null for "unrestricted", the list for "restricted"
     * @throws InvalidArgumentException for any other mode, items with "unrestricted", or a non-list
     */
    private function items(): ?array {
        $mode = $this->request->getParam('mode');
        $items = $this->request->getParam('items');
        if ($mode === 'unrestricted' && $items === null) {
            return null;
        }
        if ($mode === 'restricted' && is_array($items) && array_is_list($items)) {
            return $items;
        }
        throw new InvalidArgumentException('Invalid request');
    }

    /** @throws InvalidArgumentException for an unknown user or module */
    private function target(string $uid, string $module): void {
        if (!in_array($module, ResourceScopeMapper::MODULES, true) || $uid === '' || $this->userManager->get($uid) === null) {
            throw new InvalidArgumentException('Invalid request');
        }
    }

    private static function unavailable(PolicyUnavailable $e): JSONResponse {
        return new JSONResponse(['error' => 'Unavailable', 'uncertain' => $e->uncertain], Http::STATUS_SERVICE_UNAVAILABLE);
    }

    /** @throws PolicyUnavailable when the policy state cannot be read */
    private function revision(): string {
        return $this->state->state()['revision'];
    }

    /**
     * @return string the expectedRevision of the body, canonical
     * @throws MissingRevision without one
     * @throws InvalidArgumentException when it is not a canonical decimal string
     */
    private function expectedRevision(): string {
        $expected = $this->request->getParam('expectedRevision');
        if ($expected === null) {
            throw new MissingRevision();
        }
        if (!is_string($expected)) {
            throw new InvalidArgumentException('Invalid request');
        }
        return DecimalCounter::validate($expected);
    }

    /** @return string uid of the signed-in administrator, recorded on the receipt and the audit */
    private function actor(): string {
        $user = $this->session->getUser();
        if ($user === null) {
            throw new InvalidArgumentException('Invalid request');
        }
        return $user->getUID();
    }
}
