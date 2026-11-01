<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Tools\Mail\MailBodyPreflight;
use OCA\Mcp\Tools\Mail\MailGateway;
use OCA\Mcp\Tools\Mail\MailMessages;
use OCA\Mcp\Tools\Mail\MailMessageScope;
use OCA\Mcp\Tools\Mail\MailMessageTools;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * The four message tools against a Mail app in memory: they answer from the cache, read bodies only after the size
 * preflight and with no flag change, never reach another user's or a delegated account, and report the folders they
 * could not read instead of dropping them.
 */
final class MailMessageToolsTest extends TestCase {
	/** Account of alice. */
	private const OWN = 11;

	/** Account of bob. */
	private const FOREIGN = 22;

	/** The app in memory. */
	private FakeMailMessageApp $app;

	/**
	 * Alice owns account 11 with INBOX (1), Archive (2), a folder Mail never synchronized (3) and a Trash (4); bob owns
	 * account 22 with INBOX (9).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->app = (new FakeMailMessageApp())
			->withAccount(self::OWN, 'alice', 'Work', 'alice@example.com')
			->withAccount(self::FOREIGN, 'bob', 'Bob', 'bob@example.com');
		$this->app->withFolder(self::OWN, ['id' => 1, 'name' => 'INBOX'])
			->withFolder(self::OWN, ['id' => 2, 'name' => 'Archive'])
			->withFolder(self::OWN, ['id' => 3, 'name' => 'Never', 'cached' => false])
			->withFolder(self::OWN, ['id' => 4, 'name' => 'Trash', 'specialUse' => '["\\\\Trash"]'])
			->withFolder(self::FOREIGN, ['id' => 9, 'name' => 'INBOX']);
	}

	/**
	 * A folder listing is newest first, says the data is untrusted, reads only the cache and contacts no IMAP server.
	 *
	 * @return void
	 */
	public function testAListingReadsTheCacheOnly(): void {
		$this->app->withMessage(1, ['id' => 101, 'sentAt' => 100, 'subject' => "Old\u{202E}gnp", 'seen' => true])
			->withMessage(1, ['id' => 102, 'sentAt' => 200, 'preview' => 'Hello there', 'attachments' => true]);
		$out = $this->call('mail_list_messages', ['mailbox_id' => 1]);

		$this->assertTrue($out['untrustedMailData']);
		$this->assertSame(MailMessages::UNTRUSTED_NOTICE, $out['notice']);
		$this->assertSame([102, 101], array_column($out['messages'], 'id'));
		$this->assertSame('Oldgnp', $out['messages'][1]['subject'], 'bidirectional formatting removed');
		$this->assertFalse($out['messages'][1]['unread']);
		$this->assertTrue($out['messages'][0]['unread']);
		$this->assertTrue($out['messages'][0]['hasAttachments']);
		$this->assertSame('Hello there', $out['messages'][0]['preview']);
		$this->assertSame(['count' => 1, 'addresses' => [['name' => 'Sender', 'email' => 'sender@example.com']]], $out['messages'][0]['from']);
		$this->assertSame('1970-01-01T00:03:20+00:00', $out['messages'][0]['date']);
		$this->assertFalse($out['hasMore']);
		$this->assertNull($out['nextCursor']);
		$this->assertSame([], $this->app->imapCommands());
	}

	/**
	 * Another user's folder and a folder that does not exist answer the same way; a folder the Mail app never
	 * synchronized is refused with the advice to open it in Mail.
	 *
	 * @return void
	 */
	public function testForeignAndMissingFoldersAreIndistinguishable(): void {
		$foreign = $this->failure('mail_list_messages', ['mailbox_id' => 9]);
		$missing = $this->failure('mail_list_messages', ['mailbox_id' => 999]);
		$this->assertSame(MailMessages::mailboxNotFound(), $foreign);
		$this->assertSame($missing, $foreign);
		$this->assertSame(MailMessages::mailboxUnreadable('not_synchronized'), $this->failure('mail_list_messages', ['mailbox_id' => 3]));
	}

