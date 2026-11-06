<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Integration;

use Closure;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCP\Files\File;
use OCP\Files\Folder;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The only door to the native Collectives services. Every method admits the call first (see
 * {@see CollectivesAdmission}), resolves the shared native service lazily and passes the actor explicitly. The page
 * tree is always built again through `getPagesFromFolder` without forcing an index page, never through `findAll`,
 * which caches the tree for the request and may create a missing landing page. Trash, copy, ordering, emoji, tags,
 * attachments, templates, members and shares are not reachable from here.
 */
class CollectivesGateway {
	/**
	 * @param ContainerInterface $container server container the native services are shared in
	 * @param CollectivesAdmission $admission what every call proves first
	 * @param CollectivesSurface $surface the proven native shape, also for the content search variant
	 */
	public function __construct(
		private ContainerInterface $container,
		private CollectivesAdmission $admission,
		private CollectivesSurface $surface,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @return list<array{id:int, name:string, emoji:?string, can_edit:bool, can_share:bool}> the user's collectives
	 * @throws ToolFailure when the call is not admitted or Collectives fails
	 */
	public function collectives(string $uid): array {
		$this->admission->admit($uid, 'read');
		$native = $this->call(fn (): array => $this->service(CollectivesSurface::COLLECTIVES)->getCollectives($uid));
		return array_values(array_map(static fn (object $collective): array => PageProjection::collective($collective),
			array_filter($native, static fn (mixed $item): bool => is_object($item))));
	}

	/**
	 * A fresh read of the collective and of the actor's level in it; nothing is reused from an earlier call.
	 * @param int $cid collective id
	 * @param string $uid authenticated actor
	 * @param string $operation exact grant of the call
	 * @return array{id:int, name:string, emoji:?string, can_edit:bool, can_share:bool} the collective
	 * @throws ToolFailure when the collective is unknown to the actor or the call is not admitted
	 */
	public function collective(int $cid, string $uid, string $operation): array {
		$this->admission->admit($uid, $operation);
		return PageProjection::collective($this->call(fn (): object => $this->service(CollectivesSurface::COLLECTIVES)->getCollective($cid, $uid)));
	}

	/**
	 * @param int $cid collective id
	 * @param string $uid authenticated actor
	 * @param string $operation exact grant of the call
	 * @return Folder the actor's folder of the collective
	 * @throws ToolFailure when the collective is unknown to the actor or the call is not admitted
	 */
	public function folder(int $cid, string $uid, string $operation): Folder {
		$this->admission->admit($uid, $operation);
		return $this->call(fn (): Folder => $this->service(CollectivesSurface::PAGES)->getFolder($cid, 0, $uid));
	}

	/**
	 * Builds the page tree; call it only after {@see \OCA\Mcp\Modules\Collectives\Locating\MissingIndexCheck} passed.
	 * @param int $cid collective id
	 * @param Folder $folder the actor's folder of the collective
	 * @param string $uid authenticated actor
	 * @param string $operation exact grant of the call
	 * @return list<array<string, mixed>> every page, built now
	 * @throws ToolFailure when the collective is unknown to the actor or the call is not admitted
	 */
	public function pages(int $cid, Folder $folder, string $uid, string $operation): array {
		$this->admission->admit($uid, $operation);
		$native = $this->call(fn (): array => $this->service(CollectivesSurface::PAGES)->getPagesFromFolder($cid, $folder, $uid, true, false));
		return array_values(array_map(static fn (object $page): array => PageProjection::page($page),
			array_filter($native, static fn (mixed $item): bool => is_object($item))));
	}

	/**
	 * @param int $cid collective id
	 * @param int $pageId page id
	 * @param string $uid authenticated actor
	 * @param string $operation exact grant of the call
	 * @return File the page file as the actor's mount of the collective holds it
	 * @throws ToolFailure when the page is unknown to the actor or the call is not admitted
	 */
	public function pageFile(int $cid, int $pageId, string $uid, string $operation): File {
		$this->admission->admit($uid, $operation);
		return $this->call(fn (): File => $this->service(CollectivesSurface::PAGES)->getPageFile($cid, $pageId, $uid));
	}

	/**
	 * The native edit permission, read again now: the collective and the actor's level are fetched fresh (the page
	 * service caches its collective for the request) and the page service's own check runs as well.
	 * @param int $cid collective id
	 * @param string $uid authenticated actor
	 * @param string $operation exact grant of the call
	 * @throws ToolFailure forbidden when the actor may read but not edit, not found when not a member
	 */
	public function assertEditable(int $cid, string $uid, string $operation): void {
		if (!$this->collective($cid, $uid, $operation)['can_edit']) {
			throw new ToolFailure(CommonMessages::forbidden());
		}
		$this->call(fn () => $this->service(CollectivesSurface::PAGES)->verifyEditPermissions($cid, $uid));
	}

	/**
	 * @param int $cid collective id
	 * @param string $query text to find
	 * @param string $uid authenticated actor
	 * @param int $budget most native results asked for, where the version lets the caller say
	 * @return array{ids:list<int>, capped:bool} page ids in native rank order, and whether the native answer may have stopped at the budget
	 * @throws ToolFailure when this version has no proven content search, or the call is not admitted
	 */
	public function searchContent(int $cid, string $query, string $uid, int $budget): array {
		$this->admission->admit($uid, 'read');
		$arity = $this->surface->searchArity() ?? throw new ToolFailure(CollectivesMessages::contentSearchUnavailable());
		$results = $this->call(function () use ($cid, $query, $uid, $budget, $arity): array {
			$collective = $this->service(CollectivesSurface::COLLECTIVES)->getCollective($cid, $uid);
			$search = $this->service(CollectivesSurface::SEARCH);
			return $arity === CollectivesSurface::SEARCH_WITH_LIMIT
				? $search->searchCollective($collective, $query, $budget)
				: $search->searchCollective($collective, $query);
		});
		$ids = [];
		foreach ($results as $hit) {
			$id = is_array($hit) ? (int)($hit['file_id'] ?? 0) : 0;
			if ($id > 0 && !in_array($id, $ids, true)) {
				$ids[] = $id;
			}
		}
		return ['ids' => array_slice($ids, 0, $budget), 'capped' => count($results) >= $budget];
	}

	/**
	 * One native creation, never retried; the caller reads the result back on its own.
	 * @param int $cid collective id
	 * @param int $parentId parent page id, 0 for the landing page
	 * @param string $title title of the page
	 * @param string $uid authenticated actor
	 * @return int id of the created page as Collectives answered it
	 * @throws Throwable whatever the native call throws, untouched: the caller decides what an attempt means
	 */
	public function create(int $cid, int $parentId, string $title, string $uid): int {
		$this->admission->admit($uid, 'create');
		return PageProjection::page($this->service(CollectivesSurface::PAGES)->create($cid, $parentId, $title, null, $uid))['id'];
	}

	/**
	 * One native move, never retried; the caller reads the result back on its own.
	 * @param int $cid collective id
	 * @param int $pageId page to move
	 * @param int|null $parentId new parent, null to keep it
	 * @param string|null $title new title, null to keep it
	 * @param int $index position among the subpages of the new parent
	 * @param string $uid authenticated actor
	 * @return int id of the page as Collectives answered it
	 * @throws Throwable whatever the native call throws, untouched: the caller decides what an attempt means
	 */
	public function move(int $cid, int $pageId, ?int $parentId, ?string $title, int $index, string $uid): int {
		$this->admission->admit($uid, 'move');
		return PageProjection::page($this->service(CollectivesSurface::PAGES)->move($cid, $pageId, $parentId, $title, $index, $uid))['id'];
	}

	/**
	 * @param string $class one of the CollectivesSurface service classes
	 * @return object the shared native service
	 * @throws ToolFailure when the container answers something else
	 */
	private function service(string $class): object {
		$service = $this->container->get($class);
		if (!$service instanceof $class) {
			throw new ToolFailure(CollectivesMessages::unavailable());
		}
		return $service;
	}

	/**
	 * Runs a native read and maps its failures to the module's answers, never the native message.
	 * @template T
	 * @param Closure(): T $read the native read
	 * @return T what it returned
	 * @throws ToolFailure not found, forbidden or unavailable
	 */
	private function call(Closure $read): mixed {
		try {
			return $read();
		} catch (ToolFailure $e) {
			throw $e;
		} catch (Throwable $e) {
			throw self::failure($e);
		}
	}

	/**
	 * @param Throwable $e what a native call threw
	 * @return ToolFailure the answer it means for the caller
	 */
	public static function failure(Throwable $e): ToolFailure {
		$class = $e::class;
		return match (true) {
			$e instanceof ToolFailure => $e,
			str_ends_with($class, 'NotPermittedException') => new ToolFailure(CommonMessages::forbidden()),
			str_ends_with($class, 'NotFoundException') => new ToolFailure(CommonMessages::notFound()),
			default => new ToolFailure(CollectivesMessages::unavailable()),
		};
	}
}
