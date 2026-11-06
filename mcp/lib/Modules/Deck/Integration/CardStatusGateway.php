<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\Modules\Deck\Failures\DeckRefusalException;
use OCA\Mcp\Modules\Deck\Presentation\DeckStatusMessages;
use OCP\IConfig;

/** Native READ/EDIT and status operations, composed lazily outside the legacy facade. */
final class CardStatusGateway implements CardStatusGatewayInterface {
	/** @param DeckServiceContext $services Request-local native services. @param IConfig $config Native app version for the plan. */
	public function __construct(private DeckServiceContext $services, private IConfig $config) {
	}

	/** {@inheritDoc} Scalar metadata is never collected before both native permission checks. */
	public function state(string $userId, int $cardId): array {
		$this->services->bindUser($userId);
		/** @var PermissionService $permissions */
		$permissions = $this->services->get(PermissionService::class);
		/** @var CardMapper $cards */
		$cards = $this->services->get(CardMapper::class);
		$permissions->checkPermission($cards, $cardId, Acl::PERMISSION_READ);
		$permissions->checkPermission($cards, $cardId, Acl::PERMISSION_EDIT);
		$card = $cards->find($cardId, false);
		$boardId = (int)$cards->findBoardId($cardId);
		/** @var BoardService $boards */
		$boards = $this->services->get(BoardService::class);
		$board = $boards->find($boardId, false, true);
		if ($board->getArchived() || $board->getDeletedAt() || $card->getDeletedAt()) {
			throw new DeckRefusalException(DeckStatusMessages::inactiveBoard());
		}
		/** @var StackMapper $stacks */
		$stacks = $this->services->get(StackMapper::class);
		$list = $stacks->find((int)$card->getStackId());
		$supported = method_exists($stacks, 'findDoneColumnForBoard') && property_exists($list, 'isDoneColumn');
		$isDone = $supported && (bool)$list->getIsDoneColumn();
		$destination = $supported ? ($isDone ? $list : $stacks->findDoneColumnForBoard($boardId)) : null;
		$owner = (string)$board->getOwner();
		return ['card' => [
			'id' => (int)$card->getId(), 'boardId' => $boardId, 'title' => (string)$card->getTitle(),
			'stackId' => (int)$card->getStackId(), 'done' => $card->getDone()?->getTimestamp(),
			'archived' => (bool)$card->getArchived(), 'deletedAt' => (int)$card->getDeletedAt(),
			'lastModified' => (int)$card->getLastModified(),
		], 'board' => ['id' => $boardId, 'name' => (string)$board->getTitle(), 'owner' => $owner,
			'ownerDisplayName' => $this->services->displayName($owner), 'archived' => false],
			'list' => ['id' => (int)$list->getId(), 'title' => (string)$list->getTitle(), 'isDoneColumn' => $isDone],
			'autoMove' => ['supported' => $supported, 'destination' => $destination === null ? null : [
				'id' => (int)$destination->getId(), 'title' => (string)$destination->getTitle(),
			]], 'version' => $this->config->getAppValue('deck', 'installed_version', '')];
	}

	/** {@inheritDoc} No full-form update and no mapper writes. */
	public function setDone(string $userId, int $cardId, bool $done): void {
		$this->services->bindUser($userId);
		/** @var CardService $cards */
		$cards = $this->services->get(CardService::class);
		if ($done) {
			$cards->done($cardId);
		} else {
			$cards->undone($cardId);
		}
	}

	/** {@inheritDoc} Archive never implies completion, and unarchive never clears it. */
	public function setArchived(string $userId, int $cardId, bool $archived): void {
		$this->services->bindUser($userId);
		/** @var CardService $cards */
		$cards = $this->services->get(CardService::class);
		if ($archived) {
			$cards->archive($cardId);
		} else {
			$cards->unarchive($cardId);
		}
	}
}
