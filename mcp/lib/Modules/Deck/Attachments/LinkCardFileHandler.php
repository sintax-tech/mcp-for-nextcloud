<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Attachments;

use Closure;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Platform\Logging\ExceptionDetails;
use OCA\Mcp\Modules\Deck\Attachments\CardFileLinkPreparation;
use OCA\Mcp\Modules\Deck\Attachments\CardLinkMessages;
use OCA\Mcp\Modules\Deck\Attachments\CardLinkPlan;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Deck\Recipients\LinkRecipientContext;
use OCA\Mcp\Modules\Deck\Integration\NativeCardFileLink;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\Constants;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One native file link with a complete fresh warning, signed state and conservative outcome attribution.
 */
final class LinkCardFileHandler extends \OCA\Mcp\Modules\Deck\AbstractHandler {
	/**
	 * Public tool identity shared with the typed recipient context.
	 */
	public const TOOL = LinkRecipientContext::OPERATION;

	/**
	 * Lazily assembled operation services, resolved only after the optional app gate admits a call.
	 *
	 * @var array{prepare:CardFileLinkPreparation,native:NativeCardFileLink,plans:CardLinkPlan,shared:SharedWriteGuard}|null
	 */
	private ?array $services = null;

	/**
	 * Receive the existing handler boundaries and a lazy focused service composition.
	 *
	 * @param DeckGatewayInterface $gateway Native source boundary for the existing shared-board gate.
	 * @param LoggerInterface $logger Receives exception classes only.
	 * @param Closure():array{prepare:CardFileLinkPreparation,native:NativeCardFileLink,plans:CardLinkPlan,shared:SharedWriteGuard} $factory Lazy operation services.
	 * @return void
	 */
	public function __construct(DeckGatewayInterface $gateway, LoggerInterface $logger, private Closure $factory) {
		parent::__construct($gateway, $logger);
	}

	/**
	 * Authorize and show the complete warning without creating a share.
	 *
	 * @param array<string, mixed> $arguments Validated cardId and fileId.
	 * @param string $userId Authenticated actor.
	 * @return array<string, mixed> Fresh public preview and state HMAC.
	 * @throws Throwable On refused or uncertain source, file, policy or audience.
	 */
	public function preview(array $arguments, string $userId): array {
		$services = $this->services();
		return $services['plans']->of($services['prepare']->prepare($userId,
			(int)$arguments['cardId'], (int)$arguments['fileId']), $userId);
	}

	/**
	 * Revalidate the complete plan and execute at most one native creation attempt.
	 *
	 * Native createShare EDIT and the result READ check follow recipient expansion. Deck's
	 * CirclesService::isUserInCircle starts the checked user's own session rather than using
	 * super-session authority; membership can still come from its request cache, not a fresh lookup.
	 * No additional MCP authorization based on Circles is performed after expansion.
	 *
	 * @param array<string, mixed> $arguments Validated targets, confirmation and previous plan_state.
	 * @param string $userId Authenticated actor.
	 * @return array<string, mixed> Standard MCP result, without stale file metadata on uncertain visibility.
	 * @throws \InvalidArgumentException For an absent mandatory confirmation state.
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			$services = $this->services();
			$cardId = (int)$arguments['cardId'];
			$fileId = (int)$arguments['fileId'];
			$state = $services['prepare']->prepare($userId, $cardId, $fileId);
			$plan = $services['plans']->of($state, $userId);
			if (($arguments['confirm'] ?? false) !== true) {
				return ['requiresConfirmation' => true] + $plan;
			}
			PlanState::require($arguments);
			if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) {
				throw new PlanChanged($plan);
			}
			$shared = $this->confirmShared($arguments, $userId, $state['source']['board']);
			if ($shared !== null) {
				return $shared;
			}
			// Linking does not require UPDATE on the original: READ+SHARE was already checked.
			// Reuse only the shared-resource confirmation payload, from ownership read before Circles.
			if ($state['snapshot']['fileAccess']['scope'] !== NodeAccessInfo::PERSONAL
				&& ($arguments['confirm_shared'] ?? false) !== true) {
				return $services['shared']->request($state['snapshot']['fileAccess'], $state['snapshot']['file']['path']);
			}
			$created = null;
			$error = null;
			try {
				$created = $services['native']->create($userId, $cardId, $state['node']);
			} catch (Throwable $failure) {
				$error = $failure;
			}
			try {
				$visible = $services['prepare']->visibleResult($userId, $cardId, $fileId);
				$observed = $services['native']->find($userId, $cardId, $visible['node']);
			} catch (Throwable $failure) {
				$this->logger->warning('Deck file link result cannot be observed: {exceptionClass} (code {exception_code})', ['exceptionClass' => $failure::class, 'exception_code' => ExceptionDetails::code($failure)]);
				return $this->uncertain();
			}
			if ($observed === null) {
				if ($error !== null) {
					throw $error;
				}
				return $this->uncertain();
			}
			// Native Deck rejects duplicates before returning an ID. A row found after an exception
			// without that return value is not proof this attempt created it, even for the same UID.
			if ($created === null || !$this->matches($created, $observed, $userId, $cardId, $fileId)) {
				return $this->uncertain();
			}
			return ['confirmed' => true, 'linked' => true, 'changed' => true,
				'attachmentId' => (int)$observed->getId(), 'file' => $visible['metadata'],
				'permissions' => Constants::PERMISSION_READ, 'verification' => 'returned-id-and-observed-state'];
		});
	}

	/**
	 * Require the returned native creation identity and every observed target/permission field to agree.
	 *
	 * @param IShare $created Share returned by this attempt's native createShare.
	 * @param IShare $observed Share re-read by canonical file and card.
	 * @param string $uid Authenticated initiator.
	 * @param int $cardId Authorized card.
	 * @param int $fileId Authorized canonical node.
	 * @return bool Whether the exact identity and native read-only contract were observed.
	 */
	private function matches(IShare $created, IShare $observed, string $uid, int $cardId, int $fileId): bool {
		foreach ([$created, $observed] as $share) {
			if ((int)$share->getId() <= 0 || $share->getShareType() !== IShare::TYPE_DECK
				|| $share->getSharedBy() !== $uid || (string)$share->getSharedWith() !== (string)$cardId
				|| (int)$share->getNodeId() !== $fileId || $share->getPermissions() !== Constants::PERMISSION_READ) {
				return false;
			}
		}
		return (string)$created->getId() === (string)$observed->getId();
	}

	/**
	 * Return no pre-write metadata or optimistic attribution when the creation outcome is uncertain.
	 *
	 * @return array{confirmed:false,message:string} Non-error result that prevents an automatic retry.
	 */
	private function uncertain(): array {
		return ['confirmed' => false, 'message' => CardLinkMessages::uncertain()];
	}

	/**
	 * Resolve the focused operation services only for an actual preview/confirm.
	 *
	 * @return array{prepare:CardFileLinkPreparation,native:NativeCardFileLink,plans:CardLinkPlan,shared:SharedWriteGuard} Operation dependencies.
	 */
	private function services(): array {
		return $this->services ??= ($this->factory)();
	}

	/**
	 * Identify this tool in sanitized handler logs.
	 *
	 * @return string Public tool identity.
	 */
	protected function toolName(): string {
		return self::TOOL;
	}
}
