<?php
declare(strict_types=1);

/**
 * Test doubles for the `OCA\Deck\Service` classes and the Deck exceptions.
 *
 * Signatures mirror Deck `v1.17.5`, the tag whose `appinfo/info.xml` declares
 * `nextcloud min-version="33" max-version="33"`. Every method throws when called without a mock,
 * so an unexpected real implementation can never be mistaken for a stub.
 */

namespace OCA\Deck\Model {
	/** Helper that lets Deck tell "argument absent" from "argument is null", see Deck `lib/Model/OptionalNullableValue.php`. */
	class OptionalNullableValue {
		/**
		 * @param mixed $value Value carried by the wrapper, possibly null.
		 */
		public function __construct(private mixed $value) {
		}

		/**
		 * @return mixed The wrapped value.
		 */
		public function getValue(): mixed {
			return $this->value;
		}
	}
}

namespace OCA\Deck {

	/** Raised by Deck instead of confirming that a resource exists. */
	class NoPermissionException extends \Exception {
	}

	/** Raised when Deck refuses an operation on an archived board or card. */
	class StatusException extends \Exception {
	}

	/** Raised when an archived entity takes part in an operation that does not allow it. */
	class ArchivedItemException extends \Exception {
	}

	/** Raised when the Deck validator rejects the payload. */
	class BadRequestException extends \Exception {
	}

}

namespace OCA\Deck\Exceptions {

	use OCA\Deck\StatusException;

	/**
	 * Raised by Deck on a conflicting change. It lives in `OCA\Deck\Exceptions` and is a StatusException, as in Deck
	 * 1.15, 1.16 and 1.17 (`lib/Exceptions/ConflictException.php`).
	 */
	class ConflictException extends StatusException {
	}
}

namespace OCA\Deck\Service {

	use OCA\Deck\Db\Board;
	use OCA\Deck\Db\Card;
	use OCA\Deck\Db\IPermissionMapper;
	use OCA\Deck\Db\Stack;
	use OCA\Deck\Model\OptionalNullableValue;

	/** Board service double; signatures mirror Deck `v1.17.5` `lib/Service/BoardService.php`. */
	class BoardService {
		/**
		 * @param string $userId User the service answers for.
		 */
		public function setUserId(string $userId): void {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}

		/**
		 * @param int $since Change-tracking filter, -1 for no filter.
		 * @param bool $fullDetails Enrich each board with settings and sessions.
		 * @param bool $includeArchived Include archived boards.
		 * @return Board[] Boards of the user.
		 */
		public function findAll(int $since = -1, bool $fullDetails = false, bool $includeArchived = true): array {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}

		/**
		 * @param int $boardId Board to read.
		 * @param bool $fullDetails Enrich the board.
		 * @param bool $allowDeleted Allow a board marked as deleted.
		 * @return Board The board.
		 */
		public function find(int $boardId, bool $fullDetails = true, bool $allowDeleted = false): Board {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}

		/**
		 * @param \OCA\Deck\Db\IPermissionMapper $mapper Mapper of the entity.
		 * @param int $id Entity id.
		 * @return bool True when the board is archived.
		 */
		public function isArchived($mapper, int $id): bool {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}

		/**
		 * @param string $title Board title, validated by Deck to 1-100 characters.
		 * @param string $userId Owner of the new board.
		 * @param string $color Six hexadecimal digits, without `#`.
		 * @return Board Created board, with its default labels.
		 */
		public function create(string $title, string $userId, string $color): Board {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}

		/**
		 * @param int $id Board to delete; needs `PERMISSION_MANAGE`.
		 * @return Board Board marked as deleted (soft delete, recoverable in Deck).
		 */
		public function delete(int $id): Board {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}

		/**
		 * @param int $id Board to bring back from the trash; needs `PERMISSION_MANAGE`, allowed on a deleted board.
		 * @return Board Board with `deleted_at` back to 0 (Deck `lib/Service/BoardService.php:259`).
		 */
		public function deleteUndo(int $id): Board {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}
	}

	/** Stack service double; signatures mirror Deck `v1.17.5` `lib/Service/StackService.php`. */
	class StackService {
		/**
		 * @param int $stackId Stack to read.
		 * @return Stack The stack, with its cards.
		 */
		public function find(int $stackId): Stack {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}

		/**
		 * @param int $boardId Board whose stacks are listed.
		 * @param int $since Change-tracking filter, -1 for no filter.
		 * @return Stack[] Stacks of the board, with their cards.
		 */
		public function findAll(int $boardId, int $since = -1): array {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}

		/**
		 * @param string $title Stack title.
		 * @param int $boardId Board that receives the stack; needs `PERMISSION_MANAGE`.
		 * @param int $order Position among the stacks of the board.
		 * @return Stack Created stack.
		 */
		public function create(string $title, int $boardId, int $order): Stack {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}

		/**
		 * @param int $id Stack to delete; needs `PERMISSION_MANAGE`.
		 * @return Stack Stack marked as deleted (soft delete); its cards are not touched.
		 */
		public function delete(int $id): Stack {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}

		/**
		 * Deck has no undo for a stack; the web interface brings one back with `deletedAt: 0` through this method.
		 *
		 * @param int $id Stack to update; needs `PERMISSION_MANAGE`, allowed on a deleted stack.
		 * @param string $title Stack title.
		 * @param int $boardId Board of the stack; needs `PERMISSION_MANAGE`.
		 * @param int $order Position among the stacks of the board.
		 * @param int|null $deletedAt Deletion timestamp, 0 brings the stack back.
		 * @return Stack Updated stack.
		 */
		public function update(int $id, string $title, int $boardId, int $order, ?int $deletedAt): Stack {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}
	}

	/** Card service double; signatures mirror Deck `v1.17.5` `lib/Service/CardService.php`. */
	class CardService {
		/**
		 * @param int $cardId Card to read.
		 * @return Card Enriched card.
		 */
		public function find(int $cardId): Card {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}

		/**
		 * @param string $title Card title.
		 * @param int $stackId Destination stack.
		 * @param string $type Card type.
		 * @param int $order Position in the stack.
		 * @param string $owner Owner uid.
		 * @param string $description Card description.
		 * @param string|null $duedate Due date as `YYYY-MM-DD`.
		 * @return Card Created card.
		 */
		public function create(string $title, int $stackId, string $type, int $order, string $owner, string $description = '', $duedate = null): Card {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}

		/**
		 * @param int $id Card to update.
		 * @param string $title New title.
		 * @param int $stackId Stack the card stays in or moves to.
		 * @param string $type Card type.
		 * @param string $owner Owner uid.
		 * @param string $description New description.
		 * @param int $order Position in the stack.
		 * @param string|null $duedate New due date.
		 * @param int|null $deletedAt Deletion timestamp, null keeps the current one.
		 * @param bool|null $archived Archive flag, null keeps the current one.
		 * @param OptionalNullableValue|null $done Done date, and a null argument nulls the column.
		 * @return Card Updated card.
		 */
		public function update(int $id, string $title, int $stackId, string $type, string $owner, string $description = '', int $order = 0, ?string $duedate = null, ?int $deletedAt = null, ?bool $archived = null, ?OptionalNullableValue $done = null): Card {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}

		/**
		 * @param int $id Card to move.
		 * @param int $stackId Destination stack.
		 * @param int $order Position in the destination stack.
		 * @return Card[] Cards of the destination stack after the move.
		 */
		public function reorder(int $id, int $stackId, int $order): array {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}

		/**
		 * @param int $id Card to soft delete.
		 * @return Card Deleted card.
		 */
		public function delete(int $id): Card {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}
	}

	/** Assignment service double; signature mirrors Deck v1.17.5. */
	class AssignmentService {
		/**
		 * @param int $cardId Created card.
		 * @param string $userId Account to assign.
		 * @param int $type Assignment type (user by default).
		 * @return \OCA\Deck\Db\Assignment Persisted assignment.
		 */
		public function assignUser(int $cardId, string $userId, int $type = 0): \OCA\Deck\Db\Assignment {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}

		/**
		 * @param int $cardId Card the account leaves.
		 * @param string $userId Account to unassign.
		 * @param int $type Assignment type (user by default).
		 * @return \OCA\Deck\Db\Assignment Removed assignment.
		 */
		public function unassignUser(int $cardId, string $userId, int $type = 0): \OCA\Deck\Db\Assignment {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}
	}

	/** Permission service double; signature mirrors Deck `v1.17.5` `lib/Service/PermissionService.php:112`. */
	class PermissionService {
		/**
		 * @param IPermissionMapper|null $mapper Mapper of the entity, null when the id is already a board id.
		 * @param int|string $id Entity id.
		 * @param int $permission One of the `Acl::PERMISSION_*` levels.
		 * @param string|null $userId User to check, null for the bound user.
		 * @param bool $allowDeletedCard Allow an access to a deleted card.
		 * @param bool $allowDeletedBoard Allow an access to a deleted board.
		 * @return bool Always true; Deck throws `NoPermissionException` when access is refused.
		 */
		public function checkPermission(?IPermissionMapper $mapper, $id, int $permission, $userId = null, bool $allowDeletedCard = false, bool $allowDeletedBoard = false): bool {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}

		/**
		 * @param int $boardId Board whose permissions are read.
		 * @param string|null $userId User to check, null for the bound user.
		 * @param bool $allowDeletedBoard Allow a board marked as deleted.
		 * @return array<int, bool> Permission level to flag, see `Acl::PERMISSION_*`.
		 */
		public function getPermissions(int $boardId, ?string $userId = null, bool $allowDeletedBoard = false): array {
			throw new \LogicException('Stub: expected a PHPUnit mock.');
		}
	}
}
