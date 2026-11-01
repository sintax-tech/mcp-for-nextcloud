<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog;

use Psr\Container\ContainerInterface;

/** A container that builds any class with `new` and records what it was asked for. */
final class ProbeContainer implements ContainerInterface {
	/** @var list<string> ids asked, in order */
	public array $asked = [];

	/**
	 * @param string $id class to build
	 * @return object a new instance
	 */
	public function get(string $id): mixed {
		$this->asked[] = $id;
		return new $id();
	}

	/**
	 * @param string $id class name
	 * @return bool whether the class exists
	 */
	public function has(string $id): bool {
		return class_exists($id);
	}
}
