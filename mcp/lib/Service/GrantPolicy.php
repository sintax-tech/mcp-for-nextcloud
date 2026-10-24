<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

use Closure;
use InvalidArgumentException;
use OCA\Mcp\Service\Policy\GrantUnknown;
use OCA\Mcp\Service\Policy\PolicyOperationContext;
use OCA\Mcp\Service\Policy\PolicyResolver;
use OCA\Mcp\Service\Policy\PolicyUnavailable;
use OCA\Mcp\Service\Policy\PolicyWriter;
use OCA\Mcp\Service\Policy\Resolution\ScopeResolution;
use OCA\Mcp\Service\Scope\ScopeReader;
use OCP\Config\IUserConfig;
use OCP\IConfig;

/**
 * MCP access policy: global switch, admin eligibility, personal connection and grants for (module, operation).
 * Grants never widen Nextcloud ACLs.
 *
 * Every authorization read is the resolution of PolicyResolver (contracts §4.1–§4.3): an individual Allow or Deny,
 * then the groups that may grant (trusted source, active lifecycle), then the default. Without any group policy that
 * is exactly the 1.0.8 answer. A resolution that is unknown (it depended only on a group of indeterminate policy), an
 * abandoned writer context or a failed read is never answered with a default: it throws PolicyUnavailable, which the
 * entry points answer with 503 and which never revokes anything. The unknown answer is the subtype GrantUnknown, so a
 * listing can omit the one entry that depends on it while a failed read still fails the whole request.
 *
 * Reads go back to the persisted policy, so a change applies to the next operation. Inside within() one operation
 * reuses one snapshot (the controlled reuse of contracts §4.2, T14); a write through this service, or the end of the
 * operation, discards it. The snapshot is held per operation and released in finally, never kept between operations.
 */
class GrantPolicy implements ScopeReader {
    /** Operations an admin can grant per module; Files deliberately has no delete. */
    public const CATALOG = [
        // 'restore' rolls a file back to a stored version and 'create' adds a folder, a copy or a new file;
        // like every operation but read, both start denied. 'share' shares an own file with a person or a
        // group and 'link' creates a public link; they are separate so an admin can allow one without the
        // other, and removing a share asks for the operation of its type. Listing shares stays a 'read'.
        'files' => ['read', 'edit', 'create', 'move', 'restore', 'share', 'link'],
        'notes' => ['read', 'create', 'edit', 'move', 'delete'],
        'deck' => ['read', 'create', 'edit', 'move', 'delete'],
        'contacts' => ['read', 'create', 'edit', 'delete'],
        'tasks' => ['read', 'create', 'edit', 'delete'],
        'calendar' => ['read', 'create', 'edit', 'move', 'delete', 'transfer'],
        // 'create' is what talk_create_group asks for: opening a conversation invites people, so it is denied by
        // default like every other operation that is not a read.
        // Account search; read only, so the model can find who to invite or message.
        'people' => ['read'],
        'talk' => ['read', 'reply', 'attach', 'quote', 'create'],
        // Reading the server log; read only, and the one read that starts denied (see DENIED_READS).
        'logs' => ['read'],
        // Nextcloud Mail, read only: accounts and cached folders. There is no write operation to grant.
        'mail' => ['read'],
    ];

    /**
     * Modules whose read starts denied like a write. The server log shows the file names, URLs and accounts of every
     * user, so nobody may read it by omission: an administrator turns it on user by user.
     */
    public const DENIED_READS = ['logs'];

    /** App id under which every value of this policy is stored. */
    public const APP = 'mcp';
    /** App config key of the global service switch; never core's reserved 'enabled'. */
    public const SERVICE_KEY = 'service_enabled';
    /** User config key of the admin eligibility. */
    public const ELIGIBLE_KEY = 'eligible';
    /** User config key of the personal connection. */
    public const CONNECTED_KEY = 'connected';
    /**
     * App config keys core stable33 owns for every app (AppManager, OC_App, AppConfig lexicon bypass)
     * plus legacy ones; this app must never read or write them.
     */
    public const RESERVED_APP_KEYS = ['enabled', 'installed_version', 'types', 'levels', 'ocsid'];

