<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Tools\Mail\MailAttachmentMessages;
use OCA\Mcp\Tools\Mail\MailAttachmentReader;
use OCA\Mcp\Tools\Mail\MailAttachmentTools;
use OCA\Mcp\Tools\Mail\MailBodyPreflight;
use OCA\Mcp\Tools\Mail\MailGateway;
use OCA\Mcp\Tools\Mail\MailMessages;
use OCA\Mcp\Tools\ToolFailure;
use OCP\App\IAppManager;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

/**
 * mail_read_attachment against a Mail app in memory: on one client, the generation is checked before and after the
 * selection, the metadata preflight runs before the Mail app reads the part, on that very client, and only the text
 * comes back. Nothing of the message is fetched as content, no flag changes, and another user's message, a sealed
 * message, an oversized one or a part that is no attachment never reach the attachment read.
 */
final class MailAttachmentToolsTest extends TestCase {
	/** Account of alice. */
	private const OWN = 11;

	/** Account of bob. */
	private const FOREIGN = 22;

	/** The app in memory. */
	private FakeMailMessageApp $app;

	/**
	 * Alice owns account 11 with INBOX (1); bob owns account 22 with INBOX (9). Message 7 of alice has a CSV attachment
	 * (part 2) beside its body (part 1).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->app = (new FakeMailMessageApp())
			->withAccount(self::OWN, 'alice', 'Work', 'alice@example.com')
			->withAccount(self::FOREIGN, 'bob', 'Bob', 'bob@example.com');
		$this->app->withFolder(self::OWN, ['id' => 1, 'name' => 'INBOX'])->withFolder(self::FOREIGN, ['id' => 9, 'name' => 'INBOX']);
		$this->app->withMessage(1, ['id' => 7, 'structure' => self::withCsv(), 'attachmentContents' => ['2' => "name;total\r\nAna;10\u{0007}"]]);
	}

	/**
	 * The read runs STATUS, the metadata FETCH (envelope, size, structure), the generation of the selection, then the
	 * Mail app's attachment read on the same client, and logs out; the text comes back cleaned, with the declared name
	 * and type and nothing of the message.
	 *
	 * @return void
	 */
	public function testTheAttachmentIsReadAfterTheChecksOnTheSameClient(): void {
		$out = $this->call(['message_id' => 7, 'attachment_id' => '2']);

		$this->assertSame(['imap:status', 'imap:fetch:envelope,size,structure', 'imap:status(selected)', 'imap:attachment:2', 'imap:logout'], $this->app->imapCommands());
		$this->assertSame(1, count(array_keys($this->app->journal->getArrayCopy(), 'IMAPClientFactory::getClient')), 'one client');
		$this->assertTrue($out['untrustedMailData']);
		$this->assertSame(MailAttachmentMessages::UNTRUSTED_NOTICE, $out['notice']);
		$this->assertSame(['messageId' => 7, 'id' => '2', 'name' => 'data.csv', 'declaredMimeType' => 'text/csv', 'kind' => 'text',
			'text' => "name;total\nAna;10", 'truncated' => false, 'totalChars' => 17], $out['attachment']);
		$this->assertArrayNotHasKey('message', $out);
	}

	/**
	 * The text is cut to max_chars, with the length before the cut.
	 *
	 * @return void
	 */
	public function testTheTextIsCutToMaxChars(): void {
		$attachment = $this->call(['message_id' => 7, 'attachment_id' => '2', 'max_chars' => 4])['attachment'];
		$this->assertSame(['name', true, 17], [$attachment['text'], $attachment['truncated'], $attachment['totalChars']]);
	}

	/**
	 * A PDF attachment is read from its content.
	 *
	 * @return void
	 */
	public function testAPdfAttachmentIsRead(): void {
		$structure = FakeMimePart::multipart('multipart/mixed', [new FakeMimePart('text/plain'), new FakeMimePart('application/octet-stream', 'scan.bin', 'attachment')]);
		$this->app->withMessage(1, ['id' => 8, 'structure' => $structure, 'attachmentContents' => ['2' => AttachmentSamples::pdf('Invoice 42')]]);
		$attachment = $this->call(['message_id' => 8, 'attachment_id' => '2'])['attachment'];
		$this->assertSame('pdf', $attachment['kind']);
		$this->assertStringContainsString('Invoice 42', $attachment['text']);
	}