	/**
	 * A search over every folder merges the folders newest first, lists the unsynchronized folder as skipped and never
	 * reaches bob's account.
	 *
	 * @return void
	 */
	public function testASearchOverEveryFolderMergesAndReportsSkippedFolders(): void {
		$this->app->withMessage(1, ['id' => 101, 'sentAt' => 300])
			->withMessage(2, ['id' => 201, 'sentAt' => 400])
			->withMessage(4, ['id' => 401, 'sentAt' => 500, 'deleted' => true])
			->withMessage(9, ['id' => 901, 'sentAt' => 900]);
		$out = $this->call('mail_search_messages', []);

		$this->assertSame([401, 201, 101], array_column($out['messages'], 'id'), 'a deleted message is listed in the trash only');
		$this->assertSame([1, 2, 4], array_column($out['folders'], 'mailboxId'));
		$this->assertSame([['accountId' => self::OWN, 'mailboxId' => 3, 'path' => 'Never', 'reason' => 'not_synchronized']], $out['skippedFolders']);
		$this->assertSame([], $this->app->imapCommands());
	}

	/**
	 * Every message of a conversation is listed, not only its newest one, although the Mail query groups by thread by
	 * default since Mail 5.5.
	 *
	 * @return void
	 */
	public function testConversationsAreNotCollapsed(): void {
		$this->app->withMessage(1, ['id' => 1, 'sentAt' => 10, 'threadRootId' => '<t@x>'])
			->withMessage(1, ['id' => 2, 'sentAt' => 20, 'threadRootId' => '<t@x>'])
			->withMessage(1, ['id' => 3, 'sentAt' => 20, 'threadRootId' => '<t@x>']);
		$this->assertSame([3, 2, 1], array_column($this->call('mail_list_messages', ['mailbox_id' => 1])['messages'], 'id'));
		$this->assertSame([3, 2, 1], array_column($this->call('mail_search_messages', ['mailbox_id' => 1])['messages'], 'id'));
	}

	/**
	 * The filters reach the folder query of the Mail app: sender, subject, unread, dates in the user's time zone.
	 *
	 * @return void
	 */
	public function testTheFiltersSelectTheMessages(): void {
		$this->app->withMessage(1, ['id' => 1, 'sentAt' => 1_790_000_000, 'from' => [['Carol', 'carol@example.com']], 'subject' => 'Venue confirmed'])
			->withMessage(1, ['id' => 2, 'sentAt' => 1_790_000_100, 'from' => [['Carol', 'carol@example.com']], 'subject' => 'Lunch', 'seen' => true])
			->withMessage(1, ['id' => 3, 'sentAt' => 1_700_000_000, 'from' => [['Carol', 'carol@example.com']], 'subject' => 'Venue old']);
		$out = $this->call('mail_search_messages', ['account_id' => self::OWN, 'from' => ['carol'], 'subject' => 'venue', 'unread' => true, 'since' => '2026-09-01']);
		$this->assertSame([1], array_column($out['messages'], 'id'));
		$this->assertSame(MailMessages::invalidDate('yesterday'), $this->failure('mail_search_messages', ['since' => 'yesterday']));
		$this->assertSame(MailMessages::invalidDateRange(), $this->failure('mail_search_messages', ['since' => '2026-10-02', 'until' => '2026-10-01']));
	}

	/**
	 * The body filter runs the Mail app's body search once per folder and nothing else on the server.
	 *
	 * @return void
	 */
	public function testTheBodyFilterUsesTheMailBodySearch(): void {
		$this->app->withMessage(1, ['id' => 1, 'body' => 'The venue is booked'])
			->withMessage(1, ['id' => 2, 'body' => 'Nothing here'])
			->withMessage(2, ['id' => 3, 'body' => 'venue again']);
		$out = $this->call('mail_search_messages', ['account_id' => self::OWN, 'body' => 'venue']);
		$this->assertSame([3, 1], array_column($out['messages'], 'id'));
		$this->assertSame(['imap:search', 'imap:search', 'imap:search'], $this->app->imapCommands(), 'one SEARCH per readable folder (1, 2, 4)');
	}

