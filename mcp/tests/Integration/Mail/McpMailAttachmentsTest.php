<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Modules\Mail\Presentation\MailAttachmentMessages;
use OCA\Mcp\Modules\Mail\Reading\MailBodyPreflight;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/**
 * mail_read_attachment against the real Mail app and a real IMAP server (Mail cells only; mail/seed-attachments.sh
 * seeds the Attachments folder).
 *
 * Without `mail.attachments` the tool is absent and uncallable, even with `mail.messages`; with it alone the tool reads.
 * CSV in ISO-8859-1, HTML, PDF and a DOCX declared as octet-stream come back as text; an image, a PDF outside the
 * decoding budget, an embedded message, an S/MIME message, a message over 1 MiB, the body part, another user's message
 * and a cached UID of an older generation are refused. Through all of it the IMAP server keeps every flag, counter and
 * modification sequence (CONDSTORE) and the Mail tables of the account do not change; the rawlogs of the window are
 * kept for the PEEK evidence.
 */
#[Group('mail')]
final class McpMailAttachmentsTest extends IntegrationTestCase {
	use MailCellRows;

	/** The attachment tool. */
	private const TOOL = 'mail_read_attachment';

	/**
	 * Runs only in a Mail cell, with a Mail release the tool exists for.
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
	 * Leaves alice without the messages and attachments grants, as the other Mail tests expect.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (getenv('MCP_IT_WITH_MAIL') === '1') {
			$policy = Server::get(GrantPolicy::class);
			$policy->setGrant(MailActor::ALICE, 'mail', 'messages', false);
			$policy->setGrant(MailActor::ALICE, 'mail', 'attachments', false);
		}
		parent::tearDown();
	}

	/**
	 * With the messages grant but not the attachments one, the tool is neither listed nor callable.
	 *
	 * @return void
	 */
	public function testWithoutTheGrantTheToolIsAbsent(): void {
		$policy = Server::get(GrantPolicy::class);
		$policy->setGrant(MailActor::ALICE, 'mail', 'messages', true);
		$policy->setGrant(MailActor::ALICE, 'mail', 'attachments', false);
		$this->assertNotContains(self::TOOL, array_column(MailActor::run(MailActor::ALICE, 'tools'), 'name'));
		$message = $this->subjects($this->mailbox($this->account(MailActor::ALICE), 'Attachments'))['Attachment csv'];
		$calls = MailActor::run(MailActor::ALICE, 'calls', ['calls' => [[self::TOOL, ['message_id' => $message, 'attachment_id' => '2']]]]);
		$this->assertSame(['refused' => true], $calls[0]['result']);
	}

	/**
	 * With the attachments grant alone the tool reads the text of every supported kind, refuses everything else, and
	 * changes nothing on the server or in the Mail tables.
	 *
	 * @return void
	 */
	public function testAttachmentsAreReadAsTextAndChangeNothing(): void {
		$policy = Server::get(GrantPolicy::class);
		$policy->setGrant(MailActor::ALICE, 'mail', 'messages', false);
		$policy->setGrant(MailActor::ALICE, 'mail', 'attachments', true);
		$own = $this->account(MailActor::ALICE);
		$byName = $this->subjects($this->mailbox($own, 'Attachments'));
		$foreign = $this->messageIds($this->mailbox($this->account(MailActor::BOB), 'INBOX'))[0];
		$regen = $this->subjects($this->mailbox($own, 'Regen'))['Regen original'];

		$imap = $this->imap();
		$serverBefore = $imap->state();
		$cacheBefore = $this->cache($own);

		$tools = array_values(array_filter(MailActor::run(MailActor::ALICE, 'tools'), static fn (array $tool): bool => str_starts_with($tool['name'], 'mail_')));
		$this->assertSame(['mail_list_accounts', 'mail_list_mailboxes', self::TOOL], array_column($tools, 'name'), 'attachments without messages');
		$this->assertTrue($tools[2]['annotations']['readOnlyHint']);
		$this->assertNotContains('confirm', $tools[2]['properties']);

		self::markCell('ml3-attachments-start');
		$read = static fn (int $message, string $part): array => [self::TOOL, ['message_id' => $message, 'attachment_id' => $part]];
		$calls = MailActor::run(MailActor::ALICE, 'calls', ['calls' => [
			$read($byName['Attachment csv'], '2'),
			$read($byName['Attachment html'], '2'),
			$read($byName['Attachment pdf'], '2'),
			$read($byName['Attachment docx'], '2'),
			$read($byName['Attachment gif'], '2'),
			$read($byName['Attachment lzw pdf'], '2'),
			$read($byName['Attachment embedded'], '2'),
			$read($byName['Attachment smime'], '1'),
			$read($byName['Attachment oversized'], '2'),
			$read($byName['Attachment csv'], '1'),
			$read($byName['Attachment csv'], '3'),
			$read($foreign, '2'),
			$read(987654321, '2'),
			$read($regen, '1'),
		]]);
		self::markCell('ml3-attachments-end');
		$results = array_column($calls, 'result');

		$csv = $this->decoded($results[0])['attachment'];
		$this->assertSame(['id' => '2', 'name' => 'cidades.csv', 'declaredMimeType' => 'text/csv', 'kind' => 'text', 'text' => "nome;cidade\nJoão;São Paulo\n"],
			array_intersect_key($csv, array_flip(['id', 'name', 'declaredMimeType', 'kind', 'text'])));
		$html = $this->decoded($results[1])['attachment'];
		$this->assertSame(['html', 'Agenda of Friday'], [$html['kind'], $html['text']]);
		$pdf = $this->decoded($results[2])['attachment'];
		$this->assertSame('pdf', $pdf['kind']);
		$this->assertStringContainsString('Invoice 42 paid', $pdf['text']);
		$docx = $this->decoded($results[3])['attachment'];
		$this->assertSame(['docx', "Meeting minutes\nBudget approved", 'application/octet-stream'], [$docx['kind'], $docx['text'], $docx['declaredMimeType']]);

		$refusals = [
			4 => MailAttachmentMessages::unsupportedType(),
			5 => MailAttachmentMessages::unreadable(),
			6 => MailAttachmentMessages::unsupportedType(),
			7 => MailAttachmentMessages::encrypted(),
			9 => MailAttachmentMessages::attachmentNotFound(),
			10 => MailAttachmentMessages::attachmentNotFound(),
			11 => MailMessages::messageNotFound(),
			12 => MailMessages::messageNotFound(),
			13 => MailMessages::messageChanged(),
		];
		foreach ($refusals as $index => $message) {
			$this->assertSame(['content' => [['type' => 'text', 'text' => $message]], 'isError' => true], $results[$index], "call $index");
		}
		$this->assertTrue($results[8]['isError'] ?? false, 'the oversized message');
		$this->assertStringStartsWith('This message is ', $results[8]['content'][0]['text']);
		$this->assertStringEndsWith(intdiv(MailBodyPreflight::MAX_MESSAGE_BYTES, 1024) . ' KiB allowed; its attachments were not read. Open it in Mail.', $results[8]['content'][0]['text']);
		self::markCell(sprintf('ml3-refused started=%.3f ended=%.3f', $calls[4]['started'], $calls[13]['ended']));

		$this->assertSame($serverBefore, $imap->state(), 'the IMAP server changed: a flag, a counter or a modification sequence moved');
		$imap->close();
		$this->assertSame($cacheBefore, $this->cache($own), 'the Mail tables of the account changed');
	}
}
