<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

/**
 * Finds a calendar object after it was moved to the CalDAV trash.
 *
 * CalDavBackend::deleteCalendarObject (nextcloud/server stable33, line ~1590) renames the trashed row to
 * "<name>-deleted.<ext>" to free the original URI, so the original URI no longer finds anything.
 */
final class TrashedObject {
    /**
     * @param string $uri original object URI, e.g. "abc.ics"
     * @return string URI the backend gives the trashed row, e.g. "abc-deleted.ics"
     */
    public static function uri(string $uri): string {
        $info = pathinfo($uri);
        return isset($info['extension']) && $info['extension'] !== ''
            ? $info['filename'] . '-deleted.' . $info['extension']
            : $info['filename'] . '-deleted';
    }

    /**
     * @param CalendarStore $store calendar storage
     * @param int $calendarId backend calendar id
     * @param string $uri original object URI, from before the deletion
     * @return array{id:int, uri:string, etag:string, data:string, deleted:bool}|null the trashed row (renamed, or the
     *         original row flagged deleted), or null when the object is not in the trash
     */
    public static function row(CalendarStore $store, int $calendarId, string $uri): ?array {
        foreach ([self::uri($uri), $uri] as $candidate) {
            $row = $store->object($calendarId, $candidate);
            if ($row !== null && $row['deleted']) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @param CalendarStore $store calendar storage
     * @param int $calendarId backend calendar id
     * @param string $uri original object URI, from before the deletion
     * @return bool whether the object is now in the trash
     */
    public static function exists(CalendarStore $store, int $calendarId, string $uri): bool {
        return self::row($store, $calendarId, $uri) !== null;
    }
}
