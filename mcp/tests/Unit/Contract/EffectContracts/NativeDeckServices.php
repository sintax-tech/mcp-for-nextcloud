<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\IPermissionMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Service\StackService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;

require_once __DIR__ . '/../../Tools/Deck/Stubs/deck_stubs.php';

/**
 * The native Deck services the real gateway resolves from the container, each a subclass of the Deck v1.17.5
 * signature stub answering from {@see NativeDeck}: the services run Deck's own permission check before they read or
 * write; the mappers are the plain queries without one ({@see NativeDeckMappers}). A method these flows never reach
 * keeps the stub body, which throws, so an unexpected native call fails the contract instead of passing silently.
 */
final class NativeDeckServices {
	/** Static factory only. */
	private function __construct() {
	}

	/**
	 * @param NativeDeck $deck the database behind the services
	 * @param IUserManager $users accounts the gateway resolves display names from
	 * @return array<string, object> the services by the class name the gateway asks the container for; fresh objects
	 *     per request over the same database, as the Deck DI container builds them per PHP request
	 */
	public static function of(NativeDeck $deck, IUserManager $users): array {
		return [
			BoardService::class => self::boards($deck), StackService::class => self::stacks($deck),
			CardService::class => self::cards($deck), PermissionService::class => self::permissions($deck),
			AssignmentMapper::class => new AssignmentMapper(), IUserManager::class => $users,
		] + NativeDeckMappers::of($deck);
	}

	/**
	 * @param NativeDeck $deck database
	 * @return BoardService setUserId binds the account; delete stamps the trash; deleteUndo brings the board back
	 */
	private static function boards(NativeDeck $deck): BoardService {
		return new class($deck) extends BoardService {
			/** @param NativeDeck $deck database */
			public function __construct(private NativeDeck $deck) {
			}

			/** {@inheritDoc} */
			public function create(string $title, string $userId, string $color): Board {
				$id = max(array_keys($this->deck->boards)) + 1;
				$this->deck->record('BoardService::create', [$title, $userId, $color]);
				$this->deck->boards[$id] = ['title' => $title, 'owner' => $userId, 'color' => $color, 'deletedAt' => 0];
				return $this->deck->board($id);
			}

			/** {@inheritDoc} */
			public function setUserId(string $userId): void {
				$this->deck->bind($userId);
			}

			/** {@inheritDoc} */
			public function findAll(int $since = -1, bool $fullDetails = false, bool $includeArchived = true): array {
				return $this->deck->readableBoards();
			}

			/** {@inheritDoc} */
			public function find(int $boardId, bool $fullDetails = true, bool $allowDeleted = false): Board {
				if (!isset($this->deck->boards[$boardId]) || (!$allowDeleted && $this->deck->boards[$boardId]['deletedAt'] !== 0)) {
					throw new DoesNotExistException('board');
				}
				return $this->deck->board($boardId);
			}

			/** {@inheritDoc} */
			public function delete(int $id): Board {
				$this->deck->check(null, $id, Acl::PERMISSION_MANAGE);
				$this->deck->record('BoardService::delete', [$id]);
				$this->deck->boards[$id]['deletedAt'] = NativeDeck::NOW;
				return $this->deck->board($id);
			}

			/** {@inheritDoc} */
			public function deleteUndo(int $id): Board {
				$this->deck->record('BoardService::deleteUndo', [$id]);
				$this->deck->boards[$id]['deletedAt'] = 0;
				return $this->deck->board($id);
			}
		};
	}

	/**
	 * @param NativeDeck $deck database
	 * @return StackService findAll after READ; delete after MANAGE; update restores a list from the trash
	 */
	private static function stacks(NativeDeck $deck): StackService {
		return new class($deck) extends StackService {
			/** @param NativeDeck $deck database */
			public function __construct(private NativeDeck $deck) {
			}

			/** {@inheritDoc} */
			public function create(string $title, int $boardId, int $order): Stack {
				$this->deck->check(null, $boardId, Acl::PERMISSION_MANAGE);
				$id = max(array_keys($this->deck->stacks)) + 1;
				$this->deck->record('StackService::create', [$title, $boardId, $order]);
				$this->deck->stacks[$id] = ['boardId' => $boardId, 'title' => $title, 'order' => $order, 'deletedAt' => 0];
				return $this->deck->stack($id);
			}

			/** {@inheritDoc} */
			public function findAll(int $boardId, int $since = -1): array {
				$this->deck->check(null, $boardId, Acl::PERMISSION_READ);
				return $this->deck->stacksOf($boardId, false, true);
			}

			/** {@inheritDoc} */
			public function delete(int $id): Stack {
				$this->deck->check('stack', $id, Acl::PERMISSION_MANAGE);
				$this->deck->record('StackService::delete', [$id]);
				$this->deck->stacks[$id]['deletedAt'] = NativeDeck::NOW;
				return $this->deck->stack($id);
			}

			/** {@inheritDoc} */
			public function update(int $id, string $title, int $boardId, int $order, ?int $deletedAt): Stack {
				$this->deck->record('StackService::update', [$id, $title, $boardId, $order, $deletedAt]);
				$this->deck->stacks[$id]['deletedAt'] = (int)$deletedAt;
				return $this->deck->stack($id);
			}
		};
	}

