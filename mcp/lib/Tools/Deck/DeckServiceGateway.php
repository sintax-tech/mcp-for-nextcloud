<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Model\OptionalNullableValue;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\AssignmentService;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Service\StackService;
use OCA\Mcp\Service\UserTimezone;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ISession;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;

/**
 * The only class that talks to the Deck app.
 *
 * Deck exposes no OCP API, so the services of `OCA\Deck\*` are resolved lazily through the
 * container: injecting them in a constructor would break the `mcp` container on instances where
 * the Deck app is disabled. Class names are strings so this file can be autoloaded with the Deck
 * absent, and every Deck signature here was read from the tag that matches Nextcloud 33
 * (`v1.17.5`, `nextcloud min-version="33" max-version="33"`).
 */
final class DeckServiceGateway implements DeckGatewayInterface {
	/** Last position of a stack, so a created card lands at the bottom like the Deck web UI. */
	private const LAST_ORDER = 99999;

	/** Visible boards a follow-up scans at most, so one call stays a bounded number of queries. */
	private const MAX_BOARDS = 100;

	/** How far back a card counts as the one a failed creation saved, in seconds. */
	private const CREATED_WITHIN = 120;

	/** @var array<string, object> Services resolved so far in this request. */
	private array $resolved = [];

	/**
	 * @param ContainerInterface $container Nextcloud server container, resolves Deck services by FQCN.
	 * @param ITimeFactory $time Clock the follow-up reads `overdue` from, so it can be pinned in a test.
	 * @param UserTimezone|null $zones Timezone of the caller, deciding which day a due date falls on;
	 *     PHP's default when absent.
	 * @param ISession|null $session Session the DI container reads the Deck services' `userId` from; checked
	 *     against the caller before every Deck call, skipped when absent.
	 */
	public function __construct(
		private ContainerInterface $container,
		private ITimeFactory $time,
		private ?UserTimezone $zones = null,
		private ?ISession $session = null,
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function listBoards(string $userId): array {
		/** @var BoardService $boardService */
		$boardService = $this->bindUser($userId);

		// `since: -1` disables the change-tracking filter; `fullDetails: false` skips the settings and session enrichment.
		return $boardService->findAll(-1, false, false);
	}

	/**
	 * {@inheritDoc}
	 */
	public function listStacks(string $userId, int $boardId): array {
		$this->bindUser($userId);

		/** @var StackService $stackService */
		$stackService = $this->service(StackService::class);

		// `StackService::findAll()` checks PERMISSION_READ on the board before listing its stacks.
		return $stackService->findAll($boardId);
	}

	/**
	 * {@inheritDoc}
	 */
	public function listCards(string $userId, int $stackId, int $limit, int $offset): array {
		$this->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->service(PermissionService::class);
		/** @var StackMapper $stackMapper */
		$stackMapper = $this->service(StackMapper::class);
		/** @var CardMapper $cardMapper */
		$cardMapper = $this->service(CardMapper::class);

		// CardMapper::findAll() is a plain query with no ACL at all, so the check comes first.
		$permissionService->checkPermission($stackMapper, $stackId, Acl::PERMISSION_READ);

		$items = $cardMapper->findAll($stackId, $limit, $offset);
		$items = is_array($items) ? array_values($items) : [];

		// Deck embeds the assignments of a card only on the detail read, so the listing loads them
		// in one query for the whole page instead of one query per card.
		$this->attachAssignments($items);

		return [
			'items' => $items,
			'boardId' => $stackMapper->findBoardId($stackId),
		];
	}

	/**
	 * {@inheritDoc}
	 */
	public function followupCards(string $userId, string $status, ?int $boardId, ?string $assignee, ?string $dueBefore, int $limit): array {
		// `listBoards()` binds the caller to the Deck before anything is read for them.
		$managed = $this->managedBoards($userId, $boardId);
		$now = $this->time->getTime();
		$zone = $this->zone($userId);

		/** @var StackMapper $stackMapper */
		$stackMapper = $this->service(StackMapper::class);
		/** @var CardMapper $cardMapper */
		$cardMapper = $this->service(CardMapper::class);

		$truncated = $managed['capped'];
		$items = [];

		foreach ($managed['boards'] as $board) {
			// One card past the limit is proof the answer is incomplete, so the scan stops there.
			if (count($items) > $limit) {
				break;
			}

			$stackIds = array_map(
				static fn (Stack $stack): int => (int)$stack->getId(),
				$stackMapper->findAll((int)$board->getId()),
			);
			if ($stackIds === []) {
				continue;
			}

			// Deck already leaves archived and deleted cards out.
			$matching = [];
			foreach ($this->cardsOfStacks($cardMapper, $stackIds) as $cardsOfStack) {
				foreach ($cardsOfStack ?? [] as $card) {
					if (CardCriteria::matches($card, $status, $dueBefore, $now, $zone)) {
						$matching[] = $card;
					}
				}
			}
			if ($matching === []) {
				continue;
			}

			$this->attachAssignments($matching);

			foreach ($matching as $card) {
				if ($assignee !== null && !in_array($assignee, CardCriteria::assignedUids($card), true)) {
					continue;
				}

				$items[] = ['card' => $card, 'boardId' => (int)$board->getId()];
			}
		}

		if (count($items) > $limit) {
			$truncated = true;
			$items = array_slice($items, 0, $limit);
		}

		return ['items' => $items, 'truncated' => $truncated];
	}

	/**
	 * {@inheritDoc}
	 */
	public function findCard(string $userId, int $cardId): Card {
		$this->bindUser($userId);

		/** @var CardService $cardService */
		$cardService = $this->service(CardService::class);

		// `CardService::find()` checks PERMISSION_READ and refuses a deleted card.
		return $cardService->find($cardId);
	}

	/** {@inheritDoc} */
	public function validateAssignees(string $userId, int $stackId, array $assignees): array {
		$this->bindUser($userId);
		/** @var PermissionService $permissions */
		$permissions = $this->service(PermissionService::class);
		/** @var StackMapper $stacks */
		$stacks = $this->service(StackMapper::class);
		$permissions->checkPermission($stacks, $stackId, Acl::PERMISSION_EDIT);
		$boardId = $stacks->findBoardId($stackId);
		/** @var IUserManager $users */
		$users = $this->service(IUserManager::class);
		$out = [];
		foreach (CardInput::assignees($assignees) as $uid) {
			$user = $users->get($uid);
			// Deck evaluates owner, direct ACL, group and circle membership for this account.
			if ($user === null || !($permissions->getPermissions($boardId, $uid)[Acl::PERMISSION_READ] ?? false)) {
				throw new ArgumentValidationException('Invalid argument: assignees', 'assignees',
					Translator::t('each account must exist and have access to the board'));
			}
			$out[] = ['uid' => $uid, 'displayName' => $user->getDisplayName()];
		}
		return $out;
	}

	/** {@inheritDoc} */
	public function assignCardUser(string $userId, int $cardId, string $assignee): Assignment {
		$this->bindUser($userId);
		/** @var AssignmentService $assignments */
		$assignments = $this->service(AssignmentService::class);
		return $assignments->assignUser($cardId, $assignee);
	}

	/** {@inheritDoc} */
	public function unassignCardUser(string $userId, int $cardId, string $assignee): Assignment {
		$this->bindUser($userId);
		/** @var AssignmentService $assignments */
		$assignments = $this->service(AssignmentService::class);
		// Deck v1.17.5: `unassignUser(int $cardId, string $userId, int $type = 0)`; the user type is the default.
		return $assignments->unassignUser($cardId, $assignee);
	}

	/**
	 * {@inheritDoc}
	 */
	public function createCard(string $userId, int $stackId, string $title, string $description, ?string $duedate, ?int $order = null): Card {
		$this->bindUser($userId);

		/** @var CardService $cardService */
		$cardService = $this->service(CardService::class);

		// `type` is free text in Deck (only length-checked), so the tools always write `note`.
		return $cardService->create($title, $stackId, 'note', $order ?? self::LAST_ORDER, $userId, $description, $this->instant($userId, $duedate));
	}

	/**
	 * {@inheritDoc}
	 */
	public function updateCard(string $userId, Card $card, string $title, string $description, ?string $duedate): Card {
		$this->bindUser($userId);

		/** @var CardService $cardService */
		$cardService = $this->service(CardService::class);

		// Deck v1.17.5 nulls `done` when the optional argument is left out, so the current value is
		// sent back wrapped to preserve it; every other field the form requires comes from the card.
		return $cardService->update(
			$card->getId(),
			$title,
			$card->getStackId(),
			$card->getType(),
			(string)$card->getOwner(),
			$description,
			$card->getOrder(),
			$this->instant($userId, $duedate),
			null,
			null,
			new OptionalNullableValue($card->getDone()),
			...$this->fieldsAfterDone($cardService, $card),
		);
	}

	/**
	 * The current values of the card fields a Deck release appended to `CardService::update()` after `done`.
	 *
	 * Deck 1.18 (Nextcloud 34) added `?string $startdate` and `?string $color` and writes both on every update, so
	 * leaving them out clears them; Deck 1.19 (Nextcloud 35) made `color` an `OptionalNullableValue`, null leaving it
	 * alone, but still clears a start date left out. Up to 1.17 the signature ends at `done` and the card has neither
	 * field, so nothing is sent. The signature is read from the method itself: named arguments would fail on the
	 * releases without these parameters. Reading stops at a parameter this class does not know, which keeps its default.
	 *
	 * @param object $cardService OCA\Deck\Service\CardService
	 * @param Card $card Card being updated, as Deck loaded it
	 * @return list<mixed> arguments for the parameters after `done`, in order
	 */
	private function fieldsAfterDone(object $cardService, Card $card): array {
		$parameters = (new \ReflectionMethod($cardService, 'update'))->getParameters();
		$names = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $parameters);
		$after = array_slice($parameters, (int)array_search('done', $names, true) + 1);

		$arguments = [];
		foreach ($after as $parameter) {
			if ($parameter->getName() === 'startdate') {
				$startdate = $card->getStartdate();
				// Deck reads it back with `new \DateTime($startdate)`; ATOM keeps the instant and its offset.
				$arguments[] = $startdate instanceof \DateTimeInterface ? $startdate->format(DATE_ATOM) : $startdate;
			} elseif ($parameter->getName() === 'color') {
				$type = $parameter->getType();
				$arguments[] = $type instanceof \ReflectionNamedType && $type->getName() === OptionalNullableValue::class
					? new OptionalNullableValue($card->getColor())
					: $card->getColor();
			} else {
				break;
			}
		}
		return $arguments;
	}

