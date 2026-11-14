<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck;

/** Preserves the Deck tool order; dependency tools carry the existing capability key. */
final class DeckToolCatalog {
	/** @var list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> existing tools in publication order */
	public const TOOLS = [
		\OCA\Mcp\Modules\Deck\Tools\ListBoards::class,
		\OCA\Mcp\Modules\Deck\Tools\ListLabels::class,
		\OCA\Mcp\Modules\Deck\Tools\ListCardFiles::class,
		\OCA\Mcp\Modules\Deck\Tools\UnlinkCardFile::class,
		\OCA\Mcp\Modules\Deck\Tools\LinkCardFile::class,
		\OCA\Mcp\Modules\Deck\Tools\EditCardLabels::class,
		\OCA\Mcp\Modules\Deck\Tools\ListStacks::class,
		\OCA\Mcp\Modules\Deck\Tools\ListCards::class,
		\OCA\Mcp\Modules\Deck\Tools\ReadCard::class,
		\OCA\Mcp\Modules\Deck\Tools\CreateCard::class,
		\OCA\Mcp\Modules\Deck\Tools\EditCard::class,
		\OCA\Mcp\Modules\Deck\Tools\MoveCard::class,
		\OCA\Mcp\Modules\Deck\Tools\DeleteCard::class,
		\OCA\Mcp\Modules\Deck\Tools\FollowupCards::class,
		\OCA\Mcp\Modules\Deck\Tools\CreateBoard::class,
		\OCA\Mcp\Modules\Deck\Tools\CreateStack::class,
		\OCA\Mcp\Modules\Deck\Tools\DeleteStack::class,
		\OCA\Mcp\Modules\Deck\Tools\DeleteBoard::class,
		\OCA\Mcp\Modules\Deck\Tools\ReadCardDependencies::class,
		\OCA\Mcp\Modules\Deck\Tools\AddCardDependency::class,
		\OCA\Mcp\Modules\Deck\Tools\RemoveCardDependency::class,
		\OCA\Mcp\Modules\Deck\Tools\SetCardDone::class,
		\OCA\Mcp\Modules\Deck\Tools\SetCardArchived::class,
		\OCA\Mcp\Modules\Deck\Tools\CreateLabel::class,
		\OCA\Mcp\Modules\Deck\Tools\UpdateLabel::class,
		\OCA\Mcp\Modules\Deck\Tools\DeleteLabel::class,
	];

	/** Constant holder. */
	private function __construct() {
	}
}
