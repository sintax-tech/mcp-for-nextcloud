<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mail\Exception\ClientException;
use OCP\AppFramework\Db\DoesNotExistException;

require_once __DIR__ . '/../../../stubs/OCA/Mail/Exception/ClientException.php';
require_once __DIR__ . '/../../../stubs/Horde/ImapClientStubs.php';

/**
 * A Mail app with folders, messages and an IMAP server in memory, behind the same MailServices the message tools use.
 *
 * Every service is a strict {@see RecordingDouble}, so a sync, a flag change or any write is an error. The message
 * mapper filters like the Mail mapper (grouping by thread unless told not to, as SearchQuery does since Mail 5.5) and
 * orders by the stored date only, returning messages of the same date in an order
 * opposite to the tools' own and limited to the page, as the real query may: a pager that ended a page inside a date
 * would lose messages here. The IMAP side records every command (`imap:status`, `imap:fetch:<items>`, `imap:body`,
 * `imap:attachment:<part>`, `imap:select`, `imap:store:<+|-><flag>`, `imap:move:<destination>`, `imap:logout`); only a
 * STORE of the IMAP message mapper changes the server flags it keeps per message (`serverFlags`), only a MOVE takes a
 * message off the server's source folder (`onServer`, recorded in `moved` with its new UID), and a STORE or MOVE on a
 * client that did not select the folder read-write is recorded with `(not selected)`. An attachment read on another client than the
 * last one handed out is recorded as `imap:attachment:<part>(other client)`.
 */
final class FakeMailMessageApp extends FakeMailApp {
	/** @var array<int, array<string, mixed>> folders by id */
	public array $folders = [];

	/** @var array<int, array<string, mixed>> messages by id */
	public array $messages = [];

	/** @var array<string, string> UIDVALIDITY of each folder on the server, by folder name (default 7) */
	public array $uidValidity = [];

	/**
	 * @var array<string, string> UIDVALIDITY a folder has once a client opens it, by folder name, when it was recreated
	 *     between a STATUS and the first SELECT/EXAMINE (default: the one of $uidValidity)
	 */
	public array $uidValidityOnOpen = [];

	/** Whether the next getImapMessage() throws a server error. */
	public bool $serverFails = false;

	/** Search query handed out instead of a new FakeSearchQuery, to stand for another Mail release. */
	public ?object $searchQuery = null;

	/** Whether the next getAttachment() throws a server error. */
	public bool $attachmentFails = false;

	/** Whether the next STORE throws a server error. */
	public bool $storeFails = false;

	/** Whether a read-write SELECT throws a server error. */
	public bool $selectFails = false;

	/** Whether the next MOVE throws a server error. */
	public bool $moveFails = false;

	/**
	 * Whether the next MOVE copies the message to the destination, keeps the original and then throws, as a UID COPY
	 * that succeeded before the UID EXPUNGE of the fallback, or its answer, was lost.
	 */
	public bool $copyThenFails = false;

	/** @var list<string> capabilities the IMAP server reports (Horde's `capability`) */
	public array $capabilities = ['MOVE', 'UIDPLUS'];

	/** Whether reading the capabilities throws. */
	public bool $capabilityFails = false;

	/** @var list<array{id:int, mailbox:string}> messages the server copied while keeping the original */
	public array $copied = [];

	/**
	 * How the next draft save fails: null (it works), 'refused' (ClientException before any write) or 'after' (the draft
	 * is appended, then the save throws).
	 */
	public ?string $draftFails = null;

	/**
	 * What the next Outbox send records: the status and whether the message was built (a Message-ID header), or 'save' /
	 * 'send' for a save or a send that throws.
	 *
	 * @var array{status:int|null, built:bool}|string
	 */
	public array|string $sendOutcome = ['status' => 12, 'built' => true];

	/** @var list<array{id:int, type:int, accountId:int, subject:string, body:string, sendAt:int|null, to:list<array{email:string, label:string}>, cc:list<array{email:string, label:string}>, bcc:list<array{email:string, label:string}>, attachments:array<mixed>}> messages saved to the Outbox */
	public array $outbox = [];

	/** UID the next draft save returns instead of the one it gives the draft; null keeps the real one. */
	public ?int $draftUidAnswer = null;

