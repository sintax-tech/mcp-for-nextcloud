<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Exception as DavException;
use Sabre\DAV\Server;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

/**
 * Runs calendar writes through the embedded Nextcloud CalDAV server.
 *
 * Every operation gets a fresh `EmbeddedCalDavServer(false)`, or its Nextcloud 31 predecessor
 * ({@see EmbeddedCalDavServerFactory}), and is dispatched with
 * `Server::invokeMethod($request, $response, false)`, so the effect is the same as the user's acting
 * in the Calendar app or through any CalDAV client: the same ACL, the same iCalendar validation, the
 * same scheduling rules, the same trash and the same sync notifications.
 *
 * Nextcloud 33, stable33 (citations are file:line of that branch):
 * - `new EmbeddedCalDavServer(false)` (apps/dav/lib/CalDAV/EmbeddedCalDavServer.php:40-118) loads the
 *   core plugin, the CalDAV plugin, the Schedule, Notifications and ACL plugins, and registers app
 *   plugins on `beforeMethod:*`.
 * - The principal is `principals/users/<uid>`, set with `CustomPrincipalPlugin::setCurrentPrincipal`
 *   (apps/dav/lib/CalDAV/Auth/CustomPrincipalPlugin.php:17-20). With a principal already set, the
 *   Sabre auth plugin returns early (sabre/dav/lib/DAV/Auth/Plugin.php:117-133) and no auth backend runs.
 * - `$server->httpRequest` and `$server->httpResponse` are public (sabre/dav/lib/DAV/Server.php:60,67);
 *   the Nextcloud Schedule plugin reads `x-nc-scheduling` from `$request` on a calendar object change
 *   and from `$this->server->httpRequest` before an unbind
 *   (apps/dav/lib/CalDAV/Schedule/Plugin.php:159-171 and 223-229), so the object handed to
 *   `invokeMethod` must be the same one parked on the server. That is how `send_invitations: false`
 *   is guaranteed.
 * - MOVE never schedules a message: the Sabre schedule plugin returns early on a MOVE
 *   (sabre/dav/lib/CalDAV/Schedule/Plugin.php:371-377) and nothing listens to `afterMove`.
 *
 * `createFromString` is deliberately not used: it calls `Server::createFile()` on a private server,
 * skipping `beforeMethod`, the method ACL and the request headers (apps/dav/lib/CalDAV/CalendarImpl.php:182-222).
 */
class EmbeddedDavDispatcher implements CalendarDav {
    /** App that owns the event size limit and the sendInvitations switch. */
    private const DAV_APP = 'dav';
    /** Event size limit key; same one the CalDavValidatePlugin reads. */
    private const SIZE_LIMIT_KEY = 'event_size_limit';
    /** Default event size limit in bytes (10 MiB), matching CalDavValidatePlugin. */
    public const DEFAULT_SIZE_LIMIT = 10485760;
    /** Header the Nextcloud Schedule plugin checks to skip scheduling. */
    private const SCHEDULING_HEADER = 'x-nc-scheduling';
    /** Value of the scheduling header that suppresses every message. */
    private const SCHEDULING_OFF = 'false';
    /** Principal prefix of Nextcloud users. */
    private const PRINCIPAL_PREFIX = 'principals/users/';

    /**
     * @param \Closure(): Server $serverFactory builds one embedded CalDAV server per operation
     * @param Session $session session the DAV plugins read the sender from
     * @param IConfig $config Nextcloud configuration, for dav/event_size_limit
     * @param LoggerInterface $logger receives the exception class and code only, never event content
     */
    public function __construct(
        private \Closure $serverFactory,
        private Session $session,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * @inheritDoc
     */
    public function put(string $userId, string $calendarUri, string $objectUri, string $ics, bool $scheduling = false, ?int $sizeLimit = null, array $acceptedStatuses = [201]): DavResult {
        $path = DavPathBuilder::objectPath($userId, $calendarUri, $objectUri);
        $this->assertWithinSizeLimit($ics, $sizeLimit);
        return $this->dispatch('PUT', $path, $userId, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'If-None-Match' => '*',
        ] + $this->schedulingHeaders($scheduling), $ics, $acceptedStatuses);
    }

