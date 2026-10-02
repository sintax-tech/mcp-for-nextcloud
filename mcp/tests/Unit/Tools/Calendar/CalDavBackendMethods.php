<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

/**
 * Public methods of the Nextcloud DAV backends that the adapters may call, per Nextcloud major, taken from
 * `apps/dav/lib` at the oldest release of each major appinfo/info.xml declares: v31.0.0, v32.0.0 and v33.0.0.
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
        '31' => [
            'applyShareAcl', 'calendarQuery', 'calendarSearch', 'createCalendar', 'createCalendarObject',
            'createSchedulingObject', 'createSubscription', 'deleteAllBirthdayCalendars', 'deleteAllSharesByUser',
            'deleteCalendar', 'deleteCalendarObject', 'deleteOutdatedSchedulingObjects', 'deleteSchedulingObject',
            'deleteSubscription', 'getCalendarById', 'getCalendarByUri', 'getCalendarObject',
            'getCalendarObjectById', 'getCalendarObjectByUID', 'getCalendarObjects', 'getCalendarsForUser',
            'getCalendarsForUserCount', 'getChangesForCalendar', 'getDeletedCalendarObjects',
            'getDeletedCalendarObjectsByPrincipal', 'getDeletedCalendars', 'getDenormalizedData',
            'getLimitedCalendarObjects', 'getMultipleCalendarObjects', 'getPublicCalendar', 'getPublicCalendars',
            'getPublishStatus', 'getSchedulingObject', 'getSchedulingObjects', 'getShares', 'getSubscriptionById',
            'getSubscriptionsForUser', 'getSubscriptionsForUserCount', 'getUsersOwnCalendars', 'moveCalendar',
            'moveCalendarObject', 'preloadShares', 'pruneOutdatedSyncTokens', 'purgeAllCachedEventsForSubscription',
            'purgeCachedEventsForSubscription', 'restoreCalendar', 'restoreCalendarObject', 'restoreChanges',
            'search', 'searchPrincipalUri', 'setClassification', 'setPublishStatus', 'updateCalendar',
            'updateCalendarObject', 'updateProperties', 'updateShares', 'updateSubscription',
        ],
        '32' => [
            'applyShareAcl', 'calendarQuery', 'calendarSearch', 'createCalendar', 'createCalendarObject',
            'createSchedulingObject', 'createSubscription', 'deleteAllBirthdayCalendars', 'deleteAllSharesByUser',
            'deleteCalendar', 'deleteCalendarObject', 'deleteOutdatedSchedulingObjects', 'deleteSchedulingObject',
            'deleteSubscription', 'exportCalendar', 'getCalendarById', 'getCalendarByUri', 'getCalendarObject',
            'getCalendarObjectById', 'getCalendarObjectByUID', 'getCalendarObjects', 'getCalendarsForUser',
            'getCalendarsForUserCount', 'getChangesForCalendar', 'getDeletedCalendarObjects',
            'getDeletedCalendarObjectsByPrincipal', 'getDeletedCalendars', 'getDenormalizedData',
            'getFederatedCalendarByUri', 'getFederatedCalendarsForUser', 'getLimitedCalendarObjects',
            'getMultipleCalendarObjects', 'getPublicCalendar', 'getPublicCalendars', 'getPublishStatus',
            'getSchedulingObject', 'getSchedulingObjects', 'getShares', 'getSharesByShareePrincipal',
            'getSubscriptionById', 'getSubscriptionByUri', 'getSubscriptionsForUser',
            'getSubscriptionsForUserCount', 'getUsersOwnCalendars', 'moveCalendar', 'moveCalendarObject',
            'preloadPublishStatuses', 'preloadShares', 'pruneOutdatedSyncTokens',
            'purgeAllCachedEventsForSubscription', 'purgeCachedEventsForSubscription', 'restoreCalendar',
            'restoreCalendarObject', 'restoreChanges', 'search', 'searchPrincipalUri', 'setPublishStatus',
            'unshare', 'updateCalendar', 'updateCalendarObject', 'updateProperties', 'updateShares',
            'updateSubscription',
        ],
        '33' => [
            'applyShareAcl', 'calendarQuery', 'calendarSearch', 'createCalendar', 'createCalendarObject',
            'createSchedulingObject', 'createSubscription', 'deleteAllBirthdayCalendars', 'deleteAllSharesByUser',
            'deleteCalendar', 'deleteCalendarObject', 'deleteOutdatedSchedulingObjects', 'deleteSchedulingObject',
            'deleteSubscription', 'exportCalendar', 'getCalendarById', 'getCalendarByUri', 'getCalendarObject',
            'getCalendarObjectById', 'getCalendarObjectByUID', 'getCalendarObjects', 'getCalendarsForUser',
            'getCalendarsForUserCount', 'getChangesForCalendar', 'getDeletedCalendarObjects',
            'getDeletedCalendarObjectsByPrincipal', 'getDeletedCalendars', 'getDenormalizedData',
            'getFederatedCalendarByUri', 'getFederatedCalendarsForUser', 'getLimitedCalendarObjects',
            'getMultipleCalendarObjects', 'getPublicCalendar', 'getPublicCalendars', 'getPublishStatus',
            'getSchedulingObject', 'getSchedulingObjects', 'getShares', 'getSharesByShareePrincipal',
            'getSubscriptionById', 'getSubscriptionByUri', 'getSubscriptionsForUser',
            'getSubscriptionsForUserCount', 'getUsersOwnCalendars', 'moveCalendar', 'moveCalendarObject',
            'preloadPublishStatuses', 'preloadShares', 'pruneOutdatedSyncTokens',
            'purgeAllCachedEventsForSubscription', 'purgeCachedEventsForSubscription', 'restoreCalendar',
            'restoreCalendarObject', 'restoreChanges', 'search', 'searchPrincipalUri', 'setPublishStatus',
            'unshare', 'updateCalendar', 'updateCalendarObject', 'updateProperties', 'updateShares',
            'updateSubscription',
        ],
    ];

    /** @var array<string, list<string>> public methods of OCA\DAV\CardDAV\CardDavBackend, per Nextcloud major */
    public const CARDDAV = [
        '31' => [
            'applyShareAcl', 'collectCardProperties', 'createAddressBook', 'createCard', 'deleteAddressBook',
            'deleteCard', 'getAddressBookById', 'getAddressBooksByUri', 'getAddressBooksForUser',
            'getAddressBooksForUserCount', 'getCard', 'getCardUri', 'getCards', 'getChangesForAddressBook',
            'getContact', 'getMultipleCards', 'getShares', 'getUsersOwnAddressBooks', 'moveCard',
            'pruneOutdatedSyncTokens', 'search', 'searchPrincipalUri', 'updateAddressBook', 'updateCard',
            'updateShares',
        ],
        '32' => [
            'applyShareAcl', 'collectCardProperties', 'createAddressBook', 'createCard', 'deleteAddressBook',
            'deleteAllSharesByUser', 'deleteCard', 'getAddressBookById', 'getAddressBooksByUri',
            'getAddressBooksForUser', 'getAddressBooksForUserCount', 'getCard', 'getCardUri', 'getCards',
            'getChangesForAddressBook', 'getContact', 'getMultipleCards', 'getShares', 'getUsersOwnAddressBooks',
            'moveCard', 'pruneOutdatedSyncTokens', 'search', 'searchPrincipalUri', 'updateAddressBook',
            'updateCard', 'updateShares',
        ],
        '33' => [
            'applyShareAcl', 'collectCardProperties', 'createAddressBook', 'createCard', 'deleteAddressBook',
            'deleteAllSharesByUser', 'deleteCard', 'getAddressBookById', 'getAddressBooksByUri',
            'getAddressBooksForUser', 'getAddressBooksForUserCount', 'getCard', 'getCardUri', 'getCards',
            'getChangesForAddressBook', 'getContact', 'getMultipleCards', 'getShares', 'getUsersOwnAddressBooks',
            'moveCard', 'pruneOutdatedSyncTokens', 'search', 'searchPrincipalUri', 'updateAddressBook',
            'updateCard', 'updateShares',
        ],
    ];
}
