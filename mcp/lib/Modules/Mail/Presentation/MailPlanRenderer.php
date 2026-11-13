<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Presentation;

/** Selects the unchanged renderer of each Mail write. */
final class MailPlanRenderer {
	/**
	 * @param string $tool published tool name
	 * @param array<string, mixed> $plan the write preview
	 * @return string|null the readable plan body, or null for an unknown plan
	 */
	public function render(string $tool, array $plan): ?string {
		return match (true) {
			$tool === 'mail_save_attachment' => (new MailSavePlanRenderer())->render($plan),
			$tool === \OCA\Mcp\Modules\Mail\Editing\SetFlagsHandler::TOOL => (new MailFlagPlanRenderer())->render($plan),
			$tool === \OCA\Mcp\Modules\Mail\Moving\MoveMessageHandler::TOOL => (new MailMovePlanRenderer())->render($plan),
			$tool === \OCA\Mcp\Modules\Mail\Creation\CreateDraftHandler::TOOL => (new MailDraftPlanRenderer())->render($plan),
			$tool === \OCA\Mcp\Modules\Mail\Sending\SendMessageHandler::TOOL => (new MailSendPlanRenderer())->render($plan),
			default => null,
		};
	}
}
