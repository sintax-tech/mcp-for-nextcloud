<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/*
 * Strict doubles of the native Teams (Circles 33-35) and Collectives classes creating a collective reaches, with the
 * signatures of lib/CirclesManager.php, lib/Model/Circle.php, lib/Model/FederatedUser.php,
 * lib/Mount/CollectiveFolderManager.php, lib/Db/PageMapper.php and lib/Db/Page.php (pinned in
 * collectives-admin-sources.json). Their state lives in
 * {@see \OCA\Mcp\Tests\Unit\Modules\Collectives\TeamsScene}. Members are plain objects of TeamMember, so this file
 * never declares OCA\Circles\Model\Member, which a Deck test stubs on its own.
 */

namespace OCA\Circles\Model\Probes {
	/** Double of the team listing filter; never built by the module. */
	class CircleProbe {
	}
}

namespace OCA\Circles\Model {
	use OCA\Mcp\Tests\Unit\Modules\Collectives\TeamsScene;

	/** Double of a Teams identity. */
	class FederatedUser {
		/**
		 * @param string $userId the account, or the display name of a group's system team
		 * @param int $type user, or team for a group
		 * @param string|null $group the group a team identity stands for
		 */
		public function __construct(public string $userId, public int $type = 1, public ?string $group = null) {
		}
	}

	/** Double of a team, as the actor's session sees it. */
	class Circle {
		/** @param string $id single id @param array{name:string, owner:string, members:list<string>} $data the team */
		public function __construct(private string $id, private array $data) {
		}

		/** @return string single id */
		public function getSingleId(): string {
			return $this->id;
		}

		/** @return string name */
		public function getName(): string {
			return $this->data['name'];
		}

		/** @return int what the team stands for: 2 for a group's system team */
		public function getSource(): int {
			return $this->data['source'] ?? 16;
		}

		/** @return string sanitized name */
		public function getSanitizedName(): string {
			return $this->data['name'];
		}

		/** @return string display name */
		public function getDisplayName(): string {
			return $this->data['display'] ?? $this->data['name'];
		}

		/** @return object the owner */
		public function getOwner(): object {
			return new \OCA\Mcp\Tests\Unit\Modules\Collectives\TeamMember($this->data['owner'], 1, 9);
		}

		/** @param int $limit ignored @return list<object> the members */
		public function getMembers(int $limit = 0): array {
			return TeamsScene::members($this->id);
		}

		/** @param int $flag settings asked about @param int $test ignored @return bool whether any is set */
		public function isConfig(int $flag, int $test = 0): bool {
			return ((TeamsScene::$teams[$this->id]['config'] ?? 0) & $flag) !== 0;
		}
	}
}

namespace OCA\Circles {
	use OCA\Circles\Model\Circle;
	use OCA\Circles\Model\FederatedUser;
	use OCA\Circles\Model\Probes\CircleProbe;
	use OCA\Mcp\Tests\Unit\Modules\Collectives\TeamsScene;

	/** Double of the Teams manager: one shared session, as upstream keeps it on the shared FederatedUserService. */
	class CirclesManager {
		/** @param string $userId account @return FederatedUser its identity */
		public function getLocalFederatedUser(string $userId): FederatedUser {
			TeamsScene::$calls[] = 'resolve:' . $userId;
			if (TeamsScene::$failResolve) {
				throw new \RuntimeException('identity could not be resolved');
			}
			TeamsScene::$identities[$userId] = true;
			return new FederatedUser($userId);
		}

		/** @param FederatedUser|null $federatedUser initiator @param bool $forceSync ignored */
		public function startSession(?FederatedUser $federatedUser = null, bool $forceSync = false): void {
			TeamsScene::$currentUser = $federatedUser?->userId;
		}

		/** @param bool $forceSync ignored */
		public function startSuperSession(bool $forceSync = false): void {
			TeamsScene::$calls[] = 'mcp-super';
			TeamsScene::$currentUser = null;
			TeamsScene::$bypass = true;
		}

