<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Inspection;

use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\Resolution\PolicyResolver;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Platform\Dav\CalDav\CollectionAccess as CalendarAccess;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess as ScopedCalendarAccess;
use Throwable;

/**
 * Backend of the inspector's "user + calendar" (VEVENT) and "user + task list" (VTODO) question (contracts A.4, B.6;
 * F2 of the ticket review): why the tools of one user can or cannot reach one calendar, through the resolution the
 * tools themselves use, in its order: the policy gates and the grant of the operation, then the resource scope, then
 * the native ACL. It never answers "allowed" unless every gate was evaluated; a gate that could not be evaluated is
 * named instead. The answer is for the administrator only and holds no name: the caller (the inspector route) checks
 * the administrator and CSRF before asking, and audits the question.
 *
 * The native ACL is read from the user's own calendars, like the tools: a calendar that does not exist and one the user
 * has no access to look the same from there, so both answer no_native_access.
 */
class CalendarAccessExplainer {
    public const ALLOWED = 'allowed';
    /** A policy gate blocks before the resource: the gate says which (account, service, eligibility, grant...). */
    public const BLOCKED = 'blocked';
    public const OUT_OF_SCOPE = 'out_of_scope';
    public const NO_NATIVE_ACCESS = 'no_native_access';
    public const READ_ONLY = 'read_only';
    /** A gate could not be evaluated (unknown grant, unreadable scope or policy, native read failure). */
    public const NOT_EVALUATED = 'not_evaluated';

    /**
     * @param PolicyResolver $resolver the resolution the tools use
     * @param CalendarAccess $native the user's native calendars, never narrowed by the scope
     */
    public function __construct(private PolicyResolver $resolver, private CalendarAccess $native) {}

    /**
     * @param string $uid the user whose tools are explained
     * @param string $module calendar or tasks
     * @param string $calendarId canonical decimal calendar id, as the scope stores it
     * @param string $operation catalog operation of the module the tool would perform; any other than read needs
     *        write access to the calendar
     * @return array{reason:string, gate:?string} reason as the constants of this class; gate names the gate that
     *         blocked or could not be evaluated (resource_scope, native_acl, policy or a gate of the resolution)
     * @throws InvalidArgumentException for a module, operation or id outside the contract
     */
    public function explain(string $uid, string $module, string $calendarId, string $operation = 'read'): array {
        $component = array_search($module, ScopedCalendarAccess::MODULES, true);
        if ($component === false || !GrantPolicy::inCatalog($module, $operation) || !preg_match('/^[1-9][0-9]*$/D', $calendarId)) {
            throw new InvalidArgumentException('Invalid request');
        }
        try {
            // A diagnosis is no writer command: it never persists an observation of group trust.
            $context = $this->resolver->begin($uid, [], [], false);
            try {
                $effective = $context->resolveEffective($module, $operation, [$calendarId]);
            } finally {
                $context->close();
            }
        } catch (PolicyUnavailable) {
            return ['reason' => self::NOT_EVALUATED, 'gate' => 'policy'];
        }
        if ($effective->allowed === null) {
            return ['reason' => self::NOT_EVALUATED, 'gate' => $effective->blockingGate];
        }
        if ($effective->allowed === false) {
            return ['reason' => $effective->blockingGate === 'resource_scope' ? self::OUT_OF_SCOPE : self::BLOCKED, 'gate' => $effective->blockingGate];
        }
        try {
            $calendars = $this->native->visible($uid, (string)$component);
        } catch (Throwable) {
            return ['reason' => self::NOT_EVALUATED, 'gate' => 'native_acl'];
        }
        foreach ($calendars as $calendar) {
            if ((string)$calendar->id === $calendarId) {
                return $operation === 'read' || $calendar->writable
                    ? ['reason' => self::ALLOWED, 'gate' => null]
                    : ['reason' => self::READ_ONLY, 'gate' => 'native_acl'];
            }
        }
        return ['reason' => self::NO_NATIVE_ACCESS, 'gate' => 'native_acl'];
    }
}
