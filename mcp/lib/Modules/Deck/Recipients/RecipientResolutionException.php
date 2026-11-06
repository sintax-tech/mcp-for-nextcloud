<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Recipients;

use RuntimeException;

/**
 * A known recipient-composition failure with a fixed sanitized category, never native identity data.
 */
final class RecipientResolutionException extends RuntimeException {
	/**
	 * Keep the caller-safe category immutable and inaccessible to arbitrary exception text.
	 *
	 * @param string $safeMessage Translated text from RecipientMessages only.
	 * @return void
	 */
	private function __construct(private readonly string $safeMessage) {
		parent::__construct();
	}

	/**
	 * Refuse an incomplete native resolution.
	 *
	 * @return self Known failure without participant details.
	 */
	public static function incomplete(): self {
		return new self(\OCA\Mcp\Modules\Deck\Recipients\RecipientMessages::incomplete());
	}

	/**
	 * Refuse an unsupported native recipient type or origin.
	 *
	 * @return self Known failure without participant details.
	 */
	public static function unsupported(): self {
		return new self(\OCA\Mcp\Modules\Deck\Recipients\RecipientMessages::unsupported());
	}

	/**
	 * Refuse a bounded expansion instead of returning its partial result.
	 *
	 * @return self Known failure without partial counts.
	 */
	public static function limit(): self {
		return new self(\OCA\Mcp\Modules\Deck\Recipients\RecipientMessages::limit());
	}

	/**
	 * Return the fixed category only after source authorization.
	 *
	 * @return string Translated sanitized refusal.
	 */
	public function callerMessage(): string {
		return $this->safeMessage;
	}
}
