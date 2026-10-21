<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\FileAccess\References;

use OCP\Files\Node;

/**
 * The Files scope step of {@see OutputReferenceFilter}, the first gate a structured file reference passes, before
 * the exclusion tags and the native access. It applies to references coming from Talk and Tasks as well; the Notes
 * exception of the Files scope does not extend to them.
 */
interface FileReferenceScope {
    /**
     * @param string $userId authenticated user
     * @return bool whether a Files scope narrows what this user's tools may reach at all; false lets the filter
     *              skip the scope step and, when no exclusion is configured either, every resolution
     */
    public function restricts(string $userId): bool;

    /**
     * @param string $userId authenticated user
     * @param Node $node the referenced node, resolved in the user's own tree
     * @return bool whether the node is inside the user's Files scope
     */
    public function allows(string $userId, Node $node): bool;
}
