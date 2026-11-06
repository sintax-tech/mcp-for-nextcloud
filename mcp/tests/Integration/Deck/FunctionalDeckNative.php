<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\StackService;
use OCP\Server;

/** Native fixture writes and fresh raw persistence observations; no MCP business logic is implemented here. */
final class FunctionalDeckNative {
	/**
	 * @param string $uid Bound native caller.
	 * @param string $action Explicit fixture action.
	 * @param array<string,mixed> $in Owned IDs and test values.
	 * @return array<string,mixed> Persisted observations or native IDs.
	 */
	public static function run(string $uid, string $action, array $in): array {
		if ($action === 'activity') { return CardActivityProbe::read($uid, (int)$in['card']); }
		$cards = Server::get(CardService::class);
		$boards = Server::get(BoardService::class);
		$stacks = Server::get(StackService::class);
		if ($action === 'seed') {
			$board = $boards->create('Deck functional proof', $uid, '008800');
			$boards->addAcl($board->getId(), 0, $in['editor'], true, false, false);
			$boards->addAcl($board->getId(), 0, $in['service'], true, false, false);
			$stack = $stacks->create('Todo', $board->getId(), 0);
			$done = $stacks->create('Completed', $board->getId(), 1);
			$out = ['board' => $board->getId(), 'stack' => $stack->getId(), 'doneStack' => $done->getId()];
			foreach (['card', 'nativeCard', 'selfCard', 'serviceCard', 'faultCard', 'nativeFaultCard'] as $name) {
				$out[$name] = $cards->create('Synthetic ' . $name, $stack->getId(), 'note', 0, $uid, 'Preserved', '2026-10-03T02:47:19Z')->getId();
			}
			return $out;
		}
		if ($action === 'column') {
			$supported = method_exists($stacks, 'setDoneStack');
			if ($supported) { $stacks->setDoneStack($in['doneStack'], $in['board'], $in['enabled']); }
			return ['supported' => $supported];
		}
		if ($action === 'native-status') {
			match ($in['method']) {
				'done' => $cards->done($in['card']), 'undone' => $cards->undone($in['card']),
				'archive' => $cards->archive($in['card']), 'unarchive' => $cards->unarchive($in['card']),
			};
			return ['applied' => true];
		}
		if ($action === 'read-only') {
			foreach (Server::get(\OCA\Deck\Db\AclMapper::class)->findAll($in['board']) as $acl) {
				if ($acl->getParticipant() === $in['editor']) { $boards->updateAcl($acl->getId(), (bool)($in['edit'] ?? false), false, false); }
			}
			return ['applied' => true];
		}
		if ($action === 'cleanup') {
			$boards->delete($in['board']);
			return ['cleaned' => true];
		}
		if ($action === 'read') {
			$card = $cards->find($in['card']);
			$db = Server::get(\OCP\IDBConnection::class);
			$q = $db->getQueryBuilder();
			$row = $q->select('done', 'archived', 'stack_id', 'last_modified')->from('deck_cards')
				->where($q->expr()->eq('id', $q->createNamedParameter($in['card'])))->executeQuery()->fetch();
			$assignments = Server::get(\OCA\Deck\Db\AssignmentMapper::class)->findAll($in['card']);
			return ['done' => $card->getDone()?->getTimestamp(), 'archived' => (bool)$card->getArchived(),
				'stackId' => $card->getStackId(), 'lastModified' => $card->getLastModified(),
				'assignees' => array_map(static fn ($a): string => (string)$a->getParticipant(), $assignments),
				'raw' => $row, 'clock' => time(), 'timezone' => date_default_timezone_get(),
				'duedate' => $card->getDuedate()?->getTimestamp()];
		}
		if ($action === 'devices') {
			$db = Server::get(\OCP\IDBConnection::class);
			$result = [];
			foreach (['notifications_pushhash', 'notifications_webpush'] as $table) {
				try {
					$q = $db->getQueryBuilder();
					$result[$table] = (int)$q->select($q->func()->count('*'))->from($table)
						->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))->executeQuery()->fetchOne();
				} catch (\Throwable $e) {
					$result[$table] = ['unavailable' => $e::class];
				}
			}
			return $result;
		}
		throw new \RuntimeException('Unknown functional fixture action');
	}
}
