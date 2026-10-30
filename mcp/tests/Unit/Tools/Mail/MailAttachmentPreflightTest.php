<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Tools\Mail\MailAttachmentMessages;
use OCA\Mcp\Tools\Mail\MailAttachmentPreflight;
use OCA\Mcp\Tools\Mail\MailBodyPreflight;
use OCA\Mcp\Tools\Mail\MailMessages;
use OCA\Mcp\Tools\ToolFailure;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../stubs/Horde/ImapClientStubs.php';

/**
 * The attachment preflight reads metadata only (envelope, size, structure) and lets through exactly the attachments the
 * Mail fetcher lists, refusing an id of any other part, a sealed message and a message over the budget.
 */
final class MailAttachmentPreflightTest extends TestCase {
	/**
	 * Ids are MIME part numbers: never the header (0), no other characters, bounded in length and depth.
	 *
	 * @return void
	 */
	public function testIdsMustBeMimePartNumbers(): void {
		foreach (['1', '2', '2.1', '10.3.4', '9999'] as $id) {
			$this->assertTrue(MailAttachmentPreflight::validId($id), $id);
		}
		foreach (['0', '00', '01', '2.0', '2.', '.2', '2..1', '-1', '1e3', ' 2', "2\n", '2/1', '10000', 'abc', str_repeat('1.', 16) . '1'] as $id) {
			$this->assertFalse(MailAttachmentPreflight::validId($id), var_export($id, true));
		}
	}

	/**
	 * An attachment of a multipart message, as the Mail fetcher lists it, passes with its declared description; the
	 * FETCH asks for envelope, size and structure only.
	 *
	 * @return void
	 */
	public function testAListedAttachmentPasses(): void {
		[$client, $journal] = $this->client(self::mixed(), 4000);
		$part = (new MailAttachmentPreflight())->check($client, 'INBOX', 9, '<m9@example.com>', '2');
		$this->assertSame(['2', 'text/csv', 'utf-8', 'data.csv'], [$part->id, $part->type, $part->charset, $part->name]);
		$this->assertSame(['fetch:envelope,size,structure'], $journal->getArrayCopy());
	}

	/**
	 * Named application parts, named calendars and the only part of a single-part message pass too.
	 *
	 * @return void
	 */
	public function testTheOtherListedShapesPass(): void {
		$preflight = new MailAttachmentPreflight();
		$this->assertSame('application/pdf', $preflight->check($this->client(self::mixed())[0], 'INBOX', 9, '<m9@example.com>', '3')->type);
		$this->assertSame('text/calendar', $preflight->check($this->client(self::mixed())[0], 'INBOX', 9, '<m9@example.com>', '4.2')->type);
		$single = FakeMimePart::single(new FakeMimePart('application/pdf', 'only.pdf'));
		$this->assertSame('1', $preflight->check($this->client($single)[0], 'INBOX', 9, '<m9@example.com>', '1')->id);
	}

	/**
	 * Parts the Mail fetcher does not list as attachments are not found: the body, a multipart, a signature, an unnamed
	 * calendar, a part that does not exist and a part inside an embedded message.
	 *
	 * @return void
	 */
	public function testOtherPartsAreNotAttachments(): void {
		foreach (['1', '4', '4.1', '4.3', '6', '7', '5.1'] as $id) {
			$this->assertRefusal(MailAttachmentMessages::attachmentNotFound(), self::mixed(), $id);
		}
	}

	/**
	 * The fetcher does not walk into a multipart it lists as an attachment, or into a named (inline) one, so their
	 * parts are no listed attachment and are refused before any content is read; a plain nested multipart is walked.
	 *
	 * @return void
	 */
	public function testOnlyMultipartsTheFetcherEntersLeadToAttachments(): void {
		$inner = static fn (): FakeMimePart => new FakeMimePart('text/plain', 'inner.txt', 'attachment', 'utf-8');
		$attached = FakeMimePart::multipart('multipart/mixed', [new FakeMimePart('text/plain'),
			FakeMimePart::nested('multipart/mixed', [$inner()], 'bundle.eml', 'attachment')]);
		$inline = FakeMimePart::multipart('multipart/mixed', [new FakeMimePart('text/plain'),
			FakeMimePart::nested('multipart/related', [$inner()], 'page.html', 'inline')]);
		$plain = FakeMimePart::multipart('multipart/mixed', [new FakeMimePart('text/plain'),
			FakeMimePart::nested('multipart/mixed', [new FakeMimePart('text/plain'), $inner()])]);
		[$client, $journal] = $this->client($attached);
		try {
			(new MailAttachmentPreflight())->check($client, 'INBOX', 9, '<m9@example.com>', '2.1');
			$this->fail('refusal expected');
		} catch (ToolFailure $e) {
			$this->assertSame(MailAttachmentMessages::attachmentNotFound(), $e->getMessage());
		}
		$this->assertSame(['fetch:envelope,size,structure'], $journal->getArrayCopy(), 'metadata only');
		$this->assertRefusal(MailAttachmentMessages::attachmentNotFound(), $inline, '2.1');
		$this->assertSame('inner.txt', (new MailAttachmentPreflight())->check($this->client($plain)[0], 'INBOX', 9, '<m9@example.com>', '2.2')->name);
	}

	/**
	 * An embedded message is listed by Mail but is no file whose text is read here.
	 *
	 * @return void
	 */
	public function testAnEmbeddedMessageIsUnsupported(): void {
		$this->assertRefusal(MailAttachmentMessages::unsupportedType(), self::mixed(), '5');
	}

