<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use LogicException;

/**
 * Strict stand-in for an object of the Mail app: it answers only the methods and the magic properties it was given,
 * records every call and read into a shared journal and fails on any other method or property. A sync, a flag change or any write the module might reach for is
 * therefore an error in the test, never a silent no-op.
 */
final class RecordingDouble {
	/**
	 * Receives the methods it answers and the journal it writes to.
	 *
	 * @param string $label name of the double in the journal, such as "AccountService"
	 * @param array<string, callable> $methods answer of each allowed method, by name
	 * @param \ArrayObject<int, string> $journal shared list of "Label::method" calls, in order
	 * @param array<string, callable> $properties answer of each allowed magic property, by name
	 * @return void
	 */
	public function __construct(
		private string $label,
		private array $methods,
		private \ArrayObject $journal,
		private array $properties = [],
	) {
	}

	/**
	 * Records the read and answers it, or fails for a property the double was not given.
	 *
	 * @param string $name property read
	 * @return mixed the answer of the property
	 * @throws LogicException for a property the module must not read
	 */
	public function __get(string $name): mixed {
		$this->journal[] = $this->label . '->' . $name;
		if (!isset($this->properties[$name])) {
			throw new LogicException($this->label . '->' . $name . ' must not be read by the Mail tools');
		}
		return ($this->properties[$name])();
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
		$this->journal[] = $this->label . '::' . $name;
		if (!isset($this->methods[$name])) {
			throw new LogicException($this->label . '::' . $name . '() must not be called by the Mail tools');
		}
		return ($this->methods[$name])(...$arguments);
	}
}
