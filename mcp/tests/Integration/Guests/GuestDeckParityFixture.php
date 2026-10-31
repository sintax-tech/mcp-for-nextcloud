<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Guests;

use OCA\Mcp\Tests\Integration\Deck\DeckFixture as F;
use OCA\Mcp\Tools\Deck\CardReferenceFilter;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\StackService;
use OCA;
use OCP;
use OCP\Constants;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\Files\IRootFolder;
use OC_Util;
use RuntimeException;

/**
 * Native resources and read-only probes owned solely by the guest parity scenario.
 */
final class GuestDeckParityFixture {
	/**
	 * Bound native wrapper traversal while observing storage provenance.
	 */
	private const MAX_STORAGE_WRAPPERS = 12;
	/**
	 * Native share-query sentinel requesting every matching share.
	 */
	private const UNLIMITED_SHARES = -1;

	private OCP\App\IAppManager $apps;
	private BoardService $boards;
	private CardService $cards;
	private StackService $stacks;
	private IRootFolder $root;
	private IManager $shares;

	/**
	 * Resolve the native fixture services once in the fresh owner process.
	 *
	 * @param string $uid Disposable owner identity.
	 * @return void
	 */
	private function __construct(private string $uid) {
		$this->apps = Server::get(OCP\App\IAppManager::class);
		GuestDeckParityProbe::registerBackend($this->apps);
		$this->boards = Server::get(BoardService::class);
		$this->boards->setUserId($uid);
		$this->cards = Server::get(CardService::class);
		$this->stacks = Server::get(StackService::class);
		$this->root = Server::get(IRootFolder::class);
		$this->shares = Server::get(IManager::class);
	}

	/**
	 * Execute one native action in the fresh owner process booted by DeckFixture.
	 *
	 * @param string $uid Disposable owner identity.
	 * @param string $action Explicit parity fixture action.
	 * @param array<string, mixed> $in Resource ledger and exact fixture inputs.
	 * @return array<string, mixed> Native observations or remaining resources after cleanup.
	 */
	public static function run(string $uid, string $action, array $in): array {
		if ($uid !== F::OWNER) {
			throw new RuntimeException('Only the disposable owner prepares this guest fixture');
		}
		$fixture = new self($uid);
		return match ($action) {
			'guest-external-prepare' => $fixture->prepare($in),
			'guest-external-associate' => $fixture->associate($in),
			'guest-origin-probe' => $fixture->probe($in),
			'guest-external-inspect' => $fixture->inspect($in),
			'guest-external-cleanup' => $fixture->cleanup($in),
			default => throw new RuntimeException('Unknown guest parity action'),
		};
	}

	/**
	 * Create the native files, board and guest ACL.
	 *
	 * @param array<string, mixed> $in Exact fixture inputs and resource ledger.
	 * @return array<string, mixed> Native action result.
	 */
	private function prepare(array $in): array {
		if ($this->uid !== F::OWNER || $in['guest'] !== 'mcp-it-parity-guest') {
			throw new RuntimeException('Only the disposable owner prepares this guest fixture');
		}
		$this->apps->loadApp('files_external');
		OC_Util::tearDownFS();
		OC_Util::setupFS($this->uid);
		$suffix = $in['suffix'];
		$external = $this->root->getUserFolder($this->uid)->get($in['mount'])
			->newFile('guest-deck-external-' . $suffix . '.txt', 'External Deck bytes ' . $suffix);
		$editable = $this->root->getUserFolder($this->uid)->get($in['mount'])
			->newFile('guest-share-editable-' . $suffix . '.txt', 'Editable share bytes ' . $suffix);
		$home = $this->root->getUserFolder($this->uid)->newFile('guest-deck-home-' . $suffix . '.txt', 'Home Deck bytes');
		$board = $this->boards->create('Guest external ' . $suffix, $this->uid, '008800');
		$stack = $this->stacks->create('Files', $board->getId(), 0);
		$card = $this->cards->create('External file visibility', $stack->getId(), 'plain', 0, $this->uid);
		$this->boards->addAcl($board->getId(), OCA\Deck\Db\Acl::PERMISSION_TYPE_USER, $in['guest'], true, false, false);
		$out = ['boardId' => $board->getId(), 'homeName' => $home->getName(), 'cardId' => $card->getId(), 'externalFileId' => $external->getId(),
			'externalName' => $external->getName(), 'externalHash' => hash('sha256', $external->getContent()),
			'externalMountType' => $external->getMountPoint()->getMountType(),
			'editableFileId' => $editable->getId(), 'editableName' => $editable->getName(),
			'editableHash' => hash('sha256', $editable->getContent()),
			'homeFileId' => $home->getId(), 'homeHash' => hash('sha256', $home->getContent())];
		return $out;
	}

