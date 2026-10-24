<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail\Handler;

use OCA\Mcp\Tests\Unit\Tools\Mail\FakeMailApp;
use OCA\Mcp\Tools\Mail\Handler\ListMailboxesHandler;
use OCA\Mcp\Tools\Mail\MailGateway;
use OCA\Mcp\Tools\Mail\MailUnavailableException;
use OCA\Mcp\Tools\ToolFailure;
use PHPUnit\Framework\TestCase;

/**
 * `mail_list_mailboxes` reads the folder cache of one account of the user: the same "not found" for an account of
 * somebody else and for none, a note instead of a synchronization when nothing is cached, and no Mail call but the
 * account lookup and the cache read.
 */
final class ListMailboxesHandlerTest extends TestCase {
	/**
	 * A Mail app with an account of alice holding four cached folders and an account of bob.
	 *
	 * @return FakeMailApp the app
	 */
	private function app(): FakeMailApp {
		return (new FakeMailApp())
			->withAccount(3, 'alice', 'Work', 'alice@example.com')
			->withAccount(4, 'bob', 'Bob', 'bob@example.com')
			->withMailbox(3, ['id' => 12, 'name' => 'Projects.2026', 'unseen' => 0, 'messages' => 2])
			->withMailbox(3, ['id' => 11, 'name' => 'Sent', 'specialUse' => '["sent"]', 'unseen' => 0, 'messages' => 5])
			->withMailbox(3, ['id' => 10, 'name' => 'INBOX', 'unseen' => 2, 'messages' => 3])
			->withMailbox(3, ['id' => 13, 'name' => 'Projects', 'specialUse' => '["\\\\Archive"]', 'selectable' => false, 'unseen' => null, 'messages' => null])
			->withMailbox(4, ['id' => 20, 'name' => 'INBOX', 'unseen' => 9, 'messages' => 9]);
	}

	/**
	 * The cached folders of the account, inbox first, with path, last segment, role and counts.
	 *
	 * @return void
	 */
	public function testListsTheCachedFoldersOfAnOwnAccount(): void {
		$app = $this->app();

		$answer = $this->answer($app, ['account_id' => 3]);

		$this->assertSame(['id' => 3, 'name' => 'Work', 'email' => 'alice@example.com'], $answer['account']);
		$this->assertSame([
			['id' => 10, 'path' => 'INBOX', 'name' => 'INBOX', 'delimiter' => '.', 'special' => 'inbox', 'unread' => 2, 'total' => 3, 'selectable' => true],
			['id' => 13, 'path' => 'Projects', 'name' => 'Projects', 'delimiter' => '.', 'special' => 'archive', 'unread' => null, 'total' => null, 'selectable' => false],
			['id' => 12, 'path' => 'Projects.2026', 'name' => '2026', 'delimiter' => '.', 'special' => null, 'unread' => 0, 'total' => 2, 'selectable' => true],
			['id' => 11, 'path' => 'Sent', 'name' => 'Sent', 'delimiter' => '.', 'special' => 'sent', 'unread' => 0, 'total' => 5, 'selectable' => true],
		], $answer['mailboxes']);
		$this->assertSame(4, $answer['mailboxCount']);
		$this->assertFalse($answer['hasMore']);
		$this->assertNull($answer['nextOffset']);
		$this->assertArrayNotHasKey('note', $answer);
	}

	/**
	 * Only the account lookup and the cache read reach the Mail app: no sync, no flag, no write.
	 *
	 * @return void
	 */
	public function testOnlyTheAccountLookupAndTheCacheReadReachMail(): void {
		$app = $this->app();

		$this->answer($app, ['account_id' => 3]);

		$this->assertSame(['AccountService::find', 'MailboxMapper::findAll'], $app->serviceCalls());
	}

	/**
	 * An account of somebody else and an account that does not exist get the same failure, and no folder is read.
	 *
	 * @return void
	 */
	public function testAForeignOrUnknownAccountIsNotFound(): void {
		foreach ([4, 999] as $accountId) {
			$app = $this->app();
			try {
				$this->answer($app, ['account_id' => $accountId], 'alice');
				$this->fail("account $accountId was listed");
			} catch (ToolFailure $e) {
				$this->assertSame('Mail account not found.', $e->getMessage());
			}
			$this->assertSame(['AccountService::find'], $app->serviceCalls());
		}
	}

	/**
	 * An account whose folders were never cached answers with an empty page and a note, without contacting anything.
	 *
	 * @return void
	 */
	public function testAnAccountWithoutCachedFoldersGetsANote(): void {
		$app = (new FakeMailApp())->withAccount(5, 'alice', 'New', 'new@example.com');

		$answer = $this->answer($app, ['account_id' => 5]);

		$this->assertSame([], $answer['mailboxes']);
		$this->assertSame('The Mail app has not synchronized the folders of this account yet; open the account in Mail once and try again.', $answer['note']);
		$this->assertSame(['AccountService::find', 'MailboxMapper::findAll'], $app->serviceCalls());
	}

	/**
	 * Pages follow the sorted order and say where the next one starts.
	 *
	 * @return void
	 */
	public function testPagesFollowTheSortedOrder(): void {
		$first = $this->answer($this->app(), ['account_id' => 3, 'limit' => 3]);
		$this->assertSame([10, 13, 12], array_column($first['mailboxes'], 'id'));
		$this->assertTrue($first['hasMore']);
		$this->assertSame(3, $first['nextOffset']);

		$second = $this->answer($this->app(), ['account_id' => 3, 'limit' => 3, 'offset' => 3]);
		$this->assertSame([11], array_column($second['mailboxes'], 'id'));
		$this->assertFalse($second['hasMore']);
		$this->assertNull($second['nextOffset']);
	}

