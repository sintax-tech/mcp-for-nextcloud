<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Events;

use OCA\Mcp\Access\Policy\PolicyLimits;

use OCA\Mcp\Access\Policy\DecimalCounter;

use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Access\Grants\GrantPolicy;
use UnexpectedValueException;

/**
 * The payload of each policy event kind written by the administrative commands (contracts §2.3, §3.2), built and
 * read back through one validator so the stored form never drifts from what undo and the future audit delivery read.
 *
 * A change is {uid, key, before, after}: uid is null for an app-wide setting; before/after hold the exact raw value,
 * and null means the key was absent. Presence and value are therefore one field, and absent stays distinct from '0'.
 *
 * - receipt (version 1): {v, actor, action, fromRevision, revision, changes}. Grant commands from the admin matrix
 *   and resource scope writes write one; revision is the revision the command committed, fromRevision + 1; changes
 *   are user cells only, 1..PolicyLimits::MAX_CELLS of them, each with before !== after.
 *
 * A resource scope change is a user cell too, under the key scope_<module> (contracts B.4): before/after hold the raw
 * scope, null for unrestricted (no mode row) or the canonical JSON list of its resource ids in ascending numeric
 * order ('[]' is restricted to nothing). Undo writes that raw scope back, absence included.
 * - audit (version 1): {v, actor, action, fromRevision, revision, receiptId, undoOf, changes}. One per committed
 *   administrative command. A command that has a receipt links it and leaves changes empty (the receipt holds them);
 *   undo links the receipt it restored in undoOf; service and logs-access changes carry their single app-wide change.
 *
 *   A group save (ACTION_GROUP) carries one group change instead: {gid, key: 'group_grants', before, after}, where
 *   before/after are groupSnapshot() strings of the group's lifecycle state and grant rows, null when it had none.
 * - preview (version 1): {v, actor, gid, revision, grants, memberHash, life}. Written by a group preview, never
 *   delivered: revision is the one the preview was taken at (no CAS), grants the target set, memberHash the SHA-256 of
 *   the member list sorted by bytes as JSON, life the lifecycle {state, gen} seen then, or null.
 * - cleanup (version 1): {v, scope, uid, cut, epoch}. Written with every revocation, in its transaction; the physical
 *   delete runs after the commit and may repeat (contracts §3.2). scope 'user' names uid and the generation cut (every
 *   credential of uid with auth_gen <= cut goes) and leaves epoch null; scope 'global' names the new auth epoch (every
 *   credential below it goes) and leaves uid and cut null. A cleanup never removes a credential issued after it.
 */
final class PolicyEventPayload {
    public const VERSION = 1;
    public const ACTION_UPDATE = 'grants.update';
    public const ACTION_BULK = 'grants.bulk';
    public const ACTION_COMMANDS = 'grants.commands';
    public const ACTION_UNDO = 'grants.undo';
    public const ACTION_SERVICE = 'service';
    public const ACTION_LOGS = 'logs_access';
    public const ACTION_SCOPE = 'scope.update';
    /** A group policy save (ticket 08); its audit carries one group change and no receipt. */
    public const ACTION_GROUP = 'group_grants';
    /** Key of the group change an ACTION_GROUP audit carries. */
    public const GROUP_KEY = 'group_grants';
    /** The system pseudo-grant a group row may carry besides the CATALOG: eligibility. */
    public const SYSTEM_MODULE = '_system';
    public const SYSTEM_ELIGIBLE = 'eligible';
    /** Grant commands of the matrix, written through \OCA\Mcp\Access\Grants\Commands\GrantCommands::applyCells(). */
    public const GRANT_ACTIONS = [self::ACTION_UPDATE, self::ACTION_BULK, self::ACTION_COMMANDS];
    /** Commands whose changes can be undone through a receipt: the grant commands and the scope write. */
    public const RECEIPT_ACTIONS = [...self::GRANT_ACTIONS, self::ACTION_SCOPE];
    /** Prefix of the user cell holding the resource scope of one module. */
    public const SCOPE_PREFIX = 'scope_';
    public const ACTIONS = [...self::RECEIPT_ACTIONS, self::ACTION_UNDO, self::ACTION_SERVICE, self::ACTION_LOGS, self::ACTION_GROUP];
    public const SCOPE_USER = 'user';
    public const SCOPE_GLOBAL = 'global';
    /** App-wide keys an audit change may name, with a null uid. */
    public const APP_KEYS = [GrantPolicy::SERVICE_KEY, 'logs_groups'];