	/**
	 * {@inheritDoc}
	 */
	public function moveCard(string $userId, int $cardId, int $stackId, ?int $order): Card {
		$this->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->service(PermissionService::class);
		/** @var StackMapper $stackMapper */
		$stackMapper = $this->service(StackMapper::class);
		/** @var CardMapper $cardMapper */
		$cardMapper = $this->service(CardMapper::class);
		/** @var CardService $cardService */
		$cardService = $this->service(CardService::class);

		if ($order === null) {
			// Reading the destination stack is an access of its own: check READ before the query.
			$permissionService->checkPermission($stackMapper, $stackId, Acl::PERMISSION_READ);
			$order = $this->nextOrder($cardMapper->findAll($stackId));
		}

		// `reorder()` checks PERMISSION_EDIT on the card and on the destination stack.
		$cards = $cardService->reorder($cardId, $stackId, $order);
		$moved = null;
		foreach ($cards as $card) {
			if ((int)$card->getId() === $cardId) {
				$moved = $card;
				break;
			}
		}

		return $moved ?? $cardService->find($cardId);
	}

	/**
	 * {@inheritDoc}
	 */
	public function deleteCard(string $userId, int $cardId): Card {
		$this->bindUser($userId);

		/** @var CardService $cardService */
		$cardService = $this->service(CardService::class);

		// Soft delete: Deck stamps `deleted_at` and keeps the row.
		return $cardService->delete($cardId);
	}

