<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Calendar;

use OCA\Mcp\Access\AppAvailability\AppEnablement;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserManager;
use OCP\Server;
use OCP\User\Events\UserFirstTimeLoggedInEvent;

/**
 * The Calendar tools over the real MCP endpoint, with the real Calendar app of the cell (provisioned by
 * ci/harness.sh, like Talk and Notes): calendar_list_calendars is called over HTTP with a real app password and
 * answers the calendars of the caller.
 *
 * The Calendar app is optional, so a cell where it could not be provisioned skips this test instead of failing; the
 * provisioning failure itself stays visible through MCP_IT_PROVISION_FAILURES (Exclusion/ProvisioningTest).
 */
final class CalendarToolsTest extends IntegrationTestCase {
    public function testTheCalendarsOfTheCallerAreListedOverTheMcpEndpoint(): void {
        if (!AppEnablement::forAnyone(Server::get(IAppManager::class), 'calendar')) {
            $this->fail('Required Calendar app was not provisioned; see provision.log');
        }

        // The endpoint answers 403 to a user the policy does not let connect yet: the same state the exclusion proof
        // gives its fixture users, left as it is, so the tests that run after this one see what they saw before.
        $policy = Server::get(GrantPolicy::class);
        $policy->setGlobalEnabled(true);
        $policy->setEligible(Fixtures::ALICE, true);
        $policy->setConnected(Fixtures::ALICE, true);

        // A user who has never logged in has no calendar yet: the personal one is the server's own provisioning on
        // the first login, driven here by the very event the dav app listens to.
        $alice = Server::get(IUserManager::class)->get(Fixtures::ALICE);
        $this->assertNotNull($alice, 'the fixture account exists');
        Server::get(IEventDispatcher::class)->dispatchTyped(new UserFirstTimeLoggedInEvent($alice));

        $client = new McpClient(LoginFactory::basic(Fixtures::ALICE));
        $reply = $client->tool('calendar_list_calendars');
        $this->assertTrue($reply->ok(), 'calendar_list_calendars: ' . $reply->describe());

        $calendars = $reply->json();
        $this->assertIsArray($calendars, 'the answer is the list of the calendars: ' . $reply->describe());
        $this->assertTrue(array_is_list($calendars), 'a list, nothing else: ' . $reply->describe());
        foreach ($calendars as $calendar) {
            $this->assertSame(['name', 'path', 'owner', 'writable'], array_keys($calendar), $reply->describe());
            $this->assertSame(Fixtures::ALICE, $calendar['owner'], 'every calendar of this answer belongs to its caller');
            $this->assertStringStartsWith('/remote.php/dav/calendars/' . Fixtures::ALICE . '/', (string)$calendar['path'],
                $reply->describe());
        }

        $personal = '/remote.php/dav/calendars/' . Fixtures::ALICE . '/personal/';
        $this->assertContains($personal, array_map(static fn (array $c): string => (string)$c['path'], $calendars),
            'the personal calendar of the caller, created by the server outside the tools');
    }
}