    /**
     * @param string $actor uid of the administrator who sent the command
     * @param string $action one of RECEIPT_ACTIONS
     * @param string $fromRevision revision the command read under the mutex
     * @param string $revision revision the command commits
     * @param list<array{uid:string, key:string, before:?string, after:?string}> $changes changed user cells
     * @return array<string, mixed> a valid receipt payload
     * @throws UnexpectedValueException when any part is invalid
     */
    public static function receipt(string $actor, string $action, string $fromRevision, string $revision, array $changes): array {
        return self::validate(PolicyEventsMapper::KIND_RECEIPT, ['v' => self::VERSION, 'actor' => $actor, 'action' => $action,
            'fromRevision' => $fromRevision, 'revision' => $revision, 'changes' => $changes]);
    }

    /**
     * @param string|null $actor uid of the administrator, or null for an internal command
     * @param string $action one of ACTIONS
     * @param string $fromRevision revision the command read under the mutex
     * @param string $revision revision the command commits
     * @param string|null $receiptId the receipt of this same command, if it has one
     * @param string|null $undoOf the receipt this command restored, for an undo
     * @param list<array{uid:?string, key:string, before:?string, after:?string}> $changes changes no receipt holds
     * @return array<string, mixed> a valid audit payload
     * @throws UnexpectedValueException when any part is invalid
     */
    public static function audit(?string $actor, string $action, string $fromRevision, string $revision, ?string $receiptId, ?string $undoOf, array $changes): array {
        return self::validate(PolicyEventsMapper::KIND_AUDIT, ['v' => self::VERSION, 'actor' => $actor, 'action' => $action,
            'fromRevision' => $fromRevision, 'revision' => $revision, 'receiptId' => $receiptId, 'undoOf' => $undoOf, 'changes' => $changes]);
    }

    /**
     * @param string $actor uid of the administrator who asked for the preview
     * @param string $gid the group, exact bytes
     * @param string $revision policy revision the preview was taken at
     * @param array<string, list<string>> $grants the target set, as groupGrants() returns it
     * @param string $memberHash memberHash() of the member list enumerated for the preview
     * @param array{state:string, gen:string}|null $life the group's lifecycle seen by the preview, or null
     * @return array<string, mixed> a valid preview payload
     * @throws UnexpectedValueException when any part is invalid
     */
    public static function preview(string $actor, string $gid, string $revision, array $grants, string $memberHash, ?array $life): array {
        return self::validate(PolicyEventsMapper::KIND_PREVIEW, ['v' => self::VERSION, 'actor' => $actor, 'gid' => $gid,
            'revision' => $revision, 'grants' => $grants, 'memberHash' => $memberHash, 'life' => $life]);
    }

    /**
     * Validates and normalizes the grant set of a group: module => operations, each pair in the CATALOG or
     * _system/eligible. Duplicates are dropped, modules and operations sorted bytewise; modules left without operations
     * disappear, so an empty set is [].
     *
     * @param mixed $grants candidate set, as decoded from JSON
     * @return array<string, list<string>> the canonical set
     * @throws \InvalidArgumentException for any other shape, module or operation
     */
    public static function groupGrants(mixed $grants): array {
        if (!is_array($grants)) {
            throw new \InvalidArgumentException('Invalid group grants');
        }
        $set = [];
        foreach ($grants as $module => $operations) {
            if (!is_string($module) || !is_array($operations) || !array_is_list($operations)) {
                throw new \InvalidArgumentException('Invalid group grants');
            }
            foreach ($operations as $operation) {
                if (!is_string($operation) || !(GrantPolicy::inCatalog($module, $operation)
                    || ($module === self::SYSTEM_MODULE && $operation === self::SYSTEM_ELIGIBLE))) {
                    throw new \InvalidArgumentException('Invalid group grants');
                }
                $set[$module][$operation] = $operation;
            }
        }
        ksort($set, SORT_STRING);
        foreach ($set as $module => $operations) {
            $operations = array_values($operations);
            sort($operations, SORT_STRING);
            $set[$module] = $operations;
        }
        return $set;
    }

