<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Reading;

use OCA\Mcp\Modules\Tables\Presentation\RowsMessages;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * A row query refused before the Tables engine is called, with one of the fixed messages of {@see RowsMessages}.
 */
final class RowsRefusal extends ToolFailure {
	/**
	 * The session is not the caller.
	 *
	 * @return self the refusal
	 */
	public static function sessionMismatch(): self {
		return new self(RowsMessages::sessionMismatch());
	}

	/**
	 * The filter, search or sort cannot be applied as given.
	 *
	 * @return self the refusal
	 */
	public static function invalidQuery(): self {
		return new self(RowsMessages::invalidQuery());
	}

	/**
	 * The query is over the limits.
	 *
	 * @return self the refusal
	 */
	public static function tooLarge(): self {
		return new self(RowsMessages::tooLarge());
	}

	/**
	 * The saved rules of the view or table cannot be applied safely.
	 *
	 * @return self the refusal
	 */
	public static function savedRulesUnusable(): self {
		return new self(RowsMessages::savedRulesUnusable());
	}

	/**
	 * The cursor is not valid for this query and state.
	 *
	 * @return self the refusal
	 */
	public static function staleCursor(): self {
		return new self(RowsMessages::staleCursor());
	}
}
