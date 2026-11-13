<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Preparation;

/** The fixed limits of one meeting dossier (D3). They are not arguments of the tool. */
final class DossierBudget {
	/** Days before the event the mail search may look. */
	public const LOOKBACK_DAYS = 30;
	/** Most messages kept, after the exact address check. */
	public const MAX_MESSAGES = 20;
	/** Most cards read from explicit links. */
	public const MAX_CARDS = 20;
	/** Most files read from explicit links. */
	public const MAX_FILES = 20;
	/** Most participants taken from the event. */
	public const MAX_PARTICIPANTS = 10;
	/** Most mail folders one dossier treats as consulted. */
	public const MAX_FOLDERS = 20;
	/** Characters kept from a file text. */
	public const EXCERPT = 240;

	/** Limits only. */
	private function __construct() {
	}
}
