<?php
/*
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/StackMapper.php
// SHA-256: 1a0f763125be937b95bc6ce96d6f7c69db42a8768d6367d87f8ba1e05a7ef0d7

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\Cache\CappedMemoryCache;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
/** @template-extends DeckMapper<Stack> */
class StackMapper extends DeckMapper implements IPermissionMapper
{
    private CappedMemoryCache $stackCache;
    private CardMapper $cardMapper;
    private ICache $cache;
    public function __construct(IDBConnection $db, CardMapper $cardMapper, ICacheFactory $cacheFactory)
    {
    }
    /**
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws \OCP\DB\Exception
     */
    public function find(int $id): Stack
    {
    }
    /**
     * @throws \OCP\DB\Exception
     */
    public function findStackFromCardId(int $cardId): ?Stack
    {
    }
    /**
     * @return Stack[]
     * @throws \OCP\DB\Exception
     */
    public function findAll(int $boardId, ?int $limit = null, int $offset = 0): array
    {
    }
    /**
     * @throws \OCP\DB\Exception
     */
    public function findDeleted(int $boardId, ?int $limit = null, int $offset = 0): array
    {
    }
    public function update(Entity $entity): Entity
    {
    }
    public function delete(Entity $entity): Entity
    {
    }
    /**
     * @throws \OCP\DB\Exception
     */
    public function isOwner(string $userId, int $id): bool
    {
    }
    /**
     * @throws \OCP\DB\Exception
     */
    public function findBoardId(int $id): ?int
    {
    }
    /**
     * @return array<Stack>
     * @throws \OCP\DB\Exception
     */
    public function findToDelete(): array
    {
    }
}
