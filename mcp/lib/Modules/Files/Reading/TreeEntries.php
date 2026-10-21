<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use OCP\Files\Folder;
use OCP\Files\Node;

final class TreeEntries {
    /**
     * @param Folder $folder folder whose entries are listed
     * @return list<Node> readable entries, folders first and both alphabetically, without the user's hidden files
     */
    public static function sorted(Folder $folder): array {
        $entries = [];
        foreach ($folder->getDirectoryListing() as $node) {
            // A leading dot is what Nextcloud's own file views hide, and its trash and versions folders
            // are implementation detail an agent planning a reorganization has no business touching.
            if (str_starts_with($node->getName(), '.') || !$node->isReadable()) {
                continue;
            }
            $entries[] = $node;
        }
        usort($entries, static fn (Node $a, Node $b) => [$a instanceof Folder ? 0 : 1, $a->getName()]
            <=> [$b instanceof Folder ? 0 : 1, $b->getName()]);
        return $entries;
    }
}
