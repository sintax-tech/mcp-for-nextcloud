<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Tables\Errors;

/** Test-only shape of the error the Tables services throw for a missing node (1.0.x and 2.x). */
class NotFoundError extends \Exception {
}

/** Test-only shape of the error the Tables services throw for a node the user may not open (1.0.x and 2.x). */
class PermissionError extends \Exception {
}

/** Test-only shape of the error the Tables services throw when they fail (1.0.x and 2.x). */
class InternalError extends \Exception {
}
