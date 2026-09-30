<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

/**
 * Resolves the calendars a user may see and write, re-reading the backend on every call.
 */
class CalendarAccess {
    /** Principal prefix of Nextcloud users. */
    private const PRINCIPAL_PREFIX = 'principals/users/';
    /** Base of the DAV path the tools expose. */
    private const PATH_PREFIX = '/remote.php/dav/calendars/';
    /** URI of the system-generated, read-only birthday calendar. */
    private const BIRTHDAY_URI = 'contact_birthdays';

    /**
     * @param CalendarStore $store calendar storage port
     */
    public function __construct(private CalendarStore $store) {}

    /**
     * Lists the user's visible event calendars: owned or shared, not trashed, supporting VEVENT.
     *
     * @param string $userId authenticated UID
     * @return list<Calendar>
     */
    public function visible(string $userId): array {
        $out = [];
        foreach ($this->store->calendarsForPrincipal(self::PRINCIPAL_PREFIX . $userId) as $row) {
            if ($row['deleted'] || ($row['components'] !== [] && !in_array('VEVENT', $row['components'], true))) {
                continue;
            }
            $owner = $row['ownerPrincipal'];
            $ownerId = str_starts_with($owner, self::PRINCIPAL_PREFIX) ? substr($owner, strlen(self::PRINCIPAL_PREFIX)) : $owner;
            $birthday = $row['uri'] === self::BIRTHDAY_URI || str_starts_with($row['uri'], self::BIRTHDAY_URI . '_shared_by_');
            $out[] = new Calendar(
                $row['id'],
                $row['uri'],
                $row['displayName'],
                $ownerId,
                $owner,
                !$row['readOnly'] && !$birthday,
                self::PATH_PREFIX . rawurlencode($userId) . '/' . rawurlencode($row['uri']) . '/',
            );
        }
        return $out;
    }

    /**
     * Resolves a tool path to one of the user's visible calendars.
     *
     * @param string $userId authenticated UID
     * @param string $path "/remote.php/dav/calendars/<uid>/<uri>/", trailing slash optional
     * @return Calendar
     * @throws CalendarException when the path is foreign, malformed or not visible
     */
    public function resolve(string $userId, string $path): Calendar {
        $uri = $this->uriFromPath($userId, $path);
        foreach ($this->visible($userId) as $calendar) {
            if ($calendar->uri === $uri) {
                return $calendar;
            }
        }
        throw CalendarException::notFound();
    }

    /**
     * Resolves a calendar and requires write access to it.
     *
     * @param string $userId authenticated UID
     * @param string $path calendar path
     * @return Calendar
     * @throws CalendarException when not visible or not writable
     */
    public function resolveWritable(string $userId, string $path): Calendar {
        $calendar = $this->resolve($userId, $path);
        if (!$calendar->writable) {
            throw CalendarException::forbidden();
        }
        return $calendar;
    }

    /**
     * @param string $userId authenticated UID
     * @param string $path calendar path
     * @return string decoded calendar URI
     * @throws CalendarException when the path does not point into the user's own calendar home
     */
    private function uriFromPath(string $userId, string $path): string {
        if (!str_starts_with($path, self::PATH_PREFIX)) {
            throw CalendarException::notFound();
        }
        $segments = explode('/', rtrim(substr($path, strlen(self::PATH_PREFIX)), '/'));
        if (count($segments) !== 2 || rawurldecode($segments[0]) !== $userId) {
            throw CalendarException::notFound();
        }
        $uri = rawurldecode($segments[1]);
        if ($uri === '' || str_contains($uri, '/') || str_contains($uri, "\0") || $uri === '.' || $uri === '..') {
            throw CalendarException::notFound();
        }
        return $uri;
    }
}
