<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Circles\CirclesManager;
use OCA\Deck\Db\Acl;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\StackService;
use OCA\Deck\Sharing\DeckShareProvider;
use OCA\Mcp\Tests\Integration\Guests\GuestDeckParityProbe;
use OCP\App\IAppManager;
use OCP\Constants;
use OCP\Files\IRootFolder;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;
use RuntimeException;
use Throwable;

/**
 * Disposable native file, ACL and share controls, always in the fresh actor process.
 */
final class CardFileLinkFixture {
	/**
	 * Bound fixed input for fixture bytes, distinct from any credential.
	 */
	private const BYTES_PREFIX = 'Native file link proof ';

	/**
	 * Bytes of entropy for collision-free disposable fixture names.
	 */
	private const SUFFIX_RANDOM_BYTES = 6;

	/**
	 * Native mtime advance that makes an etag change observable even within the same wall-clock second.
	 */
	private const ETAG_MTIME_STEP_SECONDS = 2;

	/**
	 * Dispatch one explicit native fixture action without applying MCP policy or Guests lifecycle manually.
	 *
	 * @param string $uid Disposable Deck actor already bound by the actor bootstrap.
	 * @param string $action Exact fixture action.
	 * @param array<string, mixed> $input Owned resource ledger and operation input.
	 * @return array<string, mixed> Native state observations.
	 */
	public static function run(string $uid, string $action, array $input): array {
		GuestDeckParityProbe::registerBackend(Server::get(IAppManager::class));
		return match ($action) {
			'link-seed' => self::seed($uid, $input),
			'link-inspect' => self::inspect($uid, $input),
			'link-native' => self::native($uid, $input),
			'link-cleanup' => self::cleanup($uid, $input),
			'link-change' => self::change($uid, $input),
			'link-version' => self::version($uid, $input),
			default => throw new RuntimeException('Unknown file link fixture action'),
		};
	}

