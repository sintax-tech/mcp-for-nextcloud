<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Write;

use OCA\Mcp\Tools\ToolFailure;

/**
 * A row write refused before the Tables app is asked to write, with one of the fixed messages of
 * {@see RowWriteMessages}: nothing was written.
 */
final class RowWriteRefusal extends ToolFailure {
	/**
	 * A cell on a column the target does not let the user write.
	 *
	 * @return self the refusal
	 */
	public static function column(): self {
		return new self(RowWriteMessages::columnRefused());
	}

	/**
	 * A value that does not fit its column.
	 *
	 * @return self the refusal
	 */
	public static function value(): self {
		return new self(RowWriteMessages::valueRefused());
	}

	/**
	 * A mandatory column without a value.
	 *
	 * @return self the refusal
	 */
	public static function mandatory(): self {
		return new self(RowWriteMessages::mandatoryMissing());
	}

	/**
	 * A relation cell whose target the user cannot read.
	 *
	 * @return self the refusal
	 */
	public static function relation(): self {
		return new self(RowWriteMessages::relationRefused());
	}

	/**
	 * A view whose hidden values cannot be checked.
	 *
	 * @return self the refusal
	 */
	public static function viewDefaults(): self {
		return new self(RowWriteMessages::viewDefaultsRefused());
	}

	/**
	 * A federated table or view.
	 *
	 * @return self the refusal
	 */
	public static function federated(): self {
		return new self(RowWriteMessages::federated());
	}

	/**
	 * The session is not the caller.
	 *
	 * @return self the refusal
	 */
	public static function session(): self {
		return new self(RowWriteMessages::sessionMismatch());
	}
}