	/** @var list<array{mailbox:string, to:string, cc:string, bcc:string, subject:string, body:string, attachments:array<mixed>, html:bool, previous:mixed, uid:int}> drafts the server holds */
	public array $drafts = [];

	/** Class name the services give for the Mail delete event. */
	public string $deleteEvent = 'OCA\\Mail\\Events\\MessageDeletedEvent';

	/** @var list<array{id:int, mailbox:string, uid:int}> messages the server moved, where they went */
	public array $moved = [];

	/** Class name the services give for the Mail flag event. */
	public string $flagEvent = 'OCA\\Mail\\Events\\MessageFlaggedEvent';

	/** The client the factory handed out last. */
	private ?object $lastClient = null;

	/** @var list<object> clients that selected a folder read-write */
	private array $selectingClients = [];

	/**
	 * Stores a cached folder of an account.
	 *
	 * @param int $accountId Mail account id
	 * @param array<string, mixed> $fields id, name, specialUse, cached, locked, selectable, and the three sync tokens
	 *     newToken/changedToken/vanishedToken (default: Horde tokens of UIDVALIDITY 7, as after a sync)
	 * @return self this app
	 */
	public function withFolder(int $accountId, array $fields): self {
		$token = self::syncToken('7');
		$this->folders[(int)$fields['id']] = $fields + ['accountId' => $accountId, 'specialUse' => '[]', 'cached' => true, 'locked' => false, 'selectable' => true,
			'newToken' => $token, 'changedToken' => $token, 'vanishedToken' => $token];
		return $this;
	}

	/**
	 * A sync token as Horde_Imap_Client_Base::getSyncToken() writes it.
	 *
	 * @param string $uidValidity UIDVALIDITY of the token
	 * @return string the token
	 */
	public static function syncToken(string $uidValidity): string {
		return base64_encode('U12,V' . $uidValidity . ',H34');
	}

	/**
	 * Stores a cached message with what the IMAP server holds for it.
	 *
	 * @param int $mailboxId folder id
	 * @param array<string, mixed> $fields id, uid, subject, sentAt, from, to, cc, seen, flagged, attachments,
	 *     messageId, threadRootId, preview, body, html, size (RFC822.SIZE), serverMessageId (the server's Message-ID),
	 *     structure (the BODYSTRUCTURE, a FakeMimePart), attachmentContents (decoded content by part number) and
	 *     serverFlags (the IMAP flags on the server, lower case; default: from seen and flagged)
	 * @return self this app
	 */
	public function withMessage(int $mailboxId, array $fields): self {
		$id = (int)$fields['id'];
		$fields['serverFlags'] ??= array_values(array_filter([($fields['seen'] ?? false) ? '\\seen' : null, ($fields['flagged'] ?? false) ? '\\flagged' : null]));
		$this->messages[$id] = $fields + [
			'mailboxId' => $mailboxId, 'uid' => $id, 'subject' => 'Subject ' . $id, 'sentAt' => 1_790_000_000, 'from' => [['Sender', 'sender@example.com']],
			'to' => [['Alice', 'alice@example.com']], 'cc' => [], 'bcc' => [], 'seen' => false, 'flagged' => false, 'answered' => false,
			'attachments' => false, 'messageId' => '<m' . $id . '@example.com>', 'inReplyTo' => null, 'threadRootId' => null, 'preview' => null,
			'body' => 'Body of ' . $id, 'html' => null, 'size' => 2000, 'attachmentList' => [], 'structure' => null, 'attachmentContents' => [],
		];
		return $this;
	}

	/**
	 * The mailbox mapper, answering only findAll() for the folders of this app.
	 *
	 * @param string $userId authenticated user UID
	 * @return object strict double of OCA\Mail\Db\MailboxMapper
	 */
	public function mailboxMapper(string $userId): object {
		$this->assertOn();
		return new RecordingDouble('MailboxMapper', [
			'findAll' => fn (object $account): array => array_map($this->folder(...),
				array_values(array_filter($this->folders, static fn (array $f): bool => $f['accountId'] === $account->getId()))),
		], $this->journal);
	}

