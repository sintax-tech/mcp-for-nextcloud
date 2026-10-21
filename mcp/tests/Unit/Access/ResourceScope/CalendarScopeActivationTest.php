<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\ResourceScope;

use OCA\Mcp\Tests\Support\Access\ResourceScope\ScopeFixture;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\ResourceScopeWriter;
use OCA\Mcp\Access\ResourceScope\ScopeInactive;
use OCA\Mcp\Access\ResourceScope\ScopeActivation;
use OCA\Mcp\Platform\Dav\CalDav\CollectionAccess as CalendarAccess;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess as ScopedCalendarAccess;
use PHPUnit\Framework\TestCase;

/**
 * B6 opens the activation barrier of calendar and tasks (F1 of the ticket review), end to end over the real writer,
 * the resolution core and the tools' calendar access: before the opening a calendar scope is refused and the tools see
 * every calendar; with the shipped barrier a saved scope applies from the next operation, never inside the one already
 * open (S9), without moving any epoch; files stays closed for B5.
 *
 * Calendars of alice (ScopeFixture): 11 Personal (VEVENT), 12 Team shared by bob (VEVENT and VTODO), 13 Todo (VTODO).
 */
final class CalendarScopeActivationTest extends TestCase {
    private ScopeFixture $fx;

    protected function setUp(): void {
        $this->fx = new ScopeFixture($this);
        $this->fx->config->app['mcp']['service_enabled'] = '1';
        $this->fx->config->user['alice']['mcp']['eligible'] = '1';
        $this->fx->config->user['alice']['mcp']['connected'] = '1';
    }

    /** @return array{GrantPolicy, ScopedCalendarAccess} what alice's tools read, through $writer's context */
    private function tools(ResourceScopeWriter $writer): array {
        $policy = $this->fx->policy($this->fx->services());
        return [$policy, new ScopedCalendarAccess(new CalendarAccess($this->fx->calendars), $policy)];
    }

    /** @return list<int> ids of the calendars alice's tools see for the component, in one operation */
    private function seen(GrantPolicy $policy, ScopedCalendarAccess $access, string $component): array {
        return $policy->within('alice', fn (): array => array_map(static fn ($c): int => $c->id, $access->visible('alice', $component)));
    }

    public function testBeforeTheOpeningACalendarScopeIsRefusedAndTheToolsSeeEverything(): void {
        $writer = $this->fx->commands(false);
        [$policy, $access] = $this->tools($writer);
        $before = $this->fx->snapshot();
        foreach (['calendar' => ['11'], 'tasks' => ['13']] as $module => $items) {
            try {
                $writer->setResourceScope($this->fx->revision(), 'root', 'alice', $module, $items);
                $this->fail("Closed barrier accepted $module");
            } catch (ScopeInactive) {
            }
        }
        $this->assertSame($before, $this->fx->snapshot());
        $this->assertSame([11, 12], $this->seen($policy, $access, 'VEVENT'));
        $this->assertSame([12, 13], $this->seen($policy, $access, 'VTODO'));
    }

    public function testWithTheShippedBarrierASavedScopeAppliesFromTheNextOperation(): void {
        $writer = $this->fx->commands(false, null, new ScopeActivation());
        [$policy, $access] = $this->tools($writer);
        $epoch = $this->fx->snapshot()['auth_epoch'];
        $this->fx->oauth->insertToken(['owner_uid' => 'alice', 'client_id' => 'c', 'access_hash' => 'a', 'refresh_hash' => 'r'], $this->fx->now);
        $tokens = $this->fx->oauth->tokens;

        $policy->within('alice', function () use ($writer, $access): void {
            $this->assertSame([11, 12], array_map(static fn ($c): int => $c->id, $access->visible('alice')));
            $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'calendar', ['11']);
            // The operation already open keeps the scope it read.
            $this->assertSame([11, 12], array_map(static fn ($c): int => $c->id, $access->visible('alice')));
        });
        $this->assertSame([11], $this->seen($policy, $access, 'VEVENT'));
        $this->assertSame([12, 13], $this->seen($policy, $access, 'VTODO'), 'the tasks scope is independent');

        $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'tasks', ['13']);
        $this->assertSame([13], $this->seen($policy, $access, 'VTODO'));
        $this->assertSame([11], $this->seen($policy, $access, 'VEVENT'));

        $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'calendar', null);
        $this->assertSame([11, 12], $this->seen($policy, $access, 'VEVENT'), 'unrestricted again');

        $this->assertSame($epoch, $this->fx->snapshot()['auth_epoch'], 'a scope moves no epoch');
        $this->assertSame($tokens, $this->fx->oauth->tokens, 'a scope revokes no credential');
        // A module whose barrier is closed is still refused: injected here, since every shipped barrier is open.
        $this->expectException(ScopeInactive::class);
        $this->fx->commands(false, null, new ScopeActivation(['files' => false, 'calendar' => true, 'tasks' => true]))
            ->setResourceScope($this->fx->revision(), 'root', 'alice', 'files', [$this->fx->projects]);
    }
}
