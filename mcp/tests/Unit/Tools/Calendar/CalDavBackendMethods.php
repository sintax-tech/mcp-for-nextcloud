<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

/**
 * Public methods of the Nextcloud DAV backends that the adapters may call, taken from `apps/dav/lib` at tag v33.0.2
 * (the oldest Nextcloud 33 release in production). `nextcloud/ocp` does not ship `OCA\DAV`, so the contract test
 * cannot reflect on the real classes and reads this fixed list instead.
 *
 * To update it after a Nextcloud bump: fetch the new CalDavBackend.php / CardDavBackend.php, list their public
 * methods with `grep -oE "^\s*public function [A-Za-z_]+" FILE | awk '{print $3}' | sort -u`, drop `__construct`
 * and replace the lists below. Never add a name that is absent from the oldest supported release: a method that
 * exists only in a later 33.x patch (such as `findCalendarObjectByUid`, added after 33.0.2) must not be used by the
 * adapters.
 */
final class CalDavBackendMethods {
    /** @var list<string> public methods of OCA\DAV\CalDAV\CalDavBackend at v33.0.2 */
    public const CALDAV = [
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
        'getSubscriptionById', 'getSubscriptionByUri', 'getSubscriptionsForUser', 'getSubscriptionsForUserCount',
        'getUsersOwnCalendars', 'moveCalendar', 'moveCalendarObject', 'preloadPublishStatuses', 'preloadShares',
        'pruneOutdatedSyncTokens', 'purgeAllCachedEventsForSubscription', 'purgeCachedEventsForSubscription',
        'restoreCalendar', 'restoreCalendarObject', 'restoreChanges', 'search', 'searchPrincipalUri',
        'setPublishStatus', 'unshare', 'updateCalendar', 'updateCalendarObject', 'updateProperties', 'updateShares',
        'updateSubscription',
    ];

    /** @var list<string> public methods of OCA\DAV\CardDAV\CardDavBackend at v33.0.2 */
    public const CARDDAV = [
        'applyShareAcl', 'collectCardProperties', 'createAddressBook', 'createCard', 'deleteAddressBook',
        'deleteAllSharesByUser', 'deleteCard', 'getAddressBookById', 'getAddressBooksByUri',
        'getAddressBooksForUser', 'getAddressBooksForUserCount', 'getCard', 'getCardUri', 'getCards',
        'getChangesForAddressBook', 'getContact', 'getMultipleCards', 'getShares', 'getUsersOwnAddressBooks',
        'moveCard', 'pruneOutdatedSyncTokens', 'search', 'searchPrincipalUri', 'updateAddressBook', 'updateCard',
        'updateShares',
    ];
}
