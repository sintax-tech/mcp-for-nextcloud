<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\ResourceScope;

/** A resource scope write for a module whose activation barrier is still closed; nothing is written, no receipt is created and the revision does not move. */
final class ScopeInactive extends \RuntimeException {
}