    /** @var array<string, PolicyOperationContext|null> snapshot of each open operation, by 'u' . uid; null until read */
    private array $operations = [];

    public function __construct(
        private IConfig $config,
        private IUserConfig $userConfig,
        private PolicyWriter $writer,
        private PolicyResolver $resolver,
    ) {}

    /**
     * Runs one operation of $uid (a request of the MCP endpoint, a checkout step) over one policy snapshot: every
     * read of that user inside $work reuses it, so tools/list and a tools/call each see the policy of their own
     * request, consistently, and never one of an earlier request. The snapshot is assembled on the first read and
     * discarded when $work returns or throws. A nested call joins the operation already open.
     *
     * @template T
     * @param string $uid the user of the operation
     * @param Closure(): T $work
     * @return T what $work returned
     */
    public function within(string $uid, Closure $work): mixed {
        $key = 'u' . $uid;
        if (array_key_exists($key, $this->operations)) {
            return $work();
        }
        $this->operations[$key] = null;
        try {
            return $work();
        } finally {
            $context = $this->operations[$key];
            unset($this->operations[$key]);
            $context?->close();
        }
    }

    /**
     * The user of the one operation open now ({@see within()}): the user whose resource scope a gate that is not
     * handed a user applies, the Files gate of the tools and of checkout. The endpoint and checkout each open exactly
     * one operation, for the authenticated user, around everything they run.
     *
     * @return string|null the user; null when no operation, or more than one, is open: the caller must fail closed
     */
    public function operationUser(): ?string {
        if (count($this->operations) !== 1) {
            return null;
        }
        return substr((string)array_key_first($this->operations), 1);
    }

    /**
     * The resource scope of one module, from the open operation's snapshot (contract B.5), read once with it like
     * every other policy value of the operation. Callers ask only after the tool was admitted by its grant (F3 of the
     * B4 review): the scope narrows what the grant allows.
     *
     * @param string $userId authenticated user of the operation
     * @param string $module scope module (decision D1); a module without scope is never restricted
     * @return list<string>|null the allowed resource ids, possibly none, when the module is restricted; null when not
     * @throws PolicyUnavailable when the scope or the policy cannot be read: never answered as "unrestricted"
     */
    public function scopeItems(string $userId, string $module): ?array {
        return $this->read($userId, static function (PolicyOperationContext $context) use ($module): ?array {
            $scope = $context->scope()->resolution($module);
            return match ($scope->mode) {
                ScopeResolution::UNRESTRICTED => null,
                ScopeResolution::RESTRICTED => $scope->items,
                default => throw new PolicyUnavailable('Resource scope unavailable'),
            };
        });
    }

    /**
     * @return bool whether the administrator enabled the MCP service (off by default)
     * @throws PolicyUnavailable when the policy context was abandoned
     */
    public function globalEnabled(): bool {
        $this->writer->assertUsable();
        return $this->config->getAppValue(self::APP, self::SERVICE_KEY, '0') === '1';
    }

    /**
     * @param bool $enabled new state of the global service switch
     * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain
     */
    public function setGlobalEnabled(bool $enabled): void {
        try {
            $this->writer->setGlobalEnabled($enabled);
        } finally {
            $this->discard(null);
        }
    }

    /**
     * @param string $uid Nextcloud user id
     * @return bool whether the user may connect: an individual Allow, or a group that may grant eligibility, and no
     *     individual Deny (off by default, admins included)
     * @throws PolicyUnavailable when the eligibility is unknown or cannot be read
     */
    public function eligible(string $uid): bool {
        return $this->read($uid, static fn (PolicyOperationContext $context): bool => self::known($context->eligible()->allowed));
    }

    /**
     * @param string $uid Nextcloud user id
     * @param bool $enabled new eligibility
     * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain
     */
    public function setEligible(string $uid, bool $enabled): void {
        try {
            $this->writer->setEligible($uid, $enabled);
        } finally {
            $this->discard($uid);
        }
    }