	/**
	 * Create a private original, an independent share and the requested board audience.
	 *
	 * @param string $uid Disposable owner.
	 * @param array<string, mixed> $input Audience kind and optional guest actor.
	 * @return array<string, mixed> Complete owned resource ledger and original identity/hash.
	 */
	private static function seed(string $uid, array $input): array {
		$suffix = bin2hex(random_bytes(self::SUFFIX_RANDOM_BYTES));
		$boards = Server::get(BoardService::class);
		$board = $boards->create('Link proof ' . $suffix, $uid, '008800');
		$stack = Server::get(StackService::class)->create('Files', $board->getId(), 0);
		$card = Server::get(CardService::class)->create('Link ' . $suffix, $stack->getId(), 'plain', 0, $uid);
		$reader = $input['guest'] ?? DeckFixture::READER;
		if (isset($input['guest'])) {
			$boards->addAcl($board->getId(), Acl::PERMISSION_TYPE_USER, DeckFixture::READER, false, false, false);
		}
		$boards->addAcl($board->getId(), Acl::PERMISSION_TYPE_USER, $reader, true, false, false);
		$folder = Server::get(IRootFolder::class)->getUserFolder($uid);
		$file = $folder->newFile('deck-link-' . $suffix . '.txt', self::BYTES_PREFIX . $suffix);
		$manager = Server::get(IManager::class);
		$independent = $manager->createShare($manager->newShare()->setNode($file)->setShareType(IShare::TYPE_USER)
			->setSharedWith(DeckFixture::OUTSIDER)->setSharedBy($uid)->setPermissions(Constants::PERMISSION_READ));
		$editorAcl = $boards->addAcl($board->getId(), Acl::PERMISSION_TYPE_USER, DeckFixture::EDITOR, true, false, false);
		$editorShare = $manager->createShare($manager->newShare()->setNode($file)->setShareType(IShare::TYPE_USER)
			->setSharedWith(DeckFixture::EDITOR)->setSharedBy($uid)
			->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_SHARE));
		$guestShare = null;
		if (isset($input['guest'])) {
			$guestShare = $manager->createShare($manager->newShare()->setNode($file)->setShareType(IShare::TYPE_USER)
				->setSharedWith($input['guest'])->setSharedBy($uid)->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_SHARE))->getFullId();
		}
		$circleId = null;
		if (($input['kind'] ?? 'user') === 'group') {
			$boards->addAcl($board->getId(), Acl::PERMISSION_TYPE_GROUP, DeckFixture::GROUP, false, false, false);
		} elseif (($input['kind'] ?? 'user') === 'circle') {
			$circles = Server::get(CirclesManager::class);
			$circles->startSession($circles->getLocalFederatedUser($uid), true);
			$circle = $circles->createCircle('Link proof ' . $suffix, null, false, false, false);
			$circles->addMember($circle->getSingleId(), $circles->getLocalFederatedUser(DeckFixture::READER));
			$circleId = $circle->getSingleId();
			$boards->addAcl($board->getId(), Acl::PERMISSION_TYPE_CIRCLE, $circleId, false, false, false);
		}
		return ['boardId' => (int)$board->getId(), 'cardId' => (int)$card->getId(), 'fileId' => (int)$file->getId(),
			'fileName' => $file->getName(), 'etag' => $file->getEtag(), 'hash' => hash('sha256', $file->getContent()),
			'independent' => $independent->getFullId(), 'independentId' => $independent->getId(), 'circleId' => $circleId,
			'reader' => $reader, 'owner' => $uid, 'guestShare' => $guestShare,
			'editorAcl' => $editorAcl->getId(), 'editorShare' => $editorShare->getFullId()];
	}

	/**
	 * Observe exact original file bytes and association identities without modifying a file or share.
	 *
	 * @param string $uid Disposable actor whose own Files tree is observed.
	 * @param array<string, mixed> $input Owned canonical file/card identities.
	 * @return array<string, mixed> Current readability, exact file hash and visible native association state.
	 */
	private static function inspect(string $uid, array $input): array {
		$node = Server::get(IRootFolder::class)->getUserFolder($uid)->getFirstNodeById($input['fileId']);
		if ($node === null || !$node->isReadable()) {
			return ['readable' => false, 'fileId' => null, 'hash' => null, 'links' => []];
		}
		$links = [];
		foreach (Server::get(DeckShareProvider::class)->getSharesByPath($node) as $share) {
			if ((string)$share->getSharedWith() === (string)$input['cardId']) {
				$links[] = ['id' => $share->getId(), 'fileId' => (int)$share->getNodeId(),
					'permissions' => (int)$share->getPermissions(), 'type' => $share->getShareType()];
			}
		}
		$independent = Server::get(IManager::class)->getShareById($input['independent']);
		return ['readable' => true, 'fileId' => (int)$node->getId(), 'hash' => hash('sha256', $node->getContent()),
			'links' => $links, 'permissions' => (int)$node->getPermissions(),
			'independent' => ['id' => $independent->getFullId(), 'fileId' => (int)$independent->getNodeId(),
				'recipient' => $independent->getSharedWith(), 'permissions' => (int)$independent->getPermissions()]];
	}

	/**
	 * Probe the native type-12 bypass separately from MCP, preserving the original bytes.
	 *
	 * @param string $uid Disposable owner.
	 * @param array<string, mixed> $input Exact owned node/card identities.
	 * @return array<string, mixed> Native creation status and exception class/code only.
	 */
	private static function native(string $uid, array $input): array {
		$node = Server::get(IRootFolder::class)->getUserFolder($uid)->getFirstNodeById($input['fileId']);
		$manager = Server::get(IManager::class);
		try {
			$share = $manager->createShare($manager->newShare()->setNode($node)->setShareType(IShare::TYPE_DECK)
				->setSharedWith((string)$input['cardId'])->setSharedBy($uid)->setPermissions(Constants::PERMISSION_READ));
			return ['accepted' => true, 'id' => $share->getFullId(), 'permissions' => $share->getPermissions()];
		} catch (Throwable $failure) {
			return ['accepted' => false, 'class' => $failure::class, 'code' => $failure->getCode()];
		}
	}

	/**
	 * Remove only fixture-owned shares, board and original after all preservation assertions finished.
	 *
	 * @param string $uid Disposable owner.
	 * @param array<string, mixed> $input Complete owned ledger.
	 * @return array<string, mixed> Cleanup completion evidence.
	 */
	private static function cleanup(string $uid, array $input): array {
		if ($uid !== $input['owner']) {
			throw new RuntimeException('Only the fixture owner cleans its resources');
		}
		$manager = Server::get(IManager::class);
		$node = Server::get(IRootFolder::class)->getUserFolder($uid)->getFirstNodeById($input['fileId']);
		foreach (Server::get(DeckShareProvider::class)->getSharesByPath($node) as $share) {
			if ((string)$share->getSharedWith() === (string)$input['cardId']) {
				$manager->deleteShare($share);
			}
		}
		$manager->deleteShare($manager->getShareById($input['independent']));
		$manager->deleteShare($manager->getShareById($input['editorShare']));
		if ($input['guestShare'] !== null) {
			$manager->deleteShare($manager->getShareById($input['guestShare']));
		}
		Server::get(BoardService::class)->delete($input['boardId']);
		if ($input['circleId'] !== null) {
			$circles = Server::get(CirclesManager::class);
			$circles->startSession($circles->getLocalFederatedUser($uid), true);
			$circles->destroyCircle($input['circleId']);
		}
		$node->delete();
		return ['cleaned' => true];
	}

	/**
	 * Change one native prerequisite after a real preview, in a new actor process.
	 *
	 * @param string $uid Disposable owner.
	 * @param array<string, mixed> $input Owned ledger and exact changed prerequisite.
	 * @return array<string, mixed> Observed mutation outcome.
	 */
	private static function change(string $uid, array $input): array {
		$boards = Server::get(BoardService::class);
		$manager = Server::get(IManager::class);
		$node = Server::get(IRootFolder::class)->getUserFolder($uid)->getFirstNodeById($input['fileId']);
		switch ($input['change']) {
			case 'etag':
				$old = $node->getEtag();
				$node->putContent(self::BYTES_PREFIX . 'changed');
				$node->touch(time() + self::ETAG_MTIME_STEP_SECONDS);
				return ['before' => $old, 'after' => $node->getEtag()];
			case 'member':
				$boards->addAcl($input['boardId'], Acl::PERMISSION_TYPE_USER, DeckFixture::OUTSIDER, false, false, false);
				break;
			case 'edit':
				$boards->updateAcl($input['editorAcl'], false, false, false);
				break;
			case 'share':
				$manager->updateShare($manager->getShareById($input['editorShare'])->setPermissions(Constants::PERMISSION_READ));
				break;
			case 'archive':
				Server::get(CardService::class)->archive($input['cardId']);
				break;
			default:
				throw new RuntimeException('Unknown file link prerequisite change');
		}
		return ['changed' => true];
	}

	/**
	 * Observe the current native file version in a separate process, avoiding an earlier Node object's cache.
	 *
	 * @param string $uid Disposable owner.
	 * @param array<string, mixed> $input Owned file identity.
	 * @return array{etag:string,hash:string} Fresh native version and bytes.
	 */
	private static function version(string $uid, array $input): array {
		$node = Server::get(IRootFolder::class)->getUserFolder($uid)->getFirstNodeById($input['fileId']);
		return ['etag' => $node->getEtag(), 'hash' => hash('sha256', $node->getContent())];
	}

}
