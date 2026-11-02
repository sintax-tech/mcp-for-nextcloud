<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy;
use OCA\Mcp\Platform\Numbers\DecimalCounter;
use Closure;
use OCA\Mcp\Access\Grants\Commands\{CellWriter, GlobalCommands, GlobalCommandsContract, GrantCommands, GrantCommandsContract, OverrideCommands, OverrideCommandsContract, UserAdmission};
use OCA\Mcp\Access\Policy\Credentials\{CauseCommands, CauseCommandsContract, LocalCredentialEffects, LocalCredentialEffectsContract};
use OCA\Mcp\Access\Policy\Transactions\{PolicyAssembly, PolicyContext, PolicyExecution, PolicyExecutionContract};
use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\RecheckMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\OAuth\Storage\OAuthStore;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\NativeResourceEnumerator;
use OCA\Mcp\Access\ResourceScope\ScopeActivation;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\DB\Exception as DatabaseException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Owns the top-level transaction. Trusted app commands prepare remote observations outside the mutex and
 * perform only local statements inside it. All participants use the server's singleton IDBConnection. Events the
 * command emits through its PolicyOutbox are persisted in the same transaction, after the revision CAS (the lock
 * order of contracts §2.1: mutex, app state, official config, then epochs/outbox/recheck).
 *
 * Revocation is a cut by generation (contracts §3.1): losing eligibility, disconnecting, disabling or deleting the
 * account set the user's revoked_below to the issue_seq read under the mutex; switching the service off advances the
 * global auth epoch. Each revocation writes a cleanup event in the same transaction; the physical delete runs after
 * the commit (PolicyEventProcessor), so it never removes a credential issued after the cut.
 */
class PolicyWriter implements PolicyExecutionContract, GlobalCommandsContract, GrantCommandsContract, OverrideCommandsContract, LocalCredentialEffectsContract, CauseCommandsContract {
	/** Seconds an administrator may undo a command through its receipt (contracts §2.3). */
	public const RECEIPT_TTL = 600;
	/** Most users one administrative command may touch. */
	public const MAX_UIDS = PolicyExecution::MAX_UIDS;
	/** Most distinct cells one administrative command may write. */
	public const MAX_CELLS = GrantCommands::MAX_CELLS;
	/** Seconds a group preview may be saved (contracts §2.3). */
	public const PREVIEW_TTL = 120;

	/** One context shared with the transaction and the remaining local commands. */
	private PolicyContext $context;
	/** Execution entry points sharing this writer's official services. */
	private PolicyExecution $execution;

	/** Shared LocalCredentialEffects participant. */
	private LocalCredentialEffects $effects;
	/** Shared CauseCommands participant. */
	private CauseCommands $causes;
	/** Shared CellWriter participant. */
	private CellWriter $cells;
	/** Shared UserAdmission participant. */
	private UserAdmission $admission;
	/** Shared GlobalCommands participant. */
	private GlobalCommands $globalCommands;
	/** Shared GrantCommands participant. */
	private GrantCommands $grantCommands;
	/** Shared OverrideCommands participant. */
	private OverrideCommands $overrideCommands;

	/**
	 * Keeps the historical signature and assembles one execution over one shared context.
	 *
	 * @param IDBConnection $db Official shared connection.
	 * @param PolicyStateMapper $state Mutex and revision store.
	 * @param PolicyEventsMapper $events Transactional event mapper.
	 * @param IConfig $config Official policy config.
	 * @param IUserConfig $userConfig Official user config/cache service.
	 * @param IAppConfig $appConfig Official app config/cache service.
	 * @param IUserManager $users Official account directory.
	 * @param ITimeFactory $time Official clock.
	 * @param AuthUsersMapper $authUsers Credential generations.
	 * @param RecheckMapper $rechecks Pending directory causes.
	 * @param PolicyEventProcessor $processor Post-commit cleanup/audit delivery.
	 * @param GroupGrantsMapper $groupGrants Group grants store.
	 * @param GroupLifeMapper $life Group lifecycle store.
	 * @param OAuthStore $oauth Credential storage.
	 * @param ResourceScopeMapper|null $scopes Resource scope storage, when available.
	 * @param NativeResourceEnumerator|null $resources Official resource access enumerator.
	 * @param ScopeActivation $activation Active resource families.
	 */
	public function __construct(
		private IDBConnection $db,
		private PolicyStateMapper $state,
		private PolicyEventsMapper $events,
		private IConfig $config,
		private IUserConfig $userConfig,
		private IAppConfig $appConfig,
		private IUserManager $users,
		private ITimeFactory $time,
		private AuthUsersMapper $authUsers,
		private RecheckMapper $rechecks,
		private PolicyEventProcessor $processor,
		private GroupGrantsMapper $groupGrants,
		private GroupLifeMapper $life,
		private OAuthStore $oauth,
		private ?ResourceScopeMapper $scopes = null,
		private ?NativeResourceEnumerator $resources = null,
		private ScopeActivation $activation = new ScopeActivation(),
	) {
		$this->context = new PolicyContext();
		$this->execution = PolicyAssembly::execution($db, $state, $events, $userConfig, $appConfig, $processor, $this->context);
		$this->effects = new LocalCredentialEffects($this->context, $life, $authUsers, $state);
		$this->causes = new CauseCommands($this->execution, $rechecks, $this->effects, $this->context);
		$this->admission = new UserAdmission($users);
		$this->cells = new CellWriter($this->execution, $this->admission, $config, $appConfig, $this->effects);
		$this->globalCommands = new GlobalCommands($this->execution, $this->cells);
		$this->grantCommands = new GrantCommands($this->execution, $this->cells, $this->admission);
		$this->overrideCommands = new OverrideCommands($this->cells);
	}

