<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Rows;

use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesDouble;
use OCA\Mcp\Modules\Tables\Presentation\RowsMessages;
use OCA\Mcp\Modules\Tables\Reading\RowsRefusal;
use OCA\Mcp\Modules\Tables\Reading\SavedViewRules;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Saved rules reach the engine only well formed, identical to what Tables made of them, and on columns of the target's
 * table: a hidden column of the same table is fine, another table's or a missing column refuses, as does a malformed rule.
 */
final class SavedViewRulesTest extends TestCase {
	/** Table of each known column. */
	private const COLUMNS = [11 => 1, 14 => 1, 21 => 2];

	/**
	 * A filter on a hidden column of the same table, with meta columns and stored values as they are.
	 *
	 * @return void
	 */
	public function testAFilterOnAHiddenColumnOfTheTable(): void {
		$raw = '[[{"columnId":14,"operator":"contains","value":"ok"},{"columnId":-1,"operator":"is-greater-than","value":3}],[{"columnId":11,"operator":"is-equal","value":"@my-name"}]]';
		$filter = $this->rules()->filter($raw, static fn (): array => json_decode($raw, true));
		$this->assertSame([
			[['columnId' => 14, 'operator' => 'contains', 'value' => 'ok'], ['columnId' => -1, 'operator' => 'is-greater-than', 'value' => '3']],
			[['columnId' => 11, 'operator' => 'is-equal', 'value' => '@my-name']],
		], $filter->branches());
		$this->assertTrue($this->rules()->filter(null, static fn (): array => [])->isTrue());
		$this->assertTrue($this->rules()->filter('[]', static fn (): array => [])->isTrue());
	}

	/**
	 * Another table's column, a missing column, column 0 or an unknown meta column, a malformed or empty group, invalid
	 * JSON, a difference between the stored JSON and Tables' array (1.0.0 drops conditions in silence), and a normalizer
	 * that throws: all refused, with the fixed message.
	 *
	 * @return void
	 */
	public function testUnsafeFiltersAreRefused(): void {
		$cases = [
			'foreign column' => ['[[{"columnId":21,"operator":"contains","value":"x"}]]', null],
			'missing column' => ['[[{"columnId":99,"operator":"contains","value":"x"}]]', null],
			'column 0' => ['[[{"columnId":0,"operator":"contains","value":"x"}]]', null],
			'unknown meta' => ['[[{"columnId":-9,"operator":"is-equal","value":"x"}]]', null],
			'null column' => ['[[{"columnId":null,"operator":"contains","value":"x"}]]', null],
			'empty group' => ['[[{"columnId":14,"operator":"contains","value":"ok"}],[]]', null],
			'no value' => ['[[{"columnId":14,"operator":"contains"}]]', null],
			'unknown operator' => ['[[{"columnId":14,"operator":"matches","value":"x"}]]', null],
			'not a list' => ['{"a":1}', null],
			'invalid json' => ['[[', null],
			'dropped by Tables' => ['[[{"columnId":14,"operator":"contains","value":"ok"},{"columnId":11,"operator":"is-equal","value":"a"}]]',
				[[['columnId' => 14, 'operator' => 'contains', 'value' => 'ok']]]],
			'Tables has rules the JSON lacks' => [null, [[['columnId' => 14, 'operator' => 'contains', 'value' => 'ok']]]],
		];
		foreach ($cases as $label => [$raw, $normalized]) {
			try {
				$this->rules()->filter($raw, static fn (): array => $normalized ?? (json_decode((string)$raw, true) ?: []));
				$this->fail("accepted: $label");
			} catch (RowsRefusal $e) {
				$this->assertSame(RowsMessages::savedRulesUnusable(), $e->getMessage(), $label);
			}
		}
		$this->expectException(RowsRefusal::class);
		$this->rules()->filter('[[{"columnId":14,"operator":"contains","value":"ok"}]]', static fn (): array => throw new \InvalidArgumentException('Required input fields are missing'));
	}

	/**
	 * A saved sort on columns of the table passes; a foreign column or a bad mode refuses.
	 *
	 * @return void
	 */
	public function testSavedSort(): void {
		$raw = '[{"columnId":14,"mode":"DESC"},{"columnId":-1,"mode":"ASC"}]';
		$this->assertSame([['columnId' => 14, 'mode' => 'DESC'], ['columnId' => -1, 'mode' => 'ASC']], $this->rules()->sort($raw, static fn (): array => json_decode($raw, true)));
		foreach (['[{"columnId":21,"mode":"ASC"}]', '[{"columnId":14,"mode":"UP"}]', '[{"mode":"ASC"}]'] as $bad) {
			try {
				$this->rules()->sort($bad, static fn (): array => json_decode($bad, true));
				$this->fail("accepted $bad");
			} catch (RowsRefusal) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/**
	 * The columns the checked filter and sort name, hidden ones included, are kept serialized in id order, without meta
	 * columns, for the state digest; nothing is kept before a rule is checked.
	 *
	 * @return void
	 */
	public function testTheNamedColumnsAreKeptForTheDigest(): void {
		$rules = $this->rules();
		$this->assertSame([], $rules->referencedColumns());
		$filter = '[[{"columnId":14,"operator":"contains","value":"ok"},{"columnId":-1,"operator":"is-greater-than","value":3}]]';
		$rules->filter($filter, static fn (): array => json_decode($filter, true));
		$rules->sort('[{"columnId":11,"mode":"ASC"}]', static fn (): array => [['columnId' => 11, 'mode' => 'ASC']]);
		$this->assertSame([
			['id' => 11, 'tableId' => 1, 'type' => 'text', 'textDefault' => 'default 11'],
			['id' => 14, 'tableId' => 1, 'type' => 'text', 'textDefault' => 'default 14'],
		], $rules->referencedColumns());
	}

	/**
	 * The rules of table 1.
	 *
	 * @return SavedViewRules the rules
	 */
	private function rules(): SavedViewRules {
		return new SavedViewRules(new TablesDouble('ColumnMapper', ['find' => static function (int $id): TablesDouble {
			if (!isset(self::COLUMNS[$id])) {
				throw new DoesNotExistException('no column');
			}
			return new TablesDouble('Column', ['getTableId' => static fn (): int => self::COLUMNS[$id],
				'jsonSerialize' => static fn (): array => ['id' => $id, 'tableId' => self::COLUMNS[$id], 'type' => 'text', 'textDefault' => "default $id"]], new \ArrayObject());
		}], new \ArrayObject()), 1);
	}
}