	/**
	 * A body search over more folders than allowed is refused before any server search.
	 *
	 * @return void
	 */
	public function testABodySearchOverTooManyFoldersIsRefused(): void {
		for ($id = 100; $id < 100 + MailMessageScope::MAX_BODY_FOLDERS; $id++) {
			$this->app->withFolder(self::OWN, ['id' => $id, 'name' => 'F' . $id]);
		}
		$this->assertSame(MailMessages::tooManyFolders(MailMessageScope::MAX_BODY_FOLDERS + 3, MailMessageScope::MAX_BODY_FOLDERS),
			$this->failure('mail_search_messages', ['body' => 'x']));
		$this->assertSame([], $this->app->imapCommands());
	}

	/**
	 * Paging a folder where many messages share dates returns every message exactly once, page after page.
	 *
	 * @return void
	 */
	public function testPagesNeitherLoseNorRepeatMessagesOfTheSameDate(): void {
		$id = 0;
		foreach ([500, 400, 400, 400, 400, 300, 300, 200, 100, 100, 100] as $sentAt) {
			$id++;
			$this->app->withMessage(1, ['id' => $id, 'sentAt' => $sentAt]);
			$this->app->withMessage(2, ['id' => 100 + $id, 'sentAt' => $sentAt]);
		}
		$seen = [];
		$cursor = null;
		$pages = 0;
		do {
			$out = $this->call('mail_search_messages', ['account_id' => self::OWN, 'limit' => 3] + ($cursor === null ? [] : ['cursor' => $cursor]));
			array_push($seen, ...array_column($out['messages'], 'id'));
			$cursor = $out['nextCursor'];
			$this->assertSame($out['hasMore'], $cursor !== null);
		} while ($cursor !== null && ++$pages < 20);
		sort($seen);
		$this->assertSame(array_merge(range(1, 11), range(101, 111)), $seen);
	}

	/**
	 * A cursor of another search is refused instead of skipping or repeating messages, and a forged one too.
	 *
	 * @return void
	 */
	public function testACursorOfAnotherSearchIsRefused(): void {
		foreach (range(1, 5) as $id) {
			$this->app->withMessage(1, ['id' => $id, 'sentAt' => $id * 10]);
		}
		$cursor = $this->call('mail_list_messages', ['mailbox_id' => 1, 'limit' => 2])['nextCursor'];
		$this->assertIsString($cursor);
		$this->assertSame(MailMessages::foreignCursor(), $this->failure('mail_search_messages', ['mailbox_id' => 1, 'unread' => true, 'cursor' => $cursor]));
		$this->assertSame(MailMessages::invalidCursor(), $this->failure('mail_list_messages', ['mailbox_id' => 1, 'cursor' => 'not-a-cursor']));
	}

	/**
	 * Reading a message runs the metadata preflight, then the Mail body read, on one client logged out at the end; the
	 * body comes back as text and the attachments by name, type and size.
	 *
	 * @return void
	 */
	public function testReadingAMessageRunsThePreflightThenTheBodyRead(): void {
		$this->app->withMessage(1, ['id' => 7, 'body' => "Hello\r\nWorld\u{0007}", 'attachmentList' => [['id' => '2', 'fileName' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 1234]]]);
		$out = $this->call('mail_read_message', ['message_id' => 7]);

		$this->assertSame(['imap:status', 'imap:fetch:envelope,size', 'imap:status(selected)', 'imap:body', 'imap:logout'], $this->app->imapCommands());
		$this->assertSame("Hello\nWorld", $out['message']['body']['text']);
		$this->assertSame('plain', $out['message']['body']['format']);
		$this->assertSame([['id' => '2', 'name' => 'a.pdf', 'mimeType' => 'application/pdf', 'size' => 1234]], $out['message']['attachments']);
		$this->assertArrayNotHasKey('preview', $out['message']);
	}

	/**
	 * An HTML-only body is converted to text without rendering.
	 *
	 * @return void
	 */
	public function testAnHtmlBodyBecomesText(): void {
		$this->app->withMessage(1, ['id' => 8, 'html' => '<style>p{}</style><p>Hi&nbsp;<b>there</b></p><script>alert(1)</script><img src="https://tracker.example/x.png">']);
		$body = $this->call('mail_read_message', ['message_id' => 8])['message']['body'];
		$this->assertSame('html', $body['format']);
		$this->assertSame('Hi there', $body['text']);
	}

