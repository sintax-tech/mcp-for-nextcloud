<?php
/*
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/CalDAV/CalDavBackend.php
// SHA-256: 224f3a0f8fb2293cf84b9a963d3fa1b14ce0addc95479a45b96f011ae1ad1aac

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\DAV\CalDAV;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use OCA\DAV\AppInfo\Application;
use OCA\DAV\CalDAV\Federation\FederatedCalendarEntity;
use OCA\DAV\CalDAV\Federation\FederatedCalendarMapper;
use OCA\DAV\CalDAV\Sharing\Backend;
use OCA\DAV\Connector\Sabre\Principal;
use OCA\DAV\DAV\Sharing\IShareable;
use OCA\DAV\Events\CachedCalendarObjectCreatedEvent;
use OCA\DAV\Events\CachedCalendarObjectDeletedEvent;
use OCA\DAV\Events\CachedCalendarObjectUpdatedEvent;
use OCA\DAV\Events\CalendarCreatedEvent;
use OCA\DAV\Events\CalendarDeletedEvent;
use OCA\DAV\Events\CalendarMovedToTrashEvent;
use OCA\DAV\Events\CalendarPublishedEvent;
use OCA\DAV\Events\CalendarRestoredEvent;
use OCA\DAV\Events\CalendarShareUpdatedEvent;
use OCA\DAV\Events\CalendarUnpublishedEvent;
use OCA\DAV\Events\CalendarUpdatedEvent;
use OCA\DAV\Events\SubscriptionCreatedEvent;
use OCA\DAV\Events\SubscriptionDeletedEvent;
use OCA\DAV\Events\SubscriptionUpdatedEvent;
use OCP\AppFramework\Db\TTransactional;
use OCP\Calendar\CalendarExportOptions;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\Calendar\Events\CalendarObjectRestoredEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\Calendar\Exceptions\CalendarException;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Sabre\CalDAV\Backend\AbstractBackend;
use Sabre\CalDAV\Backend\SchedulingSupport;
use Sabre\CalDAV\Backend\SubscriptionSupport;
use Sabre\CalDAV\Backend\SyncSupport;
use Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\PropPatch;
use Sabre\Uri;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VTimeZone;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\InvalidDataException;
use Sabre\VObject\ParseException;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\MaxInstancesExceededException;
use Sabre\VObject\Recur\NoInstancesException;
use function array_column;
use function array_map;
use function array_merge;
use function array_values;
use function explode;
use function is_array;
use function is_resource;
use function pathinfo;
use function rewind;
use function settype;
use function sprintf;
use function str_replace;
use function strtolower;
use function time;
/**
 * Class CalDavBackend
 *
 * Code is heavily inspired by https://github.com/fruux/sabre-dav/blob/master/lib/CalDAV/Backend/PDO.php
 *
 * @package OCA\DAV\CalDAV
 *
 * @psalm-type CalendarInfo = array{
 *     id: int,
 *     uri: string,
 *     principaluri: string,
 *     '{http://calendarserver.org/ns/}getctag': string,
 *     '{http://sabredav.org/ns}sync-token': int,
 *     '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set': \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet,
 *     '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp': \Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp,
 *     '{DAV:}displayname': string,
 *     '{urn:ietf:params:xml:ns:caldav}calendar-timezone': ?string,
 *     '{http://nextcloud.com/ns}owner-displayname': string,
 * }
 */
