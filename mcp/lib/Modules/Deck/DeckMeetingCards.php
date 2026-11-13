<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Modules\Deck\Reading\ListCardFilesHandler;
use OCA\Mcp\Modules\Deck\Reading\ReadCardHandler;
use OCA\Mcp\Modules\Deck\Tools\ListCardFiles;
use OCA\Mcp\Modules\Deck\Tools\ReadCard;
use OCA\Mcp\Platform\Meetings\MeetingDeckRead;
use OCA\Mcp\Platform\Meetings\MeetingMessages;

/**
 * The Deck reads of a meeting dossier, through deck_read_card and deck_list_card_files.
 *
 * Each call repeats the registry's own check for that tool (grant, Guests and the Deck app), then its published
 * schema, then the handler the Deck tools already use. No other tool name is dispatched.
 */
final class DeckMeetingCards implements MeetingDeckRead {
	/**
	 * @param ToolAccess $access the registry's own decision on grant, Guests and app
	 * @param DeckReadDispatch $deck the existing Deck dispatch
	 */
	public function __construct(
		private ToolAccess $access,
		private DeckReadDispatch $deck,
	) {
	}

	/** {@inheritDoc} */
	public function card(string $userId, array $arguments): array {
		return $this->read(ReadCard::definition(), ReadCardHandler::TOOL, $userId, $arguments);
	}

	/** {@inheritDoc} */
	public function links(string $userId, array $arguments): array {
		return $this->read(ListCardFiles::definition(), ListCardFilesHandler::TOOL, $userId, $arguments);
	}

	/**
	 * @param \OCA\Mcp\Mcp\Tools\ToolDefinition $definition the published read tool
	 * @param string $name the tool name the Deck dispatch already knows
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments the arguments of that tool
	 * @return array<string, mixed> the result of that read
	 * @throws ToolFailure when the actor may not call the tool
	 * @throws \InvalidArgumentException on arguments the tool refuses
	 * @throws PolicyUnavailable when the grant cannot be read now
	 */
	private function read(\OCA\Mcp\Mcp\Tools\ToolDefinition $definition, string $name, string $userId, array $arguments): array {
		if (!$this->access->allowed($definition->toArray(), $userId)) {
			throw new ToolFailure(MeetingMessages::notAllowed($definition->name));
		}
		return $this->deck->execute($name, ArgumentValidator::validate($definition->inputSchema, $arguments), $userId);
	}
}
