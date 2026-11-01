<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth\Storage;

/** Persistence lifetime shared by refresh issuance and spent-token cleanup. */
final class TokenLifetime {
	/** Refresh lifetime in seconds, identical to the released token-service contract. */
	public const REFRESH_TTL = 30 * 86400;
}