	/** {@inheritDoc} */
	public function assertUsable(): void {
		$this->execution->assertUsable();
	}

	/** {@inheritDoc} */
	public function runAdmin(string $expectedRevision, array $uids, Closure $prepare, Closure $apply): array {
		return $this->execution->runAdmin($expectedRevision, $uids, $prepare, $apply);
	}

	/** {@inheritDoc} */
	public function runInternal(array $uids, Closure $prepare, Closure $apply): array {
		return $this->execution->runInternal($uids, $prepare, $apply);
	}

	/**
	 * One OAuth unit of $uid (contracts §2.1.1, §3.1): createCode, exchange, refresh, anti-replay. The user's
	 * generations and recheck causes are read before $prepare, which does every possibly remote read (account,
	 * policy) outside any transaction; under the mutex both must still be equal, or the attempt is rolled back and
	 * replayed (at most twice, then PolicyUnavailable, which OAuth answers with temporarily_unavailable). $apply runs
	 * the local statements of the unit with the state row and the generations read under the mutex.
	 *
	 * With $classify, the unit is an issuance (contracts §3.3.4): the pending causes read before the external phase
	 * are classified there and, under the mutex, consumed (with their cut) before $apply, which therefore sees the
	 * generations after that cut. A credential issued by $apply is above the cut, and no After, retry or job of a
	 * consumed cause finds it again; a cause recorded between the phases changes the set and replays the attempt.
	 *
	 * @param string $uid owner of the credential
	 * @param Closure(array):mixed $prepare external phase; its result is passed to $apply
	 * @param Closure(mixed, array{revision:string, lock_seq:string, auth_epoch:string}, array{issue_seq:string, revoked_below:string}):bool $apply
	 *     local phase; returns whether policy changed (only consent's connected flag does)
	 * @param (Closure(string, list<array>): array<string, bool>)|null $classify CauseClassifier::classify(), for an
	 *     issuance; null for a unit that issues nothing
	 * @return array{revision:string, changed:bool, commandId:string, cleanupPending:bool}
	 * @throws PolicyUnavailable when the storage fails, the snapshot keeps changing, a cause cannot be classified, or
	 *     the outcome is uncertain
	 */
	public function runCredential(string $uid, Closure $prepare, Closure $apply, ?Closure $classify = null): array {
		return $this->runInternal([$uid], function (array $observed) use ($uid, $prepare, $classify): array {
			$causes = $this->rechecks->causes($uid);
			$before = ['generations' => $this->authUsers->generations($uid), 'causes' => $causes];
			$classified = $classify === null || $causes === [] ? [] : CauseCommands::classified($causes, $classify($uid, $causes));
			return ['before' => $before, 'classified' => $classified, 'snapshot' => $prepare($observed)];
		}, function (array $prepared, array $current) use ($uid, $apply): bool {
			if ($this->authUsers->generations($uid) !== $prepared['before']['generations']
				|| $this->rechecks->causes($uid) !== $prepared['before']['causes']) {
				throw new SnapshotInvalidated();
			}
			$consumed = $this->causes->consumeLocal($uid, $prepared['classified']) > 0;
			return $apply($prepared['snapshot'], $current, $this->authUsers->generations($uid)) || $consumed;
		});
	}

	/** {@inheritDoc} */
	public function processCauses(string $uid, Closure $classify, ?Closure $select = null): int {
		return $this->causes->processCauses($uid, $classify, $select);
	}

	/**
	 * Consent (contracts §3.1): connected=true and a new authorization code in one command, so a failure leaves
	 * neither. The code gets the next generation of the user (issue_seq + 1) and the current global epoch. Other
	 * sessions of the user are untouched.
	 *
	 * The eligibility is decided by $admit in the external phase (it may read group backends); under the mutex the
	 * service and the user's individual value are read again, and every group that made the user eligible must still
	 * be active, its gen renewed by this commit (holdLifecycles()): a pending_delete between the phases does not move
	 * the revision, so without that comparison a consent could issue on the policy of a group being deleted, and a
	 * deletion's Before that read the old gen must lose its transition and see the code this consent issues.
	 *
	 * @param string $uid the consenting user
	 * @param Closure(): (list<array{groupId:string, gen:string}>|null) $admit GrantPolicy::admission() of an enabled
	 *     account; null when the user may not consent
	 * @param Closure(string, string): void $insertCode stores the code with (generation, epoch); runs under the mutex
	 * @param (Closure(string, list<array>): array<string, bool>)|null $classify consumes the pending causes before the
	 *     code is issued (runCredential())
	 * @return bool false when the account is disabled, the service is off or the user is not eligible: nothing written
	 * @throws \InvalidArgumentException for a user the server does not know
	 * @throws PolicyUnavailable when the eligibility is unknown, the policy storage fails, the granting groups keep
	 *     changing, or the outcome is uncertain ($uncertain = true)
	 */
	public function consent(string $uid, Closure $admit, Closure $insertCode, ?Closure $classify = null): bool {
		$allowed = false;
		$this->runCredential($uid, function () use ($uid, $admit): ?array {
			$this->admission->assertUser($uid);
			return $admit();
		}, function (?array $lifecycles, array $current) use ($uid, $insertCode, &$allowed): bool {
			$individual = $this->config->getUserValueForUsers(GrantPolicy::APP, GrantPolicy::ELIGIBLE_KEY, [$uid])[$uid] ?? null;
			// An individual Allow admits by itself; a group admits only while the user has no individual value.
			$allowed = $lifecycles !== null
				&& $this->config->getAppValue(GrantPolicy::APP, GrantPolicy::SERVICE_KEY, '0') === '1'
				&& ($individual === '1' || ($individual === null && $lifecycles !== []));
			if (!$allowed) {
				return false;
			}
			$this->holdLifecycles($lifecycles);
			$changed = $this->cells->writeCell($uid, GrantPolicy::CONNECTED_KEY, '1') !== null;
			$insertCode($this->authUsers->issue($uid), $current['auth_epoch']);
			return $changed;
		}, $classify);
		return $allowed;
	}

