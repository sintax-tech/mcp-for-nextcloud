<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;

use OCA\Mcp\Mcp\Tools\{ToolDefinition, ToolContext, WriteTool};
use OCA\Mcp\Modules\Deck\DeckExecutionContext;
use OCA\Mcp\Modules\Deck\Editing\SetCardArchivedPreview;
use OCA\Mcp\Modules\Deck\Presentation\{DeckSchema, DeckRefusalMessages, DeckStatusMessages};
use OCA\Mcp\Mcp\Confirmation\PlanState;

/** Publishes one explicit desired status under the existing edit grant. */
final class SetCardArchived implements WriteTool {
	/** @param DeckExecutionContext $execution Per-user composition and app gate. @param SetCardArchivedPreview $plans Read-only preview. */
	public function __construct(private DeckExecutionContext $execution, private SetCardArchivedPreview $plans) {}
	/** @return ToolDefinition Strict static schema; WriteGate alone adds confirm. */
	public static function definition(): ToolDefinition {
		return new ToolDefinition('deck_set_card_archived', 'deck', 'edit', DeckStatusMessages::ARCHIVED_DESCRIPTION, DeckSchema::schema([
			'cardId' => DeckSchema::integer(DeckRefusalMessages::PARAM_CARD_ID, 1),
			'archived' => ['type' => 'boolean', 'description' => DeckStatusMessages::ARCHIVED_PARAMETER],
			'lastModified' => DeckSchema::integer(DeckRefusalMessages::PARAM_LAST_MODIFIED, 0),
			'confirm_shared' => DeckSchema::confirmShared(), 'plan_state' => PlanState::property(),
		], ['cardId', 'archived']), 'deck');
	}
	/** @param array<string,mixed> $arguments Validated intent. @param ToolContext $context Caller. @return array<string,mixed> Observed effect. */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->execution->execute('deck_set_card_archived', $arguments, $context->userId);
	}
	/** @param array<string,mixed> $arguments Validated intent. @param ToolContext $context Caller. @return array<string,mixed> Read-only plan. */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->plans->plan($arguments, $context->userId);
	}
}
