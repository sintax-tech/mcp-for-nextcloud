<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Access;

use PHPUnit\Framework\TestCase;
use OCA\Mcp\Modules\Deck\DeckModule;
use Psr\Container\ContainerInterface;
use OCP\ISession;

final class DeckCallerTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\DeckToolFixtures;
    use \OCA\Mcp\Tests\Support\Deck\DeckNativeFixtures;
    public function testModuleRefusesMismatchedSessionBeforeResolvingDeck(): void {
        $session = $this->createMock(ISession::class);
        $session->method('get')->with('user_id')->willReturn('bob');
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(static function (string $id) use ($session) {
            if ($id === ISession::class) {
                return $session;
            }
            throw new \LogicException('Deck resolution must not happen before identity verification');
        });
        $apps = $this->createMock(\OCP\App\IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $module = $this->deckModuleFixture(
            $this->nativeDeck($container, $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class), session: $session),
            $apps,
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class),
        );
        $result = $module->call('deck_list_boards', [], 'alice');
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorSessionNotBound(), $result['content'][0]['text']);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testMetadataDoesNotLoadTheOptionalDeckApp(): void {
        self::assertFalse(class_exists('OCA\Deck\Service\BoardService', false));
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        $factories = [];
        foreach (\OCA\Mcp\Modules\Deck\DeckToolCatalog::classes() as $tool) {
            $factories[$tool::definition()->name] = static fn() => throw new \LogicException('Metadata resolved a tool');
        }
        $module = new DeckModule($factories, $this->createMock(\OCA\Mcp\Modules\Deck\Access\DeckAvailability::class));
        self::assertCount(13, $module->definitions());
        self::assertNotEmpty($module->guideNotes());
        self::assertNull($module->renderPlan('unknown', []));
        self::assertFalse(class_exists('OCA\Deck\Service\BoardService', false));
    }
}
