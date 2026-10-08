<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCP\Files\Folder;
use OCP\Files\Node;

/** A move that passed every check: the node to move, where it goes, and the folder that receives it. */
final class Inspection {
    public function __construct(
        public readonly Node $source,
        public readonly Folder $destination,
        /** Normalized destination path, user-relative. */
        public readonly string $to,
    ) {}
}
