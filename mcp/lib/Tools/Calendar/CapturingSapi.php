<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

/**
 * SAPI that keeps Sabre's response instead of handing it to PHP.
 *
 * Sabre calls sendResponse() even when the dispatcher asked for no output, because
 * Server::checkPreconditions() answers a conditional request early (Sabre\DAV\Server:466-469).
 * The real Sapi would call header() and echo the body into whatever process runs the MCP server, so
 * this one records the status and stops there.
 */
final class CapturingSapi extends \Sabre\HTTP\Sapi {
    /** @var int how many times Sabre handed a response to the SAPI during the last dispatch */
    public static int $sendCount = 0;
    /** @var int|null status of the last response Sabre handed over */
    public static ?int $lastStatus = null;

    /**
     * @param \Sabre\HTTP\ResponseInterface $response response Sabre would otherwise emit
     * @return void
     */
    public static function sendResponse(\Sabre\HTTP\ResponseInterface $response): void {
        self::$sendCount++;
        self::$lastStatus = $response->getStatus();
    }

    /**
     * @return void clears the counters before a new dispatch
     */
    public static function reset(): void {
        self::$sendCount = 0;
        self::$lastStatus = null;
    }
}