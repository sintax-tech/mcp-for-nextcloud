<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use Closure;
use OCA\Deck\Db\Acl;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Platform\Logging\ExceptionDetails;

/**
 * Internal complete board recipients after grants and fresh active source READ/EDIT; never creates a share.
 */
final class BoardRecipientResolver {
	/**
	 * Maximum complete ACL rows supported in a single resolution.
	 */
	public const MAX_ACLS = 128;

	/**
	 * Monotonic operation-local clock, never persisted or included in either hash.
	 *
	 * @var Closure():int
	 */
	private Closure $clock;

	/**
	 * Compose authorization, native identity reads and the existing plan fingerprint mechanism.
	 *
	 * @param DeckGatewayInterface $gateway Existing fresh source authorization boundary.
	 * @param GrantPolicy $policy Caller-bound grants, re-read in every resolution operation.
	 * @param RecipientDirectory $directory Native ACL and user/group/circle reads only.
	 * @param PlanState $plans Existing instance-secret HMAC for canonical scalar snapshots.
	 * @param LoggerInterface $logger Receives only the class of an unexpected exception.
	 * @param Closure|null $clock Optional monotonic nanosecond clock for deterministic limit tests.
	 * @return void
	 */
	public function __construct(private DeckGatewayInterface $gateway, private GrantPolicy $policy,
		private \OCA\Mcp\Modules\Deck\Recipients\RecipientDirectory $directory, private PlanState $plans, private LoggerInterface $logger, ?Closure $clock = null) {
		$this->clock = $clock ?? static fn (): int => hrtime(true);
	}

	/**
	 * Resolve current recipients without touching Files; DK-4b still authorizes its node and strict policies.
	 *
	 * The result is internal and ephemeral: no executable public plan is exposed here. Recompute it on
	 * confirm. Native Deck getCircle starts a Circles super session and does not restore it.
	 * DK-4b must perform Circles-dependent authorization before resolution, or avoid using Circles
	 * permissions afterwards. This component does not change or promise restoration of native session state.
	 * Native I/O is bounded cooperatively, not cancelled; hashes do not close TOCTOU windows.
	 *
	 * @param LinkRecipientContext $context Caller and exact expected target, including file identity/version.
	 * @return RecipientSnapshot Complete authorized portrait; never an N derived from partial expansion.
	 * @throws DeckRefusalException On any grant, source, identity, expansion or budget failure.
	 */
	public function resolve(\OCA\Mcp\Modules\Deck\Recipients\LinkRecipientContext $context): \OCA\Mcp\Modules\Deck\Recipients\RecipientSnapshot {
		$authorized = false;
		$inactive = false;
		try {
			return $this->policy->within($context->actorUid, function () use ($context, &$authorized, &$inactive): \OCA\Mcp\Modules\Deck\Recipients\RecipientSnapshot {
				$this->requireGrants($context->actorUid);
				$expansion = new \OCA\Mcp\Modules\Deck\Recipients\RecipientExpansion($this->clock);
				try {
					$source = $this->gateway->cardFileContext($context->actorUid, $context->cardId, true);
				} catch (\OCA\Mcp\Modules\Deck\Failures\DeckRefusalException $first) {
					if (!$first->marksInactiveFileSource($context->actorUid, $context->cardId, $context->boardId)) {
						throw \OCA\Mcp\Modules\Deck\Attachments\CardFiles::unavailable();
					}
					$this->requireGrants($context->actorUid);
					try {
						$this->gateway->cardFileContext($context->actorUid, $context->cardId, true);
					} catch (\OCA\Mcp\Modules\Deck\Failures\DeckRefusalException $second) {
						if ($second->marksInactiveFileSource($context->actorUid, $context->cardId, $context->boardId)) {
							$this->requireGrants($context->actorUid);
							$inactive = true;
							throw $second;
						}
					}
					throw \OCA\Mcp\Modules\Deck\Attachments\CardFiles::unavailable();
				}
				if ($source['boardId'] !== $context->boardId) {
					throw \OCA\Mcp\Modules\Deck\Attachments\CardFiles::unavailable();
				}
				$authorized = true;
				$expansion->checkpoint();
				$acls = $this->directory->acls($context->boardId);
				$expansion->checkpoint();
				if (count($acls) > self::MAX_ACLS) {
					throw \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException::limit();
				}
				$hasGroup = false;
				$hasCircle = false;
				foreach ($acls as $acl) {
					if (!in_array($acl['type'], [Acl::PERMISSION_TYPE_USER, Acl::PERMISSION_TYPE_GROUP, Acl::PERMISSION_TYPE_CIRCLE], true)
						|| $acl['participant'] === '' || preg_match('//u', $acl['participant']) !== 1) {
						throw \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException::unsupported();
					}
					$hasGroup = $hasGroup || $acl['type'] === Acl::PERMISSION_TYPE_GROUP;
					$hasCircle = $hasCircle || $acl['type'] === Acl::PERMISSION_TYPE_CIRCLE;
				}
				$expansion->add($this->directory->user($source['board']['owner']));
				foreach ($acls as $acl) {
					$expansion->checkpoint();
					$users = match ($acl['type']) {
						Acl::PERMISSION_TYPE_USER => [$this->directory->user($acl['participant'])],
						Acl::PERMISSION_TYPE_GROUP => $this->directory->group($acl['participant'], $expansion),
						Acl::PERMISSION_TYPE_CIRCLE => $this->directory->circle($acl['participant'], $expansion),
					};
					foreach ($users as $user) {
						$expansion->add($user);
					}
				}
				$snapshot = new \OCA\Mcp\Modules\Deck\Recipients\RecipientSnapshot($context, $expansion->members(), $acls, $hasGroup, $hasCircle, $this->plans);
				$expansion->checkpoint();
				return $snapshot;
			});
		} catch (Throwable $failure) {
			if ($inactive && $failure instanceof \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException
				&& $failure->marksInactiveFileSource($context->actorUid, $context->cardId, $context->boardId)) {
				throw new \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException(\OCA\Mcp\Modules\Deck\Presentation\DeckAttachmentMessages::fileSourceInactive());
			}
			if (!$failure instanceof \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException && !$failure instanceof \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException
				&& !is_a($failure, 'OCA\\Deck\\NoPermissionException')
				&& !is_a($failure, 'OCP\\AppFramework\\Db\\DoesNotExistException')) {
				$this->logger->debug('Deck recipient resolution failed: {exceptionClass} (code {exception_code})', ['exceptionClass' => $failure::class, 'exception_code' => ExceptionDetails::code($failure)]);
			}
			if ($authorized) {
				throw new \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException($failure instanceof \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException
					? $failure->callerMessage() : \OCA\Mcp\Modules\Deck\Recipients\RecipientMessages::incomplete());
			}
			throw \OCA\Mcp\Modules\Deck\Attachments\CardFiles::unavailable();
		}
	}

	/**
	 * Re-evaluate all three decisions from the current operation context without exposing source metadata.
	 *
	 * GrantPolicy deliberately snapshots within() (contract B.5). Repetition is not an external refresh;
	 * concurrent configuration changes remain a TOCTOU limit. A new call/confirm opens its own snapshot.
	 *
	 * @param string $uid Authenticated actor bound by the current policy scope.
	 * @return void
	 * @throws DeckRefusalException When any required grant is absent.
	 */
	private function requireGrants(string $uid): void {
		foreach ([['deck', 'edit'], ['files', 'read'], ['files', 'share']] as [$module, $operation]) {
			if (!$this->policy->granted($uid, $module, $operation)) {
				throw \OCA\Mcp\Modules\Deck\Attachments\CardFiles::unavailable();
			}
		}
	}
}
