<?php
/*
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/DeckMapper.php
// SHA-256: dc9c90201d973cff23b03bc3e490e6fe91ff9205d0d6b587ad51f07bad6a3837

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck\Db;

use Generator;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
/**
 * @template T of Entity
 * @template-extends QBMapper<T>
 */
abstract class DeckMapper extends QBMapper
{
    /**
     * @return T
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     */
    public function find(int $id): Entity
    {
    }
    /**
     * Helper function to split passed array into chunks of 1000 elements and
     * call a given callback for fetching query results
     *
     * Can be useful to limit to 1000 results per query for oracle compatibility
     * but still iterate over all results
     */
    public function chunkQuery(array $ids, callable $callback): Generator
    {
    }
}
