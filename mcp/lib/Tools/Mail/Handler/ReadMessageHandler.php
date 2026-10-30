<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Handler;

use OCA\Mcp\Tools\Mail\MailBodyReader;
use OCA\Mcp\Tools\Mail\MailBodyText;
use OCA\Mcp\Tools\Mail\MailMessageAnswer;
use OCA\Mcp\Tools\Mail\MailMessageLocator;
use OCA\Mcp\Tools\Mail\MailMessageRows;
use OCA\Mcp\Tools\Mail\MailRows;
use OCA\Mcp\Tools\Mail\MailUnavailableException;
use OCA\Mcp\Tools\ToolFailure;

/**
 * Backs `mail_read_message`: the headers, the body as text and the attachment list of one message of the user.
 *
 * The message is resolved through the user's own accounts ({@see MailMessageLocator}), its size is checked before
 * its body is read ({@see \OCA\Mcp\Tools\Mail\MailBodyPreflight}) and the body is read by the Mail app with BODY.PEEK,
 * so the message stays unread if it was. Attachments are listed by name, type and size; their content is not read.
 */
final class ReadMessageHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'mail_read_message';

	/** Body length returned when the caller sends no max_chars. */
	public const DEFAULT_MAX_CHARS = 20000;

	/** Most attachments listed; the count says how many there were. */
	public const MAX_ATTACHMENTS = 100;

	/**
	 * Receives the locator, the body reader, the row shaper and the answer shaper.
	 *
	 * @param MailMessageLocator $locator message id to the user's own message, folder and account
	 * @param MailBodyReader $reader reads the body through the Mail app after the preflight
	 * @param MailMessageRows $rows shapes the cached headers
	 * @param MailMessageAnswer $answer the untrusted answer envelope
	 * @return void
	 */
	public function __construct(
		private MailMessageLocator $locator,
		private MailBodyReader $reader,
		private MailMessageRows $rows,
		private MailMessageAnswer $answer,
	) {
	}

	/**
	 * Reads one message.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments: message_id, max_chars
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 * @throws ToolFailure when the message is not the user's, changed, is too large or cannot be read now
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function handle(array $arguments, string $userId): array {
		$found = $this->locator->locate($userId, (int)$arguments['message_id']);
		$account = $found['account'];
		$message = $found['message'];
		$imap = $this->reader->read($userId, $account, $found['mailbox'], $message);
		$plain = (string)$imap->getPlainBody();
		$html = trim($plain) === '' && ($imap->jsonSerialize()['hasHtmlBody'] ?? false) === true
			? (string)$imap->getHtmlBody((int)$message->getId()) : null;
		$attachments = array_values(is_array($imap->getAttachments()) ? $imap->getAttachments() : []);
		$row = $this->rows->summary($message, (int)$account->getId());
		unset($row['preview']);
		return $this->answer->message($row + [
			'bcc' => $this->rows->addresses($message->getBcc()),
			'replyTo' => $this->rows->addresses($imap->getReplyTo()),
			'messageId' => MailRows::text((string)($message->getMessageId() ?? '')),
			'inReplyTo' => MailRows::text((string)($message->getInReplyTo() ?? '')),
			'encrypted' => (bool)$imap->isEncrypted(),
			'signed' => (bool)$imap->isSigned(),
			'body' => MailBodyText::from($plain, $html, (int)($arguments['max_chars'] ?? self::DEFAULT_MAX_CHARS)),
			'attachmentCount' => count($attachments),
			'attachments' => array_map(static fn (array $attachment): array => [
				'id' => MailRows::text((string)($attachment['id'] ?? '')),
				'name' => isset($attachment['fileName']) ? MailRows::text((string)$attachment['fileName']) : null,
				'mimeType' => MailRows::text((string)($attachment['mime'] ?? '')),
				'size' => isset($attachment['size']) ? (int)$attachment['size'] : null,
			], array_slice($attachments, 0, self::MAX_ATTACHMENTS)),
		]);
	}
}
