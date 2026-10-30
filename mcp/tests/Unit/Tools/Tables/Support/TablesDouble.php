<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Support;

use LogicException;

/**
 * Strict stand-in for an object of the Tables app: it answers only the methods it was given, records every call with
 * its arguments into a shared journal and fails on any other method. A write the module might reach for (a tutorial
 * table, a share, a favorite) is therefore an error in the test, never a silent no-op.
 */
final class TablesDouble implements \JsonSerializable {
	/**
	 * Receives the methods it answers and the journal it writes to.
	 *
	 * @param string $label name of the double in the journal, such as "TableService"
	 * @param array<string, callable> $methods answer of each allowed method, by name
	 * @param \ArrayObject<int, array{0:string, 1:string, 2:list<mixed>}> $journal shared list of [label, method, arguments]
	 * @return void
	 */
	public function __construct(
		private string $label,
		private array $methods,
		private \ArrayObject $journal,
	) {
	}

	/**
	 * Records the call and answers it, or fails for a method the double was not given.
	 *
	 * @param string $name method called
	 * @param list<mixed> $arguments arguments of the call
	 * @return mixed the answer of the method
	 * @throws LogicException for a method the module must not call
	 */
	public function __call(string $name, array $arguments): mixed {
		$this->journal[] = [$this->label, $name, $arguments];
		if (!isset($this->methods[$name])) {
			throw new LogicException($this->label . '::' . $name . '() must not be called by the Tables tools');
		}
		return ($this->methods[$name])(...$arguments);
	}

	/**
	 * Serialization goes through the given jsonSerialize answer, like any other method.
	 *
	 * @return mixed the answer of jsonSerialize
	 * @throws LogicException when the double was not given jsonSerialize
	 */
	public function jsonSerialize(): mixed {
		return $this->__call('jsonSerialize', []);
	}
}
