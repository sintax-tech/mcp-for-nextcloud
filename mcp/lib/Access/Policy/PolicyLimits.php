<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy;

/** Shared bounds of persisted policy commands and their administrative API. */
final class PolicyLimits {
    public const RECEIPT_TTL = 600;
    public const PREVIEW_TTL = 120;
    public const MAX_UIDS = 50;
    public const MAX_CELLS = 2500;
}
