<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

/**
 * Public methods of the Nextcloud DAV backends that the adapters may call, per Nextcloud major, taken from
 * `apps/dav/lib` at the oldest release of each major appinfo/info.xml declares: v34.0.0 and v35.0.0 (the tested range, independently of info.xml).
 * `nextcloud/ocp` does not ship `OCA\DAV`, so the contract test cannot reflect on the real classes and reads these
 * fixed lists instead.
 *
 * To add a major: fetch its x.0.0 CalDavBackend.php / CardDavBackend.php, list their public methods with
 * `grep -oE "^\s*public function [A-Za-z_]+" FILE | awk '{print $3}' | sort -u`, drop `__construct` and add the lists
 * below. Never add a name that is absent from the oldest release of a major: a method that exists only in a later patch
 * (such as `findCalendarObjectByUid`, added after 33.0.2) must not be used by the adapters. Nextcloud 31 also lacks
 * `unshare`, `exportCalendar` and the federated-calendar methods of CalDavBackend, and `deleteAllSharesByUser` of
 * CardDavBackend.
 */
final class CalDavBackendMethods {
	/** @var array<string, list<string>> public methods of OCA\DAV\CalDAV\CalDavBackend, per Nextcloud major */
	public const CALDAV = [
		'34' => [
			'applyShareAcl', 'calendarQuery', 'calendarSearch', 'createCalendar', 'createCalendarObject',
			'createSchedulingObject', 'createSubscription', 'deleteAllBirthdayCalendars', 'deleteAllSharesByUser',
			'deleteCalendar', 'deleteCalendarObject', 'deleteOutdatedSchedulingObjects', 'deleteSchedulingObject',
			'deleteSubscription', 'exportCalendar', 'getCalendarById', 'getCalendarByUri', 'getCalendarObject',
			'getCalendarObjectById', 'getCalendarObjectByUID', 'getCalendarObjects', 'getCalendarsForUser',
			'getCalendarsForUserCount', 'getChangesForCalendar', 'getDeletedCalendarObjectByIdForPrincipal',
			'getDeletedCalendarObjects', 'getDeletedCalendarObjectsByPrincipal', 'getDeletedCalendars',
			'getDenormalizedData', 'getFederatedCalendarByUri', 'getFederatedCalendarsForUser',
			'getLimitedCalendarObjects', 'getMultipleCalendarObjects', 'getPublicCalendar', 'getPublicCalendars',
			'getPublishStatus', 'getSchedulingObject', 'getSchedulingObjects', 'getShares',
			'getSharesByShareePrincipal', 'getSubscriptionById', 'getSubscriptionByUri', 'getSubscriptionsForUser',
			'getSubscriptionsForUserCount', 'getUsersOwnCalendars', 'moveCalendar', 'moveCalendarObject',
			'preloadPublishStatuses', 'preloadShares', 'pruneOutdatedSyncTokens', 'purgeAllCachedEventsForSubscription',
			'purgeCachedEventsForSubscription', 'restoreCalendar', 'restoreCalendarObject', 'restoreChanges', 'search',
			'searchPrincipalUri', 'setPublishStatus', 'unshare', 'updateCalendar', 'updateCalendarObject',
			'updateProperties', 'updateShares', 'updateSubscription',
		],
		'35' => [
			'applyShareAcl', 'calendarQuery', 'calendarSearch', 'createCalendar', 'createCalendarObject',
			'createSchedulingObject', 'createSubscription', 'deleteAllBirthdayCalendars', 'deleteAllSharesByUser',
			'deleteCalendar', 'deleteCalendarObject', 'deleteOutdatedSchedulingObjects', 'deleteSchedulingObject',
			'deleteSubscription', 'exportCalendar', 'findCalendarObjectByUid', 'getCalendarById', 'getCalendarByUri',
			'getCalendarObject', 'getCalendarObjectById', 'getCalendarObjectByUID', 'getCalendarObjects',
			'getCalendarsForUser', 'getCalendarsForUserCount', 'getChangesForCalendar',
			'getDeletedCalendarObjectByIdForPrincipal', 'getDeletedCalendarObjects',
			'getDeletedCalendarObjectsByPrincipal', 'getDeletedCalendars', 'getDenormalizedData',
			'getFederatedCalendarByUri', 'getFederatedCalendarsForUser', 'getLimitedCalendarObjects',
			'getMultipleCalendarObjects', 'getPublicCalendar', 'getPublicCalendars', 'getPublishStatus',
			'getSchedulingObject', 'getSchedulingObjects', 'getShares', 'getSharesByShareePrincipal',
			'getSubscriptionById', 'getSubscriptionByUri', 'getSubscriptionsForUser', 'getSubscriptionsForUserCount',
			'getUsersOwnCalendars', 'moveCalendar', 'moveCalendarObject', 'preloadPublishStatuses', 'preloadShares',
			'pruneOutdatedSyncTokens', 'purgeAllCachedEventsForSubscription', 'purgeCachedEventsForSubscription',
			'restoreCalendar', 'restoreCalendarObject', 'restoreChanges', 'search', 'searchPrincipalUri',
			'setPublishStatus', 'unshare', 'updateCalendar', 'updateCalendarObject', 'updateProperties', 'updateShares',
			'updateSubscription',
		],
	];

	/** @var array<string, list<string>> public methods of OCA\DAV\CardDAV\CardDavBackend, per Nextcloud major */
	public const CARDDAV = [
		'34' => [
			'applyShareAcl', 'collectCardProperties', 'createAddressBook', 'createCard', 'deleteAddressBook',
			'deleteAllSharesByUser', 'deleteCard', 'getAddressBookById', 'getAddressBooksByUri',
			'getAddressBooksForUser', 'getAddressBooksForUserCount', 'getCard', 'getCardUri', 'getCards',
			'getChangesForAddressBook', 'getContact', 'getMultipleCards', 'getPendingCards', 'getShares',
			'getUsersOwnAddressBooks', 'markCardsAsPending', 'moveCard', 'pruneOutdatedSyncTokens', 'search',
			'searchPrincipalUri', 'updateAddressBook', 'updateCard', 'updateShares',
		],
		'35' => [
			'applyShareAcl', 'collectCardProperties', 'createAddressBook', 'createCard', 'deleteAddressBook',
			'deleteAllSharesByUser', 'deleteCard', 'getAddressBookById', 'getAddressBooksByUri',
			'getAddressBooksForUser', 'getAddressBooksForUserCount', 'getCard', 'getCardByUid', 'getCardUri',
			'getCards', 'getChangesForAddressBook', 'getContact', 'getMultipleCards', 'getPendingCards', 'getShares',
			'getUsersOwnAddressBooks', 'markCardsAsPending', 'moveCard', 'pruneOutdatedSyncTokens', 'search',
			'searchPrincipalUri', 'updateAddressBook', 'updateCard', 'updateShares',
		],
	];
}
