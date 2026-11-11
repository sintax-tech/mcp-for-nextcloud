<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Calendar\Integration\CalendarAvailability;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityParticipants;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityWindow;
use OCA\Mcp\Modules\Calendar\Reading\CheckAvailabilityHandler;
use OCP\Calendar\IManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;

/** Adds the bounded read to the legacy Calendar test fixture without growing its shared setup. */
final class CalendarAvailabilityFixture {
	/** Static fixture only. */
	private function __construct() {}

	/**
	 * @param GrantPolicy $policy unrestricted mock for legacy event/attachment tests; scope barriers have dedicated tests
	 * @param IUserManager&MockObject $users existing local account fixture
	 * @param ContainerInterface&MockObject $container lazy manager boundary
	 * @param IManager $manager existing native availability double
	 * @param list<string> $uids exact account IDs known by the fixture
	 * @return CheckAvailabilityHandler actual read handler over the same mock data as the scheduling warnings
	 */
	public static function handler(GrantPolicy $policy, IUserManager&MockObject $users, ContainerInterface&MockObject $container, IManager $manager, array $uids): CheckAvailabilityHandler {
		$users->method('getByEmail')->willReturnCallback(static fn ($email) => array_values(array_filter(
			array_map(static fn ($uid) => $users->get($uid), $uids),
			static fn ($user) => $user !== null && $user->getEMailAddress() === $email,
		)));
		$container->method('get')->with(IManager::class)->willReturn($manager);
		return new CheckAvailabilityHandler($policy, new AvailabilityWindow(), new AvailabilityParticipants($users), new CalendarAvailability($container));
	}
}
