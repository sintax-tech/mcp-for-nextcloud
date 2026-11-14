<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar;

use OCA\Mcp\Access\ResourceScope\NativeCalendars;
use OCA\Mcp\Mcp\Tools\ModuleRegistration;
use OCA\Mcp\Modules\Calendar\Integration\DavCalendarSelftestReader;
use OCA\Mcp\Modules\Calendar\Integration\DavCalendarStore;
use OCA\Mcp\Modules\Calendar\Integration\EmbeddedCalDavServerFactory;
use OCA\Mcp\Modules\Calendar\Locating\CalendarStore;
use OCA\Mcp\Modules\Calendar\Selftest\CalendarSelftestReader;
use OCA\Mcp\Modules\Calendar\Writing\CalendarDav;
use OCA\Mcp\Modules\Calendar\Writing\EmbeddedDavDispatcher;
use OCA\Mcp\Platform\Dav\Session;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The DI of the Calendar module. Its tools and work classes are built by the container from their constructors; what
 * needs a binding is the calendar enumeration Access declares, the two ports the module writes and reads CalDAV
 * through, the dispatcher that builds one embedded CalDAV server per operation, and the reader of the optional
 * selftest, and the event step of a meeting. The module has no capability probe.
 */
final class CalendarRegistration implements ModuleRegistration {
	/**
	 * {@inheritDoc}
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(\OCA\Mcp\Modules\Calendar\Integration\CalendarAvailability::class, static fn (ContainerInterface $c): \OCA\Mcp\Modules\Calendar\Integration\CalendarAvailability => new \OCA\Mcp\Modules\Calendar\Integration\CalendarAvailability($c));
		$context->registerService(NativeCalendars::class, static fn (ContainerInterface $c): NativeCalendars => $c->get(NativeCalendarsAdapter::class));
		$context->registerService(CalendarSelftestReader::class, static fn (ContainerInterface $c): CalendarSelftestReader => new DavCalendarSelftestReader($c));
		$context->registerService(CalendarDav::class, static fn (ContainerInterface $c): CalendarDav => $c->get(EmbeddedDavDispatcher::class));
		// DavCalendarStore resolves CalDavBackend lazily (the app container falls back to the server one) on first use.
		$context->registerService(CalendarStore::class, static fn (ContainerInterface $c): CalendarStore => new DavCalendarStore($c));
		// The dispatcher builds one embedded CalDAV server per operation, so building the module never
		// loads the DAV app; a DAV app that fails to load only breaks the calendar writes.
		$context->registerService(EmbeddedDavDispatcher::class, static fn (ContainerInterface $c): EmbeddedDavDispatcher => new EmbeddedDavDispatcher(
			// The non-public embedded server, the one that takes a principal from CustomPrincipalPlugin instead of
			// answering with principals/system/public; the factory knows the Nextcloud 31 class as well.
			static fn (): \Sabre\DAV\Server => (new EmbeddedCalDavServerFactory($c))->create(),
			new Session($c->get(IUserSession::class)),
			$c->get(IConfig::class),
			$c->get(LoggerInterface::class),
		));
		$context->registerService(\OCA\Mcp\Platform\Dav\CalDavObjects::class, static fn (ContainerInterface $c): \OCA\Mcp\Platform\Dav\CalDavObjects => $c->get(CalendarStore::class));
		$context->registerService(\OCA\Mcp\Platform\Dav\CalDavWrite::class, static fn (ContainerInterface $c): \OCA\Mcp\Platform\Dav\CalDavWrite => $c->get(CalendarDav::class));
		$context->registerService(\OCA\Mcp\Access\ResourceScope\ScopedCalendarLookup::class, static fn (ContainerInterface $c): \OCA\Mcp\Access\ResourceScope\ScopedCalendarLookup => $c->get(\OCA\Mcp\Modules\Calendar\Locating\ScopedCalendarAccess::class));
		$context->registerService(\OCA\Mcp\Access\References\ReadableCalendarEvent::class, static fn (ContainerInterface $c): \OCA\Mcp\Access\References\ReadableCalendarEvent => $c->get(CalendarEventReference::class));
		$context->registerService(\OCA\Mcp\Platform\Meetings\MeetingCalendar::class, static fn (ContainerInterface $c): \OCA\Mcp\Platform\Meetings\MeetingCalendar => $c->get(CalendarMeetingSteps::class));
		$context->registerService(\OCA\Mcp\Platform\Meetings\MeetingEventRead::class, static fn (ContainerInterface $c): \OCA\Mcp\Platform\Meetings\MeetingEventRead => new CalendarMeetingEvents(
			$c->get(\OCA\Mcp\Mcp\Tools\Discovery\ToolAccess::class),
			$c->get(\OCA\Mcp\Modules\Calendar\Reading\ListEventsHandler::class),
			$c->get(\OCA\Mcp\Modules\Calendar\Integration\MeetingEventSupplement::class),
		));
	}

	/**
	 * @return list<class-string<\OCA\Mcp\Mcp\Tools\CapabilityProbe>> none: every Calendar tool exists wherever the app does
	 */
	public function probes(): array {
		return [];
	}
}