    /**
     * @param string $uid Nextcloud user id
     * @return bool whether the user activated their own connection (off by default)
     * @throws PolicyUnavailable when the policy context was abandoned
     */
    public function connected(string $uid): bool {
        $this->writer->assertUsable();
        return $this->config->getUserValue($uid, self::APP, self::CONNECTED_KEY, '0') === '1';
    }

    /**
     * @param string $uid Nextcloud user id
     * @param bool $enabled new personal connection state
     * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain
     */
    public function setConnected(string $uid, bool $enabled): void {
        try {
            $this->writer->setConnected($uid, $enabled);
        } finally {
            $this->discard($uid);
        }
    }

    /**
     * Users whose eligibility resolves to true, or whose personal connection is on, for the admin filters and the
     * status summary. Eligibility includes the members of the groups that may grant it (PolicyResolver::eligibleUids).
     *
     * @param string $key ELIGIBLE_KEY or CONNECTED_KEY
     * @return list<string> uids, sorted
     * @throws InvalidArgumentException for any other key
     * @throws PolicyUnavailable when the policy cannot be read
     */
    public function flaggedUsers(string $key): array {
        if (!in_array($key, [self::ELIGIBLE_KEY, self::CONNECTED_KEY], true)) {
            throw new InvalidArgumentException('Invalid request');
        }
        if ($key === self::ELIGIBLE_KEY) {
            return $this->resolver->eligibleUids();
        }
        $this->writer->assertUsable();
        $found = iterator_to_array($this->userConfig->searchUsersByValueString(self::APP, $key, '1'), false);
        $uids = array_map('strval', $found);
        sort($uids);
        return array_values(array_unique($uids));
    }

    /**
     * Users who are eligible and activated their connection: the one meaning of "connected" in the status summary and
     * in the matrix filter. A personal connection without eligibility does not count.
     *
     * @return list<string> uids, sorted
     * @throws PolicyUnavailable when the policy cannot be read
     */
    public function connectedUsers(): array {
        return array_values(array_intersect($this->flaggedUsers(self::ELIGIBLE_KEY), $this->flaggedUsers(self::CONNECTED_KEY)));
    }

    /**
     * @param string $uid Nextcloud user id
     * @return bool true only when the service is on, the user is connected and eligible
     * @throws PolicyUnavailable when the answer depends on an eligibility that is unknown or cannot be read
     */
    public function canConnect(string $uid): bool {
        return $this->read($uid, static fn (PolicyOperationContext $context): bool => $context->serviceEnabled()
            && $context->connected() && self::known($context->eligible()->allowed));
    }

    /**
     * The eligibility decision a consent or an issuance acts on, for its writer command (contracts §3.1; F3 of the
     * review of ticket 07): always a fresh read, outside any open operation, that persists no observation, since it
     * runs in the external phase of a writer command.
     *
     * @param string $uid Nextcloud user id
     * @param bool $connection also require the personal connection (an existing lineage); a consent sets it itself
     * @return list<array{groupId:string, gen:string}>|null null when the service is off, the user is not eligible, or
     *     not connected when $connection; otherwise the lifecycles of the groups that made the user eligible ([] for
     *     an individual Allow), which the command must find unchanged under its mutex
     * @throws PolicyUnavailable when the eligibility is unknown or cannot be read
     */
    public function admission(string $uid, bool $connection): ?array {
        $context = $this->resolver->begin($uid, [], [], false);
        try {
            if (!$context->serviceEnabled() || ($connection && !$context->connected())) {
                return null;
            }
            $eligibility = $context->eligible();
            return self::known($eligibility->allowed) ? $context->lifecycles($eligibility->groupIds) : null;
        } finally {
            $context->close();
        }
    }

