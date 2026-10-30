<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls;

/** Classifies native errors by class, never by text containing user data. */
final class PollsCalls {
	/**
	 * Whether a native failure means missing or forbidden.
	 * @param \Throwable $error native error
	 * @return bool true for the two indistinguishable absence cases
	 */
	public static function missing(\Throwable $error): bool {
		return $error instanceof \OCP\AppFramework\Db\DoesNotExistException
			|| is_a($error, 'OCA\\Polls\\Exceptions\\ForbiddenException')
			|| is_a($error, 'OCA\\Polls\\Exceptions\\NotFoundException');
	}
}
