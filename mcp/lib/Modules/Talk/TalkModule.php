<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk;

use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Modules\Talk\Presentation\TalkPlanRenderer;

/** Publishes the unchanged Talk catalog and delegates human-readable plans. */
final class TalkModule extends CatalogModule {
	/** Published tool names, retained for callers of the previous module. */
	public const TOOL_LIST = 'talk_list_conversations';
	public const TOOL_READ = 'talk_read_messages';
	public const TOOL_REPLY = 'talk_reply';
	public const TOOL_ATTACH = 'talk_attach_file';
	public const TOOL_QUOTE = 'talk_quote_file';
	public const TOOL_MESSAGE_USER = 'talk_message_user';
	public const TOOL_SEND_BATCH = 'talk_send_batch';
	public const TOOL_CREATE_GROUP = 'talk_create_group';
	public const TOOL_LIST_UNREAD = 'talk_list_unread';
	public const TOOL_READ_UNREAD = 'talk_read_unread';

	/** @return list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> unchanged ordered tools */
	public static function catalog(): array {
		return TalkToolCatalog::TOOLS;
	}

	/** @return class-string<TalkRegistration> lazy native-service resolver construction */
	public static function registration(): ?string {
		return TalkRegistration::class;
	}

	/**
	 * @param string $tool public writing tool name
	 * @param array<string,mixed> $plan existing confirmation plan
	 * @return string|null unchanged Markdown body, or the existing generic fallback
	 */
	public function renderPlan(string $tool, array $plan): ?string {
		return TalkPlanRenderer::render($tool, $plan);
	}
}