    /**
     * What keeps a user eligible once the individual value is removed, for a reset of an individual Allow or an undo
     * that restores absence (contracts §3.1, §4.1; F2 of the second reading of 1.1.0): a fresh read in the external
     * phase of the writer command, outside any open operation, persisting no observation.
     *
     * @param string $uid Nextcloud user id
     * @return list<array{groupId:string, gen:string}>|null the lifecycles of the groups that keep the user eligible,
     *     which the command renews under its mutex (PolicyWriter::holdLifecycles()); null when nothing does
     * @throws PolicyUnavailable when the inherited eligibility is unknown or cannot be read
     */
    public function inheritance(string $uid): ?array {
        $context = $this->resolver->begin($uid, [], [], false);
        try {
            $eligibility = $context->inheritedEligibility();
            return self::known($eligibility->allowed) ? $context->lifecycles($eligibility->groupIds) : null;
        } finally {
            $context->close();
        }
    }

    /**
     * @param string $uid Nextcloud user id
     * @param string $module key of CATALOG
     * @param string $operation operation listed for the module in CATALOG
     * @return bool the resolved grant: individual Allow or Deny, else a group that may grant it, else
     *     {@see self::defaultGranted()}
     * @throws InvalidArgumentException for a module or operation outside CATALOG
     * @throws PolicyUnavailable when the grant is unknown or cannot be read
     */
    public function granted(string $uid, string $module, string $operation): bool {
        self::grantKey($module, $operation);
        return $this->read($uid, static fn (PolicyOperationContext $context): bool => self::known($context->granted($module, $operation)->allowed));
    }

    /**
     * Whether any of the operations is granted, as the admission of a tool whose kind of write depends on its
     * arguments (files_share: `share` for a person, `link` for a public link). Three-valued, so the order of the
     * alternatives never changes the answer: a known Allow of any of them is true, even when another one is unknown;
     * without an Allow, an unknown one makes the answer unknown; only when every one is known and denied is it false.
     * The call itself still checks the concrete operation it needs (ShareAccess::assertGranted).
     *
     * @param string $uid Nextcloud user id
     * @param string $module key of CATALOG
     * @param non-empty-list<string> $operations operations of the module
     * @return bool whether at least one of them is granted
     * @throws InvalidArgumentException for an empty list, or a module or operation outside CATALOG
     * @throws PolicyUnavailable when no alternative is granted and one is unknown, or the policy cannot be read
     */
    public function grantedAny(string $uid, string $module, array $operations): bool {
        if ($operations === []) {
            throw new InvalidArgumentException('No operation');
        }
        foreach ($operations as $operation) {
            self::grantKey($module, $operation);
        }
        return $this->read($uid, static function (PolicyOperationContext $context) use ($module, $operations): bool {
            $unknown = false;
            foreach ($operations as $operation) {
                $allowed = $context->granted($module, $operation)->allowed;
                if ($allowed === true) {
                    return true;
                }
                $unknown = $unknown || $allowed === null;
            }
            return $unknown ? self::known(null) : false;
        });
    }

    /**
     * Whether the resource scope of a module can be read in the open operation's snapshot, before any native lookup.
     *
     * @param string $userId authenticated user of the operation
     * @param string $module scope module (decision D1)
     * @throws PolicyUnavailable when the scope of the module, or the policy, cannot be read
     */
    public function assertReadable(string $userId, string $module): void {
        $this->read($userId, static function (PolicyOperationContext $context) use ($module): void {
            if ($context->scope()->resolution($module)->mode === ScopeResolution::UNAVAILABLE) {
                throw new PolicyUnavailable('Resource scope unavailable');
            }
        });
    }

    /**
     * The resource scope of the open operation's snapshot (contract B.5), read once with it like every other policy
     * value of the operation; assertReadable() comes first wherever a native lookup could tell existence apart.
     *
     * @param string $userId authenticated user of the operation
     * @param string $module scope module (decision D1)
     * @param string $resourceId canonical decimal id of the resource
     * @return bool whether the resource is inside the user's scope of that module
     * @throws PolicyUnavailable when the scope or the policy cannot be read
     */
    public function inScope(string $userId, string $module, string $resourceId): bool {
        return $this->read($userId, static fn (PolicyOperationContext $context): bool => $context->scope()->allows($module, [$resourceId]));
    }

    /**
     * What a user who was never given nor denied the grant gets: read is allowed, except in DENIED_READS, and every
     * other operation is denied.
     *
     * @param string $module key of CATALOG
     * @param string $operation operation of the module
     * @return bool the default grant
     */
    public static function defaultGranted(string $module, string $operation): bool {
        return $operation === 'read' && !in_array($module, self::DENIED_READS, true);
    }