    /** @return bool whether the canonical set grants eligibility */
    public static function grantsEligibility(array $grants): bool {
        return in_array(self::SYSTEM_ELIGIBLE, $grants[self::SYSTEM_MODULE] ?? [], true);
    }

    /**
     * The audit form of a group's stored policy.
     *
     * @param string|null $state lifecycle state, or null without a lifecycle
     * @param array<string, list<string>> $grants canonical stored rows
     * @return string|null JSON {state, grants}, or null when the group has neither a lifecycle nor rows
     */
    public static function groupSnapshot(?string $state, array $grants): ?string {
        if ($state === null && $grants === []) {
            return null;
        }
        return json_encode(['state' => $state, 'grants' => (object)$grants], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The fingerprint a preview stores and a save compares (contracts §2.3): the whole member list, not its count.
     *
     * @param list<string> $uids member uids, in any order, without duplicates
     * @return string SHA-256 hex of the JSON list sorted by bytes
     */
    public static function memberHash(array $uids): string {
        sort($uids, SORT_STRING);
        return hash('sha256', json_encode($uids, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param string $uid owner of the revoked credentials
     * @param string $cut canonical generation: every credential of $uid at or below it is invalid
     * @return array<string, mixed> a valid cleanup payload
     * @throws UnexpectedValueException when any part is invalid
     */
    public static function cleanupUser(string $uid, string $cut): array {
        return self::validate(PolicyEventsMapper::KIND_CLEANUP, ['v' => self::VERSION, 'scope' => self::SCOPE_USER,
            'uid' => $uid, 'cut' => $cut, 'epoch' => null]);
    }

    /**
     * @param string $epoch the auth epoch just committed: every credential below it is invalid
     * @return array<string, mixed> a valid cleanup payload
     * @throws UnexpectedValueException when any part is invalid
     */
    public static function cleanupGlobal(string $epoch): array {
        return self::validate(PolicyEventsMapper::KIND_CLEANUP, ['v' => self::VERSION, 'scope' => self::SCOPE_GLOBAL,
            'uid' => null, 'cut' => null, 'epoch' => $epoch]);
    }

    /**
     * Checks a payload of a receipt or audit event exactly: no missing, extra or mistyped field.
     *
     * @param string $kind one of PolicyEventsMapper::KINDS
     * @param array<array-key, mixed> $payload as built or as read back from storage
     * @return array<string, mixed> the same payload
     * @throws UnexpectedValueException when the payload does not match its kind
     */
    public static function validate(string $kind, array $payload): array {
        if ($kind === PolicyEventsMapper::KIND_CLEANUP) {
            return self::validateCleanup($payload);
        }
        if ($kind === PolicyEventsMapper::KIND_PREVIEW) {
            return self::validatePreview($payload);
        }
        $receipt = $kind === PolicyEventsMapper::KIND_RECEIPT;
        if (!$receipt && $kind !== PolicyEventsMapper::KIND_AUDIT) {
            throw new UnexpectedValueException('No payload is defined for this policy event kind');
        }
        $fields = $receipt
            ? ['v', 'actor', 'action', 'fromRevision', 'revision', 'changes']
            : ['v', 'actor', 'action', 'fromRevision', 'revision', 'receiptId', 'undoOf', 'changes'];
        $keys = array_keys($payload);
        sort($keys);
        sort($fields);
        if ($keys !== $fields || ($payload['v'] ?? null) !== self::VERSION) {
            throw new UnexpectedValueException('Invalid policy event payload');
        }
        $actor = $payload['actor'];
        $action = $payload['action'];
        $valid = (is_string($actor) ? self::validUid($actor) : (!$receipt && $actor === null))
            && in_array($action, $receipt ? self::RECEIPT_ACTIONS : self::ACTIONS, true)
            && is_string($payload['fromRevision']) && is_string($payload['revision'])
            && self::nextRevision($payload['fromRevision'], $payload['revision'])
            && is_array($payload['changes']) && array_is_list($payload['changes'])
            && count($payload['changes']) <= PolicyLimits::MAX_CELLS
            && (!$receipt || $payload['changes'] !== []);
        if ($valid && !$receipt) {
            $valid = self::optionalId($payload['receiptId']) && self::optionalId($payload['undoOf'])
                && ($payload['receiptId'] === null || $payload['changes'] === [])
                && ($action === self::ACTION_UNDO) === ($payload['undoOf'] !== null);
        }
        if (!$valid) {
            throw new UnexpectedValueException('Invalid policy event payload');
        }
        foreach ($payload['changes'] as $change) {
            $group = is_array($change) && array_key_exists('gid', $change);
            // A group change belongs to a group save, alone; a group save carries nothing else.
            if ($group !== ($action === self::ACTION_GROUP) || !($group ? self::validGroupChange($change) : self::validChange($change, $receipt))) {
                throw new UnexpectedValueException('Invalid policy event payload');
            }
        }
        if ($action === self::ACTION_GROUP && count($payload['changes']) !== 1) {
            throw new UnexpectedValueException('Invalid policy event payload');
        }
        if ($receipt) {
            // A scope receipt holds exactly one scope cell, and a grant receipt holds none.
            $scopeCells = count(array_filter($payload['changes'], static fn (array $change): bool => self::scopeModule((string)$change['key']) !== null));
            $scope = $action === self::ACTION_SCOPE;
            if ($scope ? ($scopeCells !== 1 || count($payload['changes']) !== 1) : $scopeCells !== 0) {
                throw new UnexpectedValueException('Invalid policy event payload');
            }
        }
        return $payload;
    }

    /**
     * @param string $module catalog module, or null for eligibility
     * @param string $operation catalog operation, or GrantPolicy::ELIGIBLE_KEY with a null module
     * @return string|null the user preference key of the cell, or null when the pair names no cell
     */
    public static function userKey(?string $module, string $operation): ?string {
        if ($module === null) {
            return $operation === GrantPolicy::ELIGIBLE_KEY ? GrantPolicy::ELIGIBLE_KEY : null;
        }
        return GrantPolicy::inCatalog($module, $operation) ? 'grant_' . $module . '_' . $operation : null;
    }

    /** @return bool whether $key is eligibility or a grant of the CATALOG */
    public static function validUserKey(string $key): bool {
        if ($key === GrantPolicy::ELIGIBLE_KEY) {
            return true;
        }
        foreach (GrantPolicy::CATALOG as $module => $operations) {
            foreach ($operations as $operation) {
                if ($key === 'grant_' . $module . '_' . $operation) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return string|null the module of a scope cell key, or null for any other key */
    public static function scopeModule(string $key): ?string {
        if (!str_starts_with($key, self::SCOPE_PREFIX)) {
            return null;
        }
        $module = substr($key, strlen(self::SCOPE_PREFIX));
        return in_array($module, \OCA\Mcp\Db\ResourceScopeMapper::MODULES, true) ? $module : null;
    }

    /**
     * @param list<string>|null $items canonical ids, unique, or null for unrestricted
     * @return string|null the raw scope a receipt stores: null, or the JSON list in ascending numeric order
     */
    public static function scopeValue(?array $items): ?string {
        if ($items === null) {
            return null;
        }
        usort($items, DecimalCounter::compare(...));
        return json_encode($items, JSON_THROW_ON_ERROR);
    }

    /**
     * @param string|null $value raw scope as stored in a receipt
     * @return list<string>|null|false the ids (null for unrestricted), or false when the value is not a canonical scope
     */
    public static function scopeItems(?string $value): array|null|false {
        if ($value === null) {
            return null;
        }
        $items = json_decode($value, true);
        if (!is_array($items) || !array_is_list($items) || count($items) > \OCA\Mcp\Db\ResourceScopeMapper::MAX_ITEMS) {
            return false;
        }
        foreach ($items as $id) {
            try {
                if (!is_string($id) || DecimalCounter::validate($id, DecimalCounter::MAX) === '0') {
                    return false;
                }
            } catch (\InvalidArgumentException) {
                return false;
            }
        }
        // Canonical form only: sorted, unique and encoded exactly as scopeValue() does.
        return count(array_unique($items)) === count($items) && self::scopeValue($items) === $value ? $items : false;
    }

    /** @return bool a user id the official user config accepts: non-empty, valid UTF-8, without NUL */
    public static function validUid(string $uid): bool {
        return $uid !== '' && !str_contains($uid, "\0") && mb_check_encoding($uid, 'UTF-8');
    }

    private static function validateCleanup(array $payload): array {
        $keys = array_keys($payload);
        sort($keys);
        $counter = static function (mixed $value): bool {
            try {
                return is_string($value) && DecimalCounter::validate($value, DecimalCounter::MAX) === $value;
            } catch (\InvalidArgumentException) {
                return false;
            }
        };
        $valid = $keys === ['cut', 'epoch', 'scope', 'uid', 'v'] && ($payload['v'] ?? null) === self::VERSION
            && match ($payload['scope'] ?? null) {
                self::SCOPE_USER => is_string($payload['uid']) && self::validUid($payload['uid']) && $counter($payload['cut']) && $payload['epoch'] === null,
                self::SCOPE_GLOBAL => $payload['uid'] === null && $payload['cut'] === null && $counter($payload['epoch']),
                default => false,
            };
        if (!$valid) {
            throw new UnexpectedValueException('Invalid policy event payload');
        }
        return $payload;
    }

    private static function validatePreview(array $payload): array {
        $keys = array_keys($payload);
        sort($keys);
        $valid = $keys === ['actor', 'gid', 'grants', 'life', 'memberHash', 'revision', 'v'] && $payload['v'] === self::VERSION
            && is_string($payload['actor']) && self::validUid($payload['actor'])
            && is_string($payload['gid']) && self::validUid($payload['gid'])
            && is_string($payload['revision']) && self::canonical($payload['revision'])
            && is_string($payload['memberHash']) && preg_match('/^[0-9a-f]{64}$/D', $payload['memberHash']) === 1
            && ($payload['life'] === null || self::validLife($payload['life']));
        try {
            $valid = $valid && self::groupGrants($payload['grants']) === $payload['grants'];
        } catch (\InvalidArgumentException) {
            $valid = false;
        }
        if (!$valid) {
            throw new UnexpectedValueException('Invalid policy event payload');
        }
        return $payload;
    }

    private static function validLife(mixed $life): bool {
        if (!is_array($life)) {
            return false;
        }
        $keys = array_keys($life);
        sort($keys);
        return $keys === ['gen', 'state'] && in_array($life['state'], \OCA\Mcp\Db\GroupLifeMapper::STATES, true)
            && is_string($life['gen']) && preg_match('/^[0-9a-f]{32}$/D', $life['gen']) === 1;
    }

    private static function validGroupChange(array $change): bool {
        $keys = array_keys($change);
        sort($keys);
        $raw = static fn (mixed $value): bool => $value === null || is_string($value);
        return $keys === ['after', 'before', 'gid', 'key'] && $change['key'] === self::GROUP_KEY
            && is_string($change['gid']) && self::validUid($change['gid'])
            && $raw($change['before']) && $raw($change['after']) && $change['before'] !== $change['after'];
    }

    private static function canonical(string $value): bool {
        try {
            return DecimalCounter::validate($value, DecimalCounter::MAX) === $value;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private static function validChange(mixed $change, bool $receipt): bool {
        if (!is_array($change)) {
            return false;
        }
        $keys = array_keys($change);
        sort($keys);
        if ($keys !== ['after', 'before', 'key', 'uid']) {
            return false;
        }
        ['uid' => $uid, 'key' => $key, 'before' => $before, 'after' => $after] = $change;
        $raw = static fn (mixed $value): bool => $value === null || is_string($value);
        if (!is_string($key) || !$raw($before) || !$raw($after) || $before === $after) {
            return false;
        }
        if ($uid === null) {
            return !$receipt && in_array($key, self::APP_KEYS, true);
        }
        if (self::scopeModule($key) !== null) {
            return is_string($uid) && self::validUid($uid) && self::scopeItems($before) !== false && self::scopeItems($after) !== false;
        }
        return is_string($uid) && self::validUid($uid) && self::validUserKey($key);
    }

    private static function optionalId(mixed $id): bool {
        return $id === null || (is_string($id) && PolicyEventsMapper::validId($id));
    }

    private static function nextRevision(string $from, string $to): bool {
        try {
            return DecimalCounter::increment($from) === DecimalCounter::validate($to, DecimalCounter::MAX);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