	/**
	 * Attach the scanned files and create the independent share.
	 *
	 * @param array<string, mixed> $in Exact fixture inputs and resource ledger.
	 * @return array<string, mixed> Native action result.
	 */
	private function associate(array $in): array {
		// A fresh native process sees files scanned after creation (NC32 caches misses during post_create).
		$this->apps->loadApp('files_external');
		OC_Util::tearDownFS();
		OC_Util::setupFS($this->uid);
		$ownerRoot = $this->root->getUserFolder($this->uid);
		$external = $ownerRoot->get($in['mount'] . '/' . $in['externalName']);
		$home = $ownerRoot->getById($in['homeFileId'])[0];
		$out = [];
		foreach (['externalShare' => $external, 'homeShare' => $home] as $key => $file) {
			$created = $this->shares->createShare($this->shares->newShare()->setNode($file)->setShareType(IShare::TYPE_DECK)
				->setSharedWith((string)$in['cardId'])->setPermissions(Constants::PERMISSION_READ)->setSharedBy($this->uid));
			$out[$key] = (int)$created->getId();
			$out['shareIds'][] = $created->getFullId();
		}
		$independent = $this->shares->createShare($this->shares->newShare()->setNode($external)->setShareType(IShare::TYPE_USER)
			->setSharedWith('mcp-it-bob')->setPermissions(Constants::PERMISSION_READ)->setSharedBy($this->uid));
		$out['independent'] = ['id' => $independent->getFullId(), 'fileId' => (int)$independent->getNodeId(),
			'permissions' => $independent->getPermissions(), 'recipient' => $independent->getSharedWith()];
		return $out;
	}

	/**
	 * Observe native storage wrappers without inferring origin from paths.
	 *
	 * @param array<string, mixed> $in Exact fixture inputs and resource ledger.
	 * @return array<string, mixed> Native action result.
	 */
	private function probe(array $in): array {
		// Read-only native metadata: no mounted path is used to infer the source.
		$describe = static function ($node): array {
			$storage = $node->getStorage();
			$wrappers = [];
			$seen = [];
			$shareType = null;
			for ($depth = 0; $depth < self::MAX_STORAGE_WRAPPERS; $depth++) {
				if (isset($seen[spl_object_id($storage)])) { break; }
				$seen[spl_object_id($storage)] = true;
				$entry = ['class' => $storage::class, 'localShare' => (bool)$storage->instanceOfStorage(OCP\Files\Storage\ISharedStorage::class)];
				if ($storage instanceof OCP\Files\Storage\ISharedStorage) {
					$share = $storage->getShare();
					$shareType = $share->getShareType();
					$entry['share'] = ['type' => $share->getShareType(), 'sourceId' => $share->getNodeId(), 'owner' => $share->getShareOwner()];
				}
				$wrappers[] = $entry;
				if (!method_exists($storage, 'getWrapperStorage')) { break; }
				$storage = $storage->getWrapperStorage();
			}
			return ['id' => $node->getId(), 'path' => $node->getPath(), 'owner' => $node->getOwner()?->getUID(),
				'mountType' => $node->getMountPoint()->getMountType(), 'shareType' => $shareType, 'updateable' => $node->isUpdateable(), 'storage' => $wrappers];
		};
		$out = ['owner' => [], 'guest' => []];
		foreach (['owner' => $this->uid, 'guest' => $in['guest']] as $key => $actor) {
			$actorRoot = $this->root->getUserFolder($actor);
			foreach ([$in['externalFileId'], $in['editableFileId']] as $fileId) {
				foreach ($actorRoot->getById($fileId) as $node) {
					$out[$key][] = $describe($node) + ['relativePath' => $actorRoot->getRelativePath($node->getPath())];
				}
			}
		}
		return $out;
	}

