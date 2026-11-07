<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Tables\Errors;

// Schema preparation fixtures may have already loaded these identical native error shapes.

/** Test-only shape of the error the Tables services throw for a missing node (1.0.x and 2.x). */
if (!class_exists(NotFoundError::class, false)) {
	class NotFoundError extends \Exception {
	}
}

/** Test-only shape of the error the Tables services throw for a node the user may not open (1.0.x and 2.x). */
if (!class_exists(PermissionError::class, false)) {
	class PermissionError extends \Exception {
	}
}

/** Test-only shape of the error the Tables services throw when they fail (1.0.x and 2.x). */
if (!class_exists(InternalError::class, false)) {
	class InternalError extends \Exception {
	}
}
