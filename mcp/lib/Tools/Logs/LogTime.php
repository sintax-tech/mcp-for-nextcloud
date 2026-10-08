<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Logs;

/**
 * Reads the `time` of a log entry the way the core wrote it: in the `logdateformat` system setting (ISO 8601 by
 * default) and the `logtimezone` one (UTC by default). A time this format cannot read is not guessed: it is null, and
 * a date filter leaves the entry out and counts it.
 */
final class LogTime {
    private \DateTimeZone $zone;

    /**
     * @param string $format the logdateformat system setting
     * @param string $zone the logtimezone system setting; an unknown zone is UTC, as for the core
     */
    public function __construct(private string $format, string $zone) {
        try {
            $this->zone = new \DateTimeZone($zone);
        } catch (\Exception) {
            $this->zone = new \DateTimeZone('UTC');
        }
    }

    /**
     * @param mixed $time the `time` field of an entry
     * @return \DateTimeImmutable|null the instant, or null when the field does not follow the format
     */
    public function parse(mixed $time): ?\DateTimeImmutable {
        if (!is_string($time) || $time === '') {
            return null;
        }
        // '!' resets every field the format leaves out, so a time never borrows the current date.
        $parsed = \DateTimeImmutable::createFromFormat('!' . $this->format, $time, $this->zone);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }
        return $parsed;
    }
}
