<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Deck\BoardBlueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/**
 * Everything `deck_create_board` refuses is refused here, before the first write, so the plan and the
 * execution read the structure through the same code.
 */
final class BoardBlueprintTest extends TestCase {
	public function testATitleAloneIsABoardWithoutListsInTheDefaultColor(): void {
		$blueprint = BoardBlueprint::parse(['title' => '  Projeto  '], 'alice');

		self::assertSame('Projeto', $blueprint['title']);
		self::assertSame(BoardBlueprint::DEFAULT_COLOR, $blueprint['color']);
		self::assertFalse($blueprint['colorGiven']);
		self::assertSame([], $blueprint['stacks']);
		self::assertSame(0, $blueprint['cardCount']);
	}

	public function testTheStructureIsKeptInTheOrderGivenAndNormalised(): void {
		$blueprint = BoardBlueprint::parse([
			'title' => 'Projeto',
			'color' => '#AbCdEf',
			'stacks' => [
				['title' => ' A fazer ', 'cards' => [
					['title' => ' Primeiro ', 'description' => 'Texto', 'duedate' => '2026-10-05', 'assignees' => ['alice', 'alice']],
					['title' => 'Segundo'],
				]],
				['title' => 'Feito'],
			],
		], 'alice');

		self::assertSame('abcdef', $blueprint['color']);
		self::assertTrue($blueprint['colorGiven']);
		self::assertSame(2, $blueprint['cardCount']);
		self::assertSame('A fazer', $blueprint['stacks'][0]['title']);
		self::assertSame([
			['title' => 'Primeiro', 'description' => 'Texto', 'duedate' => '2026-10-05', 'assignees' => ['alice']],
			['title' => 'Segundo', 'description' => '', 'duedate' => null, 'assignees' => []],
		], $blueprint['stacks'][0]['cards']);
		self::assertSame([], $blueprint['stacks'][1]['cards']);
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}> Arguments and the field the refusal names.
	 */
	public static function refusedProvider(): array {
		$card = static fn (array $fields): array => ['title' => 'Projeto', 'stacks' => [['title' => 'L', 'cards' => [['title' => 'C'] + $fields]]]];

		return [
			'blank board title' => [['title' => "  \t"], 'title'],
			'board title over 100' => [['title' => str_repeat('a', 101)], 'title'],
			'color with a non hexadecimal digit' => [['title' => 'P', 'color' => '12345g'], 'color'],
			'color too short' => [['title' => 'P', 'color' => 'fff'], 'color'],
			'color with a css function' => [['title' => 'P', 'color' => 'rgb(0,0,0)'], 'color'],
			'blank list title' => [['title' => 'P', 'stacks' => [['title' => ' ']]], 'stacks[0].title'],
			'list title over 100' => [['title' => 'P', 'stacks' => [['title' => 'ok'], ['title' => str_repeat('a', 101)]]], 'stacks[1].title'],
			'blank card title' => [['title' => 'P', 'stacks' => [['title' => 'L', 'cards' => [['title' => 'ok'], ['title' => '  ']]]]], 'stacks[0].cards[1].title'],
			'description too long' => [$card(['description' => str_repeat('a', 100001)]), 'stacks[0].cards[0].description'],
			'impossible date' => [$card(['duedate' => '2026-02-31']), 'stacks[0].cards[0].duedate'],
			'malformed assignees' => [$card(['assignees' => [7]]), 'stacks[0].cards[0].assignees'],
			'somebody else cannot be assigned on a new board' => [$card(['assignees' => ['pedro']]), 'stacks[0].cards[0].assignees'],
			'yourself and somebody else' => [$card(['assignees' => ['alice', 'pedro']]), 'stacks[0].cards[0].assignees'],
		];
	}

	/**
	 * @param array<string, mixed> $arguments Arguments the registry already passed against the schema.
	 * @param string $field Field the refusal must name.
	 */
	#[DataProvider('refusedProvider')]
	public function testSemanticMistakesAreRefusedNamingTheField(array $arguments, string $field): void {
		try {
			BoardBlueprint::parse($arguments, 'alice');
			self::fail('accepted ' . $field);
		} catch (ArgumentValidationException $e) {
			self::assertSame($field, $e->details()['field']);
		}
	}

	public function testMoreThanTwentyListsAreRefused(): void {
		$stacks = array_map(static fn (int $i): array => ['title' => 'L' . $i], range(1, 21));

		$this->expectException(ArgumentValidationException::class);
		BoardBlueprint::parse(['title' => 'P', 'stacks' => $stacks], 'alice');
	}

	public function testTwentyListsAreAccepted(): void {
		$stacks = array_map(static fn (int $i): array => ['title' => 'L' . $i], range(1, 20));

		self::assertCount(20, BoardBlueprint::parse(['title' => 'P', 'stacks' => $stacks], 'alice')['stacks']);
	}

	/** The ceiling is on the whole call, not per list, so ten lists of eleven cards are refused. */
	public function testMoreThanOneHundredCardsInTotalAreRefused(): void {
		$cards = array_map(static fn (int $i): array => ['title' => 'C' . $i], range(1, 11));
		$stacks = array_map(static fn (int $i): array => ['title' => 'L' . $i, 'cards' => $cards], range(1, 10));

		try {
			BoardBlueprint::parse(['title' => 'P', 'stacks' => $stacks], 'alice');
			self::fail('accepted 110 cards');
		} catch (ArgumentValidationException $e) {
			self::assertSame('stacks', $e->details()['field']);
		}
	}

	public function testOneHundredCardsInTotalAreAccepted(): void {
		$cards = array_map(static fn (int $i): array => ['title' => 'C' . $i], range(1, 10));
		$stacks = array_map(static fn (int $i): array => ['title' => 'L' . $i, 'cards' => $cards], range(1, 10));

		self::assertSame(100, BoardBlueprint::parse(['title' => 'P', 'stacks' => $stacks], 'alice')['cardCount']);
	}

	/** The refusal teaches the way out and never echoes the account the model asked for. */
	public function testTheRefusedAssigneeIsNotEchoed(): void {
		try {
			BoardBlueprint::parse(['title' => 'P', 'stacks' => [['title' => 'L', 'cards' => [['title' => 'C', 'assignees' => ['segredo-pedro']]]]]], 'alice');
			self::fail('accepted another account');
		} catch (ArgumentValidationException $e) {
			self::assertStringNotContainsString('segredo-pedro', $e->clientMessage());
			self::assertStringContainsString('share the board', $e->clientMessage());
		}
	}
}
