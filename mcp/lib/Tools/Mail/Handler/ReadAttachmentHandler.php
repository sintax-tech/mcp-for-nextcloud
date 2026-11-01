<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Handler;

use OCA\Mcp\Tools\Mail\MailAttachmentMessages;
use OCA\Mcp\Tools\Mail\MailAttachmentPreflight;
use OCA\Mcp\Tools\Mail\MailAttachmentReader;
use OCA\Mcp\Tools\Mail\MailAttachmentText;
use OCA\Mcp\Tools\Mail\MailBodyText;
use OCA\Mcp\Tools\Mail\MailMessageLocator;
use OCA\Mcp\Tools\Mail\MailRows;
use OCA\Mcp\Tools\Mail\MailUnavailableException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

/**
 * Backs `mail_read_attachment`: the text of one attachment of a message of the user, and nothing of the message
 * itself (no headers, no body).
 *
 * The message is resolved through the user's own accounts on every call ({@see MailMessageLocator}), the attachment is
 * read by the Mail app after the generation, size and structure checks ({@see MailAttachmentReader}) without marking the
 * message as read, and only its text comes back ({@see MailAttachmentText}), cut to max_chars. The name and the declared
 * type are shown as the sender wrote them.
 */
final class ReadAttachmentHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'mail_read_attachment';

	/** Text length returned when the caller sends no max_chars. */
	public const DEFAULT_MAX_CHARS = 20000;

	/**
	 * Receives the locator, the attachment reader and the text extractor.
	 *
	 * @param MailMessageLocator $locator message id to the user's own message, folder and account
	 * @param MailAttachmentReader $reader reads the attachment through the Mail app after the checks
	 * @param MailAttachmentText $text turns the readable kinds into text
	 * @return void
	 */
	public function __construct(
		private MailMessageLocator $locator,
		private MailAttachmentReader $reader,
		private MailAttachmentText $text,
	) {
	}

	/**
	 * Reads the text of one attachment.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments: message_id, attachment_id, max_chars
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 * @throws ToolFailure when the message is not the user's, changed or is too large, the attachment does not exist,
	 *     is encrypted, of a kind not read as text, damaged or too large, or the server cannot be read now
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function handle(array $arguments, string $userId): array {
		$attachmentId = (string)$arguments['attachment_id'];
		if (!MailAttachmentPreflight::validId($attachmentId)) {
			throw new ToolFailure(MailAttachmentMessages::attachmentNotFound());
		}
		$found = $this->locator->locate($userId, (int)$arguments['message_id']);
		$read = $this->reader->read($userId, $found['account'], $found['mailbox'], $found['message'], $attachmentId);
		$part = $read['part'];
		$extracted = $this->text->extract($read['content'], $part);
		$body = MailBodyText::from($extracted['text'], null, (int)($arguments['max_chars'] ?? self::DEFAULT_MAX_CHARS));
		return ToolResult::json([
			'untrustedMailData' => true,
			'notice' => MailAttachmentMessages::UNTRUSTED_NOTICE,
			'attachment' => [
				'messageId' => (int)$found['message']->getId(),
				'id' => $part->id,
				'name' => $part->name === null ? null : MailRows::text($part->name),
				'declaredMimeType' => MailRows::text($part->type),
				'kind' => $extracted['kind'],
				'text' => $body['text'],
				'truncated' => $body['truncated'],
				'totalChars' => $body['totalChars'],
			],
		]);
	}
}