	/**
	 * @param NativeDeck $deck database
	 * @return CardService find after READ (no card of the trash); create, reorder, delete and the label links after EDIT
	 */
	private static function cards(NativeDeck $deck): CardService {
		return new class($deck) extends CardService {
			/** @param NativeDeck $deck database */
			public function __construct(private NativeDeck $deck) {
			}

			/** {@inheritDoc} */
			public function assignDependentCard(int $cardId, int $dependentCardId): Card {
				$this->deck->record('CardService::assignDependentCard', [$cardId, $dependentCardId]);
				$this->deck->edges[$cardId][] = $dependentCardId;
				return $this->deck->card($cardId);
			}

			/** {@inheritDoc} */
			public function removeDependentCard(int $cardId, int $dependentCardId): Card {
				$this->deck->record('CardService::removeDependentCard', [$cardId, $dependentCardId]);
				$this->deck->edges[$cardId] = array_values(array_diff($this->deck->edges[$cardId] ?? [], [$dependentCardId]));
				return $this->deck->card($cardId);
			}

			/** {@inheritDoc} */
			public function find(int $cardId): Card {
				$this->deck->check('card', $cardId, Acl::PERMISSION_READ);
				return $this->deck->card($cardId);
			}

			/** {@inheritDoc} */
			public function create(string $title, int $stackId, string $type, int $order, string $owner, string $description = '', $duedate = null): Card {
				$this->deck->check('stack', $stackId, Acl::PERMISSION_EDIT);
				$this->deck->record('CardService::create', [$title, $stackId, $type, $order, $owner, $description, $duedate]);
				return $this->deck->insertCard($title, $stackId, $order, $owner, $description, $duedate);
			}

			/** {@inheritDoc} */
			public function update(int $id, string $title, int $stackId, string $type, string $owner, string $description = '',
				int $order = 0, ?string $duedate = null, ?int $deletedAt = null, ?bool $archived = null,
				?\OCA\Deck\Model\OptionalNullableValue $done = null): Card {
				$this->deck->check('card', $id, Acl::PERMISSION_EDIT);
				$this->deck->record('CardService::update', [$id, $title, $stackId, $type, $owner, $description, $order, $duedate]);
				$this->deck->cards[$id] = ['title' => $title, 'description' => $description, 'lastModified' => NativeDeck::NOW]
					+ $this->deck->cards[$id];
				return $this->deck->card($id);
			}

			/** {@inheritDoc} */
			public function reorder(int $id, int $stackId, int $order): array {
				$this->deck->check('card', $id, Acl::PERMISSION_EDIT);
				$this->deck->check('stack', $stackId, Acl::PERMISSION_EDIT);
				$this->deck->record('CardService::reorder', [$id, $stackId, $order]);
				$this->deck->cards[$id] = ['stackId' => $stackId, 'order' => $order, 'lastModified' => NativeDeck::NOW] + $this->deck->cards[$id];
				return $this->deck->cardsOf($stackId);
			}

			/** {@inheritDoc} */
			public function assignLabel(int $cardId, int $labelId): Card {
				return $this->label('CardService::assignLabel', $cardId, $labelId, true);
			}

			/** {@inheritDoc} */
			public function removeLabel(int $cardId, int $labelId): Card {
				return $this->label('CardService::removeLabel', $cardId, $labelId, false);
			}

			/**
			 * One label link written after Deck's EDIT check on the card.
			 *
			 * @param string $call the native method
			 * @param int $cardId card
			 * @param int $labelId label
			 * @param bool $assign whether the link is added or removed
			 * @return Card the card as it is now
			 */
			private function label(string $call, int $cardId, int $labelId, bool $assign): Card {
				$this->deck->check('card', $cardId, Acl::PERMISSION_EDIT);
				$this->deck->record($call, [$cardId, $labelId]);
				$labels = array_values(array_diff($this->deck->cardLabels[$cardId] ?? [], [$labelId]));
				if ($assign) {
					$labels[] = $labelId;
				}
				sort($labels);
				$this->deck->cardLabels[$cardId] = $labels;
				return $this->deck->card($cardId);
			}

			/** {@inheritDoc} */
			public function delete(int $id): Card {
				$this->deck->check('card', $id, Acl::PERMISSION_EDIT);
				$this->deck->record('CardService::delete', [$id]);
				$this->deck->cards[$id] = ['deletedAt' => NativeDeck::NOW, 'lastModified' => NativeDeck::NOW] + $this->deck->cards[$id];
				return $this->deck->card($id);
			}
		};
	}

	/**
	 * @param NativeDeck $deck database
	 * @return PermissionService the ACL of the database for the bound account
	 */
	private static function permissions(NativeDeck $deck): PermissionService {
		return new class($deck) extends PermissionService {
			/** @param NativeDeck $deck database */
			public function __construct(private NativeDeck $deck) {
			}

			/** {@inheritDoc} */
			public function checkPermission(?IPermissionMapper $mapper, $id, int $permission, $userId = null, bool $allowDeletedCard = false, bool $allowDeletedBoard = false): bool {
				$kind = $mapper instanceof CardMapper ? 'card' : ($mapper instanceof StackMapper ? 'stack' : null);
				$this->deck->check($kind, (int)$id, $permission, $allowDeletedCard);
				return true;
			}

			/** {@inheritDoc} */
			public function getPermissions(int $boardId, ?string $userId = null, bool $allowDeletedBoard = false): array {
				$levels = [Acl::PERMISSION_READ, Acl::PERMISSION_EDIT, Acl::PERMISSION_SHARE, Acl::PERMISSION_MANAGE];
				return array_combine($levels, array_map(fn (int $level): bool => $this->deck->permits($boardId, (string)$userId, $level), $levels));
			}
		};
	}
}
