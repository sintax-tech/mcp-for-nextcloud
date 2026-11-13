<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Deck\Db\LabelMapper;
use OCA\Deck\Service\{BoardService, CardService, StackService};
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyWriter;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Mcp\Tools\{ToolRegistry, Discovery\CapabilityRegistry};
use OCA\Mcp\Modules\Deck\DeckModule;
use OCP\Server;

/** Fresh-process native observations and MCP calls for disposable board-label resources. */
final class BoardLabelFixture {
	/** @param string $uid Disposable actor. @param string $action Explicit action. @param array $in Owned ledger. @return array Observed state. */
	public static function run(string $uid, string $action, array $in): array {
		$boards = Server::get(BoardService::class);
		$boards->setUserId($uid);
		if ($action === 'board-label-grants') { return self::grants(); }
		if ($action === 'board-label-seed') {
			$board = $boards->create('MCP labels ' . bin2hex(random_bytes(6)), $uid, '008800');
			$stack = Server::get(StackService::class)->create('Labels', $board->getId(), 0);
			$boards->addAcl($board->getId(), 0, DeckFixture::READER, false, false, false);
			$boards->addAcl($board->getId(), 0, DeckFixture::EDITOR, true, false, false);

			return ['grants' => self::grants(), 'boardId' => $board->getId(), 'stackId' => $stack->getId(), 'owner' => $uid];
		}
		if ($action === 'board-label-cleanup') {
			if ($uid !== $in['owner']) { throw new \RuntimeException('Only fixture owner may clean'); }
			try {
				$boards->delete($in['boardId']);
				$boards->deleteForce($in['boardId']);
			} finally {
				foreach ($in['grants'] as $actor => $operations) {
					foreach ($operations as $operation => $before) {
						if ($before === null) {
							Server::get(PolicyWriter::class)->resetOverride($actor, 'deck', $operation);
						} else {
							Server::get(GrantPolicy::class)->setGrant($actor, 'deck', $operation, $before === '1');
						}
					}
				}
			}
			return ['cleaned' => true];
		}
		if ($action === 'board-label-inspect') {
			$labels = array_map(static fn ($l): array => ['id' => (int)$l->getId(), 'title' => $l->getTitle(), 'color' => $l->getColor()],
				Server::get(LabelMapper::class)->findAll($in['boardId']));
			$cards = [];
			foreach ($in['cardIds'] ?? [] as $id) {
				$card = Server::get(CardService::class)->find($id);
				$assigned = array_map(static fn ($l): int => (int)$l->getId(), $card->getLabels());
				sort($assigned);
				$cards[$id] = ['id' => $card->getId(), 'title' => $card->getTitle(), 'labels' => $assigned];
			}
			$all = Server::get(StackService::class)->findAll($in['boardId']);
			return ['labels' => $labels, 'cards' => $cards,
				'cardCount' => array_sum(array_map(static fn ($s): int => count($s->getCards()), $all))];
		}
		if ($action !== 'board-label-call') { throw new \RuntimeException('Unknown board label fixture action'); }
		$policy = Server::get(GrantPolicy::class);
		$policy->setGrant($uid, 'deck', 'labels', $in['grant'] ?? true);
		$policy->setGrant($uid, 'deck', 'create', true);
		$registry = new ToolRegistry([Server::get(DeckModule::class)], $policy, Server::get(\OCP\App\IAppManager::class),
			Server::get(\OCP\IUserManager::class), Server::get(\Psr\Log\LoggerInterface::class),
			Server::get(GuestRestrictions::class), Server::get(CapabilityRegistry::class));
		$out = ['tools' => array_column($registry->list($uid), 'name')];
		try {
			$out['result'] = $registry->call($in['tool'], $in['arguments'], $uid);
		} catch (\InvalidArgumentException) {
			$out['refused'] = true;
		}
		return $out;
	}
	/** @return array Raw grant values, preserving absent overrides as null. */
	private static function grants(): array {
		$grants = [];
		$config = Server::get(\OCP\IConfig::class);
		foreach ([DeckFixture::OWNER, DeckFixture::EDITOR, DeckFixture::READER] as $actor) {
			foreach (['labels', 'create'] as $operation) {
				$values = $config->getUserValueForUsers('mcp', 'grant_deck_' . $operation, [$actor]);
				$grants[$actor][$operation] = $values[$actor] ?? null;
			}
		}
		return $grants;
	}
	/** Static actor fixture. */
	private function __construct() {
	}
}
