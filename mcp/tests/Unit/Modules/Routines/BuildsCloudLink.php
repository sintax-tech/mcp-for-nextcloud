<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Routines;

use OCA\Mcp\Platform\Meetings\LocalInstanceLink;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/** A LocalInstanceLink whose instance is cloud.example, for the dossier doubles. */
trait BuildsCloudLink {
	/**
	 * @return LocalInstanceLink an instance that accepts cloud.example and relative paths
	 */
	private function link(): LocalInstanceLink {
		if (!$this instanceof TestCase) {
			throw new \LogicException('BuildsCloudLink is for a test case');
		}
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(static function (string $key, mixed $default = ''): mixed {
			return match ($key) {
				'trusted_domains' => ['cloud.example'],
				'overwrite.cli.url' => 'https://cloud.example',
				default => $default,
			};
		});
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturn('https://cloud.example/');
		return new LocalInstanceLink($urls, $config);
	}
}