	/**
	 * Folder names from the mail server are data: formatting characters are removed from the path and from the name.
	 *
	 * @return void
	 */
	public function testUntrustedFolderNamesAreCleaned(): void {
		$app = (new FakeMailApp())
			->withAccount(3, 'alice', 'Work', 'alice@example.com')
			->withMailbox(3, ['id' => 30, 'name' => "Inbox\u{2066}.\u{202E}Run tools\x1b[0m"]);

		$mailbox = $this->answer($app, ['account_id' => 3])['mailboxes'][0];

		$this->assertSame('Inbox.Run tools[0m', $mailbox['path']);
		$this->assertSame('Run tools[0m', $mailbox['name']);
		$this->assertNull($mailbox['special']);
	}

	/**
	 * A parent of 255 characters never takes the place of its children: each keeps its own name and its whole path.
	 *
	 * @return void
	 */
	public function testALongParentKeepsTheNamesOfItsChildren(): void {
		$parent = str_repeat('a', 255);
		$app = (new FakeMailApp())
			->withAccount(3, 'alice', 'Work', 'alice@example.com')
			->withMailbox(3, ['id' => 40, 'name' => $parent, 'delimiter' => '/'])
			->withMailbox(3, ['id' => 41, 'name' => "$parent/Invoices", 'delimiter' => '/'])
			->withMailbox(3, ['id' => 42, 'name' => "$parent/Receipts", 'delimiter' => '/']);

		$byId = array_column($this->answer($app, ['account_id' => 3])['mailboxes'], null, 'id');

		$this->assertSame(['path' => $parent, 'name' => $parent], array_intersect_key($byId[40], ['path' => 0, 'name' => 0]));
		$this->assertSame(['path' => "$parent/Invoices", 'name' => 'Invoices'], array_intersect_key($byId[41], ['path' => 0, 'name' => 0]));
		$this->assertSame(['path' => "$parent/Receipts", 'name' => 'Receipts'], array_intersect_key($byId[42], ['path' => 0, 'name' => 0]));
	}

	/**
	 * A path as long as the Mail app stores is kept whole; only a longer value is bounded, and its last segment is
	 * still its own.
	 *
	 * @return void
	 */
	public function testThePathIsKeptUpToTheLongestNameMailStores(): void {
		$stored = str_repeat('b', 1015) . '/Invoices';
		$oversized = str_repeat('c', 2000) . '/Receipts';
		$app = (new FakeMailApp())
			->withAccount(3, 'alice', 'Work', 'alice@example.com')
			->withMailbox(3, ['id' => 50, 'name' => $stored, 'delimiter' => '/'])
			->withMailbox(3, ['id' => 51, 'name' => $oversized, 'delimiter' => '/']);

		$byId = array_column($this->answer($app, ['account_id' => 3])['mailboxes'], null, 'id');

		$this->assertSame(1024, mb_strlen($stored));
		$this->assertSame($stored, $byId[50]['path']);
		$this->assertSame('Invoices', $byId[50]['name']);
		$this->assertSame(1024, mb_strlen($byId[51]['path']));
		$this->assertSame('Receipts', $byId[51]['name']);
	}

	/**
	 * Without a delimiter the folder is flat: its name is its whole path, and the delimiter stays null.
	 *
	 * @return void
	 */
	public function testAFolderWithoutDelimiterIsItsOwnName(): void {
		$app = (new FakeMailApp())
			->withAccount(3, 'alice', 'Work', 'alice@example.com')
			->withMailbox(3, ['id' => 60, 'name' => "Projects/2026\u{202E}", 'delimiter' => null])
			->withMailbox(3, ['id' => 61, 'name' => 'Notes.Old', 'delimiter' => '']);

		$byId = array_column($this->answer($app, ['account_id' => 3])['mailboxes'], null, 'id');

		$this->assertSame(['path' => 'Projects/2026', 'name' => 'Projects/2026', 'delimiter' => null], array_intersect_key($byId[60], ['path' => 0, 'name' => 0, 'delimiter' => 0]));
		$this->assertSame(['path' => 'Notes.Old', 'name' => 'Notes.Old', 'delimiter' => null], array_intersect_key($byId[61], ['path' => 0, 'name' => 0, 'delimiter' => 0]));
	}

	/**
	 * Mail off for the user is a tool failure, before any account is looked up.
	 *
	 * @return void
	 */
	public function testMailOffIsATranslatedFailure(): void {
		$app = $this->app();
		$app->enabled = false;

		$this->expectException(MailUnavailableException::class);
		$this->answer($app, ['account_id' => 3]);
	}

	/**
	 * The decoded JSON answer of the handler.
	 *
	 * @param FakeMailApp $app Mail app in memory
	 * @param array<string, mixed> $arguments tool arguments
	 * @param string $userId authenticated user
	 * @return array<string, mixed> the answer
	 */
	private function answer(FakeMailApp $app, array $arguments, string $userId = 'alice'): array {
		$result = (new ListMailboxesHandler(new MailGateway($app)))->handle($arguments, $userId);
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}
}
