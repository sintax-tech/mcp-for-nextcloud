<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

use InvalidArgumentException;
use OCA\Mcp\Tools\Mail\Handler\ReadAttachmentHandler;
use OCP\App\IAppManager;
use OCP\ITempManager;

/**
 * The attachment tool of the Mail module: its definition and the delegation of a call to its handler.
 *
 * It reads, so its operation is `read` and the registry never asks for `confirm` nor marks it as writing. Who may call
 * it is decided by the separate grant `mail.attachments` (`grantAnyOf`), which starts denied like every operation but
 * read ({@see \OCA\Mcp\Access\Grants\GrantPolicy::defaultGranted()}): neither `mail.read` nor `mail.messages` opens an
 * attachment. It does not need `mail.messages` either: the tool resolves the message, its folder and its account
 * itself and returns nothing of the message but the attachment text; in practice the ids come from mail_read_message.
 * It carries the app id, so it is hidden while Mail is not enabled for the user or is older than MCP supports, and it
 * is not even defined below Mail 5.10.11 ({@see MailAttachmentSupport}).
 */
final class MailAttachmentTools {
	/** Grant operation of the attachment tool. */
	public const OPERATION = 'attachments';

	/**
	 * Receives the Mail classes, the account boundary, the app manager and the temporary file manager.
	 *
	 * @param MailServices $services resolver of the Mail classes
	 * @param MailGateway $accounts read-only access to the accounts and folders of the Mail app
	 * @param IAppManager $apps tells the installed Mail version
	 * @param ITempManager $tempManager temporary files for the DOCX and ODT reader
	 * @return void
	 */
	public function __construct(
		private MailServices $services,
		private MailGateway $accounts,
		private IAppManager $apps,
		private ITempManager $tempManager,
	) {
	}

	/**
	 * The definition of the tool, when the installed Mail reads attachments without marking messages as read.
	 *
	 * @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, grantAnyOf:list<string>, app:string}>
	 */
	public function definitions(): array {
		if (!MailAttachmentSupport::available($this->apps)) {
			return [];
		}
		return [[
			'name' => ReadAttachmentHandler::TOOL,
			'description' => MailAttachmentMessages::TOOL_READ_ATTACHMENT,
			'module' => MailModule::MODULE,
			'operation' => 'read',
			'grantAnyOf' => [self::OPERATION],
			'app' => MailServices::APP_ID,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'message_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id of the message, from a listing, a search or a thread.'],
					'attachment_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => MailAttachmentPreflight::MAX_ID_LENGTH,
						'description' => 'Id of the attachment, as the attachments of mail_read_message list it: a MIME part number such as "2" or "2.1".'],
					'max_chars' => ['type' => 'integer', 'minimum' => 1, 'maximum' => MailBodyText::MAX_CHARS, 'default' => ReadAttachmentHandler::DEFAULT_MAX_CHARS,
						'description' => 'Most characters of the text returned (default 20000, at most 100000).'],
				],
				'required' => ['message_id', 'attachment_id'],
				'additionalProperties' => false,
			],
		]];
	}

	/**
	 * Whether a tool name is the attachment tool.
	 *
	 * @param string $name tool name
	 * @return bool true for mail_read_attachment
	 */
	public static function handles(string $name): bool {
		return $name === ReadAttachmentHandler::TOOL;
	}

	/**
	 * Delegates a call to the handler of the tool.
	 *
	 * @param string $name tool name from definitions()
	 * @param array<string, mixed> $arguments arguments already validated against the schema of the tool
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 * @throws InvalidArgumentException for a tool this class does not have
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure when the message or the attachment cannot be read, or Mail is not available
	 */
	public function call(string $name, array $arguments, string $userId): array {
		if (!self::handles($name)) {
			throw new InvalidArgumentException('Unknown tool');
		}
		$locator = new MailMessageLocator($this->accounts, new MailMessageGateway($this->services));
		$text = new MailAttachmentText(new MailAttachmentDocument($this->tempManager));
		return (new ReadAttachmentHandler($locator, new MailAttachmentReader($this->services), $text))->handle($arguments, $userId);
	}
}
