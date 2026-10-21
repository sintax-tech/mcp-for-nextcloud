<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use InvalidArgumentException;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\OAuth\ClientMetadataFetcher;
use OCA\Mcp\OAuth\NativeClient;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\CustomClient;
use OCA\Mcp\Access\Grants\GrantMatrix;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Logs\LogsAccess;
use OCA\Mcp\Access\Logs\LogsGroupsConflict;
use OCA\Mcp\Access\Grants\Groups\GroupGrantsService;
use OCA\Mcp\Access\Grants\Groups\GroupSourceUntrusted;
use OCA\Mcp\Access\Policy\Transactions\MissingRevision;
use OCA\Mcp\Access\Policy\Transactions\PolicyConflict;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Grants\Commands\GrantCommands;
use OCA\Mcp\Access\Grants\Commands\ServiceCommands;
use OCA\Mcp\Access\Policy\Receipts\ReceiptUndo;
use OCA\Mcp\Access\Policy\PolicyLimits;
use OCA\Mcp\Access\Policy\Receipts\ReceiptExpired;
use OCA\Mcp\Access\Policy\Receipts\ReceiptNotFound;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * JSON API of the admin permission matrix. Every endpoint is admin-only and CSRF-protected by
 * Nextcloud's defaults (no NoAdminRequired/NoCSRFRequired); the page sends the requesttoken header.
 * Invalid input answers 400 {"error":"Invalid request"} without saying which part was wrong.
 *
 * Every policy write carries expectedRevision, the global policy revision (a decimal string) the page was built
 * from, and goes through an atomic policy command (contracts §2.3):
 * - without it: 428 {"error":"PreconditionRequired"}, nothing written;
 * - stale: 409 {"error":"Conflict", "revision", "state"}, nothing written; revision and state are read after the
 *   rollback, so the page can show what is saved now;
 * - storage failure: 503 {"error":"Unavailable", "uncertain"}; uncertain=true means the write may have committed, so
 *   the page reloads instead of repeating it. No route falls back to defaults on either error;
 * - a group policy on a group that cannot grant (contracts §3.3.5): 422 {"error":"group_source_untrusted"}, nothing
 *   written.
 * Booleans keep their 1.0.8 meaning: true is Allow and false is Deny, never Inherit.
 */