	/**
	 * {@inheritDoc}
	 */
	public function stackOwnership(string $userId, int $stackId): array {
		$this->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->service(PermissionService::class);
		/** @var StackMapper $stackMapper */
		$stackMapper = $this->service(StackMapper::class);

		// Check first: Deck answers a missing or unreadable stack with the same exception it uses
		// for a denied one, so no owner or title is read before the caller may write there.
		$permissionService->checkPermission($stackMapper, $stackId, Acl::PERMISSION_EDIT);

		return $this->ownershipOfBoard($stackMapper->findBoardId($stackId));
	}

	/**
	 * {@inheritDoc}
	 */
	public function cardOwnership(string $userId, int $cardId): array {
		$this->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->service(PermissionService::class);
		/** @var CardMapper $cardMapper */
		$cardMapper = $this->service(CardMapper::class);

		// The same check `CardService::update()` and `CardService::delete()` run, taken before the
		// read so a caller without edit access never learns who owns the board.
		$permissionService->checkPermission($cardMapper, $cardId, Acl::PERMISSION_EDIT);

		return $this->ownershipOfBoard($cardMapper->findBoardId($cardId));
	}

	/**
	 * {@inheritDoc}
	 */
	public function cardBoardId(string $userId, int $cardId): int {
		$this->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->service(PermissionService::class);
		/** @var CardMapper $cardMapper */
		$cardMapper = $this->service(CardMapper::class);

		// `CardMapper::findBoardId()` is a plain query, so the read check the card itself needs comes first.
		$permissionService->checkPermission($cardMapper, $cardId, Acl::PERMISSION_READ);

		return (int)$cardMapper->findBoardId($cardId);
	}