	/**
	 * Verify the original files, links and independent shares.
	 *
	 * @param array<string, mixed> $in Exact fixture inputs and resource ledger.
	 * @return array<string, mixed> Native action result.
	 */
	private function inspect(array $in): array {
		$this->apps->loadApp('files_external');
		OC_Util::tearDownFS();
		OC_Util::setupFS($this->uid);
		$file = $this->root->getUserFolder($this->uid)->get($in['mount'] . '/' . $in['externalName']);
		$independent = $this->shares->getShareById($in['independent']['id']);
		$guestShare = $this->shares->getShareById($in['guestShare']['id']);
		$editable = $this->root->getUserFolder($this->uid)->get($in['mount'] . '/' . $in['editableName']);
		$out = ['fileId' => $file->getId(), 'hash' => hash('sha256', $file->getContent()),
			'editableFileId' => $editable->getId(), 'editableHash' => hash('sha256', $editable->getContent()),
			'attachments' => array_map(static fn ($s): int => (int)$s->getId(),
				Server::get(OCA\Deck\Sharing\DeckShareProvider::class)->getSharedWithByType($in['cardId'], IShare::TYPE_DECK, self::UNLIMITED_SHARES, 0)),
			'guestShare' => ['id' => $guestShare->getFullId(), 'fileId' => (int)$guestShare->getNodeId(),
				'permissions' => $guestShare->getPermissions(), 'recipient' => $guestShare->getSharedWith()],
			'independent' => ['id' => $independent->getFullId(), 'fileId' => (int)$independent->getNodeId(),
				'permissions' => $independent->getPermissions(), 'recipient' => $independent->getSharedWith()]];
		return $out;
	}

	/**
	 * Remove only resources recorded or named by this scenario.
	 *
	 * @param array<string, mixed> $in Exact fixture inputs and resource ledger.
	 * @return array<string, mixed> Native action result.
	 */
	private function cleanup(array $in): array {
		$this->apps->loadApp('files_external');
		OC_Util::tearDownFS();
		OC_Util::setupFS($this->uid);
		$owner = $this->root->getUserFolder($this->uid);
		$out = ['shares' => [], 'files' => [], 'boards' => []];
		// Also recover partially prepared fixtures using their exact, parent-owned random suffix.
		$names = [$in['mount'] . '/guest-deck-external-' . $in['suffix'] . '.txt',
			$in['mount'] . '/guest-share-editable-' . $in['suffix'] . '.txt',
			'guest-deck-home-' . $in['suffix'] . '.txt'];
		$ids = $in['shares'];
		foreach ($names as $name) {
			if ($owner->nodeExists($name)) {
				$file = $owner->get($name);
				foreach ([IShare::TYPE_DECK, IShare::TYPE_USER] as $type) {
					foreach ($this->shares->getSharesBy($this->uid, $type, $file, false, self::UNLIMITED_SHARES, 0) as $share) {
						$ids[] = $share->getFullId();
					}
				}
			}
		}
		foreach (array_unique($ids) as $id) {
			try {
				$this->shares->deleteShare($this->shares->getShareById($id));
			} catch (OCP\Share\Exceptions\ShareNotFound) {
				// A native board/file deletion may already have removed this scenario's share.
			}
			try {
				$this->shares->getShareById($id);
				$out['shares'][] = $id;
			} catch (OCP\Share\Exceptions\ShareNotFound) {
			}
		}
		foreach ($this->boards->findAll(CardReferenceFilter::FRESH_BOARD_SINCE, false, true) as $board) {
			if ($board->getTitle() === 'Guest external ' . $in['suffix']) {
				$this->boards->delete($board->getId());
				$this->boards->deleteForce($board->getId());
			}
		}
		foreach ($this->boards->findAll(CardReferenceFilter::FRESH_BOARD_SINCE, false, true) as $board) {
			if ($board->getTitle() === 'Guest external ' . $in['suffix']) {
				$out['boards'][] = $board->getId();
			}
		}
		foreach ($names as $name) {
			if ($owner->nodeExists($name)) {
				$owner->get($name)->delete();
			}
			if ($owner->nodeExists($name)) {
				$out['files'][] = $name;
			}
		}
		return $out;
	}

}