	/**
	 * The message mapper, answering only findIdsByQuery() and findByIds().
	 *
	 * @param string $userId authenticated user UID
	 * @return object strict double of OCA\Mail\Db\MessageMapper
	 */
	public function messageMapper(string $userId): object {
		$this->assertOn();
		return new RecordingDouble('MessageMapper', [
			'findIdsByQuery' => fn (object $mailbox, FakeSearchQuery $query, string $sort, ?int $limit, ?array $uids = null): array => $this->ids($mailbox->getId(), $query, $sort, $limit, $uids),
			'findByIds' => fn (string $uid, array $ids, string $sort): array => array_values(array_map($this->message(...),
				array_filter($this->messages, static fn (array $m): bool => in_array($m['id'], $ids, true)))),
		], $this->journal);
	}

	/**
	 * The mail manager, answering only getMessage(), getMailbox(), getThread() and getImapMessage().
	 *
	 * @param string $userId authenticated user UID
	 * @return object strict double of OCA\Mail\Service\MailManager
	 */
	public function mailManager(string $userId): object {
		$this->assertOn();
		return new RecordingDouble('MailManager', [
			'getMessage' => function (string $uid, int $id): object {
				$row = $this->messages[$id] ?? null;
				if ($row === null || $this->ownerOfFolder($row['mailboxId']) !== $uid) {
					throw new DoesNotExistException('missing');
				}
				return $this->message($row);
			},
			'getMailbox' => function (string $uid, int $id): object {
				if (!isset($this->folders[$id]) || $this->ownerOfFolder($id) !== $uid) {
					throw new ClientException('missing');
				}
				return $this->folder($this->folders[$id]);
			},
			'getThread' => fn (object $account, string $root): array => array_values(array_map($this->message(...), array_filter($this->messages,
				fn (array $m): bool => $m['threadRootId'] === $root && $this->folders[$m['mailboxId']]['accountId'] === $account->getId()))),
			'getImapMessage' => function (object $client, object $account, object $mailbox, int $uid, bool $loadBody = false): object {
				$this->journal[] = 'imap:body';
				if ($this->serverFails) {
					throw new \RuntimeException('IMAP said: secret mailbox detail');
				}
				foreach ($this->messages as $row) {
					if ($row['mailboxId'] === $mailbox->getId() && $row['uid'] === $uid) {
						return $this->imapMessage($row);
					}
				}
				throw new \RuntimeException('gone');
			},
		], $this->journal);
	}

	/**
	 * The body search provider of the Mail app: matching UIDs of one folder, recorded as an IMAP SEARCH.
	 *
	 * @param string $userId authenticated user UID
	 * @return object strict double of OCA\Mail\IMAP\Search\Provider
	 */
	public function searchProvider(string $userId): object {
		$this->assertOn();
		return new RecordingDouble('Provider', [
			'findMatches' => function (object $account, object $mailbox, FakeSearchQuery $query): array {
				$this->journal[] = 'imap:search';
				$uids = [];
				foreach ($this->messages as $row) {
					if ($row['mailboxId'] === $mailbox->getId() && array_filter($query->bodies, static fn (string $b): bool => stripos($row['body'], $b) !== false) !== []) {
						$uids[] = $row['uid'];
					}
				}
				return $uids;
			},
		], $this->journal);
	}

	/**
	 * The IMAP client factory of the Mail app: one recording client per call.
	 *
	 * @param string $userId authenticated user UID
	 * @return object strict double of OCA\Mail\IMAP\IMAPClientFactory
	 */
	public function clientFactory(string $userId): object {
		$this->assertOn();
		return new RecordingDouble('IMAPClientFactory', [
			'getClient' => fn (object $account): object => $this->lastClient = $this->client(),
		], $this->journal);
	}

