<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck;

use OCA\Mcp\Modules\Deck\Presentation\DeckSchema;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Deck\Tools\ListBoards;
use OCA\Mcp\Modules\Deck\Tools\ListStacks;
use OCA\Mcp\Modules\Deck\Tools\ListCards;
use OCA\Mcp\Modules\Deck\Tools\ReadCard;
use OCA\Mcp\Modules\Deck\Tools\CreateCard;
use OCA\Mcp\Modules\Deck\Tools\EditCard;
use OCA\Mcp\Modules\Deck\Tools\MoveCard;
use OCA\Mcp\Modules\Deck\Tools\DeleteCard;
use OCA\Mcp\Modules\Deck\Tools\FollowupCards;
use OCA\Mcp\Modules\Deck\Tools\CreateBoard;
use OCA\Mcp\Modules\Deck\Tools\CreateStack;
use OCA\Mcp\Modules\Deck\Tools\DeleteStack;
use OCA\Mcp\Modules\Deck\Tools\DeleteBoard;
/** Explicit metadata only: no service lookup or tool construction during discovery. */

final class DeckToolCatalog {
    /** @return list<class-string<Tool>> */
    public static function classes(): array {
        return [
            ListBoards::class,
            ListStacks::class,
            ListCards::class,
            ReadCard::class,
            CreateCard::class,
            EditCard::class,
            MoveCard::class,
            DeleteCard::class,
            FollowupCards::class,
            CreateBoard::class,
            CreateStack::class,
            DeleteStack::class,
            DeleteBoard::class,
        ];
    }

    /**
     * @param list<class-string<Tool>>|null $classes null selects the production catalog
     * @return array<string, array<string, mixed>> definitions keyed by their public name
     */
    public static function definitions(?array $classes = null): array {
        $definitions = [];
        foreach ($classes ?? self::classes() as $class) {
            if (!is_a($class, Tool::class, true)) {
                throw new \LogicException($class . ' must implement Tool');
            }
            $definition = $class::definition();
            if ($definition->operation !== 'read' && !is_a($class, WriteTool::class, true)) {
                throw new \LogicException($class . ' must implement WriteTool');
            }
            if (isset($definitions[$definition->name])) {
                throw new \LogicException('Duplicate tool name: ' . $definition->name);
            }
            $definitions[$definition->name] = DeckSchema::definitionArray($definition);
        }
        return $definitions;
    }
}