	/**
	 * An id that is no MIME part number is refused before any Mail or IMAP call; the body part, a missing part and a
	 * manipulated id are refused after the metadata FETCH, before the attachment read.
	 *
	 * @return void
	 */
	public function testManipulatedIdsNeverReachTheAttachmentRead(): void {
		foreach (['0', '2 ', '../2', '2.0'] as $id) {
			$this->assertSame(MailAttachmentMessages::attachmentNotFound(), $this->failure(['message_id' => 7, 'attachment_id' => $id]), $id);
		}
		$this->assertSame([], $this->app->journal->getArrayCopy(), 'nothing called for an id of the wrong shape');
		foreach (['1', '3', '2.1'] as $id) {
			$this->assertSame(MailAttachmentMessages::attachmentNotFound(), $this->failure(['message_id' => 7, 'attachment_id' => $id]), $id);
		}
		$this->assertSame(array_merge(...array_fill(0, 3, ['imap:status', 'imap:fetch:envelope,size,structure', 'imap:logout'])), $this->app->imapCommands());
	}

	/**
	 * Another user's message and a missing one answer the same "not found", without any IMAP command.
	 *
	 * @return void
	 */
	public function testForeignAndMissingMessagesAreIndistinguishable(): void {
		$this->app->withMessage(9, ['id' => 900, 'structure' => self::withCsv(), 'attachmentContents' => ['2' => 'bob data']]);
		$foreign = $this->failure(['message_id' => 900, 'attachment_id' => '2']);
		$this->assertSame(MailMessages::messageNotFound(), $foreign);
		$this->assertSame($foreign, $this->failure(['message_id' => 12345, 'attachment_id' => '2']));
		$this->assertSame([], $this->app->imapCommands());
	}

	/**
	 * A cache of another UID generation is refused before any FETCH, a folder recreated between the STATUS and the
	 * EXAMINE is refused after the metadata FETCH and before the attachment read, and with the generation stable the
	 * same read passes.
	 *
	 * @return void
	 */
	public function testAnotherGenerationNeverReachesTheAttachmentRead(): void {
		$this->app->withFolder(self::OWN, ['id' => 2, 'name' => 'Regenerated']);
		$this->app->uidValidity['Regenerated'] = '8';
		$this->app->withMessage(2, ['id' => 21, 'structure' => self::withCsv(), 'attachmentContents' => ['2' => 'old']]);
		$this->assertSame(MailMessages::messageChanged(), $this->failure(['message_id' => 21, 'attachment_id' => '2']));
		$this->assertSame(['imap:status', 'imap:logout'], $this->app->imapCommands());

		$this->app->journal->exchangeArray([]);
		$this->app->withFolder(self::OWN, ['id' => 3, 'name' => 'Racing']);
		$this->app->uidValidityOnOpen['Racing'] = '8';
		$this->app->withMessage(3, ['id' => 31, 'structure' => self::withCsv(), 'attachmentContents' => ['2' => 'new']]);
		$this->assertSame(MailMessages::messageChanged(), $this->failure(['message_id' => 31, 'attachment_id' => '2']));
		$this->assertSame(['imap:status', 'imap:fetch:envelope,size,structure', 'imap:status(selected)', 'imap:logout'], $this->app->imapCommands());

		$this->app->journal->exchangeArray([]);
		unset($this->app->uidValidityOnOpen['Racing']);
		$this->assertSame('new', $this->call(['message_id' => 31, 'attachment_id' => '2'])['attachment']['text']);
	}