	/**
	 * The IMAP message mapper of the Mail app, answering only getAttachment(): the decoded content of a part, read on
	 * the client it is given, recorded with the part number.
	 *
	 * @param string $userId authenticated user UID
	 * @return object strict double of OCA\Mail\IMAP\MessageMapper
	 */
	public function imapMessageMapper(string $userId): object {
		$this->assertOn();
		return new RecordingDouble('ImapMessageMapper', [
			'getAttachment' => function (object $client, string $mailbox, int $uid, string $id, string $owner): object {
				$this->journal[] = 'imap:attachment:' . $id . ($client === $this->lastClient ? '' : '(other client)');
				if ($this->attachmentFails) {
					throw new \RuntimeException('IMAP said: secret mailbox detail');
				}
				foreach ($this->messages as $row) {
					if ($this->folders[$row['mailboxId']]['name'] === $mailbox && $row['uid'] === $uid && isset($row['attachmentContents'][$id])) {
						return new RecordingDouble('Attachment', ['getContent' => static fn (): string => $row['attachmentContents'][$id]], $this->journal);
					}
				}
				throw new DoesNotExistException('Unable to load the attachment.');
			},
			'move' => fn (object $client, string $source, int $uid, string $destination): ?int => $this->moveOnServer($client, $source, $uid, $destination),
			'addFlag' => fn (object $client, object $mailbox, array $uids, string $flag) => $this->store($client, $mailbox, $uids, '+', $flag),
			'removeFlag' => fn (object $client, object $mailbox, array $uids, string $flag) => $this->store($client, $mailbox, $uids, '-', $flag),
		], $this->journal);
	}

	/**
	 * The Mail app's draft save, answering only saveDraft(): it appends to the Drafts folder the account settings name, on
	 * a client of its own (as MailTransmission does), and returns the account, the folder and the new UID.
	 *
	 * @param string $userId authenticated user UID
	 * @return object strict double of OCA\Mail\Service\MailTransmission
	 */
	public function transmission(string $userId): object {
		$this->assertOn();
		return new RecordingDouble('MailTransmission', [
			'saveDraft' => function (object $data, mixed $previous): array {
				if ($this->draftFails === 'refused') {
					throw new ClientException('No drafts mailbox configured');
				}
				$account = $data->account;
				$mailboxId = $account->getMailAccount()->getDraftsMailboxId();
				$name = $this->folders[$mailboxId]['name'];
				$this->journal[] = 'imap:append:' . $name;
				$uid = 500 + count($this->drafts);
				$this->drafts[] = ['mailbox' => $name, 'to' => $data->to, 'cc' => $data->cc, 'bcc' => $data->bcc, 'subject' => $data->subject,
					'body' => $data->body, 'attachments' => $data->attachments, 'html' => $data->html, 'previous' => $previous, 'uid' => $uid];
				if ($this->draftFails === 'after') {
					throw new \RuntimeException('IMAP said: secret mailbox detail');
				}
				return [$account, $this->folder($this->folders[$mailboxId]), $this->draftUidAnswer ?? $uid];
			},
		], $this->journal);
	}