	/**
	 * A message whose whole size is over the budget is refused after the metadata FETCH only: its content is never
	 * fetched, whatever its parts would claim.
	 *
	 * @return void
	 */
	public function testAnOversizedMessageIsRefusedWithoutReadingItsBody(): void {
		$this->app->withMessage(1, ['id' => 9, 'size' => MailBodyPreflight::MAX_MESSAGE_BYTES + 1]);
		$failure = $this->failure('mail_read_message', ['message_id' => 9]);
		$this->assertSame(MailMessages::bodyTooLarge(MailBodyPreflight::MAX_MESSAGE_BYTES + 1, MailBodyPreflight::MAX_MESSAGE_BYTES), $failure);
		$this->assertSame(['imap:status', 'imap:fetch:envelope,size', 'imap:logout'], $this->app->imapCommands());
	}

	/**
	 * A cache of an older UID generation than the server's is refused before any FETCH, whether the message at that
	 * UID now has the same Message-ID as the cached one or neither has one; so is a folder whose generation cannot be
	 * read from its sync tokens. A different Message-ID is refused at the preflight.
	 *
	 * @return void
	 */
	public function testACacheOfAnotherUidGenerationIsRefusedBeforeAnyFetch(): void {
		$this->app->withFolder(self::OWN, ['id' => 7, 'name' => 'Regenerated']);
		$this->app->uidValidity['Regenerated'] = '8';
		$this->app->withMessage(7, ['id' => 71, 'messageId' => '<same@example.com>'])
			->withMessage(7, ['id' => 72, 'messageId' => null, 'serverMessageId' => '']);
		foreach ([71, 72] as $id) {
			$this->assertSame(MailMessages::messageChanged(), $this->failure('mail_read_message', ['message_id' => $id]), "message $id");
		}
		$this->assertSame(['imap:status', 'imap:logout', 'imap:status', 'imap:logout'], $this->app->imapCommands(), 'only STATUS, never a FETCH');

		$this->app->withFolder(self::OWN, ['id' => 8, 'name' => 'Untokened', 'vanishedToken' => null])->withMessage(8, ['id' => 81]);
		$this->assertSame(MailMessages::messageChanged(), $this->failure('mail_read_message', ['message_id' => 81]));
		$this->assertSame(4, count($this->app->imapCommands()), 'no IMAP client at all for a folder without a verifiable generation');

		$this->app->withMessage(1, ['id' => 5, 'serverMessageId' => '<other@example.com>']);
		$this->assertSame(MailMessages::messageChanged(), $this->failure('mail_read_message', ['message_id' => 5]));
		$this->assertNotContains('imap:body', $this->app->imapCommands());
	}

	/**
	 * A folder recreated between the STATUS and the EXAMINE of the preflight FETCH opens a newer generation: the read is
	 * refused before the body is fetched, whether the message now at the cached UID has the same Message-ID or none.
	 * With the generation stable across the opening, the same read passes.
	 *
	 * @return void
	 */
	public function testAFolderRecreatedBeforeItsSelectionIsRefusedBeforeTheBody(): void {
		$this->app->withFolder(self::OWN, ['id' => 7, 'name' => 'Racing']);
		$this->app->uidValidityOnOpen['Racing'] = '8';
		$this->app->withMessage(7, ['id' => 71, 'messageId' => '<same@example.com>'])
			->withMessage(7, ['id' => 72, 'messageId' => null, 'serverMessageId' => '']);
		foreach ([71, 72] as $id) {
			$this->assertSame(MailMessages::messageChanged(), $this->failure('mail_read_message', ['message_id' => $id]), "message $id");
		}
		$this->assertSame(array_merge(...array_fill(0, 2, ['imap:status', 'imap:fetch:envelope,size', 'imap:status(selected)', 'imap:logout'])),
			$this->app->imapCommands(), 'STATUS saw 7, the FETCH opened 8: refused before any body fetch');

		$this->app->journal->exchangeArray([]);
		unset($this->app->uidValidityOnOpen['Racing']);
		$this->assertSame('Body of 71', $this->call('mail_read_message', ['message_id' => 71])['message']['body']['text']);
		$this->assertContains('imap:body', $this->app->imapCommands(), 'the stable generation is read');
	}

