<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\ResourceScope;

use InvalidArgumentException;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Platform\Dav\Collections\Collection as Calendar;
use OCA\Mcp\Platform\Dav\CalDav\CollectionAccess as CalendarAccess;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Throwable;

/**
 * The native resources of a target user that a resource scope may name (contracts B.4, B.6, F4): Files folders from
 * the user's own tree, calendars and task lists from the native CalendarAccess. It ignores the user's MCP grants and
 * any saved scope, so the admin page lists what the account can reach in Nextcloud, never what the MCP view shows.
 * Folders excluded by tag are listed and marked 'excluded': choosing one has no effect, the exclusion wins.
 *
 * Only the admin scope routes, the personal read of one's own scope and the scope writer's external validation use
 * it; no tool does. It returns names and paths for display, never content.
 */
class NativeResourceEnumerator {
    /** Candidates per page. */
    public const PAGE_SIZE = 50;
    /** Highest page accepted, to bound offsets. */
    public const MAX_PAGE = 10000;
    /** Longest search term accepted. */
    public const MAX_SEARCH = 100;
    /** Calendar component each scope module of a calendar holds. */
    private const COMPONENTS = ['calendar' => 'VEVENT', 'tasks' => 'VTODO'];

    public function __construct(
        private IRootFolder $rootFolder,
        private CalendarAccess $calendars,
        private VisibilityGuard $guard,
    ) {}

    /**
     * The ids of the list the target user cannot reach natively: a Files id must be a readable folder of the user's
     * tree, a calendar id one of the user's visible calendars of the module's component. A failure to read the
     * user's resources is unavailability, not an answer about them: the save is refused with a retryable 503.
     *
     * @param string $uid target user
     * @param string $module one of ResourceScopeMapper::MODULES
     * @param list<string> $ids canonical decimal ids
     * @return list<string> the unreachable ids, in the given order; empty when all are reachable
     * @throws InvalidArgumentException for an unknown module
     * @throws PolicyUnavailable when the user's tree or calendars cannot be read
     */
    public function inaccessible(string $uid, string $module, array $ids): array {
        self::checkModule($module);
        if ($ids === []) {
            return [];
        }
        try {
            if ($module === 'files') {
                $folder = $this->rootFolder->getUserFolder($uid);
                return array_values(array_filter($ids, fn (string $id): bool => $this->folder($folder, $id) === null));
            }
            $visible = $this->calendarsById($uid, $module);
            return array_values(array_filter($ids, static fn (string $id): bool => !isset($visible[$id])));
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Native resources unavailable', previous: $e);
        }
    }

