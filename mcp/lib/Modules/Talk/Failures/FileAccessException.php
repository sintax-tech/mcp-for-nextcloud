<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Failures;

use RuntimeException;

/**
 * A file the module could not reach or could not share, carrying the message the caller is allowed to see.
 *
 * It exists so a missing file, a file the user cannot share and a file that is not a file stay indistinguishable,
 * while "already shared in this conversation" can still be specific, which is a fact about the caller's own action.
 */
class FileAccessException extends RuntimeException {
	/**
	 * Retains the existing file refusal and its optional original cause.
	 * @param string $message existing public refusal message
	 * @param \Throwable|null $previous original failure retained for the server log
	 */
	public function __construct(string $message, ?\Throwable $previous = null) {
		parent::__construct($message, 0, $previous);
	}
}
