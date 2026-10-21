<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Logging;

/** Redacts exception messages from diagnostic logging. */
final class ExceptionSummary {
    /**
     * What the server log keeps of an unexpected exception: its class and code, never its message. A message may
     * carry the names and paths of the user's files in any form, so the simple guarantee is not to write it. The
     * client never sees any of it.
     *
     * @param \Throwable $e the unexpected exception
     * @return string "Class (code N)"
     */
    public static function loggable(\Throwable $e): string {
        $code = $e->getCode();
        return $e::class . ' (code ' . (is_int($code) ? $code : 0) . ')';
    }
}
