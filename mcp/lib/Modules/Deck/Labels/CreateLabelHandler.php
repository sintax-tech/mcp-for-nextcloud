<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Labels;

use OCA\Mcp\Modules\Deck\Failures\DeckRefusalException;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Deck\Presentation\DeckLabelMessages;
use Psr\Log\LoggerInterface;

/**
 * Backs `deck_create_label`: one new label on a board the caller manages, assigned to no card.
 *
 * The catalog is read again before the write, so a title taken in the meantime is refused, not duplicated. Deck inserts
 * the label before anything else can fail; a creation that throws is answered as saved when the catalog then holds a
 * new label with that title.
 */
final class CreateLabelHandler extends \OCA\Mcp\Modules\Deck\AbstractHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'deck_create_label';

	/**
	 * @param DeckGatewayInterface $gateway Deck access, the only door to the Deck app.
	 * @param LoggerInterface $logger Receives tool name and exception class on failure.
	 */
	public function __construct(DeckGatewayInterface $gateway, LoggerInterface $logger) {
		parent::__construct($gateway, $logger);
	}

	/**
	 * The validated label and its board, read for both the plan and the write.
	 *
	 * @param DeckGatewayInterface $gateway Deck access.
	 * @param array<string, mixed> $arguments Validated arguments: boardId, title and optional color.
	 * @param string $userId Authenticated caller.
	 * @return array{state: array<string, mixed>, label: array{title: string, color: string, colorGiven: bool}}
	 * @throws \Throwable When the input is invalid, the title is taken or the board cannot be managed.
	 */
	public static function prepare(DeckGatewayInterface $gateway, array $arguments, string $userId): array {
		$title = LabelInput::title((string)$arguments['title']);
		$color = LabelInput::color($arguments['color'] ?? null);
		$state = $gateway->boardLabels()->boardLabelState($userId, (int)$arguments['boardId']);
		if (LabelInput::matches($state['labels'], $title) !== []) {
			throw new DeckRefusalException(DeckLabelMessages::titleTaken($title));
		}
		return ['state' => $state, 'label' => ['title' => $title, 'color' => $color ?? LabelInput::defaultColor($title), 'colorGiven' => $color !== null]];
	}

	/**
	 * @param array<string, mixed> $arguments Validated tool arguments.
	 * @param string $userId Authenticated caller, who must manage the board.
	 * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			['state' => $state, 'label' => $label] = self::prepare($this->gateway, $arguments, $userId);
			$shared = $this->confirmShared($arguments, $userId, $state['board']);
			if ($shared !== null) {
				return $shared;
			}
			$boardId = $state['boardId'];
			$before = array_column($state['labels'], 'id');
			$warnings = [];
			$created = $this->afterWrite(
				fn (): array => $this->gateway->boardLabels()->createLabel($userId, $boardId, $label['title'], $label['color']),
				fn (): ?array => self::found($this->gateway->boardLabels()->boardLabelState($userId, $boardId)['labels'], $label['title'], $before),
				$warnings,
			);
			return ['label' => $created, 'board' => $state['board']['name']] + ($warnings === [] ? [] : ['warnings' => $warnings]);
		});
	}

	/**
	 * @param list<array{id: int, title: string}> $labels Catalog read after a failure.
	 * @param string $title Title just written.
	 * @param list<int> $before Label IDs before the write.
	 * @return array<string, mixed>|null The new label with that exact title, or null when none appeared.
	 */
	public static function found(array $labels, string $title, array $before): ?array {
		foreach ($labels as $label) {
			if ($label['title'] === $title && !in_array($label['id'], $before, true)) {
				return $label;
			}
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 * @return string The catalog of the board shows a new label.
	 */
	protected function readWith(): string {
		return 'deck_list_labels';
	}

	/**
	 * {@inheritDoc}
	 * @return string unchanged result of this operation
	 */
	protected function toolName(): string {
		return self::TOOL;
	}
}
