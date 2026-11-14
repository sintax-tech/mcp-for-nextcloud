<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Calendar\Integration\CalendarAvailability;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityCandidates;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityParticipants;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityQuery;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityWindow;
use OCA\Mcp\Modules\Calendar\Reading\CheckAvailabilityHandler;
use OCA\Mcp\Modules\Calendar\Reading\SuggestTimesHandler;
use OCA\Mcp\Modules\Calendar\Reading\SuggestionInput;
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
		return new CheckAvailabilityHandler(self::query($policy, $users, $container, $manager, $uids), new AvailabilityWindow());
	}

	/**
	 * @param GrantPolicy $policy unrestricted legacy fixture policy
	 * @param IUserManager&MockObject $users existing accounts
	 * @param ContainerInterface&MockObject $container lazy native binding
	 * @param IManager $manager native double
	 * @param list<string> $uids exact local IDs
	 * @return SuggestTimesHandler actual bounded search over the same fixture
	 */
	public static function suggestion(GrantPolicy $policy, IUserManager&MockObject $users, ContainerInterface&MockObject $container, IManager $manager, array $uids): SuggestTimesHandler {
		return new SuggestTimesHandler(self::query($policy, $users, $container, $manager, $uids), new SuggestionInput(new AvailabilityWindow()), new AvailabilityCandidates());
	}

	/**
	 * @param GrantPolicy $policy unrestricted fixture policy
	 * @param IUserManager&MockObject $users existing accounts
	 * @param ContainerInterface&MockObject $container native binding
	 * @param IManager $manager native double
	 * @param list<string> $uids exact local IDs
	 * @return AvailabilityQuery shared read, not a second implementation of free/busy
	 */
	private static function query(GrantPolicy $policy, IUserManager&MockObject $users, ContainerInterface&MockObject $container, IManager $manager, array $uids): AvailabilityQuery {
		$users->method('getByEmail')->willReturnCallback(static fn ($email) => array_values(array_filter(
			array_map(static fn ($uid) => $users->get($uid), $uids),
			static fn ($user) => $user !== null && $user->getEMailAddress() === $email,
		)));
		$container->method('get')->with(IManager::class)->willReturn($manager);
		return new AvailabilityQuery($policy, new AvailabilityParticipants($users), new CalendarAvailability($container));
	}
}
