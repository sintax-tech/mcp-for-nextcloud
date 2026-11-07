<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/*
 * Strict doubles of the native Collectives classes the module reaches, with the exact signatures of Collectives
 * 4.0.0, 4.4.0, 4.5.0 and 4.7.1 (lib/Service/PageService.php, CollectiveServiceBase.php, SearchService.php,
 * lib/Db/Collective.php, lib/Model/PageInfo.php; PageService sha256 cbfd29fa…/a4584067…/3e70835e… for 4.0.0/4.5.0/4.7.1).
 * Their state lives in {@see \OCA\Mcp\Tests\Unit\Modules\Collectives\CollectivesScene}; behaviour mirrors the
 * native code paths the module depends on: a leaf parent becomes a folder with a Readme.md, move only reorders when
 * the parent changes, and jsonSerialize carries a share token the module must never publish.
 */

namespace OCA\Collectives\Service {
	use OCA\Collectives\Db\Collective;
	use OCA\Collectives\Model\PageInfo;
	use OCA\Mcp\Tests\Unit\Modules\Collectives\CollectivesScene;
	use OCP\Files\File;
	use OCP\Files\Folder;

	/** Native "not found" of Collectives. */
	class NotFoundException extends \Exception {
	}

	/** Native "not permitted" of Collectives. */
	class NotPermittedException extends \Exception {
	}

	/** Double of CollectiveService (getCollective inherited from CollectiveServiceBase upstream). */
	class CollectiveService {
		/** @param string $userId actor @return list<Collective> the actor's collectives */
		public function getCollectives(string $userId): array {
			\OCA\Mcp\Tests\Unit\Modules\Collectives\TeamsScene::nativeRead($userId, 'collectives');
			return CollectivesScene::collectivesOf($userId);
		}

		/** @param int $id collective @param string $userId actor @return Collective a fresh entity */
		public function getCollective(int $id, string $userId): Collective {
			\OCA\Mcp\Tests\Unit\Modules\Collectives\TeamsScene::nativeRead($userId, 'collective');
			return CollectivesScene::collective($id, $userId);
		}

		/** @return array{0: Collective, 1: string} the collective and the adoption notice ({@see \OCA\Mcp\Tests\Unit\Modules\Collectives\TeamsScene}) */
		public function createCollective(string $userId, string $userLang, string $name, ?string $emoji = null): array {
			return \OCA\Mcp\Tests\Unit\Modules\Collectives\TeamsScene::createCollective($userId, $userLang, $name, $emoji);
		}
	}

	/** Double of PageService; like upstream it keeps the collective it read for the rest of the request (v4.7.1 :77-84). */
	class PageService {
		private ?Collective $collective = null;

		/** @param int $collectiveId collective @param string $userId actor @return Collective the collective read first in this request */
		private function cached(int $collectiveId, string $userId): Collective {
			if ($this->collective === null || $this->collective->jsonSerialize()['id'] !== $collectiveId) {
				$this->collective = CollectivesScene::collective($collectiveId, $userId);
			}
			return $this->collective;
		}

		/** @param int $collectiveId collective @param string $userId actor */
		public function verifyEditPermissions(int $collectiveId, string $userId): void {
			\OCA\Mcp\Tests\Unit\Modules\Collectives\TeamsScene::nativeRead($userId, 'verify');
			if (!$this->cached($collectiveId, $userId)->canEdit()) {
				throw new NotPermittedException('Not allowed to edit collective');
			}
		}

		/** @param int $collectiveId collective @param int $fileId page @param string $userId actor @return File the file */
		public function getPageFile(int $collectiveId, int $fileId, string $userId): File {
			\OCA\Mcp\Tests\Unit\Modules\Collectives\TeamsScene::nativeRead($userId, 'pageFile');
			$this->cached($collectiveId, $userId);
			return CollectivesScene::fileById($collectiveId, $fileId);
		}

		/** @param int $collectiveId collective @param int $fileId 0 for the collective folder @param string $userId actor @return Folder the folder */
		public function getFolder(int $collectiveId, int $fileId, string $userId): Folder {
			\OCA\Mcp\Tests\Unit\Modules\Collectives\TeamsScene::nativeRead($userId, 'folder');
			$this->cached($collectiveId, $userId);
			return CollectivesScene::folder();
		}

		/** @return PageInfo one page, read without writing; its parent's Readme.md must exist (getParentPageId) */
		public function find(int $collectiveId, int $id, string $userId): PageInfo {
			$landing = \OCA\Mcp\Tests\Unit\Modules\Collectives\TeamsScene::landing($id);
			if ($landing !== null) {
				\OCA\Mcp\Tests\Unit\Modules\Collectives\TeamsScene::nativeRead($userId, 'find');
				return $landing;
			}
			\OCA\Mcp\Tests\Unit\Modules\Collectives\TeamsScene::nativeRead($userId, 'find');
			$this->cached($collectiveId, $userId);
			CollectivesScene::$beforeFind === null || (CollectivesScene::$beforeFind)($id);
			return CollectivesScene::find($id);
		}

		/** @return list<PageInfo> the pages under the folder, read now */
		public function getPagesFromFolder(int $collectiveId, Folder $folder, string $userId, bool $recurse = false, bool $forceIndex = false): array {
			CollectivesScene::$calls[] = 'getPagesFromFolder' . ($forceIndex ? ':forceIndex' : '');
			$this->cached($collectiveId, $userId);
			// Every proven release creates the Readme.md of a folder that holds pages without one, forceIndex or not.
			CollectivesScene::createMissingIndexes();
			return CollectivesScene::pages();
		}

		/** @return PageInfo the created page */
		public function create(int $collectiveId, int $parentId, string $title, ?int $templateId, string $userId, ?string $defaultTitle = null): PageInfo {
			$this->verifyEditPermissions($collectiveId, $userId);
			return CollectivesScene::create($collectiveId, $parentId, $title, $templateId, $userId);
		}

		/** @return PageInfo the moved page */
		public function move(int $collectiveId, int $id, ?int $parentId, ?string $title, int $index, string $userId): PageInfo {
			$this->verifyEditPermissions($collectiveId, $userId);
			return CollectivesScene::move($collectiveId, $id, $parentId, $title, $index, $userId);
		}
	}

	/** The shape of SearchService::searchCollective in 4.0.0, for the surface only. */
	class LegacySearchService {
		/** @return list<array{file_id:int}> never called */
		public function searchCollective(Collective $collective, string $term, int $maxResults = 15): array {
			return [];
		}
	}

	/** Double of SearchService of 4.4.0 and later. */
	class SearchService {
		/** @return list<array{file_id:int}> the index hits */
		public function searchCollective(Collective $collective, string $query): array {
			// 4.0.0 receives the most results as a third argument; the double records how many it was given.
			CollectivesScene::$calls[] = 'searchCollective:' . implode(',', array_slice(func_get_args(), 2));
			\OCA\Mcp\Tests\Unit\Modules\Collectives\TeamsScene::nativeRead('alice', 'search');
			return CollectivesScene::$searchHits;
		}
	}
}

namespace OCA\Collectives\Db {
	/** Double of the Collective entity. */
	class Collective {
		/** @param array<string, mixed> $data serialized form */
		public function __construct(private array $data) {
		}

		/** @return bool whether the actor may edit */
		public function canEdit(): bool {
			return $this->data['canEdit'];
		}

		/** @return string name of the circle */
		public function getName(): string {
			return $this->data['name'];
		}

		/** @return string single id of the team */
		public function getCircleId(): string {
			return $this->data['circleId'];
		}

		/** @return array<string, mixed> native serialized form */
		public function jsonSerialize(): array {
			return $this->data;
		}
	}
}

namespace OCA\Collectives\Model {
	/** Double of PageInfo. */
	class PageInfo {
		/** @param array<string, mixed> $data serialized form */
		public function __construct(private array $data) {
		}

		/** @return array<string, mixed> native serialized form */
		public function jsonSerialize(): array {
			return $this->data;
		}
	}
}