	/** {@inheritDoc} */
	public function holdLifecycles(array $lifecycles): void {
		$this->effects->holdLifecycles($lifecycles);
	}

	/**
	 * Revokes every OAuth credential of a user, now and before (contracts §3.1, cut point (i)): disable or delete of
	 * the account, or an administrator revoking all of a user's connections. The account may already be gone; its
	 * generation row stays as a tombstone, so a recreated account never inherits the old credentials.
	 *
	 * @param string $uid Nextcloud user id, existing or not
	 * @throws \InvalidArgumentException for an invalid user id
	 * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
	 */
	public function revokeCredentials(string $uid): void {
		if (!PolicyEventPayload::validUid($uid)) {
			throw new \InvalidArgumentException('Invalid user');
		}
		$this->runInternal([$uid], fn () => null, function () use ($uid): bool {
			$this->effects->revokeUserLocal($uid);
			return false;
		});
	}

	/**
	 * Revocation observed in use (contracts §3.1, cut point (iii)): the owner was found unusable in a validation, so
	 * every generation up to $observed, the issue_seq read before that validation, is revoked. The cut is fixed and
	 * never raised: a credential issued after $observed is not touched here and is judged on its own use.
	 *
	 * @param string $uid owner of the credential
	 * @param string $observed canonical issue_seq read before the external phase
	 * @throws \InvalidArgumentException for an invalid user id or generation
	 * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
	 */
	public function revokeObserved(string $uid, string $observed): void {
		if (!PolicyEventPayload::validUid($uid)) {
			throw new \InvalidArgumentException('Invalid user');
		}
		DecimalCounter::validate($observed, DecimalCounter::MAX);
		$this->runInternal([$uid], fn () => null, function () use ($uid, $observed): bool {
			$this->effects->revokeUserLocal($uid, $observed);
			return false;
		});
	}

	/** {@inheritDoc} */
	public function underMutex(Closure $local): mixed {
		return $this->execution->underMutex($local);
	}

	/** {@inheritDoc} */
	public function setGlobalEnabled(bool $enabled): void {
		$this->globalCommands->setGlobalEnabled($enabled);
	}

	/** {@inheritDoc} */
	public function setEligible(string $uid, bool $enabled): void {
		$this->grantCommands->setEligible($uid, $enabled);
	}

	/** {@inheritDoc} */
	public function setConnected(string $uid, bool $enabled): void {
		$this->grantCommands->setConnected($uid, $enabled);
	}

	/** {@inheritDoc} */
	public function setGrant(string $uid, string $module, string $operation, bool $enabled): void {
		$this->grantCommands->setGrant($uid, $module, $operation, $enabled);
	}

	/** {@inheritDoc} */
	public function resetOverride(string $uid, string $module, string $operation): void {
		$this->overrideCommands->resetOverride($uid, $module, $operation);
	}

	/** {@inheritDoc} */
	public function applyCells(string $expectedRevision, string $actor, string $action, array $cells, ?Closure $inheritance = null): array {
		return $this->grantCommands->applyCells($expectedRevision, $actor, $action, $cells, $inheritance);
	}

	/**
	 * Undoes the cells of one receipt, as a new transaction and revision: each cell gets its exact raw value from
	 * before the command back, including absence. Tokens and epochs revoked by the command are not restored, but a
	 * cell that loses eligibility again revokes like any other change. Only the receipt's own administrator may undo
	 * it, only within RECEIPT_TTL seconds and only while the policy is still at the revision that command committed:
	 * any later change, to these cells or any other, is a conflict that touches nothing.
	 *
	 * @param string $receiptId id returned by applyCells()
	 * @param string $expectedRevision the revision the receipt's command committed
	 * @param string $actor uid of the administrator asking
	 * @return array{revision:string, changed:int} committed revision and restored cell count
	 * @throws \InvalidArgumentException for an invalid revision
	 * @throws ReceiptNotFound for an unknown or invented receipt, or one created by another administrator
	 * @throws ReceiptExpired for the caller's own receipt past RECEIPT_TTL
	 * @param (Closure(string): (list<array{groupId:string, gen:string}>|null))|null $inheritance as for applyCells(),
	 *     for an undo that restores the absence of an individual Allow of eligibility
	 * @throws PolicyConflict when the policy changed since the receipt's commit, or one of its users is gone
	 * @throws PolicyUnavailable when the policy storage fails, the outcome is uncertain ($uncertain = true), or an
	 *     individual Allow is removed while what the user inherits is unknown (nothing written, nothing cut)
	 */
	public function restoreReceipt(string $receiptId, string $expectedRevision, string $actor, ?Closure $inheritance = null): array {
		DecimalCounter::validate($expectedRevision);
		$receipt = $this->receipt($receiptId, $actor);
		if ($receipt['revision'] !== $expectedRevision) {
			throw new PolicyConflict();
		}
		$cells = array_map(static fn (array $change): array => ['uid' => $change['uid'], 'key' => $change['key'], 'value' => $change['before']], $receipt['changes']);
		$uids = array_values(array_unique(array_column($cells, 'uid')));
		// Scope cells go back to the scope tables, grant cells to the preferences.
		$scopeCells = array_values(array_filter($cells, static fn (array $cell): bool => PolicyEventPayload::scopeModule($cell['key']) !== null));
		$grantCells = array_values(array_filter($cells, static fn (array $cell): bool => PolicyEventPayload::scopeModule($cell['key']) === null));
		foreach ($scopeCells as $cell) {
			if (!$this->activation->isActive((string)PolicyEventPayload::scopeModule($cell['key']))) {
				throw new ScopeInactive('Resource scope is not active for this module');
			}
		}
		$changed = 0;
		$result = $this->runAdmin($expectedRevision, $uids, function () use ($uids, $grantCells, $inheritance): array {
			foreach ($uids as $uid) {
				if (!PolicyEventPayload::validUid($uid) || $this->users->get($uid) === null) {
					// Writing preferences back for a deleted account would leave orphans; the state moved on.
					throw new PolicyConflict();
				}
			}
			return $this->cells->inheritances($grantCells, $inheritance);
		}, function (array $inherited, array $current, string $commandId, PolicyOutbox $outbox) use ($grantCells, $scopeCells, $actor, $receiptId, &$changed): bool {
			$changes = $grantCells === [] ? [] : $this->cells->applyLocal($grantCells, $inherited);
			$revision = self::next($current['revision']);
			foreach ($scopeCells as $cell) {
				$items = PolicyEventPayload::scopeItems($cell['value']);
				$change = $items === false ? throw new PolicyUnavailable('Invalid stored receipt')
					: $this->writeScope($cell['uid'], (string)PolicyEventPayload::scopeModule($cell['key']), $items, $revision);
				if ($change !== null) {
					$changes[] = $change;
				}
			}
			$changed = count($changes);
			if ($changes === []) {
				return false;
			}
			$outbox->add(PolicyEventsMapper::KIND_AUDIT, PolicyEventPayload::audit($actor, PolicyEventPayload::ACTION_UNDO,
				$current['revision'], self::next($current['revision']), null, $receiptId, $changes));
			return true;
		});
		return ['revision' => $result['revision'], 'changed' => $changed];
	}

