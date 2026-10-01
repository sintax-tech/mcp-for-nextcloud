<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use InvalidArgumentException;
use OCA\Mcp\Tools\Deck\CardInput;
use OCA\Mcp\Tools\Deck\DeckMessages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/**
 * Covers the semantic checks the JSON Schema of the tools cannot express.
 */
final class CardInputTest extends TestCase {
	public function testTitleIsTrimmed(): void {
		self::assertSame('Revisar contrato', CardInput::requireTitle('  Revisar contrato  '));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function blankTitleProvider(): array {
		return [
			'empty' => [''],
			'blank' => ['   '],
			'tab and newline' => ["\t\n"],
		];
	}

	#[DataProvider('blankTitleProvider')]
	public function testBlankTitleIsRejected(string $title): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::errorTitleRequired());

		CardInput::requireTitle($title);
	}

	public function testDescriptionIsAcceptedUpToTheLimit(): void {
		$description = str_repeat('a', CardInput::MAX_DESCRIPTION_LENGTH);

		self::assertSame($description, CardInput::requireDescription($description));
	}

	public function testDescriptionOverTheLimitIsRejectedInsteadOfTruncated(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::errorDescriptionTooLong());

		CardInput::requireDescription(str_repeat('a', CardInput::MAX_DESCRIPTION_LENGTH + 1));
	}

	public function testValidDateIsAccepted(): void {
		self::assertSame('2026-02-28', CardInput::duedate('2026-02-28'));
	}

	public function testNullClearsTheDate(): void {
		self::assertNull(CardInput::duedate(null));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function invalidDateProvider(): array {
		return [
			'not a real day' => ['2026-02-31'],
			'wrong order' => ['31/02/2026'],
			'no padding' => ['2026-2-1'],
			'with time' => ['2026-02-28 10:00'],
			'garbage' => ['amanhã'],
			'empty' => [''],
		];
	}

	#[DataProvider('invalidDateProvider')]
	public function testInvalidDateIsRejected(string $value): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::errorInvalidDuedate());

		CardInput::duedate($value);
	}
}
