<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Creation;

/** The optional Mail HTML engine, isolated from the draft's policy. */
interface MailDraftHtmlPurifier {
	/**
	 * Purifies a fragment with the draft's restricted configuration.
	 *
	 * @param string $html UTF-8 fragment
	 * @return string purified UTF-8 fragment
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure when the engine is unavailable or fails
	 */
	public function purify(string $html): string;
}
