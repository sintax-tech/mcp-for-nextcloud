<?php
/* SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Logs;

use OCA\Mcp\Modules\Logs\Reading\LogReader;
use OCP\IConfig;
use OCP\Log\ILogFactory;
use OCP\Log\IWriter;
use PHPUnit\Framework\TestCase;

/** Wires a real file reader using the fixture writer's native eligibility threshold. */
final class FileLogFixture {
	/** @param TestCase $test Mock factory owner. @param IWriter $writer Official file-writer interface double.
	 * @param int|null $minimum Optional native threshold.
	 * @return LogReader Production block reader over a disposable file.
	 */
	public static function reader(TestCase $test, IWriter $writer, ?int $minimum = null): LogReader {
		$factory = (new \ReflectionMethod($test, 'createMock'))->invoke($test, ILogFactory::class);
		$factory->method('get')->with('file')->willReturn($writer);
		$config = (new \ReflectionMethod($test, 'createMock'))->invoke($test, IConfig::class);
		$config->method('getSystemValueInt')->willReturn($minimum ?? ($writer instanceof FakeFileLog ? $writer->minLevel : 0));
		return new LogReader($factory, $config);
	}
}
