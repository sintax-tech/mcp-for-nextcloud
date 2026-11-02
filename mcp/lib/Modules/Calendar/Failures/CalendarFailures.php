<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Failures;

use Closure;
use InvalidArgumentException;
use OCA\Mcp\Mcp\Tools\Schema\SchemaBuilder;
use RuntimeException;

/**
 * How a confirmed Calendar call reports its failures; a plan never goes through here.
 *
 * A refusal the user can read becomes an error result, an argument the schema could not validate stays the -32602 it
 * already is, and an InvalidArgumentException of a library is turned into a generic failure, so it neither becomes
 * -32602 nor shows its message.
 */
final class CalendarFailures {
	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * Runs one confirmed call or read and maps its failures.
	 *
	 * @param Closure(): array<string, mixed> $call the read, or the confirmed write
	 * @return array<string, mixed> the result of the call, or the error result of a user-facing refusal
	 * @throws CalendarArgumentException on an argument the schema cannot validate (-32602)
	 * @throws RuntimeException wrapping an InvalidArgumentException of a library, reported generically by the registry
	 */
	public static function run(Closure $call): array {
		try {
			return $call();
		} catch (CalendarException $e) {
			return SchemaBuilder::error($e->getMessage());
		} catch (CalendarArgumentException $e) {
			throw $e;
		} catch (InvalidArgumentException $e) {
			// A library InvalidArgumentException must not become -32602 nor leak its message.
			throw new RuntimeException('Calendar backend failure', 0, $e);
		}
	}
}
