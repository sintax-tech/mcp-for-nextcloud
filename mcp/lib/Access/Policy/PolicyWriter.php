<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy;
use Closure;
use OCA\Mcp\Access\Grants\Commands\{CellWriter, GlobalCommands, GlobalCommandsContract, GrantCommands, GrantCommandsContract, OverrideCommands, OverrideCommandsContract, UserAdmission};
use OCA\Mcp\Access\Grants\Groups\{GroupGrantCommands, GroupGrantCommandsContract, GroupPreview};
use OCA\Mcp\Access\Policy\Credentials\{CauseCommands, CauseCommandsContract, CredentialHolders, LocalCredentialEffects, LocalCredentialEffectsContract, ConsentCommands, ConsentCommandsContract, CredentialCommands, CredentialCommandsContract};
use OCA\Mcp\Access\Policy\Receipts\{ReceiptRestorer, ReceiptRestorerContract};
use OCA\Mcp\Access\Policy\Transactions\{PolicyAssembly, PolicyContext, PolicyExecution, PolicyExecutionContract};
use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\RecheckMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
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
class PolicyWriter implements PolicyExecutionContract, GlobalCommandsContract, GrantCommandsContract, OverrideCommandsContract, LocalCredentialEffectsContract, CauseCommandsContract, GroupGrantCommandsContract, ScopeWriterContract, ReceiptRestorerContract, ConsentCommandsContract, CredentialCommandsContract {
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
	/** Shared resource scope writes. */
	private ScopeWriter $scopeWriter;
	/** Credential units over the shared execution. */
	private CredentialCommands $credentialCommands;
	/** Consent over the shared credential units. */
	private ConsentCommands $consentCommands;
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
	 * @param CredentialHolders $credentialHolders Credential holder reads.
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
		private CredentialHolders $credentialHolders,
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
		$this->credentialCommands = new CredentialCommands($this->execution, $this->rechecks, $this->authUsers, $this->causes, $this->effects);
		$this->consentCommands = new ConsentCommands($this->credentialCommands, $this->admission, $this->config, $this->effects, $this->cells, $this->authUsers);
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

	/** {@inheritDoc} */
	public function runCredential(string $uid, Closure $prepare, Closure $apply, ?Closure $classify = null): array {
		return $this->credentialCommands->runCredential($uid, $prepare, $apply, $classify);
	}

	/** {@inheritDoc} */
	public function processCauses(string $uid, Closure $classify, ?Closure $select = null): int {
		return $this->causes->processCauses($uid, $classify, $select);
	}

	/** {@inheritDoc} */
	public function consent(string $uid, Closure $admit, Closure $insertCode, ?Closure $classify = null): bool {
		return $this->consentCommands->consent($uid, $admit, $insertCode, $classify);
	}

	/** {@inheritDoc} */
	public function holdLifecycles(array $lifecycles): void {
		$this->effects->holdLifecycles($lifecycles);
	}

	/** {@inheritDoc} */
	public function revokeCredentials(string $uid): void {
		$this->credentialCommands->revokeCredentials($uid);
	}

	/** {@inheritDoc} */
	public function revokeObserved(string $uid, string $observed): void {
		$this->credentialCommands->revokeObserved($uid, $observed);
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
