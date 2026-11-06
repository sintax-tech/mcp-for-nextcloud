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
 * {@see CollectivesAdmission}), resolves the shared native service lazily and passes the actor explicitly. Pages are
 * read one by one with `find`; the tree builders (`findAll`, `getPagesFromFolder`) are never called, because they may
 * write a missing index page. Trash, copy, ordering, emoji, tags, attachments, templates, members and shares are not
 * reachable from here.
 */
class CollectivesGateway {
	/**
	 * @param ContainerInterface $container server container the native services are shared in
	 * @param CollectivesAdmission $admission what every call proves first
	 */
	public function __construct(
		private ContainerInterface $container,
		private CollectivesAdmission $admission,
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
	 * Reads pages one by one with PageService::find, which never writes in a proven release; the tree builders of
	 * Collectives may create a missing index page and are never called. A page Collectives no longer finds (removed,
	 * or its index removed meanwhile) is left out.
	 * @param int $cid collective id
	 * @param list<int> $ids page file ids, as {@see \OCA\Mcp\Modules\Collectives\Locating\PageFileWalk} found them
	 * @param string $uid authenticated actor
	 * @param string $operation exact grant of the call
	 * @return list<array<string, mixed>> the pages Collectives found, read now
	 * @throws ToolFailure when the call is not admitted, or Collectives fails otherwise than with not found
	 */
	public function pages(int $cid, array $ids, string $uid, string $operation): array {
		$this->admission->admit($uid, $operation);
		$pages = [];
		$service = $this->service(CollectivesSurface::PAGES);
		foreach ($ids as $id) {
			try {
				$pages[] = PageProjection::page($service->find($cid, $id, $uid));
			} catch (Throwable $e) {
				if (!str_ends_with($e::class, 'NotFoundException')) {
					throw self::failure($e);
				}
			}
		}
		return $pages;
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
	 * One native content search for the actor, through the collective read for them now; only for the read-only
	 * shape of 4.4.0 and later (see {@see ContentSearch}).
	 * @param int $cid collective id
	 * @param string $query text to find
	 * @param string $uid authenticated actor
	 * @return array<mixed> the native hits, untouched
	 * @throws ToolFailure when the call is not admitted or Collectives fails
	 */
	public function searchCollective(int $cid, string $query, string $uid): array {
		$this->admission->admit($uid, 'read');
		return $this->call(function () use ($cid, $query, $uid): array {
			$collective = $this->service(CollectivesSurface::COLLECTIVES)->getCollective($cid, $uid);
			return $this->service(CollectivesSurface::SEARCH)->searchCollective($collective, $query);
		});
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
	 * @return array<string, mixed> the page as Collectives answered it, slug included (computed by its own slugger)
	 * @throws Throwable whatever the native call throws, untouched: the caller decides what an attempt means
	 */
	public function move(int $cid, int $pageId, ?int $parentId, ?string $title, int $index, string $uid): array {
		$this->admission->admit($uid, 'move');
		return PageProjection::page($this->service(CollectivesSurface::PAGES)->move($cid, $pageId, $parentId, $title, $index, $uid));
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