	/**
	 * {@inheritDoc}
	 */
	public function createBoard(string $userId, string $title, string $color): Board {
		$boardService = $this->bindUser($userId);

		// Deck v1.17.5 `create(string $title, string $userId, string $color)`; it adds the four default labels.
		return $boardService->create($title, $userId, $color);
	}

	/**
	 * {@inheritDoc}
	 */
	public function createStack(string $userId, int $boardId, string $title, ?int $order): Stack {
		$this->bindUser($userId);

		/** @var StackService $stackService */
		$stackService = $this->service(StackService::class);

		if ($order === null) {
			/** @var PermissionService $permissionService */
			$permissionService = $this->service(PermissionService::class);
			/** @var StackMapper $stackMapper */
			$stackMapper = $this->service(StackMapper::class);
			// `StackService::create()` needs PERMISSION_MANAGE; reading the lists is an access of its own,
			// so the same check comes before the query.
			$permissionService->checkPermission(null, $boardId, Acl::PERMISSION_MANAGE);
			$highest = -1;
			foreach ($stackMapper->findAll($boardId) as $stack) {
				$highest = max($highest, (int)$stack->getOrder());
			}
			$order = $highest + 1;
		}

		// Deck v1.17.5 `create(string $title, int $boardId, int $order)`; it checks MANAGE and refuses an archived board.
		return $stackService->create($title, $boardId, $order);
	}

	/**
	 * {@inheritDoc}
	 */
	public function boardOwnership(string $userId, int $boardId): array {
		$this->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->service(PermissionService::class);

		// Check first: the owner and the title are read only for somebody who may manage the board.
		$permissionService->checkPermission(null, $boardId, Acl::PERMISSION_MANAGE);

		return $this->ownershipOfBoard($boardId);
	}

	/**
	 * {@inheritDoc}
	 */
	public function stackCardCount(string $userId, int $stackId): int {
		$this->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->service(PermissionService::class);
		/** @var StackMapper $stackMapper */
		$stackMapper = $this->service(StackMapper::class);

		// The same check `StackService::delete()` runs, taken before any card is read.
		$permissionService->checkPermission($stackMapper, $stackId, Acl::PERMISSION_MANAGE);

		return $this->cardsInStack($stackId);
	}

