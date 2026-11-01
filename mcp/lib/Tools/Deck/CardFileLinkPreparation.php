<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;

/**
 * Read-only authorization and state preparation, ordered before native Circles expansion.
 */
final class CardFileLinkPreparation {
	/**
	 * Compose existing authorization boundaries and the complete recipient resolver.
	 *
	 * @param DeckGatewayInterface $gateway Fresh source READ/EDIT and active-state authorization.
	 * @param GrantPolicy $grants Caller-bound policy operation; no cross-request cache.
	 * @param FilesAccessGuard $guard Fresh Files scope/exclusion boundary.
	 * @param CardFileNodeAccess $nodes Canonical File resolution with all-alias READ/SHARE.
	 * @param CardLinkPolicy $policies Strict effective policy and explicit Guest identification.
	 * @param NativeCardFileLink $native Native duplicate and actor-initiated share observations.
	 * @param NodeAccessInfo $ownership Existing Files personal/shared/team/external classification.
	 * @param BoardRecipientResolver $recipients Complete existing DK-4r expansion/HMAC.
	 * @return void
	 */
	public function __construct(private DeckGatewayInterface $gateway, private GrantPolicy $grants,
		private FilesAccessGuard $guard, private CardFileNodeAccess $nodes, private CardLinkPolicy $policies,
		private NativeCardFileLink $native, private NodeAccessInfo $ownership, private BoardRecipientResolver $recipients) {
	}

	/**
	 * Prepare one complete snapshot without writing or exposing raw ACLs or policy inputs.
	 *
	 * Files, source and strict actor/policy admission precede the only recipient expansion.
	 * No new MCP authorization that depends on Circles may follow resolve: Deck leaves a super session active.
	 * Native createShare EDIT and visibleResult READ still run afterwards. Deck CirclesService::isUserInCircle
	 * starts the checked user's own session, so these native checks do not inherit super-session authority.
	 * Their membership result may come from the native request cache; it is not a fresh membership lookup.
	 * GrantPolicy::within uses one B.5 snapshot, not external concurrent configuration refresh.
	 * A new confirm request opens a new snapshot. Checks cannot close the native createShare TOCTOU window.
	 *
	 * @param string $uid Authenticated actor.
	 * @param int $cardId Editable active card.
	 * @param int $fileId Visible File identity to link.
	 * @return array<string, mixed> Internal state, including native node and an immutable signed-plan snapshot.
	 * @throws \Throwable On any denial, duplicate, uncertainty or unsupported audience.
	 */
	public function prepare(string $uid, int $cardId, int $fileId): array {
		return $this->grants->within($uid, function () use ($uid, $cardId, $fileId): array {
			foreach ([['deck', 'edit'], ['files', 'read'], ['files', 'share']] as [$module, $operation]) {
				if (!$this->grants->granted($uid, $module, $operation)) {
					throw CardFiles::unavailable();
				}
			}
			$this->guard->clearCache();
			$this->guard->assertScopeReadable();
			$source = $this->gateway->cardFileContext($uid, $cardId, true);
			$resolved = $this->nodes->resolve($uid, $fileId, true);
			$file = $resolved['metadata'];
			if ($file['owner'] === '' || $file['etag'] === '') {
				throw CardFiles::unavailable();
			}
			$reshare = $file['owner'] !== $uid;
			$policy = $this->policies->inspect($uid, $reshare);
			if ($this->native->find($uid, $cardId, $resolved['node']) !== null) {
				throw new DeckRefusalException(CardLinkMessages::duplicate());
			}
			$shares = $this->native->visibleShares($uid, $resolved['node']);
			$scope = $this->grants->scopeItems($uid, 'files');
			if ($scope !== null) {
				sort($scope, SORT_STRING);
			}
			$tags = $this->guard->getHiddenTagIds();
			sort($tags, SORT_NUMERIC);
			$card = $source['card'];
			$origin = ['id' => $cardId, 'boardId' => $source['boardId'], 'stackId' => (int)$card->getStackId(),
				'title' => (string)$card->getTitle()];
			$permissions = (int)$resolved['node']->getPermissions();
			$fileAccess = $this->ownership->describe($resolved['node'], $uid);
			$context = new LinkRecipientContext($uid, $source['boardId'], $cardId, $fileId, $file['etag']);
			$recipients = $this->recipients->resolve($context);
			$this->policies->assertAudience($policy, $recipients);
			$snapshot = ['context' => $context->state(), 'origin' => $origin, 'board' => $source['board'],
				'file' => $file, 'permissions' => $permissions, 'reshare' => $reshare, 'shares' => $shares,
				'fileAccess' => $fileAccess, 'policies' => $policy, 'scope' => $scope, 'hiddenTags' => $tags,
				'recipientHash' => $recipients->recipientHash, 'recipientPlanHash' => $recipients->planHash];
			return ['source' => $source, 'node' => $resolved['node'], 'snapshot' => $snapshot,
				'recipients' => $recipients->presentation()];
		});
	}

	/**
	 * Revalidate result visibility without re-expanding or showing pre-write metadata after access loss.
	 *
	 * @param string $uid Authenticated actor.
	 * @param int $cardId Previously authorized card.
	 * @param int $fileId Canonical node identity.
	 * @return array{node:\OCP\Files\File,metadata:array} Currently visible file only.
	 * @throws \Throwable On any lost grant, scope/tag, source or native READ visibility.
	 */
	public function visibleResult(string $uid, int $cardId, int $fileId): array {
		return $this->grants->within($uid, function () use ($uid, $cardId, $fileId): array {
			if (!$this->grants->granted($uid, 'deck', 'edit') || !$this->grants->granted($uid, 'files', 'read')) {
				throw CardFiles::unavailable();
			}
			$this->guard->clearCache();
			$this->guard->assertScopeReadable();
			$this->gateway->cardFileContext($uid, $cardId, false);
			return $this->nodes->resolve($uid, $fileId);
		});
	}
}
