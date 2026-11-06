<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use OCA\Mcp\Modules\Talk\ReferenceLinker;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckCardReference;
use OCA\Mcp\Modules\Calendar\CalendarEventReference;
use OCA\Mcp\Modules\Calendar\Locating\{ScopedCalendarAccess,CalendarStore,EventRepository,Classification};
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\IURLGenerator;

/** Composes real owning-module adapters around the exact collaborators of the original linker tests. */
final class ReferenceLinkers {
	/**
	 * @param DeckGatewayInterface $deck original test collaborator, preserved without substitution
	 * @param ScopedCalendarAccess $calendars original test collaborator, preserved without substitution
	 * @param CalendarStore $calendarStore original test collaborator, preserved without substitution
	 * @param EventRepository $events original test collaborator, preserved without substitution
	 * @param Classification $classification original test collaborator, preserved without substitution
	 * @param IAppManager $appManager original test collaborator, preserved without substitution
	 * @param IUserManager $userManager original test collaborator, preserved without substitution
	 * @param GrantPolicy $grants original test collaborator, preserved without substitution
	 * @param IURLGenerator $urlGenerator original test collaborator, preserved without substitution
	 * @param GuestRestrictions|null $guests original test collaborator, preserved without substitution
	 * @return ReferenceLinker module composed from the original test collaborators
	 */
	public static function build(
		DeckGatewayInterface $deck,
		ScopedCalendarAccess $calendars,
		CalendarStore $calendarStore,
		EventRepository $events,
		Classification $classification,
		IAppManager $appManager,
		IUserManager $userManager,
		GrantPolicy $grants,
		IURLGenerator $urlGenerator,
		?GuestRestrictions $guests = null,
	): ReferenceLinker {
		return new ReferenceLinker(new DeckCardReference($deck), new CalendarEventReference($calendars, $calendarStore, $events, $classification),
			$appManager, $userManager, $grants, $urlGenerator, $guests);
	}
}
