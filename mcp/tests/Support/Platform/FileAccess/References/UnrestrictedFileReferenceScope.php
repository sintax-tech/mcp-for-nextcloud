<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Platform\FileAccess\References;

use OCA\Mcp\Platform\FileAccess\References\FileReferenceScope;
use OCP\Files\Node;

/** A user without a Files scope: the scope step narrows nothing, for tests of the other steps of the filter. */
final class UnrestrictedFileReferenceScope implements FileReferenceScope {
    public function restricts(string $userId): bool {
        return false;
    }

    public function allows(string $userId, Node $node): bool {
        return true;
    }
}
