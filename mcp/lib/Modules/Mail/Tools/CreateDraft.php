<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;

use OCA\Mcp\Mcp\Tools\{WriteTool, ToolContext, ToolDefinition};
use OCA\Mcp\Modules\Mail\Presentation\MailSchema;
use OCA\Mcp\Modules\Mail\Presentation\MailDraftMessages;
use OCA\Mcp\Modules\Mail\Creation\CreateDraftHandler;
use OCA\Mcp\Modules\Mail\Creation\CreateDraftPreview;
use OCA\Mcp\Modules\Mail\Creation\MailDraftTools;

/** Publishes `mail_create_draft` and delegates its plan and effect. */
final class CreateDraft implements WriteTool {
	/**
	 * @param CreateDraftHandler $work confirmed effect
	 * @param CreateDraftPreview $plans effect-free preview
	 */
	public function __construct(private CreateDraftHandler $work, private CreateDraftPreview $plans) {}

	/** {@inheritDoc} */
	public static function definition(): ToolDefinition {
		return MailSchema::definition('mail_create_draft', MailDraftTools::OPERATION, MailDraftMessages::TOOL_CREATE_DRAFT, [
			'account_id' => MailSchema::id('Id of the account whose Drafts folder gets the draft, from mail_list_accounts.'),
			'to' => MailSchema::recipients('Recipients: plain addresses such as name@example.com, without display names; omit for none.'),
			'cc' => MailSchema::recipients('Copy recipients: plain addresses such as name@example.com, without display names; omit for none.'),
			'bcc' => MailSchema::recipients('Blind copy recipients, only with Mail 5.10.11 or later (older Mail saves drafts without them, so they are refused there): plain addresses such as name@example.com, without display names; omit for none.'),
			'subject' => MailSchema::subject(),
			'body' => MailSchema::body('Text of the draft: plain text by default, or HTML when html is true.'),
			'html' => ['type' => 'boolean', 'default' => false, 'description' => 'Create a sanitized HTML draft instead of plain text. Images are forbidden; no account signature is added.'],
			'plan_state' => MailSchema::planState(),
		], ['account_id'], null, [], false);
	}

	/** {@inheritDoc} */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->work->handle($arguments, $context->userId);
	}

	/** {@inheritDoc} */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->plans->plan($arguments, $context->userId);
	}
}