	/**
	 * An encrypted message or an S/MIME container is refused before the Mail app could decrypt or unwrap it.
	 *
	 * @return void
	 */
	public function testSealedMessagesAreRefused(): void {
		foreach (['multipart/encrypted', 'application/pkcs7-mime', 'application/x-pkcs7-mime'] as $type) {
			$sealed = $type === 'multipart/encrypted'
				? FakeMimePart::multipart($type, [new FakeMimePart('application/pgp-encrypted'), new FakeMimePart('application/octet-stream', 'msg.asc', 'attachment')])
				: FakeMimePart::single(new FakeMimePart($type, 'smime.p7m', 'attachment'));
			$this->assertRefusal(MailAttachmentMessages::encrypted(), $sealed, $type === 'multipart/encrypted' ? '2' : '1');
		}
	}

	/**
	 * The whole message size is the budget, as for a body: over it, zero or missing, nothing else is decided.
	 *
	 * @return void
	 */
	public function testTheMessageSizeIsTheBudget(): void {
		$limit = MailBodyPreflight::MAX_MESSAGE_BYTES;
		(new MailAttachmentPreflight())->check($this->client(self::mixed(), $limit)[0], 'INBOX', 9, '<m9@example.com>', '2');
		$this->assertRefusal(MailAttachmentMessages::messageTooLarge($limit + 1, $limit), self::mixed(), '2', $limit + 1);
		$this->assertRefusal(MailAttachmentMessages::unavailable(), self::mixed(), '2', 0);
		$this->assertRefusal(MailAttachmentMessages::unavailable(), self::mixed(), '2', null);
	}

	/**
	 * Another Message-ID, a message gone and a missing structure are refused.
	 *
	 * @return void
	 */
	public function testAChangedMessageOrMissingStructureIsRefused(): void {
		try {
			(new MailAttachmentPreflight())->check($this->client(self::mixed())[0], 'INBOX', 9, '<other@example.com>', '2');
			$this->fail('refusal expected');
		} catch (ToolFailure $e) {
			$this->assertSame(MailMessages::messageChanged(), $e->getMessage());
		}
		$gone = new RecordingDouble('Client', ['fetch' => static fn (): object => new RecordingDouble('Results', ['first' => static fn (): ?object => null], new \ArrayObject())], new \ArrayObject());
		try {
			(new MailAttachmentPreflight())->check($gone, 'INBOX', 9, null, '2');
			$this->fail('refusal expected');
		} catch (ToolFailure $e) {
			$this->assertSame(MailMessages::messageNotFound(), $e->getMessage());
		}
		$this->assertRefusal(MailAttachmentMessages::unavailable(), null, '2');
	}

	/**
	 * A mixed message: 1 body text, 2 CSV attachment, 3 named PDF, 4 alternative with text, named calendar and unnamed
	 * calendar, 5 embedded message with a part of its own, 6 detached signature.
	 *
	 * @return FakeMimePart the structure
	 */
	private static function mixed(): FakeMimePart {
		return FakeMimePart::multipart('multipart/mixed', [
			new FakeMimePart('text/plain', null, null, 'utf-8'),
			new FakeMimePart('text/csv', 'data.csv', 'attachment', 'UTF-8'),
			new FakeMimePart('application/pdf', 'report.pdf'),
			FakeMimePart::nested('multipart/alternative', [
				new FakeMimePart('text/plain'),
				new FakeMimePart('text/calendar', 'invite.ics'),
				new FakeMimePart('text/calendar'),
			]),
			FakeMimePart::nested('message/rfc822', [new FakeMimePart('application/pdf', 'inner.pdf', 'attachment')]),
			new FakeMimePart('application/pgp-signature', 'signature.asc', 'attachment'),
		]);
	}

	/**
	 * Asserts the check refuses with exactly that message.
	 *
	 * @param string $message expected failure
	 * @param FakeMimePart|null $structure the structure the server declares
	 * @param string $id attachment id
	 * @param int|null $size RFC822.SIZE the server declares
	 * @return void
	 */
	private function assertRefusal(string $message, ?FakeMimePart $structure, string $id, ?int $size = 4000): void {
		try {
			(new MailAttachmentPreflight())->check($this->client($structure, $size)[0], 'INBOX', 9, '<m9@example.com>', $id);
			$this->fail('refusal expected: ' . $message);
		} catch (ToolFailure $e) {
			$this->assertSame($message, $e->getMessage(), $id);
		}
	}

	/**
	 * A client whose FETCH answers one message with that structure and size, recording the items asked for.
	 *
	 * @param FakeMimePart|null $structure the structure
	 * @param int|null $size RFC822.SIZE
	 * @return array{object, \ArrayObject<int, string>} the client and the journal
	 */
	private function client(?FakeMimePart $structure, ?int $size = 4000): array {
		$journal = new \ArrayObject();
		$data = new RecordingDouble('Data', [
			'getSize' => static fn (): ?int => $size,
			'getEnvelope' => static fn (): object => (object)['message_id' => '<m9@example.com>'],
			'getStructure' => static fn (): ?object => $structure,
		], new \ArrayObject());
		$client = new RecordingDouble('Client', [
			'fetch' => static function (string $mailbox, \Horde_Imap_Client_Fetch_Query $query, array $options) use ($journal, $data): object {
				$journal[] = 'fetch:' . implode(',', $query->items);
				return new RecordingDouble('Results', ['first' => static fn (): object => $data], new \ArrayObject());
			},
		], new \ArrayObject());
		return [$client, $journal];
	}
}