	/**
	 * {@inheritDoc}
	 */
	public function boardCardCount(string $userId, int $boardId): int {
		$this->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->service(PermissionService::class);

		$permissionService->checkPermission(null, $boardId, Acl::PERMISSION_MANAGE);

		return $this->cardsInBoard($boardId);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Deck's `StackService::delete()` never looks at the cards, so a card created by another request after the count
	 * would land in the trash with the list. The list is therefore counted again after the delete and, when a card
	 * showed up, restored. Deck 1.17.5 has no undo for a list, but its web interface brings one back by calling
	 * `StackService::update()` with `deletedAt: 0`; that path checks MANAGE and writes the activity, the change
	 * tracking and the board event, so the restore leaves the same traces a user restore would.
	 *
	 * A database transaction around count and delete was considered and rejected: it holds no lock on the cards
	 * table, so a concurrent insert is not blocked, and on MySQL's default REPEATABLE READ the second count would
	 * not even see it. It would also run Deck's notifications inside a transaction that may be rolled back. What
	 * remains is a window of a few milliseconds, between the recount and a request that had already passed Deck's
	 * checks (`CardService::create()` never looks at the `deleted_at` of the list); a card created in the instant right
	 * after the second count sits in the trashed list, which stays recoverable from the Deck trash. This residual
	 * window is accepted (third round of the review): closing it would need a lock shared with every card write of
	 * the Deck app, which this app cannot take, and the tool guide says so.
	 */
	public function deleteEmptyStack(string $userId, int $stackId): Stack {
		// The count runs the manage check and binds the caller; it is taken again here and not trusted from the plan.
		$cards = $this->stackCardCount($userId, $stackId);
		if ($cards > 0) {
			throw DeckRefusalException::stackNotEmpty($cards);
		}

		/** @var StackService $stackService */
		$stackService = $this->service(StackService::class);

		// Soft delete: Deck stamps `deleted_at` on the list and keeps the row.
		$stack = $stackService->delete($stackId);
		if ($this->cardsInStack($stackId) > 0) {
			$this->restoreStack($userId, $stackService, $stack);
		}

		return $stack;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The board is counted again after the delete and, when a card showed up in one of its lists, brought back with
	 * Deck's own `BoardService::deleteUndo()` (it clears `deleted_at` and writes the restore activity).
	 *
	 * The same residual window as {@see self::deleteEmptyStack()} remains and is accepted: a card created in the
	 * instant right after the second count may go to the trash with the board, where it is recoverable.
	 */
	public function deleteEmptyBoard(string $userId, int $boardId): Board {
		// Owner first: somebody who merely manages the board is refused before a single card is counted.
		if ($this->boardOwnership($userId, $boardId)['owner'] !== $userId) {
			throw DeckRefusalException::boardNotOwned();
		}
		$cards = $this->cardsInBoard($boardId);
		if ($cards > 0) {
			throw DeckRefusalException::boardNotEmpty($cards);
		}

		/** @var BoardService $boardService */
		$boardService = $this->service(BoardService::class);

		// Soft delete: Deck stamps `deleted_at` on the board, which leaves the lists of the web interface and
		// can be brought back from the Deck deleted items.
		$board = $boardService->delete($boardId);
		if ($this->cardsInBoard($boardId) > 0) {
			$this->undoBoardDelete($userId, $boardService, $boardId);
		}

		return $board;
	}

	/**
	 * Brings a list back after a card arrived during its deletion, and refuses the call either way.
	 *
	 * `StackService::update()` writes `deleted_at = 0` before it records activity and dispatches events, so an
	 * exception of the restore does not prove that the list stayed in the trash: the list is read again
	 * ({@see self::deletedStack()}) and only a list that is still in the trash is reported as not restored.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param StackService $stackService Deck list service, already bound to the caller.
	 * @param Stack $deleted The list as `StackService::delete()` returned it.
	 * @return never
	 * @throws DeckRefusalException Always: "nothing was deleted" when the list is back, a message that points to the
	 *     Deck trash when it is still there (no Deck detail is carried).
	 * @throws DeckUnconfirmedException When the restore threw and the list cannot be read again.
	 */
	private function restoreStack(string $userId, StackService $stackService, Stack $deleted): never {
		try {
			$stackService->update((int)$deleted->getId(), (string)$deleted->getTitle(), (int)$deleted->getBoardId(), (int)$deleted->getOrder(), 0);
		} catch (\Throwable $e) {
			try {
				$stillDeleted = $this->deletedStack($userId, (int)$deleted->getId()) !== null;
			} catch (\Throwable) {
				throw new DeckUnconfirmedException('deck_list_stacks', $e);
			}

			throw DeckRefusalException::stackReceivedCards(!$stillDeleted);
		}

		throw DeckRefusalException::stackReceivedCards(true);
	}

	/**
	 * Brings a board back after a card arrived during its deletion, and refuses the call either way.
	 *
	 * `BoardService::deleteUndo()` writes `deleted_at = 0` before its activity and events, so after an exception the
	 * board is read again among the caller's own boards and only one still in the trash is reported as not restored.
	 *
	 * @param string $userId UID of the authenticated caller, the owner.
	 * @param BoardService $boardService Deck board service, already bound to the caller.
	 * @param int $boardId Board that was just deleted.
	 * @return never
	 * @throws DeckRefusalException Always: "nothing was deleted" when the board is back, a message that points to the
	 *     Deck trash when it is still there (no Deck detail is carried).
	 * @throws DeckUnconfirmedException When the undo threw and the board cannot be read again.
	 */
	private function undoBoardDelete(string $userId, BoardService $boardService, int $boardId): never {
		try {
			$boardService->deleteUndo($boardId);
		} catch (\Throwable $e) {
			try {
				$stillDeleted = null;
				foreach ($this->ownedBoards($userId) as $board) {
					if ((int)$board->getId() === $boardId) {
						$stillDeleted = (int)$board->getDeletedAt() !== 0;
					}
				}
				if ($stillDeleted === null) {
					throw new \RuntimeException('The board is not among the boards of the caller');
				}
			} catch (\Throwable) {
				throw new DeckUnconfirmedException('deck_list_boards', $e);
			}

			throw DeckRefusalException::boardReceivedCards(!$stillDeleted);
		}

		throw DeckRefusalException::boardReceivedCards(true);
	}

	/**
	 * {@inheritDoc}
	 *
	 * `CardMapper::find()` runs a fresh query and, unlike `CardService::find()`, answers a deleted card too.
	 */
	public function cardState(string $userId, int $cardId): Card {
		$this->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->service(PermissionService::class);
		/** @var CardMapper $cardMapper */
		$cardMapper = $this->service(CardMapper::class);

		// Deck v1.17.5 `checkPermission($mapper, $id, $permission, $userId, $allowDeletedCard)`: a deleted card is
		// exactly what a delete leaves, so it stays readable here.
		$permissionService->checkPermission($cardMapper, $cardId, Acl::PERMISSION_READ, null, true);

		return $cardMapper->find($cardId, false);
	}

	/**
	 * {@inheritDoc}
	 */
	public function findCreatedCard(string $userId, int $stackId, string $title, array $excludeIds = []): ?Card {
		$this->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->service(PermissionService::class);
		/** @var StackMapper $stackMapper */
		$stackMapper = $this->service(StackMapper::class);
		/** @var CardMapper $cardMapper */
		$cardMapper = $this->service(CardMapper::class);

		$permissionService->checkPermission($stackMapper, $stackId, Acl::PERMISSION_READ);

		$since = $this->time->getTime() - self::CREATED_WITHIN;
		$found = null;
		foreach ($cardMapper->findAll($stackId) ?: [] as $card) {
			if (!$card instanceof Card || in_array((int)$card->getId(), $excludeIds, true)) {
				continue;
			}
			if ((string)$card->getOwner() === $userId && (string)$card->getTitle() === $title && (int)$card->getCreatedAt() >= $since
				&& ($found === null || (int)$card->getId() > (int)$found->getId())) {
				$found = $card;
			}
		}

		return $found;
	}

	/**
	 * {@inheritDoc}
	 */
	public function cardAssignments(string $userId, int $cardId): array {
		$this->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->service(PermissionService::class);
		/** @var AssignmentMapper $assignmentMapper */
		$assignmentMapper = $this->service(AssignmentMapper::class);

		$permissionService->checkPermission($this->service(CardMapper::class), $cardId, Acl::PERMISSION_READ);

		return array_values($assignmentMapper->findIn([$cardId]));
	}

	/**
	 * {@inheritDoc}
	 */
	public function stacksOf(string $userId, int $boardId): array {
		$this->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->service(PermissionService::class);
		/** @var StackMapper $stackMapper */
		$stackMapper = $this->service(StackMapper::class);

		$permissionService->checkPermission(null, $boardId, Acl::PERMISSION_READ);

		return array_values($stackMapper->findAll($boardId));
	}

	/**
	 * {@inheritDoc}
	 *
	 * `StackMapper::find()` keeps the entity of the request in memory, with the `deleted_at` the failed delete set on
	 * it even when the update never reached the database, so the lists are queried again instead.
	 */
	public function deletedStack(string $userId, int $stackId): ?Stack {
		$this->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->service(PermissionService::class);
		/** @var StackMapper $stackMapper */
		$stackMapper = $this->service(StackMapper::class);

		$boardId = $stackMapper->findBoardId($stackId) ?? throw new NoPermissionException('Permission denied');
		$permissionService->checkPermission(null, $boardId, Acl::PERMISSION_READ);

		foreach ($stackMapper->findDeleted($boardId) as $stack) {
			if ((int)$stack->getId() === $stackId) {
				return $stack;
			}
		}
		foreach ($stackMapper->findAll($boardId) as $stack) {
			if ((int)$stack->getId() === $stackId) {
				return null;
			}
		}

		throw new \RuntimeException('The list is neither active nor in the trash');
	}

	/**
	 * {@inheritDoc}
	 *
	 * `BoardMapper::findAllByOwner()` is a fresh query; `find()` would answer from the entity cache of the request.
	 * Only the caller's own boards are read, so no ACL check is needed.
	 */
	public function ownedBoards(string $userId): array {
		$this->bindUser($userId);

		/** @var BoardMapper $boardMapper */
		$boardMapper = $this->service(BoardMapper::class);

		return array_values($boardMapper->findAllByOwner($userId) ?: []);
	}

	/**
	 * Resolves the Deck board service and binds it to the caller.
	 *
	 * `BoardService::setUserId()` also calls `PermissionService::setUserId()`, which clears the
	 * permission cache, so every Deck ACL decision in this request is taken for the authenticated
	 * user rather than for any state left over by the session.
	 *
	 * `setUserId()` exists on the board service only: `CardService`, `AssignmentService` and the
	 * `ActivityManager` keep the `userId` the DI container injected, which is `ISession::get('user_id')`.
	 * When it is not the caller, Deck v1.17.5 writes the card and then fails in `enrichCards()`, so the
	 * session is checked first and the call is refused before anything of the Deck runs.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @return BoardService Deck board service bound to that user.
	 * @throws DeckSessionException When the session the Deck services read is not the caller's.
	 */
	private function bindUser(string $userId): BoardService {
		if ($this->session !== null && $this->session->get('user_id') !== $userId) {
			throw new DeckSessionException('The session user is not the authenticated caller');
		}

		/** @var BoardService $boardService */
		$boardService = $this->service(BoardService::class);
		$boardService->setUserId($userId);

		return $boardService;
	}

	/**
	 * Boards a follow-up scans: the ones the caller may manage, never the ones merely shared with
	 * them, capped at {@see self::MAX_BOARDS} so the walk stays a bounded number of queries.
	 *
	 * `PermissionService::getPermissions()` already answers the owner as allowed
	 * (`lib/Service/PermissionService.php:80` on Deck v1.17.5), so an explicit ACL lookup and
	 * ownership are the same check here.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int|null $boardId Restrict the scan to this board, or null for all of them.
	 * @return array{boards: list<Board>, capped: bool} Boards to scan, and whether visible boards
	 *     were left unexamined by the ceiling.
	 */
	private function managedBoards(string $userId, ?int $boardId): array {
		/** @var PermissionService $permissionService */
		$permissionService = $this->service(PermissionService::class);

		$boards = $this->listBoards($userId);
		if ($boardId !== null) {
			$boards = array_values(array_filter(
				$boards,
				static fn (Board $board): bool => (int)$board->getId() === $boardId,
			));
		}

		$capped = count($boards) > self::MAX_BOARDS;
		$managed = [];
		foreach (array_slice($boards, 0, self::MAX_BOARDS) as $board) {
			$permissions = $permissionService->getPermissions((int)$board->getId(), $userId);
			if (($permissions[Acl::PERMISSION_MANAGE] ?? false) === true) {
				$managed[] = $board;
			}
		}

		return ['boards' => $managed, 'capped' => $capped];
	}

	/**
	 * Timezone the caller reads due dates in.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @return \DateTimeZone Their preference, or PHP's default when no resolver was given.
	 */
	private function zone(string $userId): \DateTimeZone {
		return $this->zones?->forUser($userId) ?? new \DateTimeZone(date_default_timezone_get());
	}

	/**
	 * Due date as Deck should store it.
	 *
	 * A bare `YYYY-MM-DD` from the tools becomes midnight of that day for the caller, since Deck would
	 * otherwise read it as UTC midnight and the card would show up a day early west of Greenwich. A
	 * full timestamp (the current date kept by an edit that did not touch it) is passed through, so
	 * the time set in the Deck web interface survives.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param string|null $duedate Day, ISO 8601 timestamp, or null to clear the date.
	 * @return string|null Value for `CardService`.
	 */
	private function instant(string $userId, ?string $duedate): ?string {
		if ($duedate === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $duedate) !== 1) {
			return $duedate;
		}

		return CardCriteria::localMidnight($duedate, $this->zone($userId));
	}

	/**
	 * Embeds the assignments of a batch of cards, in one query for all of them.
	 *
	 * @param list<Card> $cards Cards of one stack, board or page, mutated in place.
	 */
	private function attachAssignments(array $cards): void {
		if ($cards === []) {
			return;
		}

		/** @var AssignmentMapper $assignmentMapper */
		$assignmentMapper = $this->service(AssignmentMapper::class);

		$byCardId = [];
		foreach ($assignmentMapper->findIn(array_map(static fn (Card $card): int => $card->getId(), $cards)) as $assignment) {
			$byCardId[(int)$assignment->getCardId()][] = $assignment;
		}

		foreach ($cards as $card) {
			$card->setAssignedUsers($byCardId[$card->getId()] ?? []);
		}
	}

	/**
	 * Open cards of a board's stacks, grouped by stack in the order given.
	 *
	 * Deck 1.17 (Nextcloud 33) reads them in one query with `CardMapper::findAllForStacks()`; Deck 1.15 and 1.16
	 * (Nextcloud 31 and 32) do not have it, so their cards are read stack by stack with `findAll()`, the query the
	 * grouped one repeats: same filters (not archived, not deleted) and the same order (`order`, then `id`).
	 *
	 * @param object $cardMapper Deck's CardMapper, of whichever supported Deck is installed.
	 * @param list<int> $stackIds Stacks of one board.
	 * @return array<int, Card[]|null> Cards per stack id; a stack without cards may be null.
	 */
	private function cardsOfStacks(object $cardMapper, array $stackIds): array {
		if (method_exists($cardMapper, 'findAllForStacks')) {
			return $cardMapper->findAllForStacks($stackIds) ?: [];
		}

		$cards = [];
		foreach ($stackIds as $stackId) {
			$found = $cardMapper->findAll($stackId);
			$cards[$stackId] = is_array($found) ? array_values($found) : [];
		}

		return $cards;
	}

	/**
	 * Resolves a Deck service once per request.
	 *
	 * @param string $class FQCN of the Deck class, used as the cache key.
	 * @return object The resolved Deck service.
	 */
	private function service(string $class): object {
		if (!isset($this->resolved[$class])) {
			$this->resolved[$class] = $this->container->get($class);
		}

		return $this->resolved[$class];
	}

	/**
	 * Owner, display name and title of a board, read after its permission check succeeded.
	 *
	 * @param mixed $boardId Board id resolved by a Deck mapper, or null when the entity is gone.
	 * @return array{owner: string, ownerDisplayName: string, name: string} Ownership of the board.
	 * @throws NoPermissionException When the board cannot be resolved, as the Deck answers it.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	private function ownershipOfBoard(mixed $boardId): array {
		if ($boardId === null) {
			// Reached only if Deck and this lookup disagree; answer like Deck does, without leaking.
			throw new NoPermissionException('Permission denied');
		}

		/** @var BoardService $boardService */
		$boardService = $this->service(BoardService::class);
		// `fullDetails: false` skips the ACL and session enrichment; `allowDeleted: true` keeps an
		// archived board answerable here, because the write itself is what refuses it.
		$board = $boardService->find((int)$boardId, false, true);
		$owner = (string)$board->getOwner();

		return [
			'owner' => $owner,
			'ownerDisplayName' => $this->displayName($owner),
			'name' => (string)$board->getTitle(),
		];
	}

