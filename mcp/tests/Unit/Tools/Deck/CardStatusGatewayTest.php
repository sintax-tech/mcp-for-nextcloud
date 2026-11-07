<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Mcp\Modules\Deck\Integration\{CardStatusGateway, DeckServiceContext};
use OCA\Deck\Service\{BoardService, CardService, PermissionService};
use OCA\Deck\Db\CardMapper;
use OCP\{IConfig, ISession};
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/** Native status calls cannot turn into whole-card updates, mapper writes or a privileged actor. */
final class CardStatusGatewayTest extends TestCase {
	/** Each direction uses the official native method once, with the exact ID. */
	public function testFourDirectionsUseTheNativeServices(): void {
		foreach ([['setDone', true, 'done'], ['setDone', false, 'undone'], ['setArchived', true, 'archive'], ['setArchived', false, 'unarchive']] as [$port, $value, $method]) {
			$cards = $this->createMock(StatusNativeCards::class);
			foreach (['done', 'undone', 'archive', 'unarchive'] as $native) {
				$cards->expects($native === $method ? self::once() : self::never())->method($native)
					->willReturn(new \OCA\Deck\Db\Card(['id' => 7]));
			}
			$cards->expects(self::never())->method('update');
			$boards = $this->createMock(BoardService::class); $boards->expects(self::once())->method('setUserId')->with('alice');
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturnMap([[CardService::class, $cards], [BoardService::class, $boards]]);
			$gateway = new CardStatusGateway(new DeckServiceContext($container), $this->createMock(IConfig::class));
			$gateway->$port('alice', 7, $value);
		}
	}

	/** Wrong session is refused before any native service resolution. */
	public function testWrongSessionCannotResolveNativeServices(): void {
		$session = $this->createMock(ISession::class); $session->method('get')->with('user_id')->willReturn('other');
		$container = $this->createMock(ContainerInterface::class); $container->expects(self::never())->method('get');
		$gateway = new CardStatusGateway(new DeckServiceContext($container, $session), $this->createMock(IConfig::class));
		$this->expectException(\OCA\Mcp\Modules\Deck\Failures\DeckSessionException::class);
		$gateway->setDone('alice', 7, true);
	}

	/** A denied read cannot expose card, board or destination metadata. */
	public function testPermissionCheckPrecedesNativeMetadata(): void {
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('checkPermission')->willThrowException(new \OCA\Deck\NoPermissionException());
		$cards = $this->createMock(CardMapper::class); $cards->expects(self::never())->method('find');
		$boards = $this->createMock(BoardService::class); $boards->expects(self::never())->method('find');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[BoardService::class, $boards], [PermissionService::class, $permissions], [CardMapper::class, $cards]]);
		$gateway = new CardStatusGateway(new DeckServiceContext($container), $this->createMock(IConfig::class));
		$this->expectException(\OCA\Deck\NoPermissionException::class);
		$gateway->state('alice', 7);
	}
}