	/**
	 * An oversized message and a sealed one are refused after the metadata FETCH only; a server that returns more than
	 * it declared is refused before any parser runs.
	 *
	 * @return void
	 */
	public function testOversizedAndSealedMessagesAreRefusedWithoutContent(): void {
		$limit = MailBodyPreflight::MAX_MESSAGE_BYTES;
		$this->app->withMessage(1, ['id' => 40, 'size' => $limit + 1, 'structure' => self::withCsv(), 'attachmentContents' => ['2' => 'x']]);
		$this->assertSame(MailAttachmentMessages::messageTooLarge($limit + 1, $limit), $this->failure(['message_id' => 40, 'attachment_id' => '2']));
		$sealed = FakeMimePart::single(new FakeMimePart('application/pkcs7-mime', 'smime.p7m', 'attachment'));
		$this->app->withMessage(1, ['id' => 41, 'structure' => $sealed, 'attachmentContents' => ['1' => 'x']]);
		$this->assertSame(MailAttachmentMessages::encrypted(), $this->failure(['message_id' => 41, 'attachment_id' => '1']));
		$this->assertNotContains('imap:attachment:2', $this->app->imapCommands());
		$this->assertNotContains('imap:attachment:1', $this->app->imapCommands());

		$this->app->withMessage(1, ['id' => 42, 'structure' => self::withCsv(), 'attachmentContents' => ['2' => str_repeat('a', MailAttachmentReader::MAX_ATTACHMENT_BYTES + 1)]]);
		$this->assertSame(MailAttachmentMessages::attachmentTooLarge(MailAttachmentReader::MAX_ATTACHMENT_BYTES), $this->failure(['message_id' => 42, 'attachment_id' => '2']));
	}

	/**
	 * A server failure during the attachment read gives a fixed message, never the server's text, and the client is
	 * logged out.
	 *
	 * @return void
	 */
	public function testAServerFailureHidesItsDetails(): void {
		$this->app->attachmentFails = true;
		$failure = $this->failure(['message_id' => 7, 'attachment_id' => '2']);
		$this->assertSame(MailAttachmentMessages::unavailable(), $failure);
		$this->assertStringNotContainsString('secret', $failure);
		$this->assertSame('imap:logout', array_slice($this->app->imapCommands(), -1)[0]);
	}

	/**
	 * Mail turned off answers with the unavailable message, before any IMAP command.
	 *
	 * @return void
	 */
	public function testMailOffIsUnavailable(): void {
		$this->app->enabled = false;
		$this->assertSame(MailMessages::unavailable(), $this->failure(['message_id' => 7, 'attachment_id' => '2']));
		$this->assertSame([], $this->app->imapCommands());
	}

	/**
	 * A message with a body (part 1), a CSV attachment (part 2) and a nested alternative (part 3, with 3.1).
	 *
	 * @return FakeMimePart the structure
	 */
	private static function withCsv(): FakeMimePart {
		return FakeMimePart::multipart('multipart/mixed', [
			new FakeMimePart('text/plain', null, null, 'utf-8'),
			new FakeMimePart('text/csv', 'data.csv', 'attachment', 'utf-8'),
			FakeMimePart::nested('multipart/alternative', [new FakeMimePart('text/plain')]),
		]);
	}

	/**
	 * The decoded JSON answer of a successful call as alice.
	 *
	 * @param array<string, mixed> $arguments tool arguments
	 * @return array<string, mixed> the answer
	 */
	private function call(array $arguments): array {
		$result = $this->tools()->call('mail_read_attachment', $arguments, 'alice');
		$this->assertArrayNotHasKey('isError', $result);
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * The message of a failing call as alice.
	 *
	 * @param array<string, mixed> $arguments tool arguments
	 * @return string the failure message
	 */
	private function failure(array $arguments): string {
		try {
			$this->tools()->call('mail_read_attachment', $arguments, 'alice');
		} catch (ToolFailure $e) {
			return $e->getMessage();
		}
		$this->fail('mail_read_attachment did not fail');
	}

	/**
	 * The attachment tool over the app in memory, with Mail 5.12.5.
	 *
	 * @return MailAttachmentTools the tool
	 */
	private function tools(): MailAttachmentTools {
		$apps = $this->createStub(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('5.12.5');
		$temp = $this->createStub(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(static fn (): string => (string)tempnam(sys_get_temp_dir(), 'mcp-att'));
		return new MailAttachmentTools($this->app, new MailGateway($this->app), $apps, $temp);
	}
}
