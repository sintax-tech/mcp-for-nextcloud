<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Policy\Support;

use OCA\Mcp\Access\Policy\PolicyWriter;
use OCA\Mcp\Db\PolicyEventsMapper;

final class InstrumentedWriter {
	public static function withEvents(PolicyWriter $official, PolicyEventsMapper $events): PolicyWriter {
		$arguments = [];
		foreach ((new \ReflectionMethod(PolicyWriter::class, '__construct'))->getParameters() as $parameter) {
			$name = $parameter->getName();
			$arguments[] = $name === 'events' ? $events : (new \ReflectionProperty(PolicyWriter::class, $name))->getValue($official);
		}
		return new PolicyWriter(...$arguments);
	}
}
