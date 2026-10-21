<?php
/*
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Service/PermissionService.php
// SHA-256: 2b3dfd18222f09062269b3e401713fbe3fc9d921445ef6991cfbeb6783743651

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck\Service;

use OCA\Circles\Model\Member;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\AclMapper;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\IPermissionMapper;
use OCA\Deck\Db\User;
use OCA\Deck\NoPermissionException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\Cache\CappedMemoryCache;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\IManager;
use Psr\Log\LoggerInterface;
class PermissionService
{
    /** @var array<string, array<string, User>> */
    private $users = [];
    private CappedMemoryCache $boardCache;
    /** @var CappedMemoryCache<array<Acl::PERMISSION_*, bool>> */
    private CappedMemoryCache $permissionCache;
    public function __construct(private LoggerInterface $logger, private CirclesService $circlesService, private AclMapper $aclMapper, private BoardMapper $boardMapper, private IUserManager $userManager, private IGroupManager $groupManager, private IManager $shareManager, private IConfig $config, private ?string $userId)
    {
    }
    /**
     * Get current user permissions for a board by id
     *
     * @return array<Acl::PERMISSION_*, bool>
     */
    public function getPermissions(int $boardId, ?string $userId = null, bool $allowDeleted = false): array
    {
    }
    /**
     * Get current user permissions for a board
     *
     * @param Board $board
     * @return array|bool
     * @internal param $boardId
     */
    public function matchPermissions(Board $board)
    {
    }
    /**
     * check permissions for replacing dark magic middleware
     *
     * @throws NoPermissionException
     */
    public function checkPermission(?IPermissionMapper $mapper, $id, int $permission, $userId = null, bool $allowDeletedCard = false, bool $allowDeletedBoard = false): bool
    {
    }
    /**
     * @param $boardId
     * @return bool
     */
    public function userIsBoardOwner($boardId, $userId = null, bool $allowDeleted = false)
    {
    }
    /**
     * Check if permission matches the acl rules for current user and groups
     *
     * @param Acl[] $acls
     * @param $permission
     * @return bool
     */
    public function userCan(array $acls, $permission, $userId = null)
    {
    }
    /**
     * Find a list of all users (including the ones from groups)
     * Required to allow assigning them to cards
     *
     * @param $boardId
     * @param $refresh
     * @return array<string, User>
     * */
    public function findUsers($boardId, $refresh = false)
    {
    }
    public function canCreate()
    {
    }
    /**
     * Set a different user than the current one, e.g. when no user is available in occ
     *
     * @param string $userId
     */
    public function setUserId(string $userId): void
    {
    }
}