class GrantsController extends Controller {
    /** Pseudo-operation of the bulk endpoint that toggles eligibility instead of a grant. */
    public const ELIGIBLE = 'eligible';
    /** Triestate of POST /api/grants/commands, mapped to the raw value stored ('1', '0' or no key). */
    public const STATES = ['allow' => '1', 'deny' => '0', 'inherit' => null];
    public function __construct(
        string $appName,
        IRequest $request,
        private GrantMatrix $matrix,
        private GrantPolicy $policy,
        private IUserManager $userManager,
        private IConfig $config,
        private LogsAccess $logs,
        private GrantCommands $commands,
        private ServiceCommands $service,
        private ReceiptUndo $undo,
        private PolicyStateMapper $state,
        private IUserSession $session,
        private GroupGrantsService $groups,
        private OAuthStore $oauth,
        private \OCA\Mcp\OAuth\OAuthClientsService $clients,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @param string $search term matched against uid, display name and e-mail
     * @param string $group group id, '' for every user
     * @param int $page 1-based page
     * @param string $filter '' for every user, 'eligible' or 'connected' (see GrantMatrix::FILTERS)
     * @return JSONResponse one matrix page (see GrantMatrix::page) plus the policy revision it was built from, or 400
     */
    public function index(string $search = '', string $group = '', int $page = 1, string $filter = ''): JSONResponse {
        return $this->guard(function () use ($search, $group, $page, $filter): array {
            // Read before the page: a write in between makes the next save a conflict, never a stale accept.
            $revision = $this->revision();
            return $this->matrix->page($search, $group, $page, $filter) + ['revision' => $revision];
        });
    }

    /**
     * Body {expectedRevision, eligible: bool} or {expectedRevision, module, operation, granted: bool}, never both.
     *
     * @param string $uid existing Nextcloud user id
     * @return JSONResponse the user's updated row with {revision, receiptId} (receiptId null when nothing changed),
     *     503 {uncertain: true, revision, receiptId} when the row cannot be read back after the commit, or 400/409/428/503
     */
    public function update(string $uid): JSONResponse {
        return $this->guard(function () use ($uid): array|JSONResponse {
            $user = $this->user($uid);
            $expected = $this->expectedRevision();
            $eligible = $this->request->getParam('eligible');
            $hasGrant = $this->request->getParam('module') !== null || $this->request->getParam('operation') !== null || $this->request->getParam('granted') !== null;
            if ($eligible !== null && !$hasGrant) {
                $key = GrantPolicy::ELIGIBLE_KEY;
                $value = self::bool($eligible);
            } elseif ($eligible === null && $hasGrant) {
                [$module, $operation] = self::grant($this->request->getParam('module'), $this->request->getParam('operation'));
                $key = (string)PolicyEventPayload::userKey($module, $operation);
                $value = self::bool($this->request->getParam('granted'));
            } else {
                throw new InvalidArgumentException('Invalid request');
            }
            $result = $this->commands->applyCells($expected, $this->actor(), PolicyEventPayload::ACTION_UPDATE,
                [['uid' => $uid, 'key' => $key, 'value' => $value ? '1' : '0']]);
            return self::afterCommit(['revision' => $result['revision'], 'receiptId' => $result['receiptId']], fn (): array => $this->matrix->rows([$user])[0]);
        }, fn (): array => $this->usersState([$uid]));
    }

    /**
     * Body {expectedRevision, uids: list<string> (1..PAGE_SIZE), module: string|null, operation: string,
     * granted: bool}; operation "eligible" with module null toggles eligibility. One transaction: every uid is
     * validated first, and all are written or none is.
     *
     * @return JSONResponse {updated: int, revision, receiptId}, or 400/409/428/503
     */
    public function bulk(): JSONResponse {
        $uids = $this->request->getParam('uids');
        return $this->guard(function () use ($uids): array {
            if (!is_array($uids) || $uids === [] || count($uids) > GrantMatrix::PAGE_SIZE || !array_is_list($uids)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $module = $this->request->getParam('module');
            $operation = $this->request->getParam('operation');
            $granted = self::bool($this->request->getParam('granted'));
            if (!($operation === self::ELIGIBLE && $module === null)) {
                [$module, $operation] = self::grant($module, $operation);
            }
            $key = (string)PolicyEventPayload::userKey($module, $operation);
            $uids = array_values(array_unique(array_map(fn ($uid) => $this->user(is_string($uid) ? $uid : '')->getUID(), $uids)));
            $expected = $this->expectedRevision();
            $result = $this->commands->applyCells($expected, $this->actor(), PolicyEventPayload::ACTION_BULK,
                array_map(static fn (string $uid): array => ['uid' => $uid, 'key' => $key, 'value' => $granted ? '1' : '0'], $uids));
            return ['updated' => count($uids), 'revision' => $result['revision'], 'receiptId' => $result['receiptId']];
        }, fn (): array => $this->usersState(is_array($uids) ? $uids : []));
    }

    /**
     * Body {expectedRevision, commands: list<{uid, module: string|null, operation, state}>}: several users and
     * operations in one transaction (at most PolicyLimits::MAX_UIDS users and MAX_CELLS distinct cells). state is
     * "allow", "deny" or "inherit" (removes the override); operation "eligible" with module null targets eligibility.
     * A boolean "granted" is refused here, so a cell never mixes the two forms, and every command names all four
     * keys: module is null for eligibility, never left out.
     *
     * @return JSONResponse {revision, changed: int, receiptId}, or 400/409/428/503
     */
    public function commands(): JSONResponse {
        $commands = $this->request->getParam('commands');
        return $this->guard(function () use ($commands): array {
            // Counted before duplicates are dropped too: a body is never larger than the limit it may write.
            if (!is_array($commands) || $commands === [] || !array_is_list($commands) || count($commands) > PolicyLimits::MAX_CELLS) {
                throw new InvalidArgumentException('Invalid request');
            }
            $cells = [];
            foreach ($commands as $command) {
                // Exactly these four keys: module is required even for eligibility, as an explicit null.
                if (!is_array($command) || array_diff(array_keys($command), ['uid', 'module', 'operation', 'state']) !== []
                    || !array_key_exists('module', $command)
                    || !is_string($command['uid'] ?? null) || !is_string($command['operation'] ?? null)
                    || !(is_string($command['module'] ?? null) || ($command['module'] ?? null) === null)
                    || !is_string($command['state'] ?? null) || !array_key_exists($command['state'], self::STATES)) {
                    throw new InvalidArgumentException('Invalid request');
                }
                $key = PolicyEventPayload::userKey($command['module'] ?? null, $command['operation']);
                if ($key === null) {
                    throw new InvalidArgumentException('Invalid request');
                }
                $cells[] = ['uid' => $command['uid'], 'key' => $key, 'value' => self::STATES[$command['state']]];
            }
            $expected = $this->expectedRevision();
            $result = $this->commands->applyCells($expected, $this->actor(), PolicyEventPayload::ACTION_COMMANDS, $cells, $this->inheritance());
            return ['revision' => $result['revision'], 'changed' => $result['changed'], 'receiptId' => $result['receiptId']];
        }, fn (): array => $this->usersState(is_array($commands) ? array_map(static fn ($c) => is_array($c) ? ($c['uid'] ?? '') : '', $commands) : []));
    }

    /**
     * Body {receiptId, expectedRevision}: undoes the cells of one of the caller's receipts, restoring each raw
     * value, absence included. expectedRevision must be the revision that receipt's command committed.
     *
     * @return JSONResponse {revision, changed: int}; 404 for an unknown or another administrator's receipt, 410 when
     *     it expired, 409 when anything changed since, 422 {"error":"ScopeInactive"} for a resource scope receipt of a
     *     module whose activation barrier is closed, or 400/428/503
     */
    public function undo(): JSONResponse {
        $receiptId = $this->request->getParam('receiptId');
        return $this->guard(function () use ($receiptId): array|JSONResponse {
            if (!is_string($receiptId)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $expected = $this->expectedRevision();
            try {
                return $this->undo->restoreReceipt($receiptId, $expected, $this->actor(), $this->inheritance());
            } catch (ReceiptNotFound) {
                return new JSONResponse(['error' => 'NotFound'], Http::STATUS_NOT_FOUND);
            } catch (ReceiptExpired) {
                return new JSONResponse(['error' => 'Gone'], Http::STATUS_GONE);
            } catch (\OCA\Mcp\Access\ResourceScope\ScopeInactive) {
                // A resource scope receipt while its module's activation barrier is closed: nothing is restored.
                return new JSONResponse(['error' => 'ScopeInactive'], Http::STATUS_UNPROCESSABLE_ENTITY);
            }
        }, fn (): ?array => is_string($receiptId) ? $this->receiptState($receiptId) : null);
    }

    /**
     * Body {expectedRevision, enabled: bool}.
     *
     * @return JSONResponse {enabled: bool, revision}, or 400/409/428/503
     */
    public function service(): JSONResponse {
        return $this->guard(function (): array|JSONResponse {
            $enabled = self::bool($this->request->getParam('enabled'));
            $result = $this->service->adminSetGlobalEnabled($this->expectedRevision(), $this->actor(), $enabled);
            return self::afterCommit(['revision' => $result['revision']], fn (): array => ['enabled' => $this->policy->globalEnabled()]);
        }, fn (): array => ['enabled' => $this->policy->globalEnabled()]);
    }

    /**
     * The stored policy of one group, as the group page shows it before a preview.
     *
     * @param string $gid group id, exact bytes
     * @return JSONResponse {groupId, trusted, state, grants, revision}: state is the lifecycle (active, pending_delete,
     *     needs_confirm) or null without a policy; trusted says whether the group can grant now; or 400/503
     */
    public function group(string $gid = ''): JSONResponse {
        return $this->guard(function () use ($gid): array {
            $revision = $this->revision();
            return $this->groups->state($gid) + ['revision' => $revision];
        });
    }

    /**
     * Body {expectedRevision, gid, grants: {module: list<operation>}}: the preview of a group's whole policy, with
     * "_system": ["eligible"] for eligibility. Nothing changes; the answer's previewId is what the save presents, for
     * PolicyLimits::PREVIEW_TTL seconds. The set is saved for the live group, future members included.
     *
     * @return JSONResponse {previewId, revision, groupId, grants, current, state, members, trusted, withdraws, revokes}:
     *     members is the member count enumerated now, withdraws whether eligibility is taken away and revokes how many
     *     credential holders lose their last source (they are signed out, and undo does not reconnect them); or
     *     400/409/422/428/503
     */
    public function previewGroup(): JSONResponse {
        $gid = $this->request->getParam('gid');
        return $this->guard(function () use ($gid): array {
            if (!is_string($gid)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $expected = $this->expectedRevision();
            return $this->groups->preview($expected, $this->actor(), $gid, $this->request->getParam('grants'));
        }, fn (): ?array => is_string($gid) ? ['group' => $this->groups->state($gid)] : null);
    }

    /**
     * Body {expectedRevision, previewId, gid, grants}: saves the previewed set as the group's whole policy. Saving the
     * current set of a group in needs_confirm is "Confirm current policy". expectedRevision is the preview's revision;
     * gid and grants must be the preview's.
     *
     * @return JSONResponse {revision, changed, state, revoked}; 409 {error, revision, state: {group, preview}} with a
     *     new preview when the revision, the member set or the group's lifecycle changed since (nothing written); 404
     *     for an unknown or another administrator's preview, 410 when it expired; or 400/422/428/503
     */
    public function saveGroup(): JSONResponse {
        $gid = $this->request->getParam('gid');
        $grants = $this->request->getParam('grants');
        return $this->guard(function () use ($gid, $grants): array|JSONResponse {
            $previewId = $this->request->getParam('previewId');
            if (!is_string($gid) || !is_string($previewId)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $expected = $this->expectedRevision();
            try {
                return $this->groups->save($expected, $this->actor(), $gid, $grants, $previewId);
            } catch (ReceiptNotFound) {
                return new JSONResponse(['error' => 'NotFound'], Http::STATUS_NOT_FOUND);
            } catch (ReceiptExpired) {
                return new JSONResponse(['error' => 'Gone'], Http::STATUS_GONE);
            }
        }, fn (): ?array => $this->groupConflictState($gid, $grants));
    }

    /**
     * Current OAuth client settings for the admin page.
     *
     * @return JSONResponse {hosts, hostsDefault, nativeClientEnabled, nativeClientId, nativeRedirectUris, customClients,
     *     revision}
     */
    public function oauthClients(): JSONResponse {
        return $this->guard(fn (): array => $this->oauthState());
    }

    /**
     * Body {expectedRevision, hosts?: list<string>, nativeClientEnabled?: bool, customClients?: list<{id?, name,
     * enabled, redirectUris}>}. customClients replaces the whole list of self-hosted clients; an entry without id is a
     * new client. The whole change is one policy command (OAuthClientsService): the page's revision is compared first
     * (428 without one, 409 {error, revision, state} when the page is outdated, nothing written), the stored list is
     * read and the request validated inside the transaction, and turning the native client off, turning a self-hosted
     * one off, deleting it or removing one of its redirect URIs deletes that client's grants, codes and spent refresh
     * records in the same transaction, so nothing issued for a withdrawn client or URI outlives the decision and a
     * failed delete leaves nothing withdrawn. An empty host list is rejected because at least one host must stay trusted.
     *
     * @return JSONResponse the same shape as oauthClients() with the new revision, 400, 409, 428 or 503
     */
    public function updateOauthClients(): JSONResponse {
        return $this->guard(function (): array|JSONResponse {
            $expected = $this->expectedRevision();
            $hosts = $this->request->getParam('hosts');
            $native = $this->request->getParam('nativeClientEnabled');
            $custom = $this->request->getParam('customClients');
            if ($hosts === null && $native === null && $custom === null) {
                throw new InvalidArgumentException('Invalid request');
            }
            $this->clients->update($expected, $hosts === null ? null : self::hosts($hosts),
                $native === null ? null : self::bool($native), $custom);
            return self::afterCommit([], fn (): array => $this->oauthState());
        }, fn (): array => $this->oauthState());
    }

    /**
     * Who may read the server log besides the administrators, for the "Server log" block.
     *
     * @return JSONResponse {groups, version, revision, missing, allGroups, available, logType}
     */
    public function logsAccess(): JSONResponse {
        return $this->guard(fn (): array => $this->logsState());
    }

    /**
     * Body {groups: list<string>, previous: list<string>, version: int, expectedRevision: string}: the groups whose
     * members pass the role gate of the logs module, and the list, list version and policy revision the page showed
     * when the change was made. Both versions are checked in one gate: when either differs, another tab or
     * administrator saved first, the answer is 409 {error: "Conflict", revision, state} and nothing is written, so a
     * stale save never puts a removed group back. An empty list leaves the administrators only.
     *
     * @return JSONResponse the same shape as logsAccess(), 400 for an invalid body, 409, 428 or 503
     */
    public function updateLogsAccess(): JSONResponse {
        $groups = $this->request->getParam('groups');
        $previous = $this->request->getParam('previous');
        $version = $this->request->getParam('version');
        $valid = static fn (mixed $list): bool => is_array($list) && array_is_list($list) && count($list) <= GrantMatrix::MAX_GROUPS;
        if (!$valid($groups) || !$valid($previous) || !is_int($version) || $version < 0) {
            return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
        }
        return $this->guard(function () use ($groups, $previous, $version): array|JSONResponse {
            $expected = $this->expectedRevision();
            try {
                $this->logs->setGroups($groups, $previous, $version, $expected, $this->actor());
            } catch (LogsGroupsConflict) {
                throw new PolicyConflict();
            }
            return self::afterCommit([], fn (): array => $this->logsState());
        }, fn (): array => $this->logsState());
    }

    /**
     * The body of a refused group save: the group as stored now and a new preview of the same set, so the page can
     * show what changed and save again. The new preview is null when it cannot be taken (the group can no longer
     * grant, the set is invalid, or the state moved again).
     *
     * @return array{group: array, preview: ?array}|null null for a request without a group id
     */
    private function groupConflictState(mixed $gid, mixed $grants): ?array {
        if (!is_string($gid)) {
            return null;
        }
        $state = ['group' => $this->groups->state($gid), 'preview' => null];
        try {
            $state['preview'] = $this->groups->preview($this->revision(), $this->actor(), $gid, $grants);
        } catch (InvalidArgumentException|GroupSourceUntrusted|PolicyConflict|PolicyUnavailable) {
        }
        return $state;
    }

    /** @return array{groups:list<string>, version:int, revision:string, missing:list<string>, allGroups:list<array{id:string, displayName:string}>, available:bool, logType:string} */
    private function logsState(): array {
        $revision = $this->revision();
        $state = $this->logs->state();
        return [
            'groups' => $state['groups'],
            'version' => $state['version'],
            'revision' => $revision,
            'missing' => $this->logs->missingGroups($state['groups']),
            'allGroups' => $this->matrix->groups(),
            'available' => $this->logs->available(),
            'logType' => $this->logs->logType(),
        ];
    }

    /**
     * @return array{hosts:list<string>, hostsDefault:bool, nativeClientEnabled:bool, nativeClientId:string,
     *     nativeRedirectUris:list<string>, revision:string, customClients:list<array{id:string, clientId:string,
     *     name:string, enabled:bool, redirectUris:list<string>}>}
     */
    private function oauthState(): array {
        // Read before the lists: a write in between makes the next save a conflict, never a stale accept.
        $revision = $this->revision();
        $raw = $this->config->getAppValue($this->appName, ClientMetadataFetcher::HOSTS_KEY, '');
        $hosts = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $h) => $h !== ''));
        $default = $hosts === [];
        return [
            'hosts' => $default ? explode(',', ClientMetadataFetcher::DEFAULT_HOSTS) : $hosts,
            'hostsDefault' => $default,
            'nativeClientEnabled' => $this->clients->nativeEnabled(),
            'nativeClientId' => NativeClient::CLIENT_ID,
            'nativeRedirectUris' => NativeClient::REDIRECT_URIS,
            'revision' => $revision,
            'customClients' => array_map(static fn (array $client): array => [
                'id' => $client['id'],
                'clientId' => CustomClient::clientId($client['id']),
                'name' => $client['name'],
                'enabled' => $client['enabled'],
                'redirectUris' => $client['redirectUris'],
            ], $this->clients->stored()),
        ];
    }

    /**
     * @param mixed $value request value, which must be a non-empty list of bare host names
     * @return list<string> validated lowercase hosts without surrounding whitespace or duplicates
     * @throws InvalidArgumentException for an empty list, a non-string or a host with scheme, port, path, wildcard or space
     */
    private static function hosts(mixed $value): array {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            throw new InvalidArgumentException('Invalid request');
        }
        foreach ($value as $index => $host) {
            if (!is_string($host)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $host = strtolower(trim($host));
            if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/D', $host) !== 1 || strlen($host) > 253) {
                throw new InvalidArgumentException('Invalid request');
            }
            $value[$index] = $host;
        }
        return array_values(array_unique($value));
    }

    /**
     * Runs one route and maps the policy errors; nothing here falls back to defaults.
     *
     * @param callable():(array<string, mixed>|JSONResponse) $action the route body
     * @param (callable():(array<string, mixed>|null))|null $state fresh state for a 409 body, read after the rollback
     */
    private function guard(callable $action, ?callable $state = null): JSONResponse {
        try {
            $result = $action();
            return $result instanceof JSONResponse ? $result : new JSONResponse($result);
        } catch (MissingRevision) {
            return new JSONResponse(['error' => 'PreconditionRequired'], Http::STATUS_PRECONDITION_REQUIRED);
        } catch (PolicyConflict) {
            try {
                return new JSONResponse(['error' => 'Conflict', 'revision' => $this->revision(), 'state' => $state === null ? null : $state()],
                    Http::STATUS_CONFLICT);
            } catch (PolicyUnavailable $e) {
                return self::unavailable($e);
            }
        } catch (PolicyUnavailable $e) {
            return self::unavailable($e);
        } catch (GroupSourceUntrusted) {
            return new JSONResponse(['error' => 'group_source_untrusted'], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (InvalidArgumentException) {
            return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * The answer of a write that already committed. A re-read for the response that fails afterwards (the user row,
     * the switch, the logs state) must not turn into "nothing was written": the answer is 503 with uncertain=true, so
     * the page reloads instead of repeating the write, and it still carries the writer's revision and receiptId, so
     * the undo stays reachable.
     *
     * @param array<string, mixed> $result what the writer returned for the response
     * @param callable():array<string, mixed> $read the state read after the commit
     */
    private static function afterCommit(array $result, callable $read): array|JSONResponse {
        try {
            return $read() + $result;
        } catch (\Throwable) {
            return new JSONResponse(['error' => 'Unavailable', 'uncertain' => true] + $result, Http::STATUS_SERVICE_UNAVAILABLE);
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
        return \OCA\Mcp\Access\Policy\DecimalCounter::validate($expected);
    }

    /**
     * What a user inherits once an individual Allow of eligibility is removed (inherit, or an undo that restores
     * absence): the writer cuts only when nothing keeps the user eligible, and refuses with 503 when that is unknown.
     *
     * @return \Closure(string): (list<array{groupId:string, gen:string}>|null)
     */
    private function inheritance(): \Closure {
        return fn (string $uid): ?array => $this->policy->inheritance($uid);
    }

    /** @return string uid of the signed-in administrator, recorded on receipts and audit */
    private function actor(): string {
        $user = $this->session->getUser();
        if ($user === null) {
            throw new InvalidArgumentException('Invalid request');
        }
        return $user->getUID();
    }

    /**
     * The current rows of a receipt's users for a 409 of its undo: only for the caller's own, valid receipt.
     *
     * @return array{users: list<array<string, mixed>>}|null null when the receipt cannot be read for the caller
     */
    private function receiptState(string $receiptId): ?array {
        $uids = $this->undo->receiptUsers($receiptId, $this->actor());
        return $uids === null ? null : $this->usersState($uids);
    }

    /**
     * @param array<mixed> $uids uids of the request, as sent
     * @return array{users: list<array<string, mixed>>} fresh rows of those that exist
     */
    private function usersState(array $uids): array {
        $users = [];
        foreach (array_unique(array_filter($uids, 'is_string')) as $uid) {
            $user = $uid === '' ? null : $this->userManager->get($uid);
            if ($user !== null) {
                $users[] = $user;
            }
            if (count($users) >= PolicyLimits::MAX_UIDS) {
                break;
            }
        }
        return ['users' => $this->matrix->rows($users)];
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
     * @param mixed $module module id from the request body, validated against GrantPolicy::CATALOG
     * @param mixed $operation operation id from the request body, validated against GrantPolicy::CATALOG
     * @return array{0:string, 1:string}
     * @throws InvalidArgumentException when the pair is not in GrantPolicy::CATALOG
     */
    private static function grant(mixed $module, mixed $operation): array {
        if (!is_string($module) || !is_string($operation) || !GrantPolicy::inCatalog($module, $operation)) {
            throw new InvalidArgumentException('Invalid request');
        }
        return [$module, $operation];
    }

    /**
     * @param mixed $value request value, which must be a JSON boolean
     * @return bool validated boolean value
     * @throws InvalidArgumentException unless the value is a JSON boolean
     */
    private static function bool(mixed $value): bool {
        if (!is_bool($value)) {
            throw new InvalidArgumentException('Invalid request');
        }
        return $value;
    }
}
