<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Selftest;

use OCA\Mcp\Modules\Calendar\Selftest\CalendarSelftestMessages;
use OCA\Mcp\Modules\Calendar\Execution\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
final class SelftestAssertions
{
    public static function tool(array $result): array
    {
        SelftestAssertions::require(!($result['isError'] ?? false), CalendarSelftestMessages::FAILED);
        $value = json_decode(
            $result['content'][0]['text'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        SelftestAssertions::require(is_array($value) && !($value['requiresConfirmation'] ?? false), CalendarSelftestMessages::FAILED);
        return $value;
    }
    public static function require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }
    public static function safeError(\Throwable $error): string
    {
        if ($error instanceof CalendarException || $error instanceof \OCA\Mcp\Platform\Dav\CalDav\CalDavFailure || $error instanceof CalendarArgumentException) {
            return $error->getMessage();
        }
        if (str_starts_with($error->getMessage(), CalendarSelftestMessages::ASSERTION_PREFIX) || in_array($error->getMessage(), [CalendarSelftestMessages::TRASH_REQUIRED, CalendarSelftestMessages::INVALID_USER, CalendarSelftestMessages::INVALID_ATTENDEE], true)) {
            return $error->getMessage();
        }
        return CalendarSelftestMessages::FAILED . ' (' . $error::class . ')';
    }
    public static function ok(
        callable $report,
        string $step,
        string $detail,
    ): void
    {
        $report(['step' => $step, 'status' => 'OK', 'detail' => $detail]);
    }
    public static function skip(callable $report, string $step): void
    {
        $report(['step' => $step, 'status' => 'NOT TESTED', 'detail' => CalendarSelftestMessages::OPTIONAL]);
    }
    public static function expectRefusal(callable $action, array $httpCodes = []): void
    {
        try {
            $action();
        } catch (CalendarException|\OCA\Mcp\Platform\Dav\CalDav\CalDavFailure $error) {
            SelftestAssertions::require($httpCodes === [] || in_array($error->getCode(), $httpCodes, true), CalendarSelftestMessages::assertion('refusal-status'));
            return;
        }
        throw new \RuntimeException(CalendarSelftestMessages::assertion('refusal'));
    }
}
