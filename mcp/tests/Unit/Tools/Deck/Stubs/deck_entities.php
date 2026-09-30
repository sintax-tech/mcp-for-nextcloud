<?php
declare(strict_types=1);

/**
 * Test doubles for the `OCA\Deck\Db` classes.
 *
 * `nextcloud/ocp` only ships `OCP\*`, and the Deck app is never installed in this test environment,
 * so the entities and mappers the module type-hints are declared here. Every signature mirrors Deck
 * `v1.17.5`, the tag whose `appinfo/info.xml` declares `nextcloud min-version="33" max-version="33"`.
 *
 * The entities take an array of fields instead of the Deck constructor, which only makes the doubles
 * easier to build in a test; the getters return what a hydrated Deck entity would return.
 */

namespace OCA\Deck\Db;

/** Permission levels used by the ACL tables; values taken from Deck `lib/Db/Acl.php:25-28`. */
class Acl {
	public const PERMISSION_READ = 0;
	public const PERMISSION_EDIT = 1;
	public const PERMISSION_SHARE = 2;
	public const PERMISSION_MANAGE = 3;

	public const PERMISSION_TYPE_USER = 0;
	public const PERMISSION_TYPE_GROUP = 1;
	public const PERMISSION_TYPE_CIRCLE = 7;
}

/** Marker interface the Deck permission service accepts as a mapper, see Deck `lib/Db/IPermissionMapper.php`. */
interface IPermissionMapper {
	/**
	 * @param string $userId User to check ownership for.
	 * @param int $id Entity id.
	 * @return bool True when the user owns the entity.
	 */
	public function isOwner(string $userId, int $id): bool;

	/**
	 * @param int $id Card, stack or board id.
	 * @return int|null Board the entity belongs to, null when it does not exist.
	 */
	public function findBoardId(int $id): ?int;
}

/** Board entity double. */
class Board {
	/**
	 * @param array{id?: int, title?: string, color?: string|null, archived?: bool, owner?: string, lastModified?: int} $fields Values the getters report.
	 */
	public function __construct(private array $fields = []) {
	}

	public function getId(): int {
		return (int)($this->fields['id'] ?? 0);
	}

	public function getTitle(): string {
		return (string)($this->fields['title'] ?? '');
	}

	public function getColor(): ?string {
		return $this->fields['color'] ?? null;
	}

	public function getArchived(): bool {
		return (bool)($this->fields['archived'] ?? false);
	}

	public function getOwner(): string {
		return (string)($this->fields['owner'] ?? '');
	}

	public function getLastModified(): int {
		return (int)($this->fields['lastModified'] ?? 0);
	}
}

/** Stack entity double. */
class Stack {
	/**
	 * @param array{id?: int, boardId?: int, title?: string, order?: int, cards?: Card[]} $fields Values the getters report.
	 */
	public function __construct(private array $fields = []) {
	}

	public function getId(): int {
		return (int)($this->fields['id'] ?? 0);
	}

	public function getBoardId(): int {
		return (int)($this->fields['boardId'] ?? 0);
	}

	public function getTitle(): string {
		return (string)($this->fields['title'] ?? '');
	}

	public function getOrder(): int {
		return (int)($this->fields['order'] ?? 0);
	}

	/**
	 * @return Card[] Cards the Deck service embeds in the stack.
	 */
	public function getCards(): array {
		return $this->fields['cards'] ?? [];
	}
}

/** Card entity double. */
class Card {
	/**
	 * @param array{id?: int, stackId?: int, title?: string, description?: string, type?: string, owner?: string, order?: int, archived?: bool, deletedAt?: int, duedate?: \DateTime|null, done?: \DateTime|null, lastModified?: int, attachmentCount?: int|null, relatedBoard?: Board|null} $fields Values the getters report.
	 */
	public function __construct(private array $fields = []) {
	}

	public function getId(): int {
		return (int)($this->fields['id'] ?? 0);
	}

	public function getStackId(): int {
		return (int)($this->fields['stackId'] ?? 0);
	}

	public function getTitle(): string {
		return (string)($this->fields['title'] ?? '');
	}

	public function getDescription(): string {
		return (string)($this->fields['description'] ?? '');
	}

	public function getType(): string {
		return (string)($this->fields['type'] ?? 'note');
	}

	public function getOwner(): string {
		return (string)($this->fields['owner'] ?? '');
	}

	public function getOrder(): int {
		return (int)($this->fields['order'] ?? 0);
	}

	public function getArchived(): bool {
		return (bool)($this->fields['archived'] ?? false);
	}

	public function getDeletedAt(): int {
		return (int)($this->fields['deletedAt'] ?? 0);
	}

	public function getDuedate(): ?\DateTime {
		return $this->fields['duedate'] ?? null;
	}

	public function getDone(): ?\DateTime {
		return $this->fields['done'] ?? null;
	}

	public function getLastModified(): int {
		return (int)($this->fields['lastModified'] ?? 0);
	}

	public function getAttachmentCount(): ?int {
		return $this->fields['attachmentCount'] ?? null;
	}

	public function getRelatedBoard(): ?Board {
		return $this->fields['relatedBoard'] ?? null;
	}
}

/** Board mapper double. */
class BoardMapper implements IPermissionMapper {
	public function isOwner(string $userId, int $id): bool {
		return false;
	}

	public function findBoardId(int $id): ?int {
		return null;
	}
}

/** Stack mapper double; signatures mirror Deck `v1.17.5` `lib/Db/StackMapper.php`. */
class StackMapper implements IPermissionMapper {
	/**
	 * @param string $userId User to check ownership for.
	 * @param int $id Stack id.
	 * @return bool True when the user owns the stack.
	 */
	public function isOwner(string $userId, int $id): bool {
		return false;
	}

	/**
	 * @param int $id Stack id.
	 * @return int|null Board the stack belongs to.
	 */
	public function findBoardId(int $id): ?int {
		return null;
	}

	/**
	 * @param int $boardId Board to list.
	 * @param int|null $limit Maximum number of stacks.
	 * @param int $offset Stacks to skip.
	 * @return Stack[] Stacks of the board.
	 */
	public function findAll(int $boardId, ?int $limit = null, $offset = 0): array {
		return [];
	}
}

/** Card mapper double; signatures mirror Deck `v1.17.5` `lib/Db/CardMapper.php`. */
class CardMapper implements IPermissionMapper {
	/**
	 * @param string $userId User to check ownership for.
	 * @param int $id Card id.
	 * @return bool True when the user owns the card.
	 */
	public function isOwner(string $userId, int $id): bool {
		return false;
	}

	/**
	 * @param int $id Card id.
	 * @return int|null Board the card belongs to.
	 */
	public function findBoardId(int $id): ?int {
		return null;
	}

	/**
	 * @param int|string $stackId Stack to list.
	 * @param int|null $limit Maximum number of cards.
	 * @param int $offset Cards to skip.
	 * @param int $since Only cards modified after this timestamp.
	 * @return Card[] Active cards of the stack.
	 */
	public function findAll($stackId, ?int $limit = null, int $offset = 0, int $since = -1) {
		return [];
	}
}
