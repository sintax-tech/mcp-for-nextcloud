<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Service\Calendar;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Mcp\Tools\Calendar\Calendar;
use Psr\Container\ContainerInterface;

/** Only reads stable33 CalDavBackend; every mutation belongs to CalendarDav. */
final class DavCalendarSelftestReader implements CalendarSelftestReader {
    /** @param ContainerInterface $container resolves DAV lazily, without starting a server */
    public function __construct(private ContainerInterface $container) {}

    /** @inheritDoc */
    public function calendarState(Calendar $calendar): ?array {
        $row = $this->container->get(CalDavBackend::class)->getCalendarById($calendar->id);
        return $row === null ? null : [
            'syncToken' => (string)$row['{http://sabredav.org/ns}sync-token'],
            'deleted' => ($row['{http://nextcloud.com/ns}deleted-at'] ?? null) !== null,
        ];
    }

    /** @inheritDoc */
    public function schedulingObjects(string $uid): array {
        return array_map(static fn (array $row): array => [
            'uri' => (string)$row['uri'], 'data' => (string)$row['calendardata'], 'etag' => (string)$row['etag'],
        ], $this->container->get(CalDavBackend::class)->getSchedulingObjects('principals/users/' . $uid));
    }
}
