<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Integration;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Reads back a collective creation without repairing anything. Only reads, in this order, each step only when the
 * one before exists: the collective as the actor sees it, the collectives root, the collective's folder and its
 * Readme.md checked with `nodeExists` on the path the folder manager builds without touching storage
 * (`getRootPath()`), then the folder itself, and the landing page through the page service. Asking the folder manager
 * or the actor's mount for a missing folder would create it (`getRootFolder()` 4.7.1 `:72-91`, `getMount()` `:113-127`).
 *
 * The landing page counts as registered only when the page service answers it with a last user: that value comes
 * from the page record alone, and is null without one (`getPageByFile`, 4.7.1 `:239,:269`).
 *
 * Both native reads open Teams sessions inside Collectives and close them only after a `try/catch`: listing the
 * collectives reaches `CircleHelper::getCircles` (`CollectiveService.php:95-96`, `CircleHelper.php:103-120`), and `find`
 * reaches `CircleHelper::getCircle` through the collective's folder. Each runs in {@see TeamsSession::closingAfter()},
 * so a failure inside leaves no current user and no bypass behind, also when the receipt turns it into `uncertain`.
 */
class FoundingReadback {
	/** Landing page of a new collective (`CollectiveFolderManager::LANDING_PAGE_TITLE` and `SUFFIX`). */
	public const LANDING = 'Readme.md';

	/**
	 * @param ContainerInterface $container server container the native services are shared in
	 * @param IRootFolder $root the server file tree, only asked whether paths exist
	 * @param TeamsSession $teams closes the Teams session the native reads may leave open
	 */
	public function __construct(
		private ContainerInterface $container,
		private IRootFolder $root,
		private TeamsSession $teams,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @return list<array{id:int, name:string, team_id:string}> the actor's collectives now
	 * @throws Throwable whatever the native read throws
	 */
	public function collectives(string $uid): array {
		$found = [];
		$native = $this->teams->closingAfter(fn (): array => $this->service(CollectivesSurface::COLLECTIVES)->getCollectives($uid));
		foreach ($native as $collective) {
			if (is_object($collective)) {
				$shown = PageProjection::collective($collective);
				$found[] = ['id' => $shown['id'], 'name' => $shown['name'], 'team_id' => (string)$collective->getCircleId()];
			}
		}
		return $found;
	}

	/**
	 * @param int $id collective id
	 * @param string $uid authenticated actor
	 * @return array{root:bool, folder:bool, landing:bool, registered:bool|null} what exists; registered is null when
	 *     the landing page could not be asked for
	 */
	public function pieces(int $id, string $uid): array {
		$found = ['root' => false, 'folder' => false, 'landing' => false, 'registered' => null];
		try {
			$folders = $this->service(FoundingSurface::FOLDERS);
			$root = '/' . ltrim((string)$folders->getRootPath(), '/');
			$found['root'] = $this->root->nodeExists($root);
			$found['folder'] = $found['root'] && $this->root->nodeExists($root . '/' . $id);
			$found['landing'] = $found['folder'] && $this->root->nodeExists($root . '/' . $id . '/' . self::LANDING);
			if ($found['landing']) {
				$file = $folders->getFolder($id)->get(self::LANDING);
				$page = $file instanceof File ? $this->teams->closingAfter(fn (): object => $this->service(CollectivesSurface::PAGES)->find($id, $file->getId(), $uid)) : null;
				$data = $page === null ? [] : (array)$page->jsonSerialize();
				$found['registered'] = is_string($data['lastUserId'] ?? null) && $data['lastUserId'] !== '';
			}
		} catch (Throwable) {
			// What was found before the failure stands; the rest stays unproven.
		}
		return $found;
	}

	/**
	 * @param string $class a native service class
	 * @return object the shared native service
	 * @throws \RuntimeException when the container answers something else
	 */
	private function service(string $class): object {
		$service = $this->container->get($class);
		if (!$service instanceof $class) {
			throw new \RuntimeException('Unexpected native service ' . $class);
		}
		return $service;
	}
}
