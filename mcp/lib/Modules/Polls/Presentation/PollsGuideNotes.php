<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Presentation;

/** What the Polls schemas cannot say: untrusted content, native costs, link credentials and creation effects. */
final class PollsGuideNotes {
	/** @return list<string> Existing guidance followed by the approved mutation limits. */
	public static function withMutations(): array {
		return [...self::notes(), ...PollsWriteMessages::notes(), PollsWriteMessages::sharing(), PollsWriteMessages::delegation()];
	}
	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @return list<string> the guide notes of the Polls module, in published order
	 */
	public static function notes(): array {
		return [PollsMessages::NOTICE, PollsMessages::READ, PollsContentMessages::COST, PollsContentMessages::LINKS, CreateMessages::TOOL, CreateMessages::NOTE];
	}
}
