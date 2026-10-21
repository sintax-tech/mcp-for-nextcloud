<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs\Reading;

use InvalidArgumentException;
use OCA\Mcp\Access\Logs\LogFileNames;
use OCP\IConfig;

/** Validates one request and reads one bounded native window without caching settings. */
final class LogQuery {
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 100;

    public function __construct(private LogScanner $scanner, private IConfig $config, private LogFileNames $fileNames) {}

    public function read(array $arguments, bool $paged): LogWindow {
        $hide = $this->fileNames->active();
        // A search inside hidden text would answer, by its count, whether a name is there: it is refused instead.
        if ($hide && isset($arguments['contains'])) {
            throw new InvalidArgumentException('contains is not available while the administrator hides the text of log '
                . 'messages; filter by min_level, app, user, since, until or req_id instead');
        }
        $filter = LogFilter::fromArguments($arguments, $this->time());
        $offset = (int)($arguments['offset'] ?? 0);
        $limit = $paged ? (int)($arguments['limit'] ?? self::DEFAULT_LIMIT) : null;
        if ($limit !== null && ($limit < 1 || $limit > self::MAX_LIMIT)) {
            throw new InvalidArgumentException('Invalid limit');
        }
        if ($offset < 0 || $offset > LogScanner::MAX_OFFSET) {
            throw new InvalidArgumentException('offset must be between 0 and ' . LogScanner::MAX_OFFSET
                . ': the log is read from its end and older entries are beyond the read budget of this tool');
        }
        $window = $this->scanner->scan($filter, $offset, $limit);
        return new LogWindow($filter, $window, $hide);
    }

    /** @return LogTime how the core writes the time of an entry on this server */
    public function time(): LogTime {
        $format = $this->config->getSystemValueString('logdateformat', \DateTimeInterface::ATOM);
        return new LogTime($format === '' ? \DateTimeInterface::ATOM : $format, $this->config->getSystemValueString('logtimezone', 'UTC'));
    }
}
