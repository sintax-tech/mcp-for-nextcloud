<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Modules\Mail\Reading\MailBodyPreflight;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/**
 * The message tools against the real Mail app and a real IMAP server (Mail cells only, mail-cell.sh seeds the folders).
 *
 * Without the `mail.messages` grant the tools are absent and uncallable. With it, listings and searches page through
 * every message of a folder, or of every folder, exactly once although many messages share a stored date across
 * folders; the body search finds the messages by their text only; a body is read as text, an HTML one without its
 * markup, the attachments by name and size; a thread is the conversation; another user's message reads like a missing
 * one; a message of more than 1 MiB of text is refused. Through all of it the IMAP server keeps every flag, counter
 * and modification sequence (CONDSTORE), and the Mail tables of the account do not change.
 */
#[Group('mail')]
final class McpMailMessagesTest extends IntegrationTestCase {
	use MailCellRows;

	/** Message id no Mail message has. */
	private const MISSING = 987654321;

	/** The message tools. */
	private const TOOLS = ['mail_list_messages', 'mail_search_messages', 'mail_read_message', 'mail_read_thread'];

	/**
	 * Runs only in a Mail cell.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_MAIL') !== '1') {
			$this->markTestSkipped('Mail cell only (MCP_IT_WITH_MAIL=1)');
		}
	}

	/**
	 * Leaves alice without the messages grant, as the other Mail tests expect.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (getenv('MCP_IT_WITH_MAIL') === '1') {
			Server::get(GrantPolicy::class)->setGrant(MailActor::ALICE, 'mail', 'messages', false);
		}
		parent::tearDown();
	}

	/**
	 * Without the grant the message tools are neither listed nor callable; mail.read alone does not open them.
	 *
	 * @return void
	 */
	public function testWithoutTheGrantTheMessageToolsAreAbsent(): void {
		$policy = Server::get(GrantPolicy::class);
		$policy->setGrant(MailActor::ALICE, 'mail', 'read', true);
		$policy->setGrant(MailActor::ALICE, 'mail', 'messages', false);
		$tools = array_column(MailActor::run(MailActor::ALICE, 'tools'), 'name');
		$this->assertContains('mail_list_mailboxes', $tools);
		$this->assertSame([], array_values(array_intersect(self::TOOLS, $tools)));
		$folder = $this->mailbox($this->account(MailActor::ALICE), 'INBOX');
		$calls = MailActor::run(MailActor::ALICE, 'calls', ['calls' => [['mail_list_messages', ['mailbox_id' => $folder]]]]);
		$this->assertSame(['refused' => true], $calls[0]['result']);
	}

	/**
	 * With the grant every tool reads, the server and the Mail tables stay as they were, and every answer is right.
	 *
	 * @return void
	 */
	public function testTheMessageToolsReadEverythingAndChangeNothing(): void {
		Server::get(GrantPolicy::class)->setGrant(MailActor::ALICE, 'mail', 'messages', true);
		$own = $this->account(MailActor::ALICE);
		$foreignMessage = $this->messageIds($this->mailbox($this->account(MailActor::BOB), 'INBOX'))[0];
		$batch = $this->mailbox($own, 'Batch');
		$reading = $this->mailbox($own, 'Reading');
		$bySubject = $this->subjects($reading);
		$sizes = $this->subjects($this->mailbox($own, 'Sizes'));
		$regen = $this->subjects($this->mailbox($own, 'Regen')) + $this->subjects($this->mailbox($own, 'RegenNoId'));

		$imap = $this->imap();
		$serverBefore = $imap->state();
		$cacheBefore = $this->cache($own);

		self::markCell('ml2-tools-start');
		$tools = MailActor::run(MailActor::ALICE, 'tools');
		$listed = array_values(array_filter($tools, static fn (array $tool): bool => in_array($tool['name'], self::TOOLS, true)));
		$this->assertSame(self::TOOLS, array_column($listed, 'name'));
		foreach ($listed as $tool) {
			$this->assertTrue($tool['annotations']['readOnlyHint'], $tool['name']);
			$this->assertNotContains('confirm', $tool['properties'], $tool['name']);
		}

		$folderPages = MailActor::run(MailActor::ALICE, 'pages', ['tool' => 'mail_list_messages', 'arguments' => ['mailbox_id' => $batch, 'limit' => 2]]);
		$this->assertSame($this->messageIds($batch), $this->pagedIds($folderPages), 'every Batch message once, the six of one date included');

		$everyPages = MailActor::run(MailActor::ALICE, 'pages', ['tool' => 'mail_search_messages', 'arguments' => ['account_id' => $own, 'limit' => 3]]);
		$expected = [];
		foreach ($this->decoded($everyPages[0])['folders'] as $folder) {
			array_push($expected, ...$this->messageIds($folder['mailboxId']));
		}
		sort($expected);
		$paged = $this->pagedIds($everyPages);
		$this->assertSame($expected, $paged, 'every message of every folder once, across folders sharing a date; missing: '
			. json_encode($this->describe(array_diff($expected, $paged)), JSON_THROW_ON_ERROR) . '; pages: '
			. json_encode(array_map(fn (array $page): array => array_column($this->decoded($page)['messages'], 'id'), $everyPages), JSON_THROW_ON_ERROR));

		$calls = MailActor::run(MailActor::ALICE, 'calls', ['calls' => [
			['mail_search_messages', ['account_id' => $own, 'body' => 'zebracorn']],
			['mail_search_messages', ['mailbox_id' => $reading, 'from' => ['dan@example.com']]],
			['mail_read_message', ['message_id' => $bySubject['HTML only']]],
			['mail_read_message', ['message_id' => $bySubject['With attachment']]],
			['mail_read_thread', ['message_id' => $bySubject['Thread start']]],
			['mail_read_message', ['message_id' => $foreignMessage]],
			['mail_read_message', ['message_id' => self::MISSING]],
			['mail_read_message', ['message_id' => $bySubject['Oversized']]],
			['mail_read_message', ['message_id' => $sizes['Base64 text']]],
			['mail_read_message', ['message_id' => $sizes['Inline size one']]],
			['mail_read_message', ['message_id' => $sizes['Large header']]],
			['mail_read_message', ['message_id' => $regen['Regen original']]],
			['mail_read_message', ['message_id' => $regen['Regen no id original']]],
			['mail_search_messages', ['mailbox_id' => $batch, 'subject' => 'Batch older', 'body' => 'nothingmatchesthis']],
			['mail_search_messages', ['mailbox_id' => $batch, 'body' => 'nothingmatchesthis']],
		]]);
		$results = array_column($calls, 'result');

		$this->assertSame(['Batch older', 'Venue booking'], $this->sortedSubjects($this->decoded($results[0])['messages']), 'the body search');
		$this->assertSame(['Re: Thread start'], array_column($this->decoded($results[1])['messages'], 'subject'));

		$html = $this->decoded($results[2])['message']['body'];
		$this->assertSame('html', $html['format']);
		$this->assertSame('Hello reader', $html['text']);

		$attachment = $this->decoded($results[3])['message'];
		$this->assertSame('See the attached plan.', trim($attachment['body']['text']));
		$this->assertSame(['plan.pdf'], array_column($attachment['attachments'], 'name'));
		$this->assertSame('application/pdf', $attachment['attachments'][0]['mimeType']);

		$this->assertSame(['Re: Thread start', 'Thread start'], array_column($this->decoded($results[4])['messages'], 'subject'));

		$this->assertTrue($results[5]['isError'] ?? false);
		$this->assertSame(MailMessages::messageNotFound(), $results[5]['content'][0]['text']);
		$this->assertSame($results[6], $results[5]);

		foreach ([7 => 'Oversized', 8 => 'Base64 text', 9 => 'Inline size one', 10 => 'Large header'] as $index => $subject) {
			$this->assertTrue($results[$index]['isError'] ?? false, $subject);
			$this->assertStringStartsWith('This message is ', $results[$index]['content'][0]['text'], $subject);
			$this->assertStringContainsString(intdiv(MailBodyPreflight::MAX_MESSAGE_BYTES, 1024) . ' KiB allowed', $results[$index]['content'][0]['text'], $subject);
		}
		foreach ([11, 12] as $index) {
			$this->assertSame(['content' => [['type' => 'text', 'text' => MailMessages::messageChanged()]], 'isError' => true], $results[$index],
				'a cached UID of an older UID generation, with the same Message-ID or none, is never read');
		}
		$this->assertSame(['Batch older'], array_column($this->decoded($results[13])['messages'], 'subject'), 'the subject matches when the body search finds nothing');
		$this->assertSame([], $this->decoded($results[14])['messages']);
		self::markCell(sprintf('ml2-refused-reads started=%.3f ended=%.3f', $calls[7]['started'], $calls[12]['ended']));

		$fragments = MailActor::run(MailActor::ALICE, 'calls', ['calls' => array_map(static fn (array $filter): array => ['mail_search_messages', ['mailbox_id' => $reading] + $filter], [
			['from' => ['carol']], ['from' => ['zed']], ['from' => ['carol', 'zed']],
			['to' => ['team']], ['to' => ['other@']], ['to' => ['team', 'other@']],
			['cc' => ['eve']], ['cc' => ['frank']], ['cc' => ['eve', 'frank']],
			['from' => ['dan', 'zed'], 'cc' => ['frank']],
		])]);
		$subjects = array_map(fn (array $call): array => $this->sortedSubjects($this->decoded($call['result'])['messages']), $fragments);
		$this->assertSame([
			['HTML only', 'Oversized', 'Thread start', 'With attachment'], ['Venue booking'], ['HTML only', 'Oversized', 'Thread start', 'Venue booking', 'With attachment'],
			['Re: Thread start'], ['Venue booking'], ['Re: Thread start', 'Venue booking'],
			['Re: Thread start'], ['Venue booking'], ['Re: Thread start', 'Venue booking'],
			['Venue booking'],
		], $subjects, 'within a field any fragment matches; different fields all match');
		self::markCell('ml2-tools-end');

		$this->assertSame($serverBefore, $imap->state(), 'the IMAP server changed: a flag, a counter or a modification sequence moved');
		$imap->close();
		$this->assertSame($cacheBefore, $this->cache($own), 'the Mail tables of the account changed');
	}

	/**
	 * The folder, stored date and flags of messages, for a failure message (synthetic fixtures only).
	 *
	 * @param list<int> $ids message ids
	 * @return list<array<string, mixed>> one row per message
	 */
	private function describe(array $ids): array {
		$rows = [];
		foreach ($ids as $id) {
			foreach ($this->select('mail_messages', 'id', (int)$id, 'id') as $row) {
				$rows[] = array_intersect_key($row, array_flip(['id', 'mailbox_id', 'uid', 'sent_at', 'flag_deleted', 'subject']));
			}
		}
		return $rows;
	}

	/**
	 * All message ids of the pages, sorted, after checking that none repeats.
	 *
	 * @param list<array<string, mixed>> $pages MCP results
	 * @return list<int> the ids
	 */
	private function pagedIds(array $pages): array {
		$ids = [];
		foreach ($pages as $page) {
			array_push($ids, ...array_column($this->decoded($page)['messages'], 'id'));
		}
		$this->assertSame(count($ids), count(array_unique($ids)), 'a message came twice');
		sort($ids);
		return $ids;
	}

	/**
	 * Subjects of message rows, sorted.
	 *
	 * @param list<array<string, mixed>> $rows message rows
	 * @return list<string> subjects
	 */
	private function sortedSubjects(array $rows): array {
		$subjects = array_column($rows, 'subject');
		sort($subjects);
		return $subjects;
	}
}
