<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Flag;

use OCA\Mcp\Tools\ArgumentValidationException;

/**
 * The flags a call asks for, as the state wanted: `seen` (read) and `flagged`, each true, false or not asked. There is no
 * toggle, so the same call gives the same result whatever the state was, and nothing but these two flags is ever named:
 * no deleted, draft, answered, recent or custom keyword.
 */
final class MailFlagRequest {
	/** The flags the tool may change, by argument name, with the IMAP system flag of each (RFC 3501). */
	public const FLAGS = ['seen' => '\\seen', 'flagged' => '\\flagged'];

	/**
	 * Keeps the asked state.
	 *
	 * @param array<string, bool> $wanted asked state by flag name, seen and/or flagged, never empty
	 * @return void
	 */
	private function __construct(
		public readonly array $wanted,
	) {
	}

	/**
	 * The request of a call.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @return self the request
	 * @throws ArgumentValidationException when the call asks for no flag
	 */
	public static function from(array $arguments): self {
		$wanted = [];
		foreach (array_keys(self::FLAGS) as $flag) {
			if (is_bool($arguments[$flag] ?? null)) {
				$wanted[$flag] = $arguments[$flag];
			}
		}
		if ($wanted === []) {
			throw new ArgumentValidationException('Invalid argument: seen', 'seen', MailFlagMessages::nothingAsked());
		}
		return new self($wanted);
	}

	/**
	 * The changes from a current state to the asked one: only the flags asked and not already so.
	 *
	 * @param array<string, bool|null> $current current state by flag name; null when unknown
	 * @return array<string, bool> the flags to set (true) or clear (false)
	 */
	public function changes(array $current): array {
		return array_filter($this->wanted, static fn (bool $set, string $flag): bool => ($current[$flag] ?? null) !== $set, ARRAY_FILTER_USE_BOTH);
	}
}