	/**
	 * The users a receipt names, for the state of a refused undo; the same ownership and validity rules as
	 * restoreReceipt(), but expiry does not matter for showing the current rows.
	 *
	 * @return list<string>|null its distinct uids in order, or null for an unknown, foreign or unreadable receipt
	 */
	public function receiptUsers(string $receiptId, string $actor): ?array {
		try {
			$receipt = $this->receipt($receiptId, $actor, false);
		} catch (ReceiptNotFound|PolicyUnavailable) {
			return null;
		}
		return array_values(array_unique(array_column($receipt['changes'], 'uid')));
	}

	/** {@inheritDoc} */
	public function adminSetGlobalEnabled(string $expectedRevision, string $actor, bool $enabled): array {
		return $this->globalCommands->adminSetGlobalEnabled($expectedRevision, $actor, $enabled);
	}

	/**
	 * The preview of a group policy (contracts §2.3), taken at the expected revision: what the save would change,
	 * the group's member count and whether it can grant. It writes a preview event (no policy change, no revision
	 * step) that setGroupGrants() must present again within PREVIEW_TTL seconds. A group that cannot grant gets no
	 * preview for a non-empty set (422).
	 *
	 * @param string $expectedRevision the revision the administrator's page was built from
	 * @param string $actor uid of the administrator
	 * @param string $gid the group, exact bytes
	 * @param mixed $grants target set, module => list of operations (PolicyEventPayload::groupGrants())
	 * @param Closure(array{gid:string, grants:array, current:array, withdraws:bool, holders:list<string>}): array{trusted:bool, members:list<string>, cuts:list<string>, sources?:list<array{groupId:string, state:?string, gen:?string}>} $admit
	 *     the external phase, without any transaction: the fresh source predicate, the member list and, when the
	 *     set withdraws eligibility, which of the given holders lose their last source, with the lifecycle of every
	 *     group those decisions read (GroupGrantsService::admit())
	 * @return array{previewId:string, revision:string, groupId:string, grants:array, current:array, state:?string, members:int, trusted:bool, withdraws:bool, revokes:int}
	 * @throws \InvalidArgumentException for an invalid revision, group or set
	 * @throws GroupSourceUntrusted for a non-empty set on a group that cannot grant
	 * @throws PolicyConflict when the expected revision is stale
	 * @throws PolicyUnavailable when the storage or the directory cannot be read, or the outcome is uncertain
	 */
	public function previewGroupGrants(string $expectedRevision, string $actor, string $gid, mixed $grants, Closure $admit): array {
		return $this->groupCommand($expectedRevision, $actor, $gid, PolicyEventPayload::groupGrants($grants), null, $admit);
	}