	/**
	 * With a subject and a body filter, a folder where the body search finds nothing still returns its subject matches;
	 * with the body filter alone it returns nothing and runs no cache query.
	 *
	 * @return void
	 */
	public function testSubjectStillMatchesWhenTheBodySearchFindsNothing(): void {
		$this->app->withMessage(1, ['id' => 1, 'subject' => 'Invoice 42', 'body' => 'Pay soon'])
			->withMessage(1, ['id' => 2, 'subject' => 'Lunch', 'body' => 'Noon']);
		$both = $this->call('mail_search_messages', ['mailbox_id' => 1, 'subject' => 'invoice', 'body' => 'zebracorn']);
		$this->assertSame([1], array_column($both['messages'], 'id'));
		$this->assertSame([], $this->call('mail_search_messages', ['mailbox_id' => 1, 'body' => 'zebracorn'])['messages']);
		$this->assertSame([1, 2], $this->sortedIds($this->call('mail_search_messages', ['mailbox_id' => 1, 'subject' => 'invoice', 'body' => 'noon'])['messages']), 'subject or body');
		$this->assertNotContains('MessageMapper:no-query', $this->app->journal->getArrayCopy(), 'an empty UID list never reaches the Mail mapper, which would run no query');
	}

	/**
	 * Within one address field the fragments are alternatives: the first, the second or both select the messages that
	 * match any of them; different fields must all match.
	 *
	 * @return void
	 */
	public function testAddressFragmentsWithinAFieldAreAlternatives(): void {
		$this->app->withMessage(1, ['id' => 1, 'from' => [['Carol', 'carol@example.com']], 'to' => [['Team', 'team@example.org']], 'cc' => [['Eve', 'eve@example.com']]])
			->withMessage(1, ['id' => 2, 'from' => [['Dan', 'dan@example.org']], 'to' => [['Other', 'other@example.net']], 'cc' => [['Frank', 'frank@example.com']]])
			->withMessage(1, ['id' => 3, 'from' => [['Zed', 'zed@example.net']], 'to' => [['Nobody', 'nobody@example.net']], 'cc' => []]);
		$ids = fn (array $filters): array => $this->sortedIds($this->call('mail_search_messages', ['mailbox_id' => 1] + $filters)['messages']);
		foreach (['from' => ['carol', 'example.org'], 'to' => ['team', 'other@'], 'cc' => ['eve', 'frank']] as $field => [$first, $second]) {
			$this->assertSame([1], $ids([$field => [$first]]), "$field first");
			$this->assertSame([2], $ids([$field => [$second]]), "$field second");
			$this->assertSame([1, 2], $ids([$field => [$first, $second]]), "$field both");
		}
		$this->assertSame([2], $ids(['from' => ['carol', 'dan'], 'cc' => ['frank']]), 'different fields all match');
	}

	/**
	 * A server failure during the read gives a fixed message, never the server's text, and the client is logged out.
	 *
	 * @return void
	 */
	public function testAServerFailureHidesItsDetails(): void {
		$this->app->withMessage(1, ['id' => 4]);
		$this->app->serverFails = true;
		$failure = $this->failure('mail_read_message', ['message_id' => 4]);
		$this->assertSame(MailMessages::bodyUnavailable(), $failure);
		$this->assertStringNotContainsString('secret', $failure);
		$this->assertSame('imap:logout', array_slice($this->app->imapCommands(), -1)[0]);
	}

	/**
	 * Another user's message and a missing one answer the same, without any IMAP command.
	 *
	 * @return void
	 */
	public function testForeignAndMissingMessagesAreIndistinguishable(): void {
		$this->app->withMessage(9, ['id' => 900]);
		$foreign = $this->failure('mail_read_message', ['message_id' => 900]);
		$this->assertSame(MailMessages::messageNotFound(), $foreign);
		$this->assertSame($foreign, $this->failure('mail_read_message', ['message_id' => 12345]));
		$this->assertSame($foreign, $this->failure('mail_read_thread', ['message_id' => 900]));
		$this->assertSame([], $this->app->imapCommands());
	}

