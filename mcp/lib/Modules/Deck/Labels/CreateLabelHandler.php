<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 Roman Matkivskyy <roman91@live.it>
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
 * the label before anything else can fail; a creation that throws is answered only as probable when the catalog then holds a
 * new label with that title; the name alone cannot establish identity.
 */
final class CreateLabelHandler extends \OCA\Mcp\Modules\Deck\AbstractHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'deck_create_label';

	/**
	 * @param DeckGatewayInterface $gateway Deck access, the only door to the Deck app.
	 * @param LoggerInterface $logger Receives tool name and exception class on failure.
	 * @param \Closure():bool|null $granted Registered policy reader, checked again at the native effect.
	 * @param \Closure(array,string):array|null $approvedPlan Fresh read-only plan for the registered tool.
	 */
	public function __construct(DeckGatewayInterface $gateway, LoggerInterface $logger, private ?\Closure $approvedPlan = null, private ?\Closure $granted = null) {
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
			if ($this->approvedPlan !== null) {
				LabelCreationPlan::require($arguments, ($this->approvedPlan)($arguments, $userId));
			}
			['state' => $state, 'label' => $label] = self::prepare($this->gateway, $arguments, $userId);
			$shared = $this->confirmShared($arguments, $userId, $state['board']);
			if ($shared !== null) {
				return $shared;
			}
			$boardId = $state['boardId'];
			$before = array_column($state['labels'], 'id');
			$warnings = [];
			$probable = false;
			$created = $this->afterApproximateWrite(
				function () use ($userId, $boardId, $label): array {
					LabelGrantGate::require($this->granted);
					return $this->gateway->boardLabels()->createLabel($userId, $boardId, $label['title'], $label['color']);
				},
				fn (): ?array => self::found($this->gateway->boardLabels()->boardLabelState($userId, $boardId)['labels'], $label['title'], $before),
				$warnings,
				$probable,
			);
			return ['confirmed' => $probable ? 'probable' : true, 'label' => $created, 'board' => $state['board']['name']] + ($warnings === [] ? [] : ['warnings' => $warnings]);
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
