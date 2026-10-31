<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tools\Files\Deletion;

use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\Storage\ISharedStorage;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;

/**
 * Reads the actor's own trash bin once after a removal; it never looks at another person's trash bin and never
 * turns an observation into a promise of future recovery.
 */
class TrashReceiptVerifier {
	/** Optional trash manager service id, resolved only after the app is known to be enabled. */
	private const MANAGER = 'OCA\\Files_Trashbin\\Trash\\ITrashManager';
	/** The item goes to the actor's own trash bin, the only one that is ever checked. */
	public const OWN = 'own';
	/** The item belongs to another person; their trash bin is never checked. */
	public const OTHER = 'other';
	/** The owner could not be read; no trash bin is checked and nobody else is named. */
	public const UNKNOWN = 'unknown';

	/**
	 * @param IAppManager $apps Native per-actor application availability.
	 * @param IUserManager $users Resolves the authenticated actor only.
	 * @param ContainerInterface $container Resolves the optional files_trashbin manager.
	 * @param ITimeFactory $time Clock for the moment of the check.
	 */
	public function __construct(
		private IAppManager $apps,
		private IUserManager $users,
		private ContainerInterface $container,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Decides before the attempt whose trash bin the item would land in.
	 *
	 * @param Node $node Admitted source, read before deletion.
	 * @param string $uid Authenticated actor.
	 * @return string OWN for the actor's item outside any received share, OTHER for another owner or a received share, UNKNOWN when the owner cannot be read.
	 */
	public function destination(Node $node, string $uid): string {
		try {
			$owner = $node->getOwner()?->getUID();
			if (!is_string($owner) || $owner === '') {
				return self::UNKNOWN;
			}
			return $owner === $uid && !$node->getStorage()->instanceOfStorage(ISharedStorage::class) ? self::OWN : self::OTHER;
		} catch (\Throwable) {
			return self::UNKNOWN;
		}
	}

	/**
	 * Builds the trash part of a removal receipt.
	 *
	 * @param string $destination Result of destination() taken before the attempt.
	 * @param string $uid Authenticated actor.
	 * @param int $id Global file ID of the removed source.
	 * @param bool $folder Whether the removed source was a folder.
	 * @param string $default Unconfirmed-destination message of the caller.
	 * @return array{fields: array<string, string>, message: string} Receipt fields (an observed removal also refines the status) and message.
	 */
	public function receipt(string $destination, string $uid, int $id, bool $folder, string $default): array {
		if ($destination === self::OTHER) {
			return ['fields' => ['trash' => 'owner_trash_not_checked'], 'message' => $default . ' ' . TrashReceiptMessages::ownerTrash()];
		}
		if ($destination !== self::OWN) {
			return ['fields' => ['trash' => 'not_confirmed'], 'message' => $default];
		}
		$checkedAt = $this->observe($uid, $id, $folder);
		if ($checkedAt === null) {
			return ['fields' => ['trash' => 'not_confirmed'], 'message' => $default];
		}
		return ['fields' => ['status' => 'removed_observed_in_trash', 'trash' => 'observed_in_own_trash', 'trashCheckedAt' => $checkedAt],
			'message' => ($folder ? TrashReceiptMessages::observedFolder() : TrashReceiptMessages::observedFile()) . ' ' . TrashReceiptMessages::expiry()];
	}

	/**
	 * Looks the ID up in the actor's own trash bin; any doubt or failure means not observed.
	 *
	 * @param string $uid Authenticated actor.
	 * @param int $id Global file ID of the removed source.
	 * @param bool $folder Expected node type.
	 * @return string|null UTC moment of a positive check, or null.
	 */
	private function observe(string $uid, int $id, bool $folder): ?string {
		try {
			$user = $this->users->get($uid);
			if ($user === null || !$this->apps->isEnabledForUser('files_trashbin', $user)) {
				return null;
			}
			$node = $this->container->get(self::MANAGER)->getTrashNodeById($user, $id);
			if (!$node instanceof Node || (int)$node->getId() !== $id || ($node instanceof Folder) !== $folder
				|| !str_starts_with($node->getPath(), '/' . $uid . '/files_trashbin/files/')) {
				return null;
			}
			return $this->time->getDateTime('now', new \DateTimeZone('UTC'))->format(\DATE_ATOM);
		} catch (\Throwable) {
			return null;
		}
	}
}