	/**
	 * Saves the whole policy of a group (contracts §2.3, §3.3.5) from one of the caller's previews: the grant rows are
	 * replaced by $grants, and the lifecycle becomes active with a new gen and policy_rev = the revision this commit
	 * writes; an empty set removes the rows and the lifecycle. Saving in pending_delete or needs_confirm substitutes
	 * the whole set, so "Confirm current policy" is this same call with the current set (coordinator clarification 5).
	 * Granting never touches connected or a user override.
	 *
	 * The save presents the preview's revision, group, set, member hash and lifecycle again. In the external phase the
	 * members are enumerated anew and the source predicate read fresh; a different member set (even of the same
	 * count) or lifecycle is a conflict with nothing written, and a non-empty set on a group that cannot grant is
	 * refused. Under the mutex only local state is compared: the lifecycle (whose pending_delete transition does not
	 * move the revision) and the rows, as read in the external phase.
	 *
	 * Withdrawing _system/eligible follows the holders rule: H0, every live credential holder with its generations,
	 * is read before the external phase, which decides who loses its last source; under the mutex H1 is read again,
	 * and any new holder or issue_seq (an issuance between the phases) replays the attempt, at most twice, then 409.
	 * Otherwise the rows, the lifecycle and the cuts (revoked_below = issue_seq) of the holders who lose commit
	 * together. Holders with another source keep their credentials, so that source must still be what the decision
	 * read: the lifecycle of every group the external phase relied on is compared under the mutex too (a deletion's
	 * pending_delete moves no revision), and each active one gets a new gen in the same commit. A deletion's pre-event
	 * that read such a group before this commit then fails its conditional transition, and its After suspends the
	 * group (needs_confirm) instead of purging a source this save relied on without a cause for its holders.
	 *
	 * Every lifecycle write is conditioned on the gen read under the mutex (the pre-event transition never takes the
	 * mutex, so it can land after that read): a lifecycle that moved in between replays the attempt.
	 *
	 * @param string $expectedRevision the preview's revision
	 * @param string $actor uid of the administrator; only the preview's own may save it
	 * @param string $gid the preview's group
	 * @param mixed $grants the preview's target set
	 * @param string $previewId id returned by previewGroupGrants()
	 * @param Closure $admit the external phase, as for previewGroupGrants()
	 * @return array{revision:string, changed:bool, state:?string, revoked:int} state: the lifecycle after the save
	 * @throws \InvalidArgumentException for an invalid revision, group or set, or one that differs from the preview
	 * @throws ReceiptNotFound for an unknown preview, or another administrator's
	 * @throws ReceiptExpired for the caller's preview past PREVIEW_TTL
	 * @throws GroupSourceUntrusted for a non-empty set on a group that cannot grant; nothing is written
	 * @throws PolicyConflict when the revision, the member set or the lifecycle changed since the preview, or the
	 *     holders kept changing
	 * @throws PolicyUnavailable when the storage or the directory cannot be read, or the outcome is uncertain
	 */
	public function setGroupGrants(string $expectedRevision, string $actor, string $gid, mixed $grants, string $previewId, Closure $admit): array {
		DecimalCounter::validate($expectedRevision);
		$grants = PolicyEventPayload::groupGrants($grants);
		$preview = $this->preview($previewId, $actor);
		if ($preview['gid'] !== $gid || $preview['grants'] !== $grants) {
			throw new \InvalidArgumentException('The save differs from its preview');
		}
		if ($preview['revision'] !== $expectedRevision) {
			throw new PolicyConflict();
		}
		return $this->groupCommand($expectedRevision, $actor, $gid, $grants, $preview, $admit);
	}

	/**
	 * Saves the resource scope of one module for one user (contracts B.4): null makes the module unrestricted, a list
	 * restricts it to exactly those resources, and an empty list restricts it to nothing. Same transaction as every
	 * policy command: expected revision, global mutex, CAS, one receipt (undoable for RECEIPT_TTL seconds by the same
	 * administrator) and one audit event; the scope rows carry policy_rev = the revision this commit produces. It
	 * touches no credential, epoch or generation: a new scope applies from the user's next operation.
	 *
	 * The module's activation barrier is checked first, before anything else is read: while it is closed the command
	 * is refused with no write, no receipt and no revision. Outside the mutex the user must exist and every resource
	 * must be reachable natively by that user (NativeResourceEnumerator, never the MCP view); duplicates are dropped
	 * and the limit applies to the distinct set. Saving the scope already stored commits nothing.
	 *
	 * @param string $expectedRevision the revision the administrator's page was built from
	 * @param string $actor uid of the administrator who sent the command
	 * @param string $uid target user
	 * @param string $module one of ResourceScopeMapper::MODULES
	 * @param list<string>|null $items canonical decimal ids, or null for unrestricted
	 * @return array{revision:string, changed:bool, receiptId:?string}
	 * @throws ScopeInactive while the module's activation barrier is closed
	 * @throws \InvalidArgumentException for an invalid actor, module, revision, id, or an unknown user
	 * @throws ScopeTooLarge for more than ResourceScopeMapper::MAX_ITEMS distinct resources
	 * @throws ScopeItemsInaccessible for a resource the user cannot reach natively
	 * @throws PolicyConflict when the expected revision is stale
	 * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
	 */
	public function setResourceScope(string $expectedRevision, string $actor, string $uid, string $module, ?array $items): array {
		if (!PolicyEventPayload::validUid($actor) || !in_array($module, ResourceScopeMapper::MODULES, true)) {
			throw new \InvalidArgumentException('Invalid command');
		}
		if (!$this->activation->isActive($module)) {
			throw new ScopeInactive('Resource scope is not active for this module');
		}
		$items = $items === null ? null : self::scopeItems($items);
		$scopes = $this->scopes;
		$resources = $this->resources;
		if ($scopes === null || $resources === null) {
			throw new PolicyUnavailable('Resource scope storage unavailable');
		}
		$receiptId = null;
		$result = $this->runAdmin($expectedRevision, [$uid], function () use ($uid, $module, $items, $resources): void {
			$this->admission->assertUser($uid);
			$missing = $items === null ? [] : $resources->inaccessible($uid, $module, $items);
			if ($missing !== []) {
				usort($missing, DecimalCounter::compare(...));
				throw new ScopeItemsInaccessible($missing);
			}
		}, function ($snapshot, array $current, string $commandId, PolicyOutbox $outbox) use ($actor, $uid, $module, $items, &$receiptId): bool {
			$receiptId = null;
			$revision = self::next($current['revision']);
			$change = $this->writeScope($uid, $module, $items, $revision);
			if ($change === null) {
				return false;
			}
			$receiptId = $outbox->add(PolicyEventsMapper::KIND_RECEIPT, PolicyEventPayload::receipt($actor, PolicyEventPayload::ACTION_SCOPE, $current['revision'], $revision, [$change]));
			$outbox->add(PolicyEventsMapper::KIND_AUDIT, PolicyEventPayload::audit($actor, PolicyEventPayload::ACTION_SCOPE, $current['revision'], $revision, $receiptId, null, []));
			return true;
		});
		return ['revision' => $result['revision'], 'changed' => $result['changed'], 'receiptId' => $receiptId];
	}

