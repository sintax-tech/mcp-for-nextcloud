<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Dav\CalDav;
use OCA\Mcp\Platform\Dav\Collections\Collection as Calendar;
use OCA\Mcp\Platform\Dav\CalDav\CollectionAccess as CalendarAccess;
use OCA\Mcp\Platform\Dav\CalDav\CalDavFailure as CalendarException;

use LogicException;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\ScopeReader;

/**
 * The calendars the tools may reach: the native {@see CalendarAccess} narrowed by the user's resource scope
 * (contract B.5). The component decides the scope module, so the calendar tools (VEVENT) answer to the calendar scope
 * and the task tools (VTODO) to the tasks scope, independently, even for one calendar that holds both components.
 *
 * The identity is the backend calendar id (B.2), which a share keeps from the owner's calendar and a rename does not
 * change; the URI, the display name and the path are never a key. A calendar outside the scope is missing, with the
 * very refusal of a calendar that does not exist (D6). A scope that cannot be read fails the operation
 * ({@see PolicyUnavailable}, 503), never a full list.
 *
 * Only the tools use this class; the administrative pages and the native resource enumeration keep CalendarAccess
 * (F4), since a scope must never hide a calendar from the person who configures it.
 */
class ScopedCollectionAccess {
    /** Scope module of each component a tool reads. */
    public const MODULES = ['VEVENT' => 'calendar', 'VTODO' => 'tasks'];

    /**
     * @param CalendarAccess $native the user's native calendars
     * @param ScopeReader $scope the resource scope of the current operation
     */
    public function __construct(private CalendarAccess $native, private ScopeReader $scope) {}

    /**
     * The visible calendars of the component, inside the scope of its module.
     *
     * @param string $userId authenticated UID
     * @param string $component VEVENT for the calendar tools, VTODO for the task tools
     * @return list<Calendar> the native list, in its order, without the calendars outside the scope
     * @throws PolicyUnavailable when the scope cannot be read
     */
    public function visible(string $userId, string $component = 'VEVENT'): array {
        $module = self::module($component);
        // Before the native list: an unreadable scope fails even a user with no calendar of this kind.
        $this->scope->assertReadable($userId, $module);
        $out = [];
        foreach ($this->native->visible($userId, $component) as $calendar) {
            if ($this->scope->inScope($userId, $module, (string)$calendar->id)) {
                $out[] = $calendar;
            }
        }
        return $out;
    }

    /**
     * @param string $userId authenticated UID
     * @param string $path "/remote.php/dav/calendars/<uid>/<uri>/", trailing slash optional
     * @param string $component VEVENT or VTODO
     * @return Calendar the visible calendar inside the scope
     * @throws CalendarException not found when the path is foreign, malformed, not visible or outside the scope
     * @throws PolicyUnavailable when the scope cannot be read
     */
    public function resolve(string $userId, string $path, string $component = 'VEVENT'): Calendar {
        $module = self::module($component);
        // Before the native lookup: with an unreadable scope a missing and an existing calendar answer alike (503).
        $this->scope->assertReadable($userId, $module);
        $calendar = $this->native->resolve($userId, $path, $component);
        if (!$this->scope->inScope($userId, $module, (string)$calendar->id)) {
            throw CalendarException::notFound();
        }
        return $calendar;
    }

    /**
     * The scope comes before the write check: a calendar outside it is missing, never "read-only".
     *
     * @param string $userId authenticated UID
     * @param string $path calendar path
     * @param string $component VEVENT or VTODO
     * @return Calendar the writable calendar inside the scope
     * @throws CalendarException not found as in resolve(), forbidden when it cannot be written
     * @throws PolicyUnavailable when the scope cannot be read
     */
    public function resolveWritable(string $userId, string $path, string $component = 'VEVENT'): Calendar {
        $calendar = $this->resolve($userId, $path, $component);
        if (!$calendar->writable) {
            throw CalendarException::forbidden();
        }
        return $calendar;
    }

    /** @throws LogicException for a component no tool reads: no module, so no scope could ever apply */
    private static function module(string $component): string {
        return self::MODULES[$component] ?? throw new LogicException('Unscoped calendar component');
    }
}