    /**
     * One page of candidates: the subfolders of $parent (the user's root when null), or the user's calendars or task
     * lists, whose name contains $search ignoring case, ordered by name. Pages never truncate: total counts every
     * match and hasMore says whether another page follows.
     *
     * The Files listing is the native one on purpose (contracts F4), with its native side effects, all admin-only and
     * none of them widening access: getUserFolder() creates the home of a user who never signed in, and
     * getDirectoryListing() (View::getDirectoryContent in stable32–35) may create the missing parent folder of a
     * nested mount and scans uncached sub-storages. It also materializes every child of the folder, files included,
     * before the folder filter and the page of 50: there is no width cap yet, and the measurement of a very wide
     * folder belongs to B7. A cache-based listing would lose the mounts and diverge from the native view.
     *
     * @param string $uid target user
     * @param string $module one of ResourceScopeMapper::MODULES
     * @param string $search term matched against the name; '' for every candidate
     * @param int $page 1-based page
     * @param string|null $parent Files only: id of the folder whose subfolders are listed
     * @return array{items:list<array{id:string, name:string, path:?string, excluded:bool}>, page:int, pageSize:int, total:int, hasMore:bool, parent:?array{id:string, name:string, path:string, excluded:bool}}
     * @throws InvalidArgumentException for an unknown module, a page out of range, an oversized term, or a parent
     *         that is not a readable folder of the user, or one given for a calendar module
     * @throws PolicyUnavailable when the user's resources cannot be read
     */
    public function candidates(string $uid, string $module, string $search, int $page, ?string $parent = null): array {
        self::checkModule($module);
        $search = trim($search);
        if ($page < 1 || $page > self::MAX_PAGE || mb_strlen($search) > self::MAX_SEARCH || ($parent !== null && $module !== 'files')) {
            throw new InvalidArgumentException('Invalid request');
        }
        $folderOfParent = null;
        try {
            if ($module === 'files') {
                $root = $this->rootFolder->getUserFolder($uid);
                $folderOfParent = $parent === null ? $root : $this->folder($root, self::canonical($parent));
                if ($folderOfParent === null) {
                    throw new InvalidArgumentException('Invalid request');
                }
                $items = [];
                foreach ($folderOfParent->getDirectoryListing() as $child) {
                    if ($child instanceof Folder && $child->isReadable()) {
                        $items[] = $this->describeFolder($root, $child);
                    }
                }
            } else {
                $items = array_values(array_map(static fn (Calendar $calendar): array => self::describeCalendar($calendar), $this->calendarsById($uid, $module)));
            }
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Native resources unavailable', previous: $e);
        }
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $items = array_values(array_filter($items, static fn (array $item): bool => str_contains(mb_strtolower($item['name']), $needle)));
        }
        // A stable order across pages: name ignoring case, then the exact name, then the id.
        usort($items, static fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['name']] <=> [mb_strtolower($b['name']), $b['name']]
            ?: DecimalCounter::compare($a['id'], $b['id']));
        $offset = ($page - 1) * self::PAGE_SIZE;
        return [
            'items' => array_slice($items, $offset, self::PAGE_SIZE),
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
            'total' => count($items),
            'hasMore' => count($items) > $offset + self::PAGE_SIZE,
            'parent' => $folderOfParent === null ? null : $this->describeFolder($root, $folderOfParent),
        ];
    }

    /**
     * Names the saved resources of a scope for display. A resource the user no longer reaches (deleted, unshared)
     * is reported as not found, without a name.
     *
     * @param string $uid target user
     * @param string $module one of ResourceScopeMapper::MODULES
     * @param list<string> $ids canonical ids of the saved scope
     * @return list<array{id:string, found:bool, name:?string, path:?string, excluded:bool}> in the given order
     * @throws InvalidArgumentException for an unknown module
     * @throws PolicyUnavailable when the user's resources cannot be read
     */
    public function describe(string $uid, string $module, array $ids): array {
        self::checkModule($module);
        if ($ids === []) {
            return [];
        }
        try {
            if ($module === 'files') {
                $root = $this->rootFolder->getUserFolder($uid);
                return array_map(function (string $id) use ($root): array {
                    $folder = $this->folder($root, $id);
                    return $folder === null ? self::missing($id) : self::found($this->describeFolder($root, $folder));
                }, $ids);
            }
            $visible = $this->calendarsById($uid, $module);
            return array_map(static fn (string $id): array => isset($visible[$id]) ? self::found(self::describeCalendar($visible[$id])) : self::missing($id), $ids);
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Native resources unavailable', previous: $e);
        }
    }

    /** @return Folder|null the readable folder with this id in the user's tree */
    private function folder(Folder $root, string $id): ?Folder {
        if ((string)$root->getId() === $id) {
            return $root;
        }
        foreach ($root->getById((int)$id) as $node) {
            if ($node instanceof Folder && (string)$node->getId() === $id && $node->isReadable()) {
                return $node;
            }
        }
        return null;
    }

    /** @return array{id:string, name:string, path:string, excluded:bool} */
    private function describeFolder(Folder $root, Folder $folder): array {
        $path = $folder === $root ? '/' : (string)$root->getRelativePath($folder->getPath());
        return [
            'id' => (string)$folder->getId(),
            'name' => $path === '/' ? '/' : $folder->getName(),
            'path' => $path,
            'excluded' => !$this->guard->isVisible($folder),
        ];
    }

    /** @return array{id:string, name:string, path:null, excluded:false} */
    private static function describeCalendar(Calendar $calendar): array {
        return ['id' => (string)$calendar->id, 'name' => $calendar->name, 'path' => null, 'excluded' => false];
    }

    /**
     * @param array{id:string, name:string, path:?string, excluded:bool} $item
     * @return array{id:string, found:true, name:string, path:?string, excluded:bool} the same shape as missing()
     */
    private static function found(array $item): array {
        return ['id' => $item['id'], 'found' => true] + $item;
    }

    /** @return array{id:string, found:false, name:null, path:null, excluded:false} */
    private static function missing(string $id): array {
        return ['id' => $id, 'found' => false, 'name' => null, 'path' => null, 'excluded' => false];
    }

    /** @return array<string, Calendar> the user's visible calendars of the module's component, by id */
    private function calendarsById(string $uid, string $module): array {
        $calendars = [];
        foreach ($this->calendars->visible($uid, self::COMPONENTS[$module]) as $calendar) {
            $calendars[(string)$calendar->id] = $calendar;
        }
        return $calendars;
    }

    /** @throws InvalidArgumentException unless the id is a positive canonical decimal */
    private static function canonical(string $id): string {
        if (DecimalCounter::validate($id, DecimalCounter::MAX) === '0') {
            throw new InvalidArgumentException('Invalid request');
        }
        return $id;
    }

    /** @throws InvalidArgumentException for a module outside ResourceScopeMapper::MODULES */
    private static function checkModule(string $module): void {
        if (!in_array($module, ResourceScopeMapper::MODULES, true)) {
            throw new InvalidArgumentException('Invalid scope module');
        }
    }
}
