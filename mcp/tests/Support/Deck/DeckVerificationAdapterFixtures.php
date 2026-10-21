<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Deck;

use LogicException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\Tests\Support\Deck\DeckDoubles;
use OCA\Mcp\Modules\Deck\Execution\DeckSessionException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ISession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

trait DeckVerificationAdapterFixtures {
    use \OCA\Mcp\Tests\Support\Deck\DeckNativeFixtures;
    private const NOW = 1790000000;
    /** @var array<string, object&MockObject> Services the container hands out. */
    private array $services = [];
    /** @var list<string> Ordered log of the calls, to prove check-before-read. */
    private array $calls = [];
    private DeckDoubles $capabilities;
    protected function setUp(): void {
        foreach ([
            BoardService::class,
            PermissionService::class,
            CardMapper::class,
            StackMapper::class,
            BoardMapper::class,
            AssignmentMapper::class,
        ] as $class) {
            $this->services[$class] = $this->createMock($class);
        }
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(fn(string $id): object => $this->services[$id] ?? throw new LogicException('Unexpected container lookup: ' . $id));
        $this->services[PermissionService::class]->method('checkPermission')->willReturnCallback(function (
            $mapper,
            $id,
            int $permission,
            $userId = null,
            bool $allowDeletedCard = false,
        ): bool {
            $this->calls[] = 'check:' . match (true) {
                $mapper === null => 'board',
                $mapper instanceof CardMapper => CardMapper::class,
                $mapper instanceof StackMapper => StackMapper::class,
                default => 'other',
            } . ':' . $id . ':' . $permission . ($allowDeletedCard ? ':deleted' : '');
            return true;
        });
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        $this->capabilities = $this->nativeDeck($container, $time, session: $this->deckSession());
    }

    /** @return list<string> the calls without the binding */
    private function checks(): array {
        return array_values(array_filter(
            $this->calls,
            static fn(string $c): bool => str_starts_with($c, 'check:') || str_starts_with($c, 'read'),
        ));
    }
}