	/** {@inheritDoc} */
	public static function next(string $revision): string {
		return PolicyExecution::next($revision);
	}

	/**
	 * One group preview ($preview null) or save, as one admin command.
	 *
	 * @param array<string, list<string>> $grants canonical target set
	 * @param array{gid:string, grants:array, memberHash:string, life:?array}|null $preview the save's preview
	 */
	private function groupCommand(string $expected, string $actor, string $gid, array $grants, ?array $preview, Closure $admit): array {
		if (!PolicyEventPayload::validUid($actor) || !PolicyEventPayload::validUid($gid)) {
			throw new \InvalidArgumentException('Invalid command');
		}
		$outcome = [];
		$result = $this->runAdmin($expected, [], function (array $observed) use ($gid, $grants, $preview, $admit): array {
			// Local reads first, H0 included, before anything remote (contracts §2.3).
			$now = $this->time->getTime();
			$life = self::life($this->life->get($gid));
			$current = $this->groupGrants->forGroups([$gid])[0]['grants'];
			$withdraws = PolicyEventPayload::grantsEligibility($current) && !PolicyEventPayload::grantsEligibility($grants);
			$holders = $withdraws ? $this->holders($now, $observed['auth_epoch']) : [];
			$candidates = [];
			foreach ($holders as $holder) {
				if (!self::allCut($holder['gen'], $holder['revoked_below'])) {
					$candidates[] = $holder['uid'];
				}
			}
			$admission = self::admission($admit(['gid' => $gid, 'grants' => $grants, 'current' => $current,
				'withdraws' => $withdraws, 'holders' => $candidates]), $withdraws ? $candidates : []);
			if ($grants !== [] && !$admission['trusted']) {
				throw new GroupSourceUntrusted();
			}
			$memberHash = PolicyEventPayload::memberHash($admission['members']);
			if ($preview !== null && ($preview['memberHash'] !== $memberHash || !self::sameLife($preview['life'], $life))) {
				throw new PolicyConflict();
			}
			return ['now' => $now, 'life' => $life, 'current' => $current, 'withdraws' => $withdraws, 'holders' => $holders,
				'admission' => $admission, 'memberHash' => $memberHash];
		}, function (array $snapshot, array $current, string $commandId, PolicyOutbox $outbox) use ($actor, $gid, $grants, $preview, &$outcome): bool {
			$stored = $this->life->get($gid);
			if (!self::sameLife(self::life($stored), $snapshot['life']) || $this->groupGrants->forGroups([$gid])[0]['grants'] !== $snapshot['current']) {
				throw new SnapshotInvalidated();
			}
			if ($snapshot['withdraws'] && $this->holders($snapshot['now'], $current['auth_epoch']) !== $snapshot['holders']) {
				throw new SnapshotInvalidated();
			}
			foreach ($snapshot['admission']['sources'] as $source) {
				if ($source['groupId'] !== $gid && !self::sameLife(self::life($this->life->get($source['groupId'])), self::life($source['gen'] === null ? null : $source))) {
					throw new SnapshotInvalidated();
				}
			}
			if ($preview === null) {
				$outcome = ['previewId' => $outbox->add(PolicyEventsMapper::KIND_PREVIEW, PolicyEventPayload::preview($actor, $gid,
					$current['revision'], $grants, $snapshot['memberHash'], $snapshot['life'])),
					'revision' => $current['revision'], 'groupId' => $gid, 'grants' => $grants, 'current' => $snapshot['current'],
					'state' => $stored['state'] ?? null, 'members' => count($snapshot['admission']['members']),
					'trusted' => $snapshot['admission']['trusted'], 'withdraws' => $snapshot['withdraws'],
					'revokes' => count($snapshot['admission']['cuts'])];
				return false;
			}
			if ($grants === $snapshot['current'] && ($grants === [] ? $stored === null : $stored['state'] === GroupLifeMapper::ACTIVE)) {
				$outcome = ['changed' => false, 'state' => $stored['state'] ?? null, 'revoked' => 0];
				return false;
			}
			$revision = self::next($current['revision']);
			$state = null;
			// Lifecycle first, conditioned on the gen read above; the rows follow only once it is held.
			if ($grants === []) {
				if ($stored !== null && !$this->life->delete($gid, $stored['gen'])) {
					throw new SnapshotInvalidated();
				}
			} else {
				// policy_rev is the revision this commit writes, never the one before the CAS (ticket 07).
				$gen = bin2hex(random_bytes(16));
				if ($stored === null) {
					// Only the writer creates a lifecycle, and only under the mutex: no pre-event can race the insert.
					$this->life->put($gid, GroupLifeMapper::ACTIVE, $gen, $revision);
				} elseif (!$this->life->replace($gid, $stored['gen'], GroupLifeMapper::ACTIVE, $gen, $revision)) {
					throw new SnapshotInvalidated();
				}
				$state = GroupLifeMapper::ACTIVE;
			}
			foreach ($snapshot['admission']['sources'] as $source) {
				// Renewed from the gen read under this mutex: sameLife() above accepted a renewal since the external
				// phase, which changes nothing this save relied on.
				if ($source['groupId'] !== $gid && $source['state'] === GroupLifeMapper::ACTIVE
					&& !$this->life->transition($source['groupId'], (string)($this->life->get($source['groupId'])['gen'] ?? ''),
						GroupLifeMapper::ACTIVE, bin2hex(random_bytes(16)))) {
					throw new SnapshotInvalidated();
				}
			}
			$this->groupGrants->replaceGroup($gid, $grants);
			foreach ($snapshot['admission']['cuts'] as $uid) {
				$this->effects->revokeUserLocal($uid);
			}
			$outbox->add(PolicyEventsMapper::KIND_AUDIT, PolicyEventPayload::audit($actor, PolicyEventPayload::ACTION_GROUP,
				$current['revision'], $revision, null, null, [['gid' => $gid, 'key' => PolicyEventPayload::GROUP_KEY,
					'before' => PolicyEventPayload::groupSnapshot($stored['state'] ?? null, $snapshot['current']),
					'after' => PolicyEventPayload::groupSnapshot($state, $grants)]]));
			$outcome = ['changed' => true, 'state' => $state, 'revoked' => count($snapshot['admission']['cuts'])];
			return true;
		});
		return $preview === null ? $outcome : ['revision' => $result['revision']] + $outcome;
	}

