<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

/**
 * Builds the CalDAV paths the dispatcher sends, from resolved identifiers only.
 *
 * No MCP argument ever reaches a URL, a host or a principal here: the caller passes a calendar URI
 * that CalendarAccess already matched against the user's visible calendars, and an object URI that
 * came from a backend row or from a generated UUID. Each segment is validated and encoded on its
 * own, so a segment can never add a path step.
 */
final class DavPathBuilder {
    /** Root of the CalDAV tree of the embedded server. */
    public const ROOT = 'calendars/';

    /** @var list<string> segments refused in any position */
    private const REFUSED = ['.', '..'];

    private function __construct() {
    }

    /**
     * @param string $userId authenticated UID, the calendar home owner
     * @param string $calendarUri calendar URI in that home
     * @param string $objectUri calendar object URI
     * @return string path relative to the server base URI
     * @throws CalendarException when a segment is empty, reserved or contains a separator
     */
    public static function objectPath(string $userId, string $calendarUri, string $objectUri): string {
        return self::calendarPath($userId, $calendarUri) . '/' . self::segment($objectUri, CalendarMessages::PATH_OBJECT);
    }

    /**
     * @param string $userId authenticated UID, the calendar home owner
     * @param string $calendarUri calendar URI in that home
     * @return string path relative to the server base URI
     * @throws CalendarException when a segment is empty, reserved or contains a separator
     */
    public static function calendarPath(string $userId, string $calendarUri): string {
        return self::ROOT . self::segment($userId, CalendarMessages::PATH_USER) . '/' . self::segment($calendarUri, CalendarMessages::PATH_CALENDAR);
    }

    /**
     * @param string $value one path segment
     * @param string $label translated noun naming the segment in the error message
     * @return string the encoded segment
     * @throws CalendarException when the value is empty, reserved or contains a separator
     */
    private static function segment(string $value, string $label): string {
        if ($value === '' || in_array($value, self::REFUSED, true) || str_contains($value, '/') || str_contains($value, '\\') || str_contains($value, "\0")) {
            throw CalendarException::blocked(CalendarMessages::invalidPathSegment($label));
        }
        return rawurlencode($value);
    }
}
