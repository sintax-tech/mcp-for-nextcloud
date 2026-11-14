<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Workflows;

/**
 * The receipt of one confirmed step of a routine that combines tools of several modules, as observed by the module
 * that owns the sink.
 *
 * A step never retries and never undoes: `failed` means the step was refused before its effect and nothing was
 * written; `uncertain` means the effect was attempted and may have been persisted, so the caller stops and reports the
 * identifiers it has instead of repeating the step.
 */
final class WorkflowStep {
	/** The effect was written and read back. */
	public const APPLIED = 'applied';
	/** The main effect exists, but some of its secondary effects failed (invitations, assignees). */
	public const PARTIAL = 'partial';
	/** Refused before the effect: grant, Guests, app, arguments, ACL or scope; nothing was written. */
	public const FAILED = 'failed';
	/** The effect was attempted and its outcome is unknown; it may have been persisted. */
	public const UNCERTAIN = 'uncertain';

	/**
	 * @param string $status one of the constants of this class
	 * @param array<string, string|int> $ids identifiers the step observed (room token, message id, event UID, file id, card id)
	 * @param array<string, mixed> $result the result of the original tool, as that tool answers it; empty on a refusal
	 * @param string|null $message a sentence written for the user: always for partial, failed and uncertain, and for applied only when there is a note
	 */
	private function __construct(
		public readonly string $status,
		public readonly array $ids,
		public readonly array $result,
		public readonly ?string $message,
	) {
	}

	/**
	 * @param array<string, string|int> $ids identifiers of what was written
	 * @param array<string, mixed> $result the result of the original tool
	 * @param string|null $message a note the user has to read although the effect exists, e.g. a missing room link
	 * @return self an applied step
	 */
	public static function applied(array $ids, array $result, ?string $message = null): self {
		return new self(self::APPLIED, $ids, $result, $message);
	}

	/**
	 * @param array<string, string|int> $ids identifiers of what was written
	 * @param array<string, mixed> $result the result of the original tool, which names what failed
	 * @param string $message what the user has to know about the part that failed
	 * @return self a step whose main effect exists and whose secondary effects partly failed
	 */
	public static function partial(array $ids, array $result, string $message): self {
		return new self(self::PARTIAL, $ids, $result, $message);
	}

	/**
	 * @param string $message the refusal, written for the user
	 * @return self a step refused before its effect, with nothing written
	 */
	public static function failed(string $message): self {
		return new self(self::FAILED, [], [], $message);
	}

	/**
	 * @param string $message what is known, written for the user
	 * @param array<string, string|int> $ids identifiers known so far, possibly none
	 * @param array<string, mixed> $result the result of the original tool, when it answered one
	 * @return self a step whose effect may have been persisted
	 */
	public static function uncertain(string $message, array $ids = [], array $result = []): self {
		return new self(self::UNCERTAIN, $ids, $result, $message);
	}
}