    /**
     * @inheritDoc
     */
    public function update(string $userId, string $calendarUri, string $objectUri, string $etag, string $ics, bool $scheduling = false, ?int $sizeLimit = null, array $acceptedStatuses = [204]): DavResult {
        $path = DavPathBuilder::objectPath($userId, $calendarUri, $objectUri);
        $this->assertWithinSizeLimit($ics, $sizeLimit);
        return $this->dispatch('PUT', $path, $userId, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'If-Match' => $etag,
        ] + $this->schedulingHeaders($scheduling), $ics, $acceptedStatuses);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $userId, string $calendarUri, string $objectUri, ?string $etag = null, bool $scheduling = false, array $acceptedStatuses = [204]): DavResult {
        $path = DavPathBuilder::objectPath($userId, $calendarUri, $objectUri);
        $headers = $etag === null ? [] : ['If-Match' => $etag];
        return $this->dispatch('DELETE', $path, $userId, $headers + $this->schedulingHeaders($scheduling), null, $acceptedStatuses);
    }

    /**
     * @inheritDoc
     */
    public function move(string $userId, string $fromCalendarUri, string $objectUri, string $toCalendarUri, ?string $etag = null, array $acceptedStatuses = [201]): DavResult {
        $source = DavPathBuilder::objectPath($userId, $fromCalendarUri, $objectUri);
        $destination = DavPathBuilder::objectPath($userId, $toCalendarUri, $objectUri);
        $headers = [
            // Sabre refuses a Destination outside the base URI (sabre/dav/lib/DAV/Server.php:559-578),
            // and Overwrite: F turns an occupied name into 412 instead of an overwrite.
            'Overwrite' => 'F',
        ] + ($etag === null ? [] : ['If-Match' => $etag]);
        return $this->dispatch('MOVE', $source, $userId, $headers, null, $acceptedStatuses, $destination);
    }

    /**
     * @inheritDoc
     */
    public function mkcalendar(string $userId, string $calendarUri, string $displayName): DavResult {
        $path = DavPathBuilder::calendarPath($userId, $calendarUri);
        $body = '<?xml version="1.0" encoding="utf-8" ?>'
            . '<cal:mkcalendar xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">'
            . '<d:set><d:prop><d:displayname>' . htmlspecialchars($displayName, ENT_XML1) . '</d:displayname></d:prop></d:set>'
            . '</cal:mkcalendar>';
        return $this->dispatch('MKCALENDAR', $path, $userId, ['Content-Type' => 'application/xml; charset=utf-8'], $body, [201]);
    }

    /**
     * @inheritDoc
     */
    public function deleteCalendar(string $userId, string $calendarUri): DavResult {
        $path = DavPathBuilder::calendarPath($userId, $calendarUri);
        return $this->dispatch('DELETE', $path, $userId, $this->schedulingHeaders(false), null, [204]);
    }

    /** @inheritDoc */
    public function probeWrite(string $actorUid, string $ownerUid, string $calendarUri, string $objectUri, string $ics): DavResult {
        $this->assertWithinSizeLimit($ics, null);
        return $this->dispatch('PUT', DavPathBuilder::objectPath($ownerUid, $calendarUri, $objectUri), $actorUid,
            ['Content-Type' => 'text/calendar; charset=utf-8', 'If-None-Match' => '*'] + $this->schedulingHeaders(false), $ics, [201]);
    }

    /**
     * @param bool $scheduling whether the caller asked for scheduling messages
     * @return array<string, string> the scheduling header, present only when scheduling is suppressed
     */
    private function schedulingHeaders(bool $scheduling): array {
        return $scheduling ? [] : [self::SCHEDULING_HEADER => self::SCHEDULING_OFF];
    }

    /**
     * @param string $ics serialized iCalendar
     * @param int|null $sizeLimit explicit limit, or null to read dav/event_size_limit
     * @return void
     * @throws CalendarException when the ICS is larger than the server accepts
     */
    private function assertWithinSizeLimit(string $ics, ?int $sizeLimit): void {
        $limit = $sizeLimit ?? $this->config->getAppValue(self::DAV_APP, self::SIZE_LIMIT_KEY, (string)self::DEFAULT_SIZE_LIMIT);
        $limit = $limit === '' ? self::DEFAULT_SIZE_LIMIT : (int)$limit;
        if (strlen($ics) > $limit) {
            throw CalendarException::limit(CalendarMessages::eventTooLarge($limit));
        }
    }

    /**
     * Dispatches one request through a brand new embedded server.
     *
     * @param string $method HTTP method
     * @param string $path path relative to the server base URI
     * @param string $userId authenticated UID, becomes the pinned principal
     * @param array<string, string> $headers request headers
     * @param string|null $body request body
     * @param list<int> $acceptedStatuses statuses that count as success
     * @param string|null $destination absolute path for the MOVE Destination header
     * @return DavResult
     * @throws CalendarException on any pipeline refusal, mapped to a user-facing message
     */
    private function dispatch(string $method, string $path, string $userId, array $headers, ?string $body, array $acceptedStatuses, ?string $destination = null): DavResult {
        $this->assertSessionMatches($userId);
        CapturingSapi::reset();
        $server = ($this->serverFactory)();
        $this->pinPrincipal($server, $userId);
        $baseUri = $server->getBaseUri();
        $request = new Request($method, $baseUri . $path, $headers, $body);
        // Sabre derives the node path from the URL relative to the base URI
        // (sabre/http/lib/Request.php:134-162), so both have to agree.
        $request->setBaseUrl($baseUri);
        if ($destination !== null) {
            $request->setHeader('Destination', $baseUri . $destination);
        }
        $response = new Response();
        // The Schedule plugin reads the request from the server, so both references must be the
        // very same objects before the dispatch.
        $server->httpRequest = $request;
        $server->httpResponse = $response;
        $server->sapi = new CapturingSapi();
        try {
            $server->invokeMethod($request, $response, false);
        } catch (DavException $exception) {
            throw $this->translate($exception, $request);
        } catch (\Throwable $exception) {
            $this->logger->error('MCP calendar: DAV dispatch failed', ['app' => 'mcp', 'exception_class' => $exception::class]);
            throw new \RuntimeException(CalendarMessages::DAV_FAILURE, 0, $exception);
        }
        return $this->result($response, $acceptedStatuses, $method);
    }

    /**
     * @param string $userId authenticated UID the caller claims to act as
     * @return void
     * @throws CalendarException when the server session is a different user, or has none
     */
    private function assertSessionMatches(string $userId): void {
        if ($this->session->uid() !== $userId) {
            throw new \RuntimeException(CalendarMessages::DAV_FAILURE);
        }
    }

    /**
     * Pins the request to the authenticated user.
     *
     * The check is structural on purpose. Naming OCA\DAV\CalDAV\Auth\CustomPrincipalPlugin would
     * mean loading the DAV app just to answer a question, and it would make this class untestable
     * off a server. On stable33 exactly one auth plugin exposes `setCurrentPrincipal`, which is
     * CustomPrincipalPlugin (apps/dav/lib/CalDAV/Auth/CustomPrincipalPlugin.php:17-20); the public
     * variant hardcodes `getCurrentPrincipal()` and has no setter
     * (apps/dav/lib/CalDAV/Auth/PublicPrincipalPlugin.php:17-19), so a public server is refused here.
     *
     * @param Server $server fresh embedded server
     * @param string $userId authenticated UID
     * @return void
     * @throws \RuntimeException when the server cannot take a pinned principal
     */
    private function pinPrincipal(Server $server, string $userId): void {
        $auth = $server->getPlugin('auth');
        if (!$auth instanceof AuthPlugin || !method_exists($auth, 'setCurrentPrincipal')) {
            $this->logger->error('MCP calendar: DAV server cannot take a pinned principal', ['app' => 'mcp', 'exception_class' => self::class]);
            throw new \RuntimeException(CalendarMessages::DAV_FAILURE);
        }
        $auth->setCurrentPrincipal(self::PRINCIPAL_PREFIX . $userId);
        if ($auth->getCurrentPrincipal() !== self::PRINCIPAL_PREFIX . $userId) {
            $this->logger->error('MCP calendar: DAV server refused the pinned principal', ['app' => 'mcp', 'exception_class' => self::class]);
            throw new \RuntimeException(CalendarMessages::DAV_FAILURE);
        }
    }

    /**
     * @param Response $response response Sabre produced
     * @param list<int> $acceptedStatuses statuses that count as success
     * @param string $method HTTP method, for the log only
     * @return DavResult
     * @throws \RuntimeException when the status is not one the caller expected
     */
    private function result(Response $response, array $acceptedStatuses, string $method): DavResult {
        $status = $response->getStatus();
        if ($status === null) {
            $status = CapturingSapi::$lastStatus;
        }
        if ($status === null || !in_array($status, $acceptedStatuses, true)) {
            $this->logger->error('MCP calendar: unexpected DAV status', ['app' => 'mcp', 'method' => $method, 'status' => $status]);
            throw new \RuntimeException(CalendarMessages::DAV_FAILURE);
        }
        return new DavResult($status, $response->getHeader('ETag'));
    }

    /**
     * Maps a Sabre exception to a user-facing calendar failure, never leaking its message or XML.
     *
     * @param DavException $exception exception Sabre raised
     * @param Request $request the request that caused it, to tell the preconditions apart
     * @return CalendarException the failure to raise
     */
    private function translate(DavException $exception, Request $request): CalendarException {
        $this->logger->error('MCP calendar: DAV write refused', ['app' => 'mcp', 'exception_class' => $exception::class, 'http_code' => $exception->getHTTPCode()]);
        $mapped = match ($exception->getHTTPCode()) {
            // 403 Forbidden and 403 NeedPrivileges: the ACL plugin refused bind, unbind or write.
            403 => CalendarException::forbidden(),
            404 => CalendarException::notFound(),
            // 409 Conflict, including OCA\DAV\Exception\UidConflict (a duplicate UID in the calendar).
            409 => CalendarException::conflict(CalendarMessages::conflictUid()),
            412 => $request->getHeader('If-Match') !== null
                ? CalendarException::conflict(CalendarMessages::conflictEtag())
                : CalendarException::conflict(CalendarMessages::conflictName()),
            // 415 or 400 from the iCalendar validation of the CalDAV plugin.
            400, 415 => CalendarException::blocked(CalendarMessages::dataRefused()),
            default => new CalendarException(CalendarMessages::DAV_FAILURE),
        };
        return new CalendarException($mapped->getMessage(), $exception->getHTTPCode(), $exception);
    }
}