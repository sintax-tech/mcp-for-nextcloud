<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Share\IShare;

/** Files references have their own Files scope, exclusion tags and native READ boundary, even inside Deck. */
final class CardFiles {
	/**
	 * @param DeckGatewayInterface $gateway Native source/share boundary, resolved only with Deck enabled.
	 * @param GrantPolicy $policy Opens/joins one caller-bound policy operation.
	 * @param FilesAccessGuard $guard Scope, exclusion and owner-chain visibility.
	 * @param IRootFolder $rootFolder Resolves nodes only in the caller's tree.
	 */
	public function __construct(private DeckGatewayInterface $gateway, private GrantPolicy $policy,
		private FilesAccessGuard $guard, private IRootFolder $rootFolder) {
	}

	/**
	 * @param string $userId Authenticated caller.
	 * @param bool $edit True for preview/confirm of an unlink.
	 * @return void
	 * @throws \Throwable On missing grants or unreadable Files scope.
	 */
	private function authorize(string $userId, bool $edit): void {
		if (!$this->policy->granted($userId, 'deck', $edit ? 'edit' : 'read')
			|| !$this->policy->granted($userId, 'files', 'read')) {
			throw self::unavailable();
		}
		$this->guard->clearCache();
		$this->guard->assertScopeReadable();
	}

	/**
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Readable card.
	 * @return array{items: list<array>} Only visible, native file associations; no native/hidden count.
	 * @throws \Throwable On source denial or uncertain listing/policy.
	 */
	public function list(string $userId, int $cardId): array {
		return $this->policy->within($userId, function () use ($userId, $cardId): array {
			$this->authorize($userId, false);
			$items = [];
			foreach ($this->gateway->cardFileShares($userId, $cardId) as $share) {
				if ($share->getShareType() !== IShare::TYPE_DECK || (string)$share->getSharedWith() !== (string)$cardId) {
					continue;
				}
				try {
					$file = $this->file($userId, (int)$share->getNodeId());
				} catch (DeckRefusalException) {
					continue;
				}
				$items[] = ['attachmentId' => (int)$share->getId(), 'type' => 'file',
					'createdBy' => (string)$share->getSharedBy(), 'createdAt' => $share->getShareTime()->getTimestamp()]
					+ $file['metadata'];
			}
			usort($items, static fn (array $a, array $b): int => $a['attachmentId'] <=> $b['attachmentId']);
			return ['items' => $items];
		});
	}

	/**
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Editable card.
	 * @param int $attachmentId Bare native share ID.
	 * @param array{fileId:int,createdBy:string,createdAt:int}|null $receipt Previously shown fields, never trusted before HMAC validation.
	 * @return array<string, mixed> Fresh visible state, or a candidate absent state requiring a valid original HMAC.
	 * @throws \Throwable On any visibility, grant, source or association failure.
	 */
	public function state(string $userId, int $cardId, int $attachmentId, ?array $receipt = null): array {
		return $this->policy->within($userId, function () use ($userId, $cardId, $attachmentId, $receipt): array {
			$this->authorize($userId, true);
			$context = $this->gateway->cardFileContext($userId, $cardId, true);
			$share = $this->gateway->cardFileShare($userId, $cardId, $attachmentId);
			if ($share === null && $receipt === null) {
				throw self::unavailable();
			}
			$actual = $share === null ? $receipt : self::receipt($share);
			if ($receipt !== null && $receipt !== $actual) {
				throw self::unavailable();
			}
			$resolved = $this->file($userId, $actual['fileId']);
			$file = $resolved['metadata'];
			$card = $context['card'];
			$snapshot = ['card' => ['id' => (int)$card->getId(), 'stackId' => (int)$card->getStackId(),
				'boardId' => $context['boardId'], 'title' => (string)$card->getTitle()],
				'board' => $context['board'], 'attachmentId' => $attachmentId, 'file' => $file, 'file_state' => $actual];
			return ['context' => $context, 'file' => $file, 'node' => $resolved['node'], 'file_state' => $actual,
				'identity' => ['attachmentId' => $attachmentId, 'cardId' => $cardId] + $actual,
				'snapshot' => $snapshot, 'present' => $share !== null];
		});
	}

	/**
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Editable source.
	 * @param int $attachmentId Already authorized association ID.
	 * @param array<string, mixed> $expected Canonical visible snapshot checked by the handler's HMAC.
	 * @return void
	 * @throws \Throwable On changed state or native error, possibly after unlink persisted.
	 */
	public function unlink(string $userId, int $cardId, int $attachmentId, array $expected): void {
		$state = $this->state($userId, $cardId, $attachmentId);
		if ($state['snapshot'] !== $expected) {
			throw new DeckConflictException(DeckMessages::filePlanChanged());
		}
		$this->gateway->unlinkCardFile($userId, $cardId, $attachmentId, $state['identity']);
	}

	/**
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Previously authorized source.
	 * @param int $attachmentId Previously authorized share ID.
	 * @param File $node Previously authorized original file; no post-unlink metadata is exposed.
	 * @return bool Whether the exact raw association still exists, without file metadata.
	 * @throws \Throwable On uncertain observation or source denial.
	 */
	public function exists(string $userId, int $cardId, int $attachmentId, File $node): bool {
		return $this->gateway->cardFileShareExists($userId, $cardId, $attachmentId, $node);
	}

	/**
	 * All aliases in the caller's Files tree must pass; a clean alias cannot bypass an excluded one.
	 *
	 * @param string $userId Authenticated caller.
	 * @param int $fileId Stable original node identity.
	 * @return array{metadata:array,node:File} Visible caller-relative metadata and an internal node for exact raw-share observation.
	 * @throws \Throwable Policy failure or generic missing/hidden/legacy/folder refusal.
	 */
	private function file(string $userId, int $fileId): array {
		return (new CardFileNodeAccess($this->guard, $this->rootFolder))->resolve($userId, $fileId);
	}

	/**
	 * @param IShare $share Native association, after source authorization.
	 * @return array{fileId:int,createdBy:string,createdAt:int} Confirmation receipt covered by the same plan HMAC.
	 */
	public static function receipt(IShare $share): array {
		return ['fileId' => (int)$share->getNodeId(), 'createdBy' => (string)$share->getSharedBy(),
			'createdAt' => $share->getShareTime()->getTimestamp()];
	}

	/**
	 * @param IShare $share Native type/card-checked association.
	 * @return array<string, mixed> Identity rechecked immediately before native unlink.
	 */
	public static function identity(IShare $share): array {
		return ['attachmentId' => (int)$share->getId(), 'cardId' => (int)$share->getSharedWith()] + self::receipt($share);
	}

	/** @return DeckRefusalException The same public refusal for hidden, missing and invalid receipts. */
	public static function unavailable(): DeckRefusalException {
		return new DeckRefusalException(DeckMessages::fileUnavailable());
	}
}
