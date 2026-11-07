<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Deck\Db\Card;
use OCA\Mcp\Modules\Deck\Editing\CardLabelChanges;
use OCA\Mcp\Modules\Deck\Failures\DeckConflictException;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__ . '/../../Tools/Deck/Stubs/deck_stubs.php';

/**
 * The native Deck boundary of the effect contract: one board of alice with its cards and labels, held as plain data,
 * behind a double of {@see DeckGatewayInterface} that answers the reads the label and file-link tools make and records
 * every label write. Like the real gateway, a label write refuses with {@see DeckConflictException} before writing when
 * the card no longer matches the snapshot the handler expected.
 */
final class DeckBoardDouble {
	/** The board every card belongs to. */
	public const BOARD = 20;
	/** The list every card sits in. */
	public const STACK = 2;

	/** @var array<int, array{id:int, boardId:int, title:string, color:string}> labels of the board, by id */
	public array $catalog = [
		1 => ['id' => 1, 'boardId' => self::BOARD, 'title' => 'Urgente', 'color' => 'ff0000'],
		2 => ['id' => 2, 'boardId' => self::BOARD, 'title' => 'Revisão', 'color' => '0082c9'],
		3 => ['id' => 3, 'boardId' => self::BOARD, 'title' => 'Cliente', 'color' => '00aa00'],
	];
	/** @var array<int, array{title:string, labels:list<int>}> cards of the board, by id */
	public array $cards = [
		7 => ['title' => 'Proposta comercial', 'labels' => [1]],
		8 => ['title' => 'Contrato', 'labels' => []],
	];
	/** @var list<string> label writes, in order: "<card> +<label>" or "<card> -<label>" */
	public array $writes = [];

	/** @param TestCase $test the running test, owner of the gateway mock */
	public function __construct(private TestCase $test) {
	}

	/** @return DeckGatewayInterface the gateway over this board; every other method of the interface is unused */
	public function gateway(): DeckGatewayInterface {
		$gateway = (new ReflectionMethod($this->test, 'createMock'))->invoke($this->test, DeckGatewayInterface::class);
		$gateway->method('cardLabelState')->willReturnCallback(fn (string $uid, int $cardId, array $requested): array => $this->labelState($cardId, $requested));
		$gateway->method('changeCardLabel')->willReturnCallback(function (string $uid, int $cardId, int $labelId, bool $assign,
			array $requested, array $expected): void {
			if (CardLabelChanges::snapshot($this->labelState($cardId, $requested)) !== $expected) {
				throw new DeckConflictException();
			}
			$labels = array_values(array_diff($this->cards[$cardId]['labels'], [$labelId]));
			if ($assign) {
				$labels[] = $labelId;
			}
			sort($labels);
			$this->cards[$cardId]['labels'] = $labels;
			$this->writes[] = $cardId . ' ' . ($assign ? '+' : '-') . $labelId;
		});
		$gateway->method('cardFileContext')->willReturnCallback(fn (string $uid, int $cardId, bool $edit): array => $this->context($cardId));
		return $gateway;
	}

	/** @return array<int, list<int>> label ids of every card, the observable effect of a label write */
	public function labels(): array {
		return array_map(static fn (array $card): array => $card['labels'], $this->cards);
	}

	/**
	 * @param int $cardId card asked for
	 * @param list<int> $requested label ids of the request
	 * @return array<string, mixed> the editable card state the real gateway returns
	 */
	private function labelState(int $cardId, array $requested): array {
		$labels = $this->cards[$cardId]['labels'];
		sort($labels);
		return $this->context($cardId) + [
			'labels' => array_map(fn (int $id): array => $this->catalog[$id], $labels),
			'requested' => array_map(fn (int $id): array => $this->catalog[$id] ?? throw new \RuntimeException('label of another board'), $requested),
		];
	}

	/**
	 * @param int $cardId card asked for
	 * @return array{card: Card, boardId: int, board: array{owner:string, ownerDisplayName:string, name:string}} its parents
	 */
	private function context(int $cardId): array {
		$card = $this->cards[$cardId] ?? throw new \RuntimeException('no such card');
		return ['card' => new Card(['id' => $cardId, 'stackId' => self::STACK, 'title' => $card['title'], 'description' => '',
			'type' => 'plain', 'owner' => 'alice', 'order' => 0, 'archived' => false, 'deletedAt' => 0,
			'lastModified' => 1_700_000_000]), 'boardId' => self::BOARD,
			'board' => ['owner' => 'alice', 'ownerDisplayName' => 'Alice', 'name' => 'Comercial']];
	}
}