	/**
	 * The Mail app's Outbox, answering only saveMessage() and sendMessage(), each recorded (`outbox:save`, `outbox:send`).
	 *
	 * @param string $userId authenticated user UID
	 * @return object strict double of OCA\Mail\Service\OutboxService
	 */
	public function outboxService(string $userId): object {
		$this->assertOn();
		return new RecordingDouble('OutboxService', [
			'saveMessage' => function (object $account, FakeLocalMessage $message, array $to, array $cc, array $bcc, array $attachments): FakeLocalMessage {
				$this->journal[] = 'outbox:save';
				if ($this->sendOutcome === 'save') {
					throw new \RuntimeException('DB said: secret detail');
				}
				$message->id = 700 + count($this->outbox);
				$message->status = 0;
				$this->outbox[] = ['id' => $message->id, 'type' => $message->type, 'accountId' => $message->accountId, 'subject' => $message->subject,
					'body' => $message->body, 'sendAt' => $message->sendAt, 'to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'attachments' => $attachments];
				return $message;
			},
			'sendMessage' => function (FakeLocalMessage $message, object $account): FakeLocalMessage {
				$this->journal[] = 'outbox:send';
				if ($this->sendOutcome === 'send') {
					throw new \RuntimeException('SMTP said: secret detail');
				}
				$message->status = $this->sendOutcome['status'];
				$message->raw = $this->sendOutcome['built'] ? "Message-ID: <sent-{$message->id}@example.com>\r\nSubject: {$message->subject}\r\n\r\n{$message->body}" : null;
				return $message;
			},
		], $this->journal);
	}

	/**
	 * A new outgoing local message.
	 *
	 * @param string $userId authenticated user UID
	 * @param int $accountId the account it is sent from
	 * @param string $subject subject
	 * @param string $body plain text
	 * @return object the message
	 */
	public function newOutgoingMessage(string $userId, int $accountId, string $subject, string $body): object {
		$this->assertOn();
		return new FakeLocalMessage(0, $accountId, $subject, $body, null);
	}

	/**
	 * The status values of the pinned Mail releases (mail-send-path.json).
	 *
	 * @param string $userId authenticated user UID
	 * @param list<string> $names constant names
	 * @return array<string, int> value by name
	 */
	public function localMessageStatuses(string $userId, array $names): array {
		$this->assertOn();
		$all = ['STATUS_RAW' => 0, 'STATUS_NO_SENT_MAILBOX' => 1, 'STATUS_SMPT_SEND_FAIL' => 10, 'STATUS_IMAP_SENT_MAILBOX_FAIL' => 11, 'STATUS_PROCESSED' => 12, 'STATUS_ERROR' => 13];
		return array_intersect_key($all, array_flip($names));
	}

	/**
	 * The message a draft save takes, with the recipient lists as the comma separated strings the Mail app would parse.
	 *
	 * @param string $userId authenticated user UID
	 * @param object $account the account
	 * @param string $to recipients
	 * @param string $cc copy recipients
	 * @param string $bcc blind copy recipients
	 * @param string $subject subject
	 * @param string $body plain text
	 * @return object a record of what OCA\Mail\Model\NewMessageData would hold
	 */
	public function newMessageData(string $userId, object $account, string $to, string $cc, string $bcc, string $subject, string $body): object {
		$this->assertOn();
		return (object)['account' => $account, 'to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'subject' => $subject, 'body' => $body, 'attachments' => [], 'html' => false];
	}

	/**
	 * The class name of the Mail delete event, as MailServices gives it.
	 *
	 * @param string $userId authenticated user UID
	 * @return string the class name
	 */
	public function deleteEventClass(string $userId): string {
		$this->assertOn();
		return $this->deleteEvent;
	}

	/**
	 * A MOVE of the IMAP message mapper: the server takes the message out of the source folder and gives it a new UID in
	 * the destination; the cache keeps its row until the Mail app is told (the delete event of the test).
	 *
	 * @param object $client the client it runs on
	 * @param string $source source folder name
	 * @param int $uid UID in the source
	 * @param string $destination destination folder name
	 * @return int the new UID
	 */
	private function moveOnServer(object $client, string $source, int $uid, string $destination): int {
		$this->journal[] = 'imap:move:' . $destination . (in_array($client, $this->selectingClients, true) ? '' : '(not selected)');
		if ($this->moveFails) {
			throw new \RuntimeException('IMAP said: secret mailbox detail');
		}
		foreach ($this->messages as $id => $row) {
			if ($this->folders[$row['mailboxId']]['name'] === $source && $row['uid'] === $uid && ($row['onServer'] ?? true) && $this->copyThenFails) {
				$this->copied[] = ['id' => $id, 'mailbox' => $destination];
				throw new \RuntimeException('IMAP said: connection lost after COPY');
			}
			if ($this->folders[$row['mailboxId']]['name'] === $source && $row['uid'] === $uid && ($row['onServer'] ?? true)) {
				$this->messages[$id]['onServer'] = false;
				$newUid = 1000 + count($this->moved);
				$this->moved[] = ['id' => $id, 'mailbox' => $destination, 'uid' => $newUid];
				return $newUid;
			}
		}
		throw new \RuntimeException('gone');
	}

	/**
	 * The class name of the Mail flag event, as MailServices gives it.
	 *
	 * @param string $userId authenticated user UID
	 * @return string the class name
	 */
	public function flagEventClass(string $userId): string {
		$this->assertOn();
		return $this->flagEvent;
	}

	/**
	 * A STORE of the IMAP message mapper: adds or removes one flag of the messages at those UIDs of the folder.
	 *
	 * @param object $client the client it runs on
	 * @param object $mailbox OCA\Mail\Db\Mailbox
	 * @param list<int> $uids UIDs
	 * @param string $sign + or -
	 * @param string $flag IMAP flag
	 * @return void
	 */
	private function store(object $client, object $mailbox, array $uids, string $sign, string $flag): void {
		$this->journal[] = 'imap:store:' . $sign . $flag . (in_array($client, $this->selectingClients, true) ? '' : '(not selected)');
		if ($this->storeFails) {
			throw new \RuntimeException('IMAP said: secret mailbox detail');
		}
		foreach ($this->messages as $id => $row) {
			if ($row['mailboxId'] === $mailbox->getId() && in_array($row['uid'], $uids, true)) {
				$flags = array_values(array_diff($row['serverFlags'], [$flag]));
				$this->messages[$id]['serverFlags'] = $sign === '+' ? [...$flags, $flag] : $flags;
			}
		}
	}

	/**
	 * A new search query.
	 *
	 * @param string $userId authenticated user UID
	 * @return object FakeSearchQuery
	 */
	public function newSearchQuery(string $userId): object {
		$this->assertOn();
		return $this->searchQuery ?? new FakeSearchQuery();
	}

	/**
	 * A flag condition, as an array the fake mapper reads.
	 *
	 * @param string $userId authenticated user UID
	 * @param string $constant SEEN, FLAGGED or DELETED
	 * @param bool $isSet whether the flag must be set
	 * @return object never returned: arrays stand for flags here
	 */
	public function flag(string $userId, string $constant, bool $isSet): object {
		return new \ArrayObject(['flag' => $constant, 'set' => $isSet]);
	}

	/**
	 * Every IMAP command recorded.
	 *
	 * @return list<string> imap:* entries in order
	 */
	public function imapCommands(): array {
		return array_values(array_filter($this->journal->getArrayCopy(), static fn (string $call): bool => str_starts_with($call, 'imap:')));
	}

	/**
	 * Throws like MailServices when Mail is off.
	 *
	 * @return void
	 */
	private function assertOn(): void {
		if (!$this->enabled) {
			throw new \OCA\Mcp\Modules\Mail\MailUnavailableException();
		}
	}

	/**
	 * The owner of the account of a folder.
	 *
	 * @param int $mailboxId folder id
	 * @return string|null owner UID
	 */
	private function ownerOfFolder(int $mailboxId): ?string {
		$accountId = $this->folders[$mailboxId]['accountId'] ?? null;
		foreach ($this->accounts as $row) {
			if ($row['id'] === $accountId) {
				return $row['owner'];
			}
		}
		return null;
	}

	/**
	 * Ids matching a query in one folder: send date descending, same dates in ascending id order, page-limited.
	 *
	 * @param int $mailboxId folder id
	 * @param FakeSearchQuery $query filters
	 * @param string $sort DESC
	 * @param int|null $limit page size
	 * @param list<int>|null $uids UIDs of the body search
	 * @return list<int> ids
	 */
	private function ids(int $mailboxId, FakeSearchQuery $query, string $sort, ?int $limit, ?array $uids): array {
		// As findIdsByChunkedParameter() of the Mail mapper: a UID list is applied chunk by chunk, so an empty one runs
		// no query and finds nothing, whatever the other filters.
		if ($uids === []) {
			$this->journal[] = 'MessageMapper:no-query';
			return [];
		}
		$rows = array_values(array_filter($this->messages, function (array $m) use ($mailboxId, $query, $uids): bool {
			if ($m['mailboxId'] !== $mailboxId) {
				return false;
			}
			// As recipientsMatch() of the Mail mapper: within one field any fragment matches (label or address); fields AND.
			foreach (['from' => $query->from, 'to' => $query->to, 'cc' => $query->cc] as $field => $fragments) {
				if ($fragments !== [] && array_filter($m[$field], static fn (array $a): bool => array_filter($fragments,
					static fn (string $fragment): bool => stripos((string)$a[0], $fragment) !== false || stripos((string)$a[1], $fragment) !== false) !== []) === []) {
					return false;
				}
			}
			// As the Mail mapper: with both a subject and a body search, either one matches.
			$subject = array_filter($query->subjects, static fn (string $s): bool => stripos($m['subject'], $s) !== false) !== [];
			$body = $uids !== null && in_array($m['uid'], $uids, true);
			$text = match (true) {
				$uids !== null && $query->subjects !== [] => $subject || $body,
				$uids !== null => $body,
				$query->subjects !== [] => $subject,
				default => true,
			};
			if (!$text) {
				return false;
			}
			if ($query->start !== null && $m['sentAt'] < (int)$query->start || $query->end !== null && $m['sentAt'] > (int)$query->end) {
				return false;
			}
			if ($query->cursor !== null && $m['sentAt'] >= $query->cursor || $query->hasAttachments && !$m['attachments']) {
				return false;
			}
			foreach ($query->flags as $flag) {
				$value = ['SEEN' => $m['seen'], 'FLAGGED' => $m['flagged'], 'DELETED' => $m['deleted'] ?? false][$flag['flag']];
				if ($value !== $flag['set']) {
					return false;
				}
			}
			return true;
		}));
		if ($query->threaded) {
			// As the self-join of MessageMapper::findIdsByQuery(): only the newest message of each thread of the folder.
			$rows = array_values(array_filter($rows, static fn (array $m): bool => $m['threadRootId'] === null || array_filter($rows,
				static fn (array $o): bool => $o['threadRootId'] === $m['threadRootId'] && [$o['sentAt'], $o['messageId']] > [$m['sentAt'], $m['messageId']]) === []));
		}
		usort($rows, static fn (array $a, array $b): int => [$b['sentAt'], $a['id']] <=> [$a['sentAt'], $b['id']]);
		return array_map(static fn (array $m): int => $m['id'], $limit === null ? $rows : array_slice($rows, 0, $limit));
	}

	/**
	 * A strict double of OCA\Mail\Db\Mailbox.
	 *
	 * @param array<string, mixed> $row stored folder
	 * @return object the double
	 */
	private function folder(array $row): object {
		return new RecordingDouble('Mailbox', [
			'getId' => static fn (): int => $row['id'],
			'getName' => static fn (): string => $row['name'],
			'getAccountId' => static fn (): int => $row['accountId'],
			'getSpecialUse' => static fn (): string => $row['specialUse'],
			'getSelectable' => static fn (): ?bool => $row['selectable'],
			'isCached' => static fn (): bool => $row['cached'],
			'hasLocks' => static fn (int $now): bool => $row['locked'],
			'getSyncNewToken' => static fn (): ?string => $row['newToken'],
			'getSyncChangedToken' => static fn (): ?string => $row['changedToken'],
			'getSyncVanishedToken' => static fn (): ?string => $row['vanishedToken'],
		], $this->journal);
	}

	/**
	 * A strict double of OCA\Mail\Db\Message.
	 *
	 * @param array<string, mixed> $row stored message
	 * @return object the double
	 */
	private function message(array $row): object {
		$list = fn (array $addresses): object => $this->addressList($addresses);
		return new RecordingDouble('Message', [
			'getId' => static fn (): int => $row['id'],
			'getUid' => static fn (): int => $row['uid'],
			'getMailboxId' => static fn (): int => $row['mailboxId'],
			'getSubject' => static fn (): string => $row['subject'],
			'getSentAt' => static fn (): int => $row['sentAt'],
			'getFlagSeen' => static fn (): bool => $row['seen'],
			'getFlagFlagged' => static fn (): bool => $row['flagged'],
			'getFlagAnswered' => static fn (): bool => $row['answered'],
			'getFlagAttachments' => static fn (): bool => $row['attachments'],
			'getPreviewText' => static fn (): ?string => $row['preview'],
			'getMessageId' => static fn (): ?string => $row['messageId'],
			'getInReplyTo' => static fn (): ?string => $row['inReplyTo'],
			'getThreadRootId' => static fn (): ?string => $row['threadRootId'],
			'getFrom' => static fn (): object => $list($row['from']),
			'getTo' => static fn (): object => $list($row['to']),
			'getCc' => static fn (): object => $list($row['cc']),
			'getBcc' => static fn (): object => $list($row['bcc']),
		], $this->journal);
	}

	/**
	 * A double of OCA\Mail\AddressList.
	 *
	 * @param list<array{0:string|null, 1:string|null}> $addresses label and email pairs
	 * @return object the double
	 */
	private function addressList(array $addresses): object {
		return new RecordingDouble('AddressList', [
			'iterate' => fn (): \Generator => (function () use ($addresses): \Generator {
				foreach ($addresses as [$label, $email]) {
					yield new RecordingDouble('Address', ['getLabel' => static fn (): ?string => $label, 'getEmail' => static fn (): ?string => $email], $this->journal);
				}
			})(),
		], $this->journal);
	}

	/**
	 * A double of OCA\Mail\Model\IMAPMessage, as the Mail fetcher builds it with the body.
	 *
	 * @param array<string, mixed> $row stored message
	 * @return object the double
	 */
	private function imapMessage(array $row): object {
		return new RecordingDouble('IMAPMessage', [
			'getPlainBody' => static fn (): string => $row['html'] === null ? $row['body'] : '',
			'getHtmlBody' => static fn (int $id): string => (string)$row['html'],
			'jsonSerialize' => static fn (): array => ['hasHtmlBody' => $row['html'] !== null],
			'getAttachments' => static fn (): array => $row['attachmentList'],
			'getReplyTo' => fn (): object => $this->addressList([]),
			'getMessageId' => static fn (): string => $row['serverMessageId'] ?? (string)$row['messageId'],
			'isEncrypted' => static fn (): bool => false,
			'isSigned' => static fn (): bool => false,
		], $this->journal);
	}

	/**
	 * A recording Horde IMAP client of the Mail app.
	 *
	 * @return object the double
	 */
	private function client(): object {
		// Like the Horde client: a FETCH opens the folder (EXAMINE), whose UIDVALIDITY is then the one of the selection,
		// and a later STATUS of the selected folder answers from that state without a command to the server.
		$selected = [];
		$double = null;
		return $double = new RecordingDouble('Client', [
			'status' => function (string $mailbox, int $item) use (&$selected): array {
				if (isset($selected[$mailbox])) {
					$this->journal[] = 'imap:status(selected)';
					return ['uidvalidity' => (int)$selected[$mailbox]];
				}
				$this->journal[] = 'imap:status';
				return ['uidvalidity' => (int)($this->uidValidity[$mailbox] ?? '7')];
			},
			'fetch' => function (string $mailbox, \Horde_Imap_Client_Fetch_Query $query, array $options) use (&$selected): object {
				$selected[$mailbox] ??= $this->uidValidityOnOpen[$mailbox] ?? $this->uidValidity[$mailbox] ?? '7';
				$this->journal[] = 'imap:fetch:' . implode(',', $query->items);
				$uid = $options['ids']->ids[0];
				$found = null;
				foreach ($this->messages as $row) {
					if ($this->folders[$row['mailboxId']]['name'] === $mailbox && $row['uid'] === $uid && ($row['onServer'] ?? true)) {
						$found = $row;
					}
				}
				return new RecordingDouble('FetchResults', ['first' => fn (): ?object => $found === null ? null : $this->fetchData($found)], $this->journal);
			},
			'openMailbox' => function (string $mailbox, int $mode) use (&$selected, &$double): void {
				$this->journal[] = 'imap:select';
				if ($this->selectFails) {
					throw new \RuntimeException('IMAP said: secret mailbox detail');
				}
				$this->selectingClients[] = $double;
				$selected[$mailbox] = $this->uidValidityOnOpen[$mailbox] ?? $this->uidValidity[$mailbox] ?? '7';
			},
			'logout' => function (): void {
				$this->journal[] = 'imap:logout';
			},
		], $this->journal, [
			// Horde_Imap_Client_Base::$capability: what the server reported at login, no command of its own here.
			'capability' => function (): object {
				if ($this->capabilityFails) {
					throw new \RuntimeException('IMAP said: secret capability detail');
				}
				return new RecordingDouble('Capability', [
					'query' => fn (string $capability): bool => in_array(strtoupper($capability), $this->capabilities, true),
				], $this->journal);
			},
		]);
	}

	/**
	 * The metadata of one message, as Horde_Imap_Client_Data_Fetch carries it.
	 *
	 * @param array<string, mixed> $row stored message
	 * @return object the double
	 */
	private function fetchData(array $row): object {
		return new RecordingDouble('FetchData', [
			'getSize' => static fn (): mixed => $row['size'],
			'getEnvelope' => static fn (): object => (object)['message_id' => $row['serverMessageId'] ?? (string)$row['messageId']],
			'getStructure' => static fn (): ?object => $row['structure'],
			'getFlags' => static fn (): array => $row['serverFlags'],
		], $this->journal);
	}
}