	/**
	 * A thread lists the messages of the conversation within the account, newest first, from the cache.
	 *
	 * @return void
	 */
	public function testAThreadListsTheConversation(): void {
		$this->app->withMessage(1, ['id' => 31, 'sentAt' => 10, 'threadRootId' => '<root@x>'])
			->withMessage(2, ['id' => 32, 'sentAt' => 20, 'threadRootId' => '<root@x>'])
			->withMessage(9, ['id' => 33, 'sentAt' => 30, 'threadRootId' => '<root@x>'])
			->withMessage(1, ['id' => 34, 'sentAt' => 40]);
		$out = $this->call('mail_read_thread', ['message_id' => 31]);
		$this->assertSame([32, 31], array_column($out['messages'], 'id'), 'bob\'s message of the same thread id never appears');
		$this->assertSame(2, $out['threadSize']);
		$this->assertFalse($out['truncated']);
		$this->assertSame([34], array_column($this->call('mail_read_thread', ['message_id' => 34])['messages'], 'id'));
		$this->assertSame([], $this->app->imapCommands());
	}

	/**
	 * Every message row links to its own conversation in the Mail app (its folder and its own id), every folder of a
	 * page to the folder; a message the Mail app has not threaded has no link.
	 *
	 * @return void
	 */
	public function testRowsLinkToTheirMessageAndFolderInMail(): void {
		$this->app->withMessage(2, ['id' => 61, 'sentAt' => 10, 'threadRootId' => '<root@x>'])
			->withMessage(2, ['id' => 62, 'sentAt' => 20, 'threadRootId' => '<root@x>'])
			->withMessage(2, ['id' => 63, 'sentAt' => 30, 'threadRootId' => null]);
		$base = FakeMailLinks::BASE;
		$page = $this->call('mail_list_messages', ['mailbox_id' => 2]);
		$this->assertSame([63 => null, 62 => "$base/box/2/thread/62", 61 => "$base/box/2/thread/61"], array_column($page['messages'], 'url', 'id'));
		$this->assertSame([['accountId' => self::OWN, 'mailboxId' => 2, 'path' => 'Archive', 'url' => "$base/box/2"]], $page['folders']);
		$this->assertSame("$base/box/2/thread/62", $this->call('mail_search_messages', ['mailbox_id' => 2, 'subject' => 'Subject 62'])['messages'][0]['url']);
		$this->assertSame("$base/box/2/thread/61", $this->call('mail_read_message', ['message_id' => 61])['message']['url']);
		$this->assertSame(["$base/box/2/thread/62", "$base/box/2/thread/61"], array_column($this->call('mail_read_thread', ['message_id' => 61])['messages'], 'url'));
	}

	/**
	 * Mail turned off answers with the unavailable message.
	 *
	 * @return void
	 */
	public function testMailOffIsUnavailable(): void {
		$this->app->enabled = false;
		$this->assertSame(MailMessages::unavailable(), $this->failure('mail_list_messages', ['mailbox_id' => 1]));
	}

	/**
	 * Ids of message rows, sorted.
	 *
	 * @param list<array<string, mixed>> $rows message rows
	 * @return list<int> the ids
	 */
	private function sortedIds(array $rows): array {
		$ids = array_column($rows, 'id');
		sort($ids);
		return $ids;
	}

	/**
	 * The decoded JSON answer of a successful call as alice.
	 *
	 * @param string $tool tool name
	 * @param array<string, mixed> $arguments tool arguments
	 * @return array<string, mixed> the answer
	 */
	private function call(string $tool, array $arguments): array {
		$result = $this->tools()->call($tool, $arguments, 'alice');
		$this->assertArrayNotHasKey('isError', $result);
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * The message of a failing call as alice.
	 *
	 * @param string $tool tool name
	 * @param array<string, mixed> $arguments tool arguments
	 * @return string the failure message
	 */
	private function failure(string $tool, array $arguments): string {
		try {
			$this->tools()->call($tool, $arguments, 'alice');
		} catch (ToolFailure $e) {
			return $e->getMessage();
		}
		$this->fail("$tool did not fail");
	}

	/**
	 * The message tools over the app in memory, in UTC.
	 *
	 * @return MailMessageTools the tools
	 */
	private function tools(): MailMessageTools {
		$config = $this->createStub(IConfig::class);
		$config->method('getUserValue')->willReturn('UTC');
		return new MailMessageTools($this->app, new MailGateway($this->app), new UserTimezone($config), FakeMailLinks::create($this));
	}
}
