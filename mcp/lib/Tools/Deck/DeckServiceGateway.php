<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Model\OptionalNullableValue;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Service\StackService;
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

	/** @var array<string, object> Services resolved so far in this request. */
	private array $resolved = [];

	/**
	 * @param ContainerInterface $container Nextcloud server container, resolves Deck services by FQCN.
	 */
	public function __construct(private ContainerInterface $container) {
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

		return [
			'items' => is_array($items) ? array_values($items) : [],
			'boardId' => $stackMapper->findBoardId($stackId),
		];
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

	/**
	 * {@inheritDoc}
	 */
	public function createCard(string $userId, int $stackId, string $title, string $description, ?string $duedate): Card {
		$this->bindUser($userId);

		/** @var CardService $cardService */
		$cardService = $this->service(CardService::class);

		// `type` is free text in Deck (only length-checked), so the tools always write `note`.
		return $cardService->create($title, $stackId, 'note', self::LAST_ORDER, $userId, $description, $duedate);
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
			$duedate,
			null,
			null,
			new OptionalNullableValue($card->getDone()),
		);
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

		return $this->boardOwnership($stackMapper->findBoardId($stackId));
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

		return $this->boardOwnership($cardMapper->findBoardId($cardId));
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
	 * Resolves the Deck board service and binds it to the caller.
	 *
	 * `BoardService::setUserId()` also calls `PermissionService::setUserId()`, which clears the
	 * permission cache, so every Deck ACL decision in this request is taken for the authenticated
	 * user rather than for any state left over by the session.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @return BoardService Deck board service bound to that user.
	 */
	private function bindUser(string $userId): BoardService {
		/** @var BoardService $boardService */
		$boardService = $this->service(BoardService::class);
		$boardService->setUserId($userId);

		return $boardService;
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
	private function boardOwnership(mixed $boardId): array {
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