	/**
	 * Cards of one list that still count as cards: the active ones and the archived ones.
	 *
	 * Deck leaves the deleted cards (its trash) out of both queries, and a card in the trash is not a reason
	 * to keep a list.
	 *
	 * @param int $stackId List to count; the caller was already checked.
	 * @return int Number of cards.
	 */
	private function cardsInStack(int $stackId): int {
		/** @var CardMapper $cardMapper */
		$cardMapper = $this->service(CardMapper::class);

		return count($cardMapper->findAll($stackId) ?: []) + count($cardMapper->findAllArchived($stackId) ?: []);
	}

	/**
	 * Cards of every list of a board, counted as {@see self::cardsInStack()} does.
	 *
	 * @param int $boardId Board to count; the caller was already checked.
	 * @return int Number of cards.
	 */
	private function cardsInBoard(int $boardId): int {
		/** @var StackMapper $stackMapper */
		$stackMapper = $this->service(StackMapper::class);

		$cards = 0;
		foreach ($stackMapper->findAll($boardId) as $stack) {
			$cards += $this->cardsInStack((int)$stack->getId());
		}

		return $cards;
	}

	/**
	 * Display name of a user, falling back to the uid when the account is gone or has none.
	 *
	 * @param string $userId UID whose name is shown to the caller.
	 * @return string Name to put in a confirmation message.
	 */
	private function displayName(string $userId): string {
		/** @var IUserManager $userManager */
		$userManager = $this->service(IUserManager::class);

		return $userManager->get($userId)?->getDisplayName() ?: $userId;
	}

	/**
	 * Last free position of a stack, so a card without an explicit order lands at the bottom.
	 *
	 * @param mixed $cards Cards returned by `CardMapper::findAll()`.
	 * @return int Position to use for the moved card.
	 */
	private function nextOrder(mixed $cards): int {
		$highest = -1;
		foreach (is_array($cards) ? $cards : [] as $card) {
			if ($card instanceof Card) {
				$highest = max($highest, (int)$card->getOrder());
			}
		}

		return $highest + 1;
	}
}
