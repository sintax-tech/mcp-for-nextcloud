<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use OCA\Mcp\Tools\ArgumentValidationException;

/**
 * Invalid argument the input schema cannot express (date format, time zone, range order).
 * The registry answers it with JSON-RPC -32602; the message uses English source text translated for the user.
 */
final class CalendarArgumentException extends ArgumentValidationException {
}
