<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck\Handler;

use OCA\Deck\Db\Board;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use Psr\Log\LoggerInterface;

/**
 * Backs `deck_list_boards`.
 *
 * Boards already come filtered to what the user may see, so this handler only caps the answer and
 * reports when it had to drop boards.
 */
final class ListBoardsHandler extends AbstractHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'deck_list_boards';

	/** Hard ceiling on the answer size; the Deck can hold more boards than this. */
	public const MAX_BOARDS = 200;

	/**
	 * @param DeckGatewayInterface $gateway Deck access, the only door to the Deck app.
	 * @param LoggerInterface $logger Receives tool name and exception class on failure.
	 * @param CardFormatter $formatter Converts Deck boards into the tool payload.
	 */
	public function __construct(
		DeckGatewayInterface $gateway,
		LoggerInterface $logger,
		private CardFormatter $formatter,
	) {
		parent::__construct($gateway, $logger);
	}

	/**
	 * {@inheritDoc}
	 *
	 * The payload is `{boards: list<{id, title, color, archived, owner, lastModified}>, truncated: bool}`,
	 * with at most {@see self::MAX_BOARDS} boards.
	 *
	 * @param array<string, mixed> $arguments Unused; the tool takes no arguments.
	 * @param string $userId UID of the authenticated caller.
	 * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($userId): array {
			$boards = $this->gateway->listBoards($userId);
			$truncated = count($boards) > self::MAX_BOARDS;
			$boards = array_slice($boards, 0, self::MAX_BOARDS);

			return [
				'boards' => array_map(fn (Board $board) => $this->formatter->board($board), $boards),
				'truncated' => $truncated,
			];
		});
	}

	/**
	 * {@inheritDoc}
	 *
	 */
	protected function toolName(): string {
		return self::TOOL;
	}
}
