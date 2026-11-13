<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Writing;

use OCA\Mcp\Modules\Mail\Presentation\MailSaveMessages;
use OCA\Mcp\Mcp\Tools\Results\{ToolResult, ToolFailure};
use OCA\Mcp\Platform\FileAccess\CommonMessages;

/** Executes one confirmed save and reports uncertainty without deleting partial results. */
final class SaveAttachmentHandler {
	/** @param MailSaveContext $context Fresh authorization, safe read and protected write. */
	public function __construct(private MailSaveContext $context) {}
	/** @param array<string,mixed> $arguments Validated arguments. @param string $userId Actor UID. @return array<string,mixed> MCP receipt. */
	public function handle(array $arguments, string $userId): array {
		if (($arguments['confirm'] ?? false) !== true) { throw new ToolFailure(CommonMessages::forbidden()); }
		$prepared = $this->context->prepare($arguments, $userId, true);
		$receipt = $this->context->save($prepared, $arguments, $userId);
		if (!isset($receipt['requiresConfirmation'])) {
			$receipt['message'] = ($receipt['verified'] ?? false) ? MailSaveMessages::saved() : MailSaveMessages::uncertain();
		}
		return ToolResult::json($receipt);
	}
}
