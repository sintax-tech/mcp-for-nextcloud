<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Failures;

use RuntimeException;

/**
 * An assignment that failed before Deck stored it is not retried, because the notification of the failed attempt
 * could not be withdrawn and a retry would give the assignee a second one (issue #26).
 *
 * It carries no caller-facing text: the assignment fails with the generic message, as it did in 1.2.5.
 */
final class DeckRetryWithheldException extends RuntimeException {
}
