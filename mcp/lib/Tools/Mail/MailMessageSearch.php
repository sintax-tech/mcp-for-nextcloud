<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

use OCA\Mcp\Tools\ToolFailure;

/**
 * One page of a message listing or search: the folders of {@see MailMessageScope}, each searched by the Mail app's own
 * folder query with the filters of {@see MailSearchCriteria}, merged by {@see MailMessagePager}. The body filter runs
 * the Mail app's body search once per folder and call, and the cache query is then limited to the UIDs it matched.
 *
 * The Mail mapper applies a UID list in chunks, so an empty list runs no query at all. A body search that matched
 * nothing therefore needs care: with a subject filter, the subject alone still matches (the Mail query ORs the two),
 * so the folder is queried without a UID list; without one, the folder has no match and is not queried.
 */
final class MailMessageSearch {
	/**
	 * Receives the boundaries and the pager.
	 *
	 * @param MailServices $services resolver of the Mail classes, for the search query objects
	 * @param MailMessageGateway $messages the folders and messages of the Mail app
	 * @param MailMessagePager $pager pages without losing or repeating a message
	 * @return void
	 */
	public function __construct(
		private MailServices $services,
		private MailMessageGateway $messages,
		private MailMessagePager $pager = new MailMessagePager(),
	) {
	}

	/**
	 * One page of messages.
	 *
	 * @param string $userId authenticated user UID
	 * @param MailMessageScope $scope the folders to read
	 * @param MailSearchCriteria $criteria the filters
	 * @param int $limit messages wanted on the page
	 * @param string|null $cursor nextCursor of the previous page
	 * @return array{messages:list<array{message:object, accountId:int}>, hasMore:bool, nextCursor:string|null} the page
	 * @throws ToolFailure when the cursor is invalid or a page cannot end without cutting a date
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function page(string $userId, MailMessageScope $scope, MailSearchCriteria $criteria, int $limit, ?string $cursor): array {
		$fingerprint = substr(hash('sha256', json_encode([$scope->mailboxIds(), $criteria->fingerprintData()], JSON_THROW_ON_ERROR)), 0, 32);
		$before = $cursor === null ? null : MailCursor::decode($cursor, $fingerprint)->before;
		$sources = [];
		$accountOf = [];
		foreach ($scope->folders as $folder) {
			$accountOf[$folder['mailboxId']] = $folder['accountId'];
			$sources[] = $this->source($userId, $folder, $criteria);
		}
		$result = $this->pager->page($sources, function (array $ids) use ($userId, $accountOf): array {
			// Only messages of the folders searched: never a row of another folder, whatever an id pointed to.
			return array_values(array_filter($this->messages->messages($userId, $ids),
				static fn (object $message): bool => isset($accountOf[(int)$message->getMailboxId()])));
		}, $limit, $before);
		return [
			'messages' => array_map(static fn (object $message): array => ['message' => $message, 'accountId' => $accountOf[(int)$message->getMailboxId()]], $result['messages']),
			'hasMore' => $result['hasMore'],
			'nextCursor' => $result['nextBefore'] === null ? null : (new MailCursor($result['nextBefore'], $fingerprint))->encode(),
		];
	}

	/**
	 * The source of one folder for the pager.
	 *
	 * @param string $userId authenticated user UID
	 * @param array{account:object, mailbox:object, accountId:int, mailboxId:int, path:string, trash:bool} $folder the folder
	 * @param MailSearchCriteria $criteria the filters
	 * @return callable(int, ?int, ?int, ?int): list<int> ids of its matching messages, newest first
	 */
	private function source(string $userId, array $folder, MailSearchCriteria $criteria): callable {
		$uids = null;
		return function (int $limit, ?int $before, ?int $since, ?int $until) use ($userId, $folder, $criteria, &$uids): array {
			if ($criteria->searchesBody() && $uids === null) {
				$uids = $this->messages->bodyMatches($userId, $folder['account'], $folder['mailbox'],
					$criteria->toQuery($this->services, $userId, $folder['trash']));
			}
			if ($uids === [] && $criteria->subject === null) {
				return [];
			}
			$query = $criteria->toQuery($this->services, $userId, $folder['trash'], $since, $until, $before);
			return $this->messages->messageIds($userId, $folder['mailbox'], $query, $limit, $uids === [] ? null : $uids);
		};
	}
}
