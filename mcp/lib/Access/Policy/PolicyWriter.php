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
use OCA\Mcp\Access\Grants\Groups\{GroupGrantCommands, GroupGrantCommandsContract, GroupPreview};
use OCA\Mcp\Access\Policy\Credentials\{CauseCommands, CauseCommandsContract, CredentialHolders, LocalCredentialEffects, LocalCredentialEffectsContract};
use OCA\Mcp\Access\Policy\Receipts\{ReceiptRestorer, ReceiptRestorerContract};
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
use OCA\Mcp\Access\ResourceScope\{ScopeWriter, ScopeWriterContract};
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
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
class PolicyWriter implements PolicyExecutionContract, GlobalCommandsContract, GrantCommandsContract, OverrideCommandsContract, LocalCredentialEffectsContract, CauseCommandsContract, GroupGrantCommandsContract, ScopeWriterContract, ReceiptRestorerContract {
	/** Seconds an administrator may undo a command through its receipt (contracts §2.3). */
	public const RECEIPT_TTL = ReceiptRestorer::RECEIPT_TTL;
	/** Most users one administrative command may touch. */
	public const MAX_UIDS = PolicyExecution::MAX_UIDS;
	/** Most distinct cells one administrative command may write. */
	public const MAX_CELLS = GrantCommands::MAX_CELLS;
	/** Seconds a group preview may be saved (contracts §2.3). */
	public const PREVIEW_TTL = GroupPreview::PREVIEW_TTL;

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
	/** Shared group grant commands. */
	private GroupGrantCommands $groupGrantCommands;
	/** Shared group preview loader. */
	private GroupPreview $groupPreview;
	/** Shared credential holder reads. */
	private CredentialHolders $credentialHolders;
	/** Shared resource scope writes. */
	private ScopeWriter $scopeWriter;
	/** Shared receipt undo. */
	private ReceiptRestorer $receipts;

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
		$this->credentialHolders = new CredentialHolders($this->oauth, $this->authUsers);
		$this->groupPreview = new GroupPreview($this->events, $this->time);
		$this->scopeWriter = new ScopeWriter($this->execution, $this->admission, $this->scopes, $this->resources, $this->activation);
		$this->receipts = new ReceiptRestorer($this->execution, $this->users, $this->events, $this->time, $this->activation, $this->cells, $this->scopeWriter);
		$this->groupGrantCommands = new GroupGrantCommands($this->execution, $this->time, $this->life, $this->groupGrants, $this->credentialHolders, $this->effects, $this->groupPreview);
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

		/** {@inheritDoc} */
	public function restoreReceipt(string $receiptId, string $expectedRevision, string $actor, ?Closure $inheritance = null): array {
		return $this->receipts->restoreReceipt($receiptId, $expectedRevision, $actor, $inheritance);
	}

		/** {@inheritDoc} */
	public function receiptUsers(string $receiptId, string $actor): ?array {
		return $this->receipts->receiptUsers($receiptId, $actor);
	}

	/** {@inheritDoc} */
	public function adminSetGlobalEnabled(string $expectedRevision, string $actor, bool $enabled): array {
		return $this->globalCommands->adminSetGlobalEnabled($expectedRevision, $actor, $enabled);
	}

		/** {@inheritDoc} */
	public function previewGroupGrants(string $expectedRevision, string $actor, string $gid, mixed $grants, Closure $admit): array {
		return $this->groupGrantCommands->previewGroupGrants($expectedRevision, $actor, $gid, $grants, $admit);
	}

		/** {@inheritDoc} */
	public function setGroupGrants(string $expectedRevision, string $actor, string $gid, mixed $grants, string $previewId, Closure $admit): array {
		return $this->groupGrantCommands->setGroupGrants($expectedRevision, $actor, $gid, $grants, $previewId, $admit);
	}

		/** {@inheritDoc} */
	public function setResourceScope(string $expectedRevision, string $actor, string $uid, string $module, ?array $items): array {
		return $this->scopeWriter->setResourceScope($expectedRevision, $actor, $uid, $module, $items);
	}

	/** {@inheritDoc} */
	public static function next(string $revision): string {
		return PolicyExecution::next($revision);
	}
}
