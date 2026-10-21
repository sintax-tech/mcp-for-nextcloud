<?php
/*
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/BoardMapper.php
// SHA-256: c3be762590fb7f490d6f08c27dd9855e10d61d9f19a9e15298eefc7f61864fd4

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck\Db;

use OCA\Deck\Service\CirclesService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\Cache\CappedMemoryCache;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
/** @template-extends QBMapper<Board> */
class BoardMapper extends QBMapper implements IPermissionMapper
{
    /** @var CappedMemoryCache<Board[]> */
    private CappedMemoryCache $userBoardCache;
    /** @var CappedMemoryCache<Board> */
    private CappedMemoryCache $boardCache;
    public function __construct(IDBConnection $db, private LabelMapper $labelMapper, private AclMapper $aclMapper, private StackMapper $stackMapper, private IUserManager $userManager, private IGroupManager $groupManager, private CirclesService $circlesService, private LoggerInterface $logger)
    {
    }
    /**
     * @param $id
     * @param bool $withLabels
     * @param bool $withAcl
     * @return Board
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws DoesNotExistException
     */
    public function find(int $id, bool $withLabels = false, bool $withAcl = false, bool $allowDeleted = false): Board
    {
    }
    public function findBoardIds(string $userId): array
    {
    }
    public function findAllForUser(string $userId, ?int $since = null, bool $includeArchived = true, ?int $before = null, ?string $term = null): array
    {
    }
    /**
     * Find all boards for a given user
     */
    public function findAllByUser(string $userId, ?int $limit = null, ?int $offset = null, ?int $since = null, bool $includeArchived = true, ?int $before = null, ?string $term = null): array
    {
    }
    public function findAllByOwner(string $userId, ?int $limit = null, ?int $offset = null)
    {
    }
    /**
     * Find all boards for a given user
     */
    public function findAllByGroups(string $userId, array $groups, ?int $limit = null, ?int $offset = null, ?int $since = null, bool $includeArchived = true, ?int $before = null, ?string $term = null): array
    {
    }
    public function findAllByCircles(string $userId, ?int $limit = null, ?int $offset = null, ?int $since = null, bool $includeArchived = true, ?int $before = null, ?string $term = null)
    {
    }
    public function findAllByTeam(string $teamId): array
    {
    }
    public function findTeamsForBoard(int $boardId): array
    {
    }
    public function isSharedWithTeam(int $boardId, string $teamId): bool
    {
    }
    public function findAll(): array
    {
    }
    public function findToDelete()
    {
    }
    public function delete(
        /** @noinspection PhpUnnecessaryFullyQualifiedNameInspection */
        \OCP\AppFramework\Db\Entity $entity
    ): \OCP\AppFramework\Db\Entity
    {
    }
    public function isOwner(string $userId, int $id): bool
    {
    }
    public function findBoardId(int $id): ?int
    {
    }
    public function mapAcl(Acl &$acl): void
    {
    }
    /**
     * @param Board $board
     */
    public function mapOwner(Board &$board)
    {
    }
    /**
     * @throws \OCP\DB\Exception
     */
    public function transferOwnership(string $ownerId, string $newOwnerId, $boardId = null): void
    {
    }
    /**
     * Reset cache for a given board or a given user
     */
    public function flushCache(?int $boardId = null, ?string $userId = null)
    {
    }
}