	/**
	 * H of the holders rule (contracts §2.3): every owner of a live code or grant of the current epoch, with the
	 * highest generation among those rows and the owner's issue_seq and revoked_below, sorted by a digest of the uid.
	 * Read before the external phase and again under the mutex: any difference is an issuance (or a cut) in between.
	 *
	 * @return array<string, array{uid:string, gen:string, issue_seq:string, revoked_below:string}>
	 * @throws PolicyUnavailable when a holder's generations cannot be read
	 */
	private function holders(int $now, string $epoch): array {
		$holders = [];
		foreach ($this->oauth->liveHolders($now, $epoch) as $key => ['uid' => $uid, 'gen' => $gen]) {
			try {
				$holders[$key] = ['uid' => $uid, 'gen' => $gen] + $this->authUsers->generations($uid);
			} catch (\InvalidArgumentException|\UnexpectedValueException $e) {
				throw new PolicyUnavailable('Cannot read the credential holders', previous: $e);
			}
		}
		ksort($holders, SORT_STRING);
		return $holders;
	}

	/** Whether every credential of a holder is already cut; a generation that cannot be compared is not. */
	private static function allCut(string $highest, string $revokedBelow): bool {
		try {
			return DecimalCounter::compare(DecimalCounter::validate($highest, DecimalCounter::MAX), $revokedBelow) <= 0;
		} catch (\InvalidArgumentException) {
			return false;
		}
	}

	/**
	 * Checks what the external phase answered: cuts only among the holders it was given, and the lifecycles it read.
	 *
	 * @param list<string> $holders the holders that may be cut
	 * @return array{trusted:bool, members:list<string>, cuts:list<string>, sources:list<array{groupId:string, state:?string, gen:?string}>}
	 */
	private static function admission(mixed $admission, array $holders): array {
		if (!is_array($admission) || !is_bool($admission['trusted'] ?? null)
			|| !is_array($admission['members'] ?? null) || !array_is_list($admission['members'])
			|| !is_array($admission['cuts'] ?? null) || !array_is_list($admission['cuts'])) {
			throw new \LogicException('Invalid group admission');
		}
		foreach ($admission['members'] as $uid) {
			if (!is_string($uid)) {
				throw new \LogicException('Invalid group admission');
			}
		}
		$cuts = array_values(array_unique($admission['cuts']));
		foreach ($cuts as $uid) {
			if (!in_array($uid, $holders, true)) {
				throw new \LogicException('A group save may cut only the holders it was given');
			}
		}
		$sources = $admission['sources'] ?? [];
		if (!is_array($sources) || !array_is_list($sources)) {
			throw new \LogicException('Invalid group admission');
		}
		$distinct = [];
		foreach ($sources as $source) {
			$valid = is_array($source) && is_string($source['groupId'] ?? null) && array_key_exists('state', $source) && array_key_exists('gen', $source)
				&& ($source['state'] === null ? $source['gen'] === null
					: in_array($source['state'], GroupLifeMapper::STATES, true) && is_string($source['gen']) && preg_match('/^[0-9a-f]{32}$/D', $source['gen']) === 1);
			if (!$valid) {
				throw new \LogicException('Invalid group admission');
			}
			$source = ['groupId' => $source['groupId'], 'state' => $source['state'], 'gen' => $source['gen']];
			$key = \OCA\Mcp\Db\IdentityKey::group($source['groupId']);
			if (isset($distinct[$key]) && $distinct[$key] !== $source) {
				// Two reads of one group disagree: it moved during the external phase.
				throw new SnapshotInvalidated();
			}
			$distinct[$key] = $source;
		}
		return ['trusted' => $admission['trusted'], 'members' => array_values(array_unique($admission['members'])), 'cuts' => $cuts,
			'sources' => array_values($distinct)];
	}

	/** @return array{state:string, gen:string}|null the part of a lifecycle a preview fixes */
	private static function life(?array $row): ?array {
		return $row === null ? null : ['state' => $row['state'], 'gen' => $row['gen']];
	}

