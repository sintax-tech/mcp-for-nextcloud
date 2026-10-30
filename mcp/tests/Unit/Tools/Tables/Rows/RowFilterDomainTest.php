<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Rows;

use OCA\Mcp\Tools\Tables\Rows\RowFilterDomain;
use OCA\Mcp\Tools\Tables\Rows\RowsMessages;
use OCA\Mcp\Tools\Tables\Rows\RowsRefusal;
use PHPUnit\Framework\TestCase;

/** The domain of user conditions: type × operator × value × release, placeholders included, anything else refused. */
final class RowFilterDomainTest extends TestCase {
	/** A single selection with options 1, 2 and 10. */
	private const SINGLE = ['id' => 13, 'type' => 'selection', 'subtype' => '', 'selectionOptions' => [['id' => 1, 'label' => 'Open'], ['id' => 2, 'label' => 'Closed'], ['id' => 10, 'label' => 'Ten']]];

	/**
	 * Accepted conditions keep the column and operator and give the value as the string the engine expects.
	 *
	 * @return void
	 */
	public function testAcceptedConditions(): void {
		$d = new RowFilterDomain('2.3.2');
		$this->assertSame(['columnId' => 11, 'operator' => 'contains', 'value' => 'abc'], $d->condition(11, ['type' => 'text', 'subtype' => 'line'], 'contains', 'abc'));
		$this->assertSame('10', $d->condition(12, ['type' => 'number'], 'is-greater-than', 10)['value']);
		$this->assertSame('2.5', $d->condition(12, ['type' => 'number'], 'is-lower-than', '2.5')['value']);
		$this->assertSame('10', $d->condition(13, self::SINGLE, 'is-equal', 10)['value']);
		$this->assertSame('@me', $d->condition(16, ['type' => 'usergroup'], 'contains', '@me')['value']);
		$this->assertSame('@checked', $d->condition(17, ['type' => 'selection', 'subtype' => 'check'], 'is-equal', '@checked')['value']);
		$this->assertSame('@datetime-days-ago:7', $d->condition(18, ['type' => 'datetime', 'subtype' => 'date'], 'is-greater-than', '@datetime-days-ago:7')['value']);
		$this->assertSame('', $d->condition(11, ['type' => 'text'], 'is-empty', null)['value']);
		$this->assertSame('7', $d->condition(-1, null, 'is-greater-than', 7)['value']);
		$this->assertSame('2026-10-01', $d->condition(-4, null, 'is-lower-than', '2026-10-01')['value']);
	}

	/**
	 * Placeholders the type does not take, an unknown placeholder, an option the column does not have, an operator of
	 * another type and a value for is-empty are refused.
	 *
	 * @return void
	 */
	public function testRefusedConditions(): void {
		$d = new RowFilterDomain('2.3.2');
		foreach ([
			[11, ['type' => 'text'], 'contains', '@my-name'],
			[11, ['type' => 'text'], 'contains', '@anything'],
			[11, ['type' => 'text'], 'is-greater-than', 'a'],
			[11, ['type' => 'text'], 'is-empty', 'x'],
			[12, ['type' => 'number'], 'contains', '1'],
			[12, ['type' => 'number'], 'is-equal', 'ten'],
			[13, self::SINGLE, 'is-equal', 3],
			[13, self::SINGLE, 'contains', 1],
			[16, ['type' => 'usergroup'], 'contains', '@my-name'],
			[17, ['type' => 'selection', 'subtype' => 'check'], 'is-equal', 'true'],
			[18, ['type' => 'datetime'], 'is-equal', '@datetime-days-ago:x'],
			[19, ['type' => 'unknown'], 'is-equal', 'x'],
			[-2, null, 'contains', 'alice'],
			[-6, null, 'is-equal', '1'],
		] as [$columnId, $column, $operator, $value]) {
			try {
				$d->condition($columnId, $column, $operator, $value);
				$this->fail("accepted $operator on " . json_encode($column));
			} catch (RowsRefusal) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/**
	 * Every placeholder the guide names is accepted on the column type it names it for, from the release it names, and
	 * the guide names no other: the guide never recommends a value the domain refuses.
	 *
	 * @return void
	 */
	public function testTheGuideNamesOnlyAcceptedPlaceholders(): void {
		preg_match_all('/@[a-z-]+(:[A-Z-]+)?/', RowsMessages::GUIDE_NOTE, $named);
		$byPlaceholder = ['@me' => [16, ['type' => 'usergroup'], 'contains', '@me'],
			'@checked' => [17, ['type' => 'selection', 'subtype' => 'check'], 'is-equal', '@checked'],
			'@unchecked' => [17, ['type' => 'selection', 'subtype' => 'check'], 'is-equal', '@unchecked'],
			'@datetime-exact-date:YYYY-MM-DD' => [18, ['type' => 'datetime'], 'is-equal', '@datetime-exact-date:2026-10-26'],
			'@datetime-days-ahead:N' => [18, ['type' => 'datetime'], 'is-lower-than', '@datetime-days-ahead:7'],
			'@datetime-days-ago:N' => [18, ['type' => 'datetime'], 'is-greater-than', '@datetime-days-ago:7']];
		$this->assertNotContains('@my-name', $named[0]);
		$this->assertCount(12, $named[0]);
		foreach ($named[0] as $placeholder) {
			[$columnId, $column, $operator, $value] = $byPlaceholder[$placeholder] ?? [18, ['type' => 'datetime'], 'is-equal', $placeholder];
			$release = str_contains($placeholder, ':') ? RowFilterDomain::DATETIME_ARGUMENTS_SINCE : '1.0.0';
			$this->assertSame($value, (new RowFilterDomain($release))->condition($columnId, $column, $operator, $value)['value'], $placeholder);
		}
	}

	/**
	 * is-not-empty from 2.3.2, relation columns from 2.2.2, datetime placeholders with an argument from 1.0.10.
	 *
	 * @return void
	 */
	public function testReleaseGates(): void {
		$this->assertContains('is-not-empty', (new RowFilterDomain('2.3.2'))->operators(11, ['type' => 'text']));
		$this->assertNotContains('is-not-empty', (new RowFilterDomain('2.2.2'))->operators(11, ['type' => 'text']));
		$this->assertSame([], (new RowFilterDomain('2.0.0'))->operators(20, ['type' => 'relation']));
		$this->assertSame(['is-equal', 'is-not-equal', 'is-empty'], (new RowFilterDomain('2.2.2'))->operators(20, ['type' => 'relation']));
		$this->assertSame('@datetime-exact-date:2026-10-26', (new RowFilterDomain('1.0.10'))->condition(18, ['type' => 'datetime'], 'is-equal', '@datetime-exact-date:2026-10-26')['value']);
		$this->expectException(RowsRefusal::class);
		(new RowFilterDomain('1.0.0'))->condition(18, ['type' => 'datetime'], 'is-equal', '@datetime-exact-date:2026-10-26');
	}
}