    /**
     * @param string $uid Nextcloud user id
     * @param string $module key of CATALOG
     * @param string $operation operation listed for the module in CATALOG
     * @param bool $enabled new grant
     * @throws InvalidArgumentException for a module or operation outside CATALOG
     * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain
     */
    public function setGrant(string $uid, string $module, string $operation, bool $enabled): void {
        try {
            $this->writer->setGrant($uid, $module, $operation, $enabled);
        } finally {
            $this->discard($uid);
        }
    }

    /**
     * The resolved policy of many users over one revision snapshot (PolicyResolver::beginMany), for the admin matrix.
     * The booleans keep their 1.0.8 meaning; null marks a value that is unknown (it depended only on a group of
     * indeterminate policy). origins says where each value comes from: individual_allow, individual_deny, group (with
     * the granting groups), default, invalid or unknown (with the indeterminate groups).
     *
     * @param list<string> $uids Nextcloud user ids
     * @return array<string, array{eligible:?bool, connected:bool, grants:array<string, array<string, ?bool>>, origins:array{eligible:array{source:string, groupIds:list<string>}, grants:array<string, array<string, array{source:string, groupIds:list<string>}>>}}> state by uid
     * @throws PolicyUnavailable when the policy cannot be read
     */
    public function forUsers(array $uids): array {
        $contexts = $this->resolver->beginMany(array_values(array_unique($uids)));
        $out = [];
        try {
            foreach ($contexts as $uid => $context) {
                $eligibility = $context->eligible();
                $state = ['eligible' => $eligibility->allowed, 'connected' => $context->connected(), 'grants' => [],
                    'origins' => ['eligible' => ['source' => $eligibility->source, 'groupIds' => $eligibility->groupIds], 'grants' => []]];
                foreach (self::CATALOG as $module => $operations) {
                    foreach ($operations as $operation) {
                        $grant = $context->granted($module, $operation);
                        $state['grants'][$module][$operation] = $grant->allowed;
                        $state['origins']['grants'][$module][$operation] = ['source' => $grant->source, 'groupIds' => $grant->groupIds];
                    }
                }
                $out[(string)$uid] = $state;
            }
        } finally {
            foreach ($contexts as $context) {
                $context->close();
            }
        }
        return $out;
    }

    /**
     * @param string $module candidate module
     * @param string $operation candidate operation
     * @return bool whether the pair exists in CATALOG
     */
    public static function inCatalog(string $module, string $operation): bool {
        return isset(self::CATALOG[$module]) && in_array($operation, self::CATALOG[$module], true);
    }

    /**
     * @template T
     * @param Closure(PolicyOperationContext): T $read
     * @return T
     * @throws PolicyUnavailable
     */
    private function read(string $uid, Closure $read): mixed {
        $key = 'u' . $uid;
        if (array_key_exists($key, $this->operations)) {
            return $read($this->operations[$key] ??= $this->resolver->begin($uid));
        }
        $context = $this->resolver->begin($uid);
        try {
            return $read($context);
        } finally {
            $context->close();
        }
    }

    /** A write through this service makes the snapshot of the open operation stale: the next read assembles a new one. */
    private function discard(?string $uid): void {
        foreach ($this->operations as $key => $context) {
            if ($uid === null || $key === 'u' . $uid) {
                $context?->close();
                $this->operations[$key] = null;
            }
        }
    }

    /**
     * @return bool the resolved answer
     * @throws GrantUnknown when it is unknown: never a default, never a revocation
     */
    private static function known(?bool $allowed): bool {
        if ($allowed === null) {
            throw new GrantUnknown();
        }
        return $allowed;
    }

    /** @throws InvalidArgumentException for a module or operation outside CATALOG */
    private static function grantKey(string $module, string $operation): string {
        if (!self::inCatalog($module, $operation)) {
            throw new InvalidArgumentException('Unknown module or operation');
        }
        return 'grant_' . $module . '_' . $operation;
    }
}
