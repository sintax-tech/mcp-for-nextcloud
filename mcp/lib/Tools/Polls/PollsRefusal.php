<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Fixed, client-safe refusals; native exceptions never provide their text to the answer. */
final class PollsRefusal extends ToolFailure {
	/**
	 * A missing poll and a poll outside the caller's ACL have the same answer.
	 * @return self safe refusal
	 */
	public static function missing(): self {
		return new self(Translator::t('Poll not found.'));
	}

	/**
	 * A missing or incompatible native surface, invalid identity or failed native call.
	 * @return self safe refusal
	 */
	public static function unavailable(): self {
		return new self(Translator::t('Polls is unavailable for this request.'));
	}

	/**
	 * A cursor of another state or a window which changed during reauthorization.
	 * @return self safe refusal
	 */
	public static function changed(): self {
		return new self(Translator::t('The poll list or options changed; start again without a cursor.'));
	}
}
