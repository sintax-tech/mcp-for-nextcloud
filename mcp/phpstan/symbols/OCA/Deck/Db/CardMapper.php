<?php
/*
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/CardMapper.php
// SHA-256: 8b63c07d566ea1728474fed36b4e4951deabece3b11a5eb8c446502c5ef083b4

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck\Db;

use DateTime;
use Exception;
use OCA\Deck\AppInfo\Application;
use OCA\Deck\Search\Query\SearchQuery;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager;
/** @template-extends QBMapper<Card> */
class CardMapper extends QBMapper implements IPermissionMapper
{
    /** @var LabelMapper */
    private $labelMapper;
    /** @var IUserManager */
    private $userManager;
    /** @var IGroupManager */
    private $groupManager;
    /** @var IManager */
    private $notificationManager;
    /** @var ICache */
    private $cache;
    private $databaseType;
    private $database4ByteSupport;
    public function __construct(IDBConnection $db, LabelMapper $labelMapper, IUserManager $userManager, IGroupManager $groupManager, IManager $notificationManager, ICacheFactory $cacheFactory, $databaseType = 'sqlite3', $database4ByteSupport = true)
    {
    }
    public function insert(Entity $entity): Entity
    {
    }
    public function update(Entity $entity, $updateModified = true): Entity
    {
    }
    public function markNotified(Card $card): Entity
    {
    }
    public function find($id, bool $enhance = true): Card
    {
    }
    /**
     * @return Card[]
     * @throws \OCP\DB\Exception
     */
    public function findAll($stackId, ?int $limit = null, int $offset = 0, int $since = -1)
    {
    }
    /**
     * @param int[] $stackIds
     * @return array<int, null|Card[]>
     * @throws \OCP\DB\Exception
     */
    public function findAllForStacks(array $stackIds, ?int $limit = null, int $offset = 0, int $since = -1): array
    {
    }
    public function queryCardsByBoard(int $boardId): IQueryBuilder
    {
    }
    public function queryCardsByBoards(array $boardIds): IQueryBuilder
    {
    }
    /**
     * @return Card[]
     */
    public function findToDelete(int $timeLimit, ?int $limit = null): array
    {
    }
    /**
     * @return Card[]
     */
    public function findDeleted(int $boardId, ?int $limit = null, int $offset = 0): array
    {
    }
    /**
     * @return Card[]
     */
    public function findCalendarEntries(int $boardId, ?int $limit = null, $offset = 0): array
    {
    }
    public function findAllArchived($stackId, $limit = null, $offset = null)
    {
    }
    public function findAllByStack($stackId, $limit = null, $offset = null)
    {
    }
    public function findAllByBoardId(int $boardId, ?int $limit = null, ?int $offset = null): array
    {
    }
    /**
     * @param int[] $boardIds
     * @return Card[]
     */
    public function findAllWithDue(array $boardIds): array
    {
    }
    /**
     * @param int[] $boardIds
     * @return Card[]
     */
    public function findToMeOrNotAssignedCards(array $boardIds, string $username): array
    {
    }
    /**
     * @return Card[]
     */
    public function findOverdue(): array
    {
    }
    /**
     * @return Card[]
     */
    public function findUnexposedDescriptionChances()
    {
    }
    /**
     * @return Card[]
     */
    public function search(array $boardIds, SearchQuery $query, ?int $limit = null, ?int $offset = null): array
    {
    }
    public function searchComments(array $boardIds, SearchQuery $query, ?int $limit = null, ?int $offset = null): array
    {
    }
    public function searchRaw($boardIds, $term, $limit = null, $offset = null)
    {
    }
    public function delete(Entity $entity): Entity
    {
    }
    public function deleteByStack($stackId): void
    {
    }
    public function assignLabel(int $card, int $label): void
    {
    }
    public function removeLabel(int $card, int $label): void
    {
    }
    public function isOwner(string $userId, int $id): bool
    {
    }
    public function findBoardId(int $id): ?int
    {
    }
    public function mapOwner(Card &$card)
    {
    }
    public function transferOwnership(string $ownerId, string $newOwnerId, ?int $boardId = null): void
    {
    }
    public function remapCardOwner(int $boardId, string $userId, string $newUserId): void
    {
    }
    public function getAllCardIds(): array
    {
    }
}
