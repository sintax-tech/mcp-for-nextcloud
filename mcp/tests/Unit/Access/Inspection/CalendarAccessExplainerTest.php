<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Inspection;

use OCA\Mcp\Tests\Support\Access\ResourceScope\ScopeFixture;

use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Support\Access\Policy\PolicyServices;
use OCA\Mcp\Access\Inspection\CalendarAccessExplainer as E;
use OCA\Mcp\Platform\Dav\CalDav\CollectionAccess as CalendarAccess;
use OCA\Mcp\Platform\Dav\CalDav\CalDavFailure as CalendarException;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess as ScopedCalendarAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * S13 for "user + calendar" and "user + task list" (F2 of the ticket review), the backend half: the reason the
 * inspector shows is the decision of the tools, gate by gate, over the same writer, resolution and calendars, and
 * "allowed" is said only when every gate was evaluated. The route, its administrator check before the target and the
 * audit belong to the inspector route (A5, ticket 13).
 *
 * Calendars of alice: 11 Personal (VEVENT), 12 Team shared by bob (VEVENT and VTODO), 13 Todo (VTODO), 15 a read-only
 * share; 14 is bob's alone.
 */
final class CalendarAccessExplainerTest extends TestCase {
    private ScopeFixture $fx;
    private PolicyServices $services;
    private GrantPolicy $policy;
    private ScopedCalendarAccess $tools;
    private E $explainer;

    protected function setUp(): void {
        $this->fx = new ScopeFixture($this);
        $this->fx->config->app['mcp']['service_enabled'] = '1';
        $this->fx->config->user['alice']['mcp']['eligible'] = '1';
        $this->fx->config->user['alice']['mcp']['connected'] = '1';
        $this->fx->config->user['alice']['mcp']['grant_calendar_edit'] = '1';
        $this->fx->config->user['alice']['mcp']['grant_tasks_edit'] = '1';
        $this->fx->calendars->addCalendar('principals/users/alice', 15, 'ro_shared_by_bob', 'principals/users/bob', ['readOnly' => true, 'components' => ['VEVENT']]);
        $this->services = $this->fx->services();
        $this->policy = $this->fx->policy($this->services);
        $native = new CalendarAccess($this->fx->calendars);
        $this->tools = new ScopedCalendarAccess($native, $this->policy);
        $this->explainer = new E($this->fx->resolver($this->services), $native);
    }

    private function scope(string $module, ?array $items): void {
        $this->fx->commands()->setResourceScope($this->fx->revision(), 'root', 'alice', $module, $items);
    }

    /**
     * What the tools decide for alice: the endpoint's admission (service, eligibility, connection), the registry's
     * grant, then the scoped calendar (writable for a write).
     */
    private function toolAllows(string $module, string $id, string $operation): bool {
        return $this->policy->within('alice', function () use ($module, $id, $operation): bool {
            if (!$this->policy->canConnect('alice') || !$this->policy->granted('alice', $module, $operation)) {
                return false;
            }
            $component = array_search($module, ScopedCalendarAccess::MODULES, true);
            $path = null;
            foreach ((new CalendarAccess($this->fx->calendars))->visible('alice', $component) as $calendar) {
                $path = (string)$calendar->id === $id ? $calendar->path : $path;
            }
            try {
                $path ??= '/remote.php/dav/calendars/alice/missing/';
                $operation === 'read' ? $this->tools->resolve('alice', $path, $component) : $this->tools->resolveWritable('alice', $path, $component);
                return true;
            } catch (CalendarException) {
                return false;
            }
        });
    }

