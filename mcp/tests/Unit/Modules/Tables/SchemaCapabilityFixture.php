<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Integration\TablesWriteCapabilities;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use Psr\Log\LoggerInterface;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;

/** Supplies the explicit positive Tables capability for discovery golden fixtures. */
final class SchemaCapabilityFixture {
	/**
	 * @param IAppManager&MockObject $apps Native application mock owned by the caller.
	 * @return TablesWriteCapabilities Positive source-pin capability, with no native app construction.
	 */
	public static function of(IAppManager&MockObject $apps): TablesWriteCapabilities {
		$apps->method('getAppVersion')->willReturn('2.3.2');
		$apps->method('isEnabledForAnyone')->willReturn(true);
		return new TablesWriteCapabilities($apps, 35, static fn (): bool => true);
	}
	/**
	 * @param IAppManager&MockObject $apps Positive native-app fixture.
	 * @param LoggerInterface $logger Request warning sink.
	 * @return CapabilityRegistry Registered positive Tables capability for the shared envelope contract.
	 */
	public static function registry(IAppManager&MockObject $apps, LoggerInterface $logger): CapabilityRegistry {
		return new CapabilityRegistry([self::of($apps)], $logger);
	}

}