		/** Ends whatever session is open. */
		public function stopSession(): void {
			TeamsScene::$calls[] = 'stop';
			TeamsScene::$currentUser = null;
			TeamsScene::$bypass = false;
		}

		/** @return list<Circle> the teams of the session's user */
		public function getCircles(?CircleProbe $probe = null, bool $refreshCache = false): array {
			return array_values(array_map(static fn (string $id): Circle => new Circle($id, TeamsScene::$teams[$id]), TeamsScene::visibleTo(TeamsScene::session())));
		}

		/** @param string $federatedId account or group @param int $type its type @return FederatedUser its identity; a group sets the Teams app */
		public function getFederatedUser(string $federatedId, int $type = 0): FederatedUser {
			if ($type === 2) {
				TeamsScene::$calls[] = 'group-app';
				TeamsScene::$currentApp = true;
				if (TeamsScene::$failResolveGroup) {
					throw new \RuntimeException('group could not be resolved');
				}
			}
			// A group is the group's system team, a team identity named after the group (importFromCircle).
			return $type === 2 ? new FederatedUser(ucfirst($federatedId), 16, $federatedId) : new FederatedUser($federatedId, $type);
		}

		/** @return object the membership Teams made */
		public function addMember(string $circleId, FederatedUser $federatedUser): object {
			return TeamsScene::add($circleId, $federatedUser);
		}

		/** @return object the member with its new level */
		public function levelMember(string $memberId, int $level): object {
			return TeamsScene::level($memberId, $level);
		}

		/** @param string $memberId the membership to end */
		public function removeMember(string $memberId): void {
			TeamsScene::remove($memberId);
		}

		/** @return Circle one team the session's user sees */
		public function getCircle(string $singleId, ?CircleProbe $probe = null): Circle {
			if (!in_array($singleId, TeamsScene::visibleTo(TeamsScene::session()), true)) {
				throw new \OCA\Collectives\Service\NotFoundException('Team not found');
			}
			return new Circle($singleId, TeamsScene::$teams[$singleId]);
		}
	}
}

namespace OCA\Collectives\Service {
	/** Native "a team of that name exists". */
	class CircleExistsException extends \Exception {
	}

	/** Native "unprocessable". */
	class UnprocessableEntityException extends \Exception {
	}
}

namespace OCA\Collectives\Mount {
	use OCA\Mcp\Tests\Unit\Modules\Collectives\TeamsScene;
	use OCP\Files\Folder;

	/** Double of the collectives' storage manager: the root is created when asked for and missing, as upstream. */
	class CollectiveFolderManager {
		/** @return string the root path, built without touching storage */
		public function getRootPath(): string {
			return TeamsScene::ROOT;
		}

		/** @param int $id collective @return Folder its folder; asking creates the root when it is missing */
		public function getFolder(int $id): Folder {
			if (!TeamsScene::$root) {
				TeamsScene::$calls[] = 'createRoot';
				TeamsScene::$root = true;
			}
			return TeamsScene::folder($id);
		}
	}
}

namespace OCA\Collectives\Db {
	use OCA\Mcp\Tests\Unit\Modules\Collectives\TeamsScene;

	/** Double of a page record, with the serialized form of 4.0.0 to 4.7.1. */
	class Page {
		/** @param array{fileId:int, lastUserId:string|null} $data the record */
		public function __construct(private array $data) {
		}

		/** @return array<string, mixed> the serialized record */
		public function jsonSerialize(): array {
			return $this->data;
		}
	}

	/** Double of the page records: a plain read by file id, which never reaches the actor's mounts or Teams. */
	class PageMapper {
		/** @param int $fileId file of the page @param bool $trashed whether trashed records are asked for @return Page|null the record */
		public function findByFileId(int $fileId, bool $trashed = false): ?Page {
			$record = TeamsScene::record($fileId, $trashed);
			return $record === null ? null : new Page($record);
		}
	}
}
