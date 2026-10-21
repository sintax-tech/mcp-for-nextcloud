<?php
/*
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/Connector/Sabre/CachingTree.php
// SHA-256: 6e71e593b131807e057ee9f9609cae4fb91a69b5d4e956f2ad5eadfca1517412

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\DAV\Connector\Sabre;

use Sabre\DAV\Tree;
class CachingTree extends Tree
{
    /**
     * Store a node in the cache
     */
    public function cacheNode(Node $node, ?string $path = null): void
    {
    }
    /**
     * @param string $path
     * @return void
     */
    public function markDirty($path)
    {
    }
}