    /** @return array<string, array{\Closure(self): void, string, string, string, string, ?string}> */
    public static function cases(): array {
        $none = static function (): void {};
        return [
            'own calendar' => [$none, 'calendar', '11', 'read', E::ALLOWED, null],
            'shared calendar by the owner id' => [$none, 'calendar', '12', 'edit', E::ALLOWED, null],
            'outside the calendar scope' => [static fn (self $t) => $t->scope('calendar', ['11']), 'calendar', '12', 'read', E::OUT_OF_SCOPE, 'resource_scope'],
            'empty scope' => [static fn (self $t) => $t->scope('calendar', []), 'calendar', '11', 'read', E::OUT_OF_SCOPE, 'resource_scope'],
            'calendar of another user' => [$none, 'calendar', '14', 'read', E::NO_NATIVE_ACCESS, 'native_acl'],
            'calendar that does not exist' => [$none, 'calendar', '987', 'read', E::NO_NATIVE_ACCESS, 'native_acl'],
            'task list as calendar' => [$none, 'calendar', '13', 'read', E::NO_NATIVE_ACCESS, 'native_acl'],
            'read-only share, write' => [$none, 'calendar', '15', 'edit', E::READ_ONLY, 'native_acl'],
            'write not granted' => [$none, 'calendar', '11', 'create', E::BLOCKED, 'grant'],
            'grant first, then scope' => [static fn (self $t) => $t->scope('calendar', []), 'calendar', '11', 'create', E::BLOCKED, 'grant'],
            'service off' => [static fn (self $t) => $t->fx->config->app['mcp']['service_enabled'] = '0', 'calendar', '11', 'read', E::BLOCKED, 'service'],
            'tasks scope, independent of calendar' => [static fn (self $t) => $t->scope('calendar', ['11']), 'tasks', '12', 'edit', E::ALLOWED, null],
            'outside the tasks scope' => [static fn (self $t) => $t->scope('tasks', ['13']), 'tasks', '12', 'read', E::OUT_OF_SCOPE, 'resource_scope'],
        ];
    }

    #[DataProvider('cases')]
    public function testTheReasonIsTheDecisionOfTheTools(\Closure $arrange, string $module, string $id, string $operation, string $reason, ?string $gate): void {
        $arrange($this);
        $this->assertSame(['reason' => $reason, 'gate' => $gate], $this->explainer->explain('alice', $module, $id, $operation));
        $this->assertSame($reason === E::ALLOWED, $this->toolAllows($module, $id, $operation), 'explanation and decision agree');
    }

    public function testAnUnreadableScopeIsNamedNeverAllowed(): void {
        $this->fx->database->dbal->executeStatement('DROP TABLE ' . ResourceScopeMapper::ITEMS_TABLE);
        $this->assertSame(['reason' => E::NOT_EVALUATED, 'gate' => 'resource_scope_unknown'], $this->explainer->explain('alice', 'calendar', '11'));
    }

    public function testAnUnreadablePolicyIsNamedNeverAllowed(): void {
        $this->fx->database->dbal->executeStatement('DELETE FROM mcp_policy_state');
        $this->assertSame(['reason' => E::NOT_EVALUATED, 'gate' => 'policy'], $this->explainer->explain('alice', 'calendar', '11'));
    }

    public function testAFailedNativeReadIsNamedNeverAllowed(): void {
        $this->fx->calendars->failure = new \RuntimeException('dav down');
        $this->assertSame(['reason' => E::NOT_EVALUATED, 'gate' => 'native_acl'], $this->explainer->explain('alice', 'calendar', '11'));
    }

    public function testAnAccountThatDoesNotExistIsBlockedByTheAccountGate(): void {
        $this->assertSame(['reason' => E::BLOCKED, 'gate' => 'account'], $this->explainer->explain('nobody', 'calendar', '11'));
    }

    /** @return array<string, array{string, string, string}> */
    public static function invalid(): array {
        return ['files is not a calendar module' => ['files', '11', 'read'], 'not canonical' => ['calendar', '011', 'read'],
            'not decimal' => ['calendar', 'personal', 'read'], 'not an operation of the module' => ['tasks', '13', 'move']];
    }

    #[DataProvider('invalid')]
    public function testInputOutsideTheContractIsRefused(string $module, string $id, string $operation): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->explainer->explain('alice', $module, $id, $operation);
    }
}