	/**
	 * Whether a lifecycle is the one a group command read, at the same revision (RF3 of the re-reading of F1): the
	 * same state and gen, or both active. The callers compare only at an unchanged revision (the preview's revision is
	 * the save's expected one; run() checks it between the phases), and at an unchanged revision the only change that
	 * keeps a group active with another gen is the renewal of holdLifecycles(), by an issuance or a reset the group
	 * admitted. That renewal changes neither the policy nor the members, so it never invalidates a preview or a save;
	 * every real transition (pending_delete, needs_confirm, purge, recreation) still does.
	 *
	 * @param array{state:string, gen:string}|null $a
	 * @param array{state:string, gen:string}|null $b
	 */
	private static function sameLife(?array $a, ?array $b): bool {
		if ($a === null || $b === null) {
			return $a === $b;
		}
		return $a['state'] === $b['state'] && ($a['gen'] === $b['gen'] || $a['state'] === GroupLifeMapper::ACTIVE);
	}

	/**
	 * Loads a group preview for its own administrator, with the same ownership-before-expiry rule as receipts.
	 *
	 * @return array{gid:string, grants:array, revision:string, memberHash:string, life:?array}
	 * @throws ReceiptNotFound|ReceiptExpired|PolicyUnavailable
	 */
	private function preview(string $previewId, string $actor): array {
		if (!PolicyEventsMapper::validId($previewId)) {
			throw new ReceiptNotFound();
		}
		try {
			$event = $this->events->find($previewId);
			$payload = $event === null || $event['kind'] !== PolicyEventsMapper::KIND_PREVIEW ? null
				: PolicyEventPayload::validate(PolicyEventsMapper::KIND_PREVIEW, $event['payload']);
		} catch (\UnexpectedValueException $e) {
			throw new PolicyUnavailable('Invalid stored preview', previous: $e);
		} catch (DatabaseException $e) {
			throw new PolicyUnavailable(previous: $e);
		}
		if ($payload === null || $payload['actor'] !== $actor) {
			throw new ReceiptNotFound();
		}
		if ($this->time->getTime() >= $event['createdAt'] + self::PREVIEW_TTL) {
			throw new ReceiptExpired();
		}
		return $payload;
	}

	/**
	 * Replaces one scope under the mutex when it differs from the stored one, reading the stored scope in this same
	 * transaction (never a read made outside it).
	 *
	 * @param list<string>|null $items canonical ids in ascending order, or null for unrestricted
	 * @param string $revision the revision this commit produces, stored as policy_rev
	 * @return array{uid:string, key:string, before:?string, after:?string}|null the change, or null for a no-op
	 * @throws PolicyUnavailable when the stored scope cannot be read or verified
	 */
	private function writeScope(string $uid, string $module, ?array $items, string $revision): ?array {
		$scopes = $this->scopes ?? throw new PolicyUnavailable('Resource scope storage unavailable');
		try {
			$stored = $scopes->forModule($uid, $module);
			$before = PolicyEventPayload::scopeValue($stored === null ? null : $stored['items']);
			$after = PolicyEventPayload::scopeValue($items);
			if ($before === $after) {
				return null;
			}
			$scopes->replace($uid, $module, $items, $revision);
		} catch (\UnexpectedValueException $e) {
			throw new PolicyUnavailable('Invalid stored resource scope', previous: $e);
		}
		return ['uid' => $uid, 'key' => PolicyEventPayload::SCOPE_PREFIX . $module, 'before' => $before, 'after' => $after];
	}

	/**
	 * @param array<mixed> $items candidate ids
	 * @return list<string> the distinct ids, in ascending numeric order
	 * @throws \InvalidArgumentException for a non-list or an id that is not a positive canonical decimal string
	 * @throws ScopeTooLarge for more than ResourceScopeMapper::MAX_ITEMS distinct ids
	 */
	private static function scopeItems(array $items): array {
		if (!array_is_list($items)) {
			throw new \InvalidArgumentException('Invalid command');
		}
		$unique = [];
		foreach ($items as $id) {
			try {
				if (!is_string($id) || DecimalCounter::validate($id, DecimalCounter::MAX) === '0') {
					throw new \InvalidArgumentException('Invalid command');
				}
			} catch (\InvalidArgumentException $e) {
				throw new \InvalidArgumentException('Invalid command', 0, $e);
			}
			$unique[$id] = true;
			if (count($unique) > ResourceScopeMapper::MAX_ITEMS) {
				throw new ScopeTooLarge('Too many resources in one scope');
			}
		}
		$ids = array_map('strval', array_keys($unique));
		usort($ids, DecimalCounter::compare(...));
		return $ids;
	}

	/**
	 * Loads a receipt for its own administrator, checking ownership before expiry so another administrator's
	 * receipt is indistinguishable from an unknown one.
	 *
	 * @param bool $live whether an expired receipt is refused (false only to describe it)
	 * @return array{revision:string, changes:list<array{uid:string, key:string, before:?string, after:?string}>}
	 * @throws ReceiptNotFound|ReceiptExpired|PolicyUnavailable
	 */
	private function receipt(string $receiptId, string $actor, bool $live = true): array {
		if (!PolicyEventsMapper::validId($receiptId)) {
			throw new ReceiptNotFound();
		}
		try {
			$event = $this->events->find($receiptId);
			$payload = $event === null || $event['kind'] !== PolicyEventsMapper::KIND_RECEIPT ? null
				: PolicyEventPayload::validate(PolicyEventsMapper::KIND_RECEIPT, $event['payload']);
		} catch (\UnexpectedValueException $e) {
			throw new PolicyUnavailable('Invalid stored receipt', previous: $e);
		} catch (DatabaseException $e) {
			throw new PolicyUnavailable(previous: $e);
		}
		if ($payload === null || $payload['actor'] !== $actor) {
			throw new ReceiptNotFound();
		}
		if ($live && $this->time->getTime() >= $event['createdAt'] + self::RECEIPT_TTL) {
			throw new ReceiptExpired();
		}
		return ['revision' => $payload['revision'], 'changes' => $payload['changes']];
	}
}
