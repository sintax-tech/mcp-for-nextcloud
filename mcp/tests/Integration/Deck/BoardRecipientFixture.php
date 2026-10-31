<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OC;
use OCA\Circles\CirclesManager;
use OCA\Circles\Model\Member;
use OCA\Deck\Db\Acl;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\CirclesService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Service\StackService;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\Policy\PolicyWriter;
use OCA\Mcp\Tools\Deck\BoardRecipientResolver;
use OCA\Mcp\Tools\Deck\DeckRecipientDirectory;
use OCA\Mcp\Tools\Deck\DeckRefusalException;
use OCA\Mcp\Tools\Deck\DeckServiceContext;
use OCA\Mcp\Tools\Deck\DeckServiceGateway;
use OCA\Mcp\Tools\Deck\LinkRecipientContext;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Native membership fixtures in fresh actor processes; no share or Files operation is used.
 */
final class BoardRecipientFixture {
	/**
	 * Opaque file identity bound by DK-4r only; the node itself is authorized in DK-4b.
	 */
	private const CONTEXT_FILE_ID = 123;

	/**
	 * Stable observed version for the fixture's opaque file context.
	 */
	private const CONTEXT_ETAG = 'fixture-etag';

	/**
	 * Dispatch one isolated actor action, recording the exact share inventory on both sides.
	 *
	 * @param string $uid Disposable actor already bound by actor.php.
	 * @param string $action Explicit fixture action.
	 * @param array $input Fixture identities and requested mutation.
	 * @return array Result plus read-only share evidence.
	 */
	public static function run(string $uid, string $action, array $input): array {
		file_put_contents('/tmp/mcp-it-observations.txt', json_encode(['recipientCirclesVersion' => Server::get(IAppManager::class)->getAppVersion('circles')], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
		$before = self::shares();
		$result = match ($action) {
			'recipient-seed' => self::seed($uid),
			'recipient-resolve' => self::resolve($uid, $input),
			'recipient-membership' => self::membership($input),
			'recipient-cleanup' => self::cleanup($input),
			default => throw new RuntimeException('Unknown recipient fixture action'),
		};
		$result['sharesBefore'] = $before;
		$result['sharesAfter'] = self::shares();
		file_put_contents('/tmp/mcp-it-observations.txt', json_encode(['recipientAction' => $action,
			'major' => getenv('MCP_IT_NC'), 'result' => $result], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
		return $result;
	}

	/**
	 * Return core share IDs without creating, changing or deleting any share.
	 *
	 * @return list<int> Stable complete share inventory.
	 */
	private static function shares(): array {
		$query = Server::get(IDBConnection::class)->getQueryBuilder();
		$rows = $query->select('id')->from('share')->orderBy('id')->executeQuery()->fetchAll();
		return array_map(static fn (array $row): int => (int)$row['id'], $rows);
	}

	/**
	 * Create overlapping native user/group/circle ACLs and a board without circle ACLs.
	 *
	 * @param string $uid Disposable owner.
	 * @return array Board, card and circle identities used by all later fresh actors.
	 */
	private static function seed(string $uid): array {
		$boards = Server::get(BoardService::class);
		$cards = Server::get(CardService::class);
		$stacks = Server::get(StackService::class);
		$suffix = bin2hex(random_bytes(6));
		$board = $boards->create('Recipients ' . $suffix, $uid, '008800');
		$stack = $stacks->create('Recipients', $board->getId(), 0);
		$card = $cards->create('Recipients', $stack->getId(), 'plain', 0, $uid);
		$boards->addAcl($board->getId(), Acl::PERMISSION_TYPE_USER, DeckFixture::READER, false, false, false);
		$boards->addAcl($board->getId(), Acl::PERMISSION_TYPE_USER, DeckFixture::EDITOR, true, false, false);
		$boards->addAcl($board->getId(), Acl::PERMISSION_TYPE_GROUP, DeckFixture::GROUP, false, false, false);
		$plain = $boards->create('Recipients plain ' . $suffix, $uid, '008800');
		$plainStack = $stacks->create('Plain', $plain->getId(), 0);
		$plainCard = $cards->create('Plain', $plainStack->getId(), 'plain', 0, $uid);
		$circles = self::circles();
		$circle = $circles->createCircle('Recipients ' . $suffix, null, false, false, false);
		$nested = $circles->createCircle('Nested ' . $suffix, null, false, true, false);
		$circles->addMember($nested->getSingleId(), $circles->getLocalFederatedUser(DeckFixture::EDITOR));
		$group = $circles->getFederatedUser(DeckFixture::GROUP, Member::TYPE_GROUP);
		$circles = self::circles();
		$circles->addMember($circle->getSingleId(), $group);
		$child = $circles->getFederatedUser($nested->getSingleId(), Member::TYPE_CIRCLE);
		$circles = self::circles();
		$circles->addMember($circle->getSingleId(), $child);
		$boards->addAcl($board->getId(), Acl::PERMISSION_TYPE_CIRCLE, $circle->getSingleId(), false, false, false);
		return ['board' => $board->getId(), 'card' => $card->getId(), 'plainBoard' => $plain->getId(),
			'plainCard' => $plainCard->getId(), 'circle' => $circle->getSingleId(), 'nested' => $nested->getSingleId(),
			'circlesVersion' => Server::get(IAppManager::class)->getAppVersion('circles'),
			'nativeMembers' => self::nativeMembers($circle->getSingleId())];
	}

	/**
	 * Observe native container/person classifications independently of the application resolver.
	 *
	 * @param string $circleId Disposable circle authorized to this fixture owner.
	 * @return list<array{id:string,singleId:string,containerId:?string,type:int,level:int,local:bool}> Native inherited membership evidence.
	 */
	private static function nativeMembers(string $circleId): array {
		$circle = Server::get(CirclesService::class)->getCircle($circleId);
		return array_map(static fn ($member): array => ['id' => $member->getUserId(), 'singleId' => $member->getSingleId(),
			'containerId' => $member->getUserType() === Member::TYPE_CIRCLE ? $member->getBasedOn()?->getSingleId() : null,
			'type' => $member->getUserType(), 'level' => $member->getLevel(), 'local' => $member->isLocal()],
			$circle->getInheritedMembers(true));
	}

	/**
	 * Resolve through real authorization and directories, comparing the native Deck reader set.
	 *
	 * @param string $uid Authenticated disposable actor.
	 * @param array $input Authorized source and optional denied grant.
	 * @return array Complete snapshot or a generic refusal with no partial count.
	 */
	private static function resolve(string $uid, array $input): array {
		$policy = Server::get(GrantPolicy::class);
		$previous = [];
		try {
			foreach ([['deck', 'edit'], ['files', 'read'], ['files', 'share']] as [$module, $operation]) {
				$values = Server::get(IConfig::class)->getUserValueForUsers('mcp', 'grant_' . $module . '_' . $operation, [$uid]);
				$previous[] = [$module, $operation, array_key_exists($uid, $values) ? (string)$values[$uid] : null];
				$policy->setGrant($uid, $module, $operation, ($input['deny'] ?? '') !== "$module.$operation");
			}
			$session = Server::get(ISession::class);
			$gateway = new DeckServiceGateway(OC::$server, Server::get(ITimeFactory::class), null, $session);
			$directory = new DeckRecipientDirectory(new DeckServiceContext(OC::$server, $session),
				Server::get(IUserManager::class), Server::get(IGroupManager::class), Server::get(IAppManager::class));
			$resolver = new BoardRecipientResolver($gateway, $policy, $directory, new PlanState(Server::get(IConfig::class)), Server::get(LoggerInterface::class));
			try {
				// DK-4r only binds file identity/version; authorization of the real Files node belongs to DK-4b.
				$snapshot = $resolver->resolve(new LinkRecipientContext($uid, $input['board'], $input['card'], self::CONTEXT_FILE_ID, self::CONTEXT_ETAG));
				$native = array_map(static fn ($user): string => $user->getUID(),
					array_values(Server::get(PermissionService::class)->findUsers($input['board'], true)));
				sort($native, SORT_STRING);
				return ['uids' => $snapshot->uids, 'native' => $native, 'presentation' => $snapshot->presentation(),
					'recipientHash' => $snapshot->recipientHash, 'planHash' => $snapshot->planHash];
			} catch (DeckRefusalException) {
				return ['refused' => true];
			}
		} finally {
			foreach ($previous as [$module, $operation, $value]) {
				if ($value === null) {
					Server::get(PolicyWriter::class)->resetOverride($uid, $module, $operation);
				} else {
					$policy->setGrant($uid, $module, $operation, $value === '1');
				}
				$values = Server::get(IConfig::class)->getUserValueForUsers('mcp', 'grant_' . $module . '_' . $operation, [$uid]);
				$restored = array_key_exists($uid, $values) ? (string)$values[$uid] : null;
				if ($restored !== $value) {
					throw new RuntimeException('Recipient fixture failed to restore the exact grant override');
				}
			}
		}
	}

	/**
	 * Mutate a real group or circle between independent resolution requests.
	 *
	 * @param array $input Container identity and add/remove operation.
	 * @return array Member identity needed for exact circle removal.
	 */
	private static function membership(array $input): array {
		if ($input['kind'] === 'group') {
			$group = Server::get(IGroupManager::class)->get(DeckFixture::GROUP);
			$user = Server::get(IUserManager::class)->get(DeckFixture::OUTSIDER);
			$input['add'] ? $group->addUser($user) : $group->removeUser($user);
			return ['changed' => true];
		}
		$circles = self::circles();
		if ($input['add']) {
			$user = $circles->getLocalFederatedUser(DeckFixture::OUTSIDER);
			$circles = self::circles();
			$member = $circles->addMember($input['circle'], $user);
			return ['member' => $member->getId()];
		}
		$circles->removeMember($input['member']);
		return ['changed' => true];
	}

	/**
	 * Bind the native Circles owner with synchronous inheritance updates.
	 *
	 * @return CirclesManager Session-bound manager.
	 */
	private static function circles(): CirclesManager {
		Server::get(IAppManager::class)->loadApp('circles');
		$circles = Server::get(CirclesManager::class);
		$circles->startSession($circles->getLocalFederatedUser(DeckFixture::OWNER), true);
		return $circles;
	}

	/**
	 * Remove only disposable boards/circles and undo the optional group change.
	 *
	 * @param array $input Complete seed identities.
	 * @return array Cleanup confirmation.
	 */
	private static function cleanup(array $input): array {
		$group = Server::get(IGroupManager::class)->get(DeckFixture::GROUP);
		$group->removeUser(Server::get(IUserManager::class)->get(DeckFixture::OUTSIDER));
		$boards = Server::get(BoardService::class);
		foreach ([$input['board'], $input['plainBoard']] as $id) {
			$boards->delete($id);
			$boards->deleteForce($id);
		}
		$circles = self::circles();
		foreach ([$input['circle'], $input['nested']] as $id) {
			self::circles()->destroyCircle($id);
		}
		return ['cleaned' => true];
	}
}