class CalDavBackend extends AbstractBackend implements SyncSupport, SubscriptionSupport, SchedulingSupport
{
    use TTransactional;
    public const CALENDAR_TYPE_CALENDAR = 0;
    public const CALENDAR_TYPE_SUBSCRIPTION = 1;
    public const CALENDAR_TYPE_FEDERATED = 2;
    public const PERSONAL_CALENDAR_URI = 'personal';
    public const PERSONAL_CALENDAR_NAME = 'Personal';
    public const RESOURCE_BOOKING_CALENDAR_URI = 'calendar';
    public const RESOURCE_BOOKING_CALENDAR_NAME = 'Calendar';
    /**
     * We need to specify a max date, because we need to stop *somewhere*
     *
     * On 32 bit system the maximum for a signed integer is 2147483647, so
     * MAX_DATE cannot be higher than date('Y-m-d', 2147483647) which results
     * in 2038-01-19 to avoid problems when the date is converted
     * to a unix timestamp.
     */
    public const MAX_DATE = '2038-01-01';
    public const ACCESS_PUBLIC = 4;
    public const CLASSIFICATION_PUBLIC = 0;
    public const CLASSIFICATION_PRIVATE = 1;
    public const CLASSIFICATION_CONFIDENTIAL = 2;
    /**
     * List of CalDAV properties, and how they map to database field names and their type
     * Add your own properties by simply adding on to this array.
     *
     * @var array
     * @psalm-var array<string, string[]>
     */
    public array $propertyMap = ['{DAV:}displayname' => ['displayname', 'string'], '{urn:ietf:params:xml:ns:caldav}calendar-description' => ['description', 'string'], '{urn:ietf:params:xml:ns:caldav}calendar-timezone' => ['timezone', 'string'], '{http://apple.com/ns/ical/}calendar-order' => ['calendarorder', 'int'], '{http://apple.com/ns/ical/}calendar-color' => ['calendarcolor', 'string'], '{' . \OCA\DAV\DAV\Sharing\Plugin::NS_NEXTCLOUD . '}deleted-at' => ['deleted_at', 'int']];
    /**
     * List of subscription properties, and how they map to database field names.
     *
     * @var array
     */
    public array $subscriptionPropertyMap = ['{DAV:}displayname' => ['displayname', 'string'], '{http://apple.com/ns/ical/}refreshrate' => ['refreshrate', 'string'], '{http://apple.com/ns/ical/}calendar-order' => ['calendarorder', 'int'], '{http://apple.com/ns/ical/}calendar-color' => ['calendarcolor', 'string'], '{http://calendarserver.org/ns/}subscribed-strip-todos' => ['striptodos', 'bool'], '{http://calendarserver.org/ns/}subscribed-strip-alarms' => ['stripalarms', 'string'], '{http://calendarserver.org/ns/}subscribed-strip-attachments' => ['stripattachments', 'string']];
    /**
     * properties to index
     *
     * This list has to be kept in sync with ICalendarQuery::SEARCH_PROPERTY_*
     *
     * @see \OCP\Calendar\ICalendarQuery
     */
    private const INDEXED_PROPERTIES = ['CATEGORIES', 'COMMENT', 'DESCRIPTION', 'LOCATION', 'RESOURCES', 'STATUS', 'SUMMARY', 'ATTENDEE', 'CONTACT', 'ORGANIZER'];
    /** @var array parameters to index */
    public static array $indexParameters = ['ATTENDEE' => ['CN'], 'ORGANIZER' => ['CN']];
    /**
     * @var string[] Map of uid => display name
     */
    protected array $userDisplayNames;
    private string $dbObjectsTable = 'calendarobjects';
    private string $dbObjectPropertiesTable = 'calendarobjects_props';
    private string $dbObjectInvitationsTable = 'calendar_invitations';
    private array $cachedObjects = [];
    private readonly ICache $publishStatusCache;
    public function __construct(private IDBConnection $db, private Principal $principalBackend, private IUserManager $userManager, private ISecureRandom $random, private LoggerInterface $logger, private IEventDispatcher $dispatcher, private IConfig $config, private Sharing\Backend $calendarSharingBackend, private FederatedCalendarMapper $federatedCalendarMapper, ICacheFactory $cacheFactory, private bool $legacyEndpoint = false)
    {
    }
    /**
     * Return the number of calendars owned by the given principal.
     *
     * Calendars shared with the given principal are not counted!
     *
     * By default, this excludes the automatically generated birthday calendar.
     */
    public function getCalendarsForUserCount(string $principalUri, bool $excludeBirthday = true): int
    {
    }
    /**
     * Return the number of subscriptions for a principal
     */
    public function getSubscriptionsForUserCount(string $principalUri): int
    {
    }
    /**
     * @return array{id: int, deleted_at: int}[]
     */
    public function getDeletedCalendars(int $deletedBefore): array
    {
    }
    /**
     * Returns a list of calendars for a principal.
     *
     * Every project is an array with the following keys:
     *  * id, a unique id that will be used by other functions to modify the
     *    calendar. This can be the same as the uri or a database key.
     *  * uri, which the basename of the uri with which the calendar is
     *    accessed.
     *  * principaluri. The owner of the calendar. Almost always the same as
     *    principalUri passed to this method.
     *
     * Furthermore it can contain webdav properties in clark notation. A very
     * common one is '{DAV:}displayname'.
     *
     * Many clients also require:
     * {urn:ietf:params:xml:ns:caldav}supported-calendar-component-set
     * For this property, you can just return an instance of
     * Sabre\CalDAV\Property\SupportedCalendarComponentSet.
     *
     * If you return {http://sabredav.org/ns}read-only and set the value to 1,
     * ACL will automatically be put in read-only mode.
     *
     * @param string $principalUri
     * @return array
     */
    public function getCalendarsForUser($principalUri)
    {
    }
    /**
     * @param $principalUri
     * @return array
     */
    public function getUsersOwnCalendars($principalUri)
    {
    }
    /**
     * @return array
     */
    public function getPublicCalendars()
    {
    }
    /**
     * @param string $uri
     * @return array
     * @throws NotFound
     */
    public function getPublicCalendar($uri)
    {
    }
    /**
     * @param string $principal
     * @param string $uri
     * @return array|null
     */
    public function getCalendarByUri($principal, $uri)
    {
    }
    /**
     * @psalm-return CalendarInfo|null
     * @return array|null
     */
    public function getCalendarById(int $calendarId): ?array
    {
    }
    /**
     * @param $subscriptionId
     */
    public function getSubscriptionById($subscriptionId)
    {
    }
    public function getSubscriptionByUri(string $principal, string $uri): ?array
    {
    }
    /**
     * Creates a new calendar for a principal.
     *
     * If the creation was a success, an id must be returned that can be used to reference
     * this calendar in other methods, such as updateCalendar.
     *
     * @param string $principalUri
     * @param string $calendarUri
     * @param array $properties
     * @return int
     *
     * @throws CalendarException
     */
    public function createCalendar($principalUri, $calendarUri, array $properties)
    {
    }
    /**
     * Updates properties for a calendar.
     *
     * The list of mutations is stored in a Sabre\DAV\PropPatch object.
     * To do the actual updates, you must tell this object which properties
     * you're going to process with the handle() method.
     *
     * Calling the handle method is like telling the PropPatch object "I
     * promise I can handle updating this property".
     *
     * Read the PropPatch documentation for more info and examples.
     *
     * @param mixed $calendarId
     * @param PropPatch $propPatch
     * @return void
     */
    public function updateCalendar($calendarId, PropPatch $propPatch)
    {
    }
    /**
     * Delete a calendar and all it's objects
     *
     * @param mixed $calendarId
     * @return void
     */
    public function deleteCalendar($calendarId, bool $forceDeletePermanently = false)
    {
    }
    public function restoreCalendar(int $id): void
    {
    }
    /**
     * Returns all calendar entries as a stream of data
     *
     * @since 32.0.0
     *
     * @return Generator<array>
     */
    public function exportCalendar(int $calendarId, int $calendarType = self::CALENDAR_TYPE_CALENDAR, ?CalendarExportOptions $options = null): Generator
    {
    }
    /**
     * Returns all calendar objects with limited metadata for a calendar
     *
     * Every item contains an array with the following keys:
     *   * id - the table row id
     *   * etag - An arbitrary string
     *   * uri - a unique key which will be used to construct the uri. This can
     *     be any arbitrary string.
     *   * calendardata - The iCalendar-compatible calendar data
     *
     * @param mixed $calendarId
     * @param int $calendarType
     * @return array
     */
    public function getLimitedCalendarObjects(int $calendarId, int $calendarType = self::CALENDAR_TYPE_CALENDAR, array $fields = []): array
    {
    }
    /**
     * Delete all of an user's shares
     *
     * @param string $principaluri
     * @return void
     */
    public function deleteAllSharesByUser($principaluri)
    {
    }
    /**
     * Returns all calendar objects within a calendar.
     *
     * Every item contains an array with the following keys:
     *   * calendardata - The iCalendar-compatible calendar data
     *   * uri - a unique key which will be used to construct the uri. This can
     *     be any arbitrary string, but making sure it ends with '.ics' is a
     *     good idea. This is only the basename, or filename, not the full
     *     path.
     *   * lastmodified - a timestamp of the last modification time
     *   * etag - An arbitrary string, surrounded by double-quotes. (e.g.:
     *   '"abcdef"')
     *   * size - The size of the calendar objects, in bytes.
     *   * component - optional, a string containing the type of object, such
     *     as 'vevent' or 'vtodo'. If specified, this will be used to populate
     *     the Content-Type header.
     *
     * Note that the etag is optional, but it's highly encouraged to return for
     * speed reasons.
     *
     * The calendardata is also optional. If it's not returned
     * 'getCalendarObject' will be called later, which *is* expected to return
     * calendardata.
     *
     * If neither etag or size are specified, the calendardata will be
     * used/fetched to determine these numbers. If both are specified the
     * amount of times this is needed is reduced by a great degree.
     *
     * @param mixed $calendarId
     * @param int $calendarType
     * @return array
     */
    public function getCalendarObjects($calendarId, $calendarType = self::CALENDAR_TYPE_CALENDAR): array
    {
    }
    public function getDeletedCalendarObjects(int $deletedBefore): array
    {
    }
    /**
     * Return all deleted calendar objects by the given principal that are not
     * in deleted calendars.
     *
     * @param string $principalUri
     * @return array
     * @throws Exception
     */
    public function getDeletedCalendarObjectsByPrincipal(string $principalUri): array
    {
    }
    /**
     * Returns information from a single calendar object, based on it's object
     * uri.
     *
     * The object uri is only the basename, or filename and not a full path.
     *
     * The returned array must have the same keys as getCalendarObjects. The
     * 'calendardata' object is required here though, while it's not required
     * for getCalendarObjects.
     *
     * This method must return null if the object did not exist.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @param int $calendarType
     * @return array|null
     */
    public function getCalendarObject($calendarId, $objectUri, int $calendarType = self::CALENDAR_TYPE_CALENDAR)
    {
    }
    /**
     * Returns a list of calendar objects.
     *
     * This method should work identical to getCalendarObject, but instead
     * return all the calendar objects in the list as an array.
     *
     * If the backend supports this, it may allow for some speed-ups.
     *
     * @param mixed $calendarId
     * @param string[] $uris
     * @param int $calendarType
     * @return array
     */
    public function getMultipleCalendarObjects($calendarId, array $uris, $calendarType = self::CALENDAR_TYPE_CALENDAR): array
    {
    }
    /**
     * Creates a new calendar object.
     *
     * The object uri is only the basename, or filename and not a full path.
     *
     * It is possible return an etag from this function, which will be used in
     * the response to this PUT request. Note that the ETag must be surrounded
     * by double-quotes.
     *
     * However, you should only really return this ETag if you don't mangle the
     * calendar-data. If the result of a subsequent GET to this object is not
     * the exact same as this request body, you should omit the ETag.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @param string $calendarData
     * @param int $calendarType
     * @return string
     */
    public function createCalendarObject($calendarId, $objectUri, $calendarData, $calendarType = self::CALENDAR_TYPE_CALENDAR)
    {
    }
    /**
     * Updates an existing calendarobject, based on it's uri.
     *
     * The object uri is only the basename, or filename and not a full path.
     *
     * It is possible return an etag from this function, which will be used in
     * the response to this PUT request. Note that the ETag must be surrounded
     * by double-quotes.
     *
     * However, you should only really return this ETag if you don't mangle the
     * calendar-data. If the result of a subsequent GET to this object is not
     * the exact same as this request body, you should omit the ETag.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @param string $calendarData
     * @param int $calendarType
     * @return string
     */
    public function updateCalendarObject($calendarId, $objectUri, $calendarData, $calendarType = self::CALENDAR_TYPE_CALENDAR)
    {
    }
    /**
     * Moves a calendar object from calendar to calendar.
     *
     * @param string $sourcePrincipalUri
     * @param int $sourceObjectId
     * @param string $targetPrincipalUri
     * @param int $targetCalendarId
     * @param string $tragetObjectUri
     * @param int $calendarType
     * @return bool
     * @throws Exception
     */
    public function moveCalendarObject(string $sourcePrincipalUri, int $sourceObjectId, string $targetPrincipalUri, int $targetCalendarId, string $tragetObjectUri, int $calendarType = self::CALENDAR_TYPE_CALENDAR): bool
    {
    }
    /**
     * Deletes an existing calendar object.
     *
     * The object uri is only the basename, or filename and not a full path.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @param int $calendarType
     * @param bool $forceDeletePermanently
     * @return void
     */
    public function deleteCalendarObject($calendarId, $objectUri, $calendarType = self::CALENDAR_TYPE_CALENDAR, bool $forceDeletePermanently = false)
    {
    }
    /**
     * @param mixed $objectData
     *
     * @throws Forbidden
     */
    public function restoreCalendarObject(array $objectData): void
    {
    }
    /**
     * Performs a calendar-query on the contents of this calendar.
     *
     * The calendar-query is defined in RFC4791 : CalDAV. Using the
     * calendar-query it is possible for a client to request a specific set of
     * object, based on contents of iCalendar properties, date-ranges and
     * iCalendar component types (VTODO, VEVENT).
     *
     * This method should just return a list of (relative) urls that match this
     * query.
     *
     * The list of filters are specified as an array. The exact array is
     * documented by Sabre\CalDAV\CalendarQueryParser.
     *
     * Note that it is extremely likely that getCalendarObject for every path
     * returned from this method will be called almost immediately after. You
     * may want to anticipate this to speed up these requests.
     *
     * This method provides a default implementation, which parses *all* the
     * iCalendar objects in the specified calendar.
     *
     * This default may well be good enough for personal use, and calendars
     * that aren't very large. But if you anticipate high usage, big calendars
     * or high loads, you are strongly advised to optimize certain paths.
     *
     * The best way to do so is override this method and to optimize
     * specifically for 'common filters'.
     *
     * Requests that are extremely common are:
     *   * requests for just VEVENTS
     *   * requests for just VTODO
     *   * requests with a time-range-filter on either VEVENT or VTODO.
     *
     * ..and combinations of these requests. It may not be worth it to try to
     * handle every possible situation and just rely on the (relatively
     * easy to use) CalendarQueryValidator to handle the rest.
     *
     * Note that especially time-range-filters may be difficult to parse. A
     * time-range filter specified on a VEVENT must for instance also handle
     * recurrence rules correctly.
     * A good example of how to interpret all these filters can also simply
     * be found in Sabre\CalDAV\CalendarQueryFilter. This class is as correct
     * as possible, so it gives you a good idea on what type of stuff you need
     * to think of.
     *
     * @param mixed $calendarId
     * @param array $filters
     * @param int $calendarType
     * @return array
     */
    public function calendarQuery($calendarId, array $filters, $calendarType = self::CALENDAR_TYPE_CALENDAR): array
    {
    }
    /**
     * custom Nextcloud search extension for CalDAV
     *
     * TODO - this should optionally cover cached calendar objects as well
     *
     * @param string $principalUri
     * @param array $filters
     * @param integer|null $limit
     * @param integer|null $offset
     * @return array
     */
    public function calendarSearch($principalUri, array $filters, $limit = null, $offset = null)
    {
    }
    /**
     * used for Nextcloud's calendar API
     *
     * @param array $calendarInfo
     * @param string $pattern
     * @param array $searchProperties
     * @param array $options
     * @param integer|null $limit
     * @param integer|null $offset
     *
     * @return array
     */
    public function search(array $calendarInfo, $pattern, array $searchProperties, array $options, $limit, $offset)
    {
    }
    /**
     * @param string $principalUri
     * @param string $pattern
     * @param array $componentTypes
     * @param array $searchProperties
     * @param array $searchParameters
     * @param array $options
     * @return array
     */
    public function searchPrincipalUri(string $principalUri, string $pattern, array $componentTypes, array $searchProperties, array $searchParameters, array $options = []): array
    {
    }
    /**
     * Searches through all of a users calendars and calendar objects to find
     * an object with a specific UID.
     *
     * This method should return the path to this object, relative to the
     * calendar home, so this path usually only contains two parts:
     *
     * calendarpath/objectpath.ics
     *
     * If the uid is not found, return null.
     *
     * This method should only consider * objects that the principal owns, so
     * any calendars owned by other principals that also appear in this
     * collection should be ignored.
     *
     * @param string $principalUri
     * @param string $uid
     * @return string|null
     */
    public function getCalendarObjectByUID($principalUri, $uid, $calendarUri = null)
    {
    }
    public function getCalendarObjectById(string $principalUri, int $id): ?array
    {
    }
    /**
     * The getChanges method returns all the changes that have happened, since
     * the specified syncToken in the specified calendar.
     *
     * This function should return an array, such as the following:
     *
     * [
     *   'syncToken' => 'The current synctoken',
     *   'added'   => [
     *      'new.txt',
     *   ],
     *   'modified'   => [
     *      'modified.txt',
     *   ],
     *   'deleted' => [
     *      'foo.php.bak',
     *      'old.txt'
     *   ]
     * );
     *
     * The returned syncToken property should reflect the *current* syncToken
     * of the calendar, as reported in the {http://sabredav.org/ns}sync-token
     * property This is * needed here too, to ensure the operation is atomic.
     *
     * If the $syncToken argument is specified as null, this is an initial
     * sync, and all members should be reported.
     *
     * The modified property is an array of nodenames that have changed since
     * the last token.
     *
     * The deleted property is an array with nodenames, that have been deleted
     * from collection.
     *
     * The $syncLevel argument is basically the 'depth' of the report. If it's
     * 1, you only have to report changes that happened only directly in
     * immediate descendants. If it's 2, it should also include changes from
     * the nodes below the child collections. (grandchildren)
     *
     * The $limit argument allows a client to specify how many results should
     * be returned at most. If the limit is not specified, it should be treated
     * as infinite.
     *
     * If the limit (infinite or not) is higher than you're willing to return,
     * you should throw a Sabre\DAV\Exception\TooMuchMatches() exception.
     *
     * If the syncToken is expired (due to data cleanup) or unknown, you must
     * return null.
     *
     * The limit is 'suggestive'. You are free to ignore it.
     *
     * @param string $calendarId
     * @param string $syncToken
     * @param int $syncLevel
     * @param int|null $limit
     * @param int $calendarType
     * @return ?array
     */
    public function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null, $calendarType = self::CALENDAR_TYPE_CALENDAR)
    {
    }
    /**
     * Returns a list of subscriptions for a principal.
     *
     * Every subscription is an array with the following keys:
     *  * id, a unique id that will be used by other functions to modify the
     *    subscription. This can be the same as the uri or a database key.
     *  * uri. This is just the 'base uri' or 'filename' of the subscription.
     *  * principaluri. The owner of the subscription. Almost always the same as
     *    principalUri passed to this method.
     *
     * Furthermore, all the subscription info must be returned too:
     *
     * 1. {DAV:}displayname
     * 2. {http://apple.com/ns/ical/}refreshrate
     * 3. {http://calendarserver.org/ns/}subscribed-strip-todos (omit if todos
     *    should not be stripped).
     * 4. {http://calendarserver.org/ns/}subscribed-strip-alarms (omit if alarms
     *    should not be stripped).
     * 5. {http://calendarserver.org/ns/}subscribed-strip-attachments (omit if
     *    attachments should not be stripped).
     * 6. {http://calendarserver.org/ns/}source (Must be a
     *     Sabre\DAV\Property\Href).
     * 7. {http://apple.com/ns/ical/}calendar-color
     * 8. {http://apple.com/ns/ical/}calendar-order
     * 9. {urn:ietf:params:xml:ns:caldav}supported-calendar-component-set
     *    (should just be an instance of
     *    Sabre\CalDAV\Property\SupportedCalendarComponentSet, with a bunch of
     *    default components).
     *
     * @param string $principalUri
     * @return array
     */
    public function getSubscriptionsForUser($principalUri)
    {
    }
    /**
     * Creates a new subscription for a principal.
     *
     * If the creation was a success, an id must be returned that can be used to reference
     * this subscription in other methods, such as updateSubscription.
     *
     * @param string $principalUri
     * @param string $uri
     * @param array $properties
     * @return mixed
     */
    public function createSubscription($principalUri, $uri, array $properties)
    {
    }
    /**
     * Updates a subscription
     *
     * The list of mutations is stored in a Sabre\DAV\PropPatch object.
     * To do the actual updates, you must tell this object which properties
     * you're going to process with the handle() method.
     *
     * Calling the handle method is like telling the PropPatch object "I
     * promise I can handle updating this property".
     *
     * Read the PropPatch documentation for more info and examples.
     *
     * @param mixed $subscriptionId
     * @param PropPatch $propPatch
     * @return void
     */
    public function updateSubscription($subscriptionId, PropPatch $propPatch)
    {
    }
    /**
     * Deletes a subscription.
     *
     * @param mixed $subscriptionId
     * @return void
     */
    public function deleteSubscription($subscriptionId)
    {
    }
    /**
     * Returns a single scheduling object for the inbox collection.
     *
     * The returned array should contain the following elements:
     *   * uri - A unique basename for the object. This will be used to
     *           construct a full uri.
     *   * calendardata - The iCalendar object
     *   * lastmodified - The last modification date. Can be an int for a unix
     *                    timestamp, or a PHP DateTime object.
     *   * etag - A unique token that must change if the object changed.
     *   * size - The size of the object, in bytes.
     *
     * @param string $principalUri
     * @param string $objectUri
     * @return array
     */
    public function getSchedulingObject($principalUri, $objectUri)
    {
    }
    /**
     * Returns all scheduling objects for the inbox collection.
     *
     * These objects should be returned as an array. Every item in the array
     * should follow the same structure as returned from getSchedulingObject.
     *
     * The main difference is that 'calendardata' is optional.
     *
     * @param string $principalUri
     * @return array
     */
    public function getSchedulingObjects($principalUri)
    {
    }
    /**
     * Deletes a scheduling object from the inbox collection.
     *
     * @param string $principalUri
     * @param string $objectUri
     * @return void
     */
    public function deleteSchedulingObject($principalUri, $objectUri)
    {
    }
    /**
     * Deletes all scheduling objects last modified before $modifiedBefore from the inbox collection.
     *
     * @param int $modifiedBefore
     * @param int $limit
     * @return void
     */
    public function deleteOutdatedSchedulingObjects(int $modifiedBefore, int $limit): void
    {
    }
    /**
     * Creates a new scheduling object. This should land in a users' inbox.
     *
     * @param string $principalUri
     * @param string $objectUri
     * @param string $objectData
     * @return void
     */
    public function createSchedulingObject($principalUri, $objectUri, $objectData)
    {
    }
    /**
     * Adds a change record to the calendarchanges table.
     *
     * @param mixed $calendarId
     * @param string[] $objectUris
     * @param int $operation 1 = add, 2 = modify, 3 = delete.
     * @param int $calendarType
     * @return void
     */
    protected function addChanges(int $calendarId, array $objectUris, int $operation, int $calendarType = self::CALENDAR_TYPE_CALENDAR): void
    {
    }
    public function restoreChanges(int $calendarId, int $calendarType = self::CALENDAR_TYPE_CALENDAR): void
    {
    }
    /**
     * Parses some information from calendar objects, used for optimized
     * calendar-queries.
     *
     * Returns an array with the following keys:
     *   * etag - An md5 checksum of the object without the quotes.
     *   * size - Size of the object in bytes
     *   * componentType - VEVENT, VTODO or VJOURNAL
     *   * firstOccurence
     *   * lastOccurence
     *   * uid - value of the UID property
     *
     * @param string $calendarData
     * @return array
     */
    public function getDenormalizedData(string $calendarData): array
    {
    }
    /**
     * @param list<array{href: string, commonName: string, readOnly: bool}> $add
     * @param list<string> $remove
     */
    public function updateShares(IShareable $shareable, array $add, array $remove): void
    {
    }
    /**
     * @return list<array{href: string, commonName: string, status: int, readOnly: bool, '{http://owncloud.org/ns}principal': string, '{http://owncloud.org/ns}group-share': bool}>
     */
    public function getShares(int $resourceId): array
    {
    }
    public function getSharesByShareePrincipal(string $principal): array
    {
    }
    public function preloadShares(array $resourceIds): void
    {
    }
    /**
     * @param boolean $value
     * @param Calendar $calendar
     * @return string|null
     */
    public function setPublishStatus($value, $calendar)
    {
    }
    /**
     * @param Calendar $calendar
     * @return string|false
     */
    public function getPublishStatus($calendar)
    {
    }
    /**
     * @param int[] $resourceIds
     */
    public function preloadPublishStatuses(array $resourceIds): void
    {
    }
    /**
     * @param int $resourceId
     * @param list<array{privilege: string, principal: string, protected: bool}> $acl
     * @return list<array{privilege: string, principal: string, protected: bool}>
     */
    public function applyShareAcl(int $resourceId, array $acl): array
    {
    }
    /**
     * update properties table
     *
     * @param int $calendarId
     * @param string $objectUri
     * @param string $calendarData
     * @param int $calendarType
     */
    public function updateProperties($calendarId, $objectUri, $calendarData, $calendarType = self::CALENDAR_TYPE_CALENDAR)
    {
    }
    /**
     * deletes all birthday calendars
     */
    public function deleteAllBirthdayCalendars()
    {
    }
    /**
     * @param $subscriptionId
     */
    public function purgeAllCachedEventsForSubscription($subscriptionId)
    {
    }
    /**
     * @param int $subscriptionId
     * @param array<int> $calendarObjectIds
     * @param array<string> $calendarObjectUris
     */
    public function purgeCachedEventsForSubscription(int $subscriptionId, array $calendarObjectIds, array $calendarObjectUris): void
    {
    }
    /**
     * Move a calendar from one user to another
     *
     * @param string $uriName
     * @param string $uriOrigin
     * @param string $uriDestination
     * @param string $newUriName (optional) the new uriName
     */
    public function moveCalendar($uriName, $uriOrigin, $uriDestination, $newUriName = null)
    {
    }
    /**
     * read VCalendar data into a VCalendar object
     *
     * @param string $objectData
     * @return VCalendar
     */
    protected function readCalendarData($objectData)
    {
    }
    /**
     * delete all properties from a given calendar object
     *
     * @param int $calendarId
     * @param int $objectId
     */
    protected function purgeProperties($calendarId, $objectId)
    {
    }
    /**
     * get ID from a given calendar object
     *
     * @param int $calendarId
     * @param string $uri
     * @param int $calendarType
     * @return int
     */
    protected function getCalendarObjectId($calendarId, $uri, $calendarType): int
    {
    }
    /**
     * @throws \InvalidArgumentException
     */
    public function pruneOutdatedSyncTokens(int $keep, int $retention): int
    {
    }
    /**
     * delete all invitations from a given calendar
     *
     * @since 31.0.0
     *
     * @param int $calendarId
     *
     * @return void
     */
    protected function purgeCalendarInvitations(int $calendarId): void
    {
    }
    /**
     * Delete all invitations from a given calendar event
     *
     * @since 31.0.0
     *
     * @param string $eventId UID of the event
     *
     * @return void
     */
    protected function purgeObjectInvitations(string $eventId): void
    {
    }
    public function unshare(IShareable $shareable, string $principal): void
    {
    }
    /**
     * @return array<string, mixed>[]
     */
    public function getFederatedCalendarsForUser(string $principalUri): array
    {
    }
    public function getFederatedCalendarByUri(string $principalUri, string $uri): ?array
    {
    }
}
