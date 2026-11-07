<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Deck\DeckModule;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/deck_stubs.php';

final class DeckExecutionContextTest extends TestCase {
	use DeckTestHelpers;

	private function module(): DeckModule {
		$apps = $this->createMock(\OCP\App\IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getUserValue')->willReturnCallback(static fn (string $uid): string => $uid === 'alice' ? 'America/Sao_Paulo' : 'Asia/Tokyo');
		$module = DeckTestModule::build($this->createMock(\Psr\Container\ContainerInterface::class), $apps,
			$this->createMock(\Psr\Log\LoggerInterface::class), $this->createMock(\OCP\IURLGenerator::class),
			$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class), config: $config);
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('findCard')->willReturn($this->card(['id' => 7, 'duedate' => new \DateTime('2026-10-01T01:00:00Z')]));
		$gateway->method('cardOwnership')->willReturnCallback(fn (string $uid): array => $this->ownershipOf($uid));
		$gateway->expects(self::never())->method('updateCard');
		DeckTestModule::setGateway($module, $gateway);
		return $module;
	}

	public function testEveryInvocationUsesItsCallerTimezoneAndKeepsAbsentNullAndInstantDistinct(): void {
		$module = $this->module();
		foreach ([['alice', '2026-09-30', '2026-09-30T22:00:00-03:00', 'America/Sao_Paulo'],
			['bob', '2026-10-01', '2026-10-01T10:00:00+09:00', 'Asia/Tokyo'],
			['alice', '2026-09-30', '2026-09-30T22:00:00-03:00', 'America/Sao_Paulo']] as [$uid, $day, $instant, $zone]) {
			$read = $this->payload($module->call('deck_read_card', ['cardId' => 7], $uid));
			self::assertSame($day, $read['duedate']);
			self::assertSame($instant, $read['dueAt']);
			$absent = $module->preview('deck_edit_card', ['cardId' => 7, 'title' => 'Edited'], $uid);
			self::assertSame($zone, $absent['timezone']);
			self::assertSame($instant, $absent['card']['before']['duedate']);
			self::assertSame($instant, $absent['card']['after']['duedate']);
			$null = $module->preview('deck_edit_card', ['cardId' => 7, 'duedate' => null], $uid);
			self::assertNull($null['card']['after']['duedate']);
			self::assertSame(['duedate'], $null['changed']);
			$same = $module->preview('deck_edit_card', ['cardId' => 7, 'duedate' => '2026-10-01T01:00:00Z'], $uid);
			self::assertSame([], $same['changed']);
			self::assertSame($instant, $same['card']['after']['duedate']);
		}
	}
}
