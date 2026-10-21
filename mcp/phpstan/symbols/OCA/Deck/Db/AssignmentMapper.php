<?php
/*
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/AssignmentMapper.php
// SHA-256: e75ba2d2b023b905c8f826bc9c161639ed4ed19e36f0b977273b014fbc836962

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Deck\Db;

use OCA\Deck\NotFoundException;
use OCA\Deck\Service\CirclesService;
use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
/** @template-extends DeckMapper<Assignment> */
class AssignmentMapper extends DeckMapper implements IPermissionMapper
{
    /** @var CardMapper */
    private $cardMapper;
    /** @var IUserManager */
    private $userManager;
    /** @var IGroupManager */
    private $groupManager;
    /** @var CirclesService */
    private $circleService;
    public function __construct(IDBConnection $db, CardMapper $cardMapper, IUserManager $userManager, IGroupManager $groupManager, CirclesService $circleService)
    {
    }
    public function findAll(int $cardId): array
    {
    }
    public function findIn(array $cardIds): array
    {
    }
    public function findByParticipant(string $participant, $type = Assignment::TYPE_USER): array
    {
    }
    public function deleteByParticipantOnBoard(string $participant, int $boardId, $type = Assignment::TYPE_USER)
    {
    }
    public function isOwner(string $userId, int $id): bool
    {
    }
    public function findBoardId(int $id): ?int
    {
    }
    /**
     * Check if user exists before assigning it to a card
     *
     * @param Entity $entity
     * @return Assignment
     * @throws NotFoundException
     */
    public function insert(Entity $entity): Entity
    {
    }
    public function mapParticipant(Assignment $assignment): void
    {
    }
    public function isUserAssigned(int $cardId, string $userId): bool
    {
    }
    public function remapAssignedUser(int $boardId, string $userId, string $newUserId): void
    {
    }
}
