<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Access;

use LogicException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Model\OptionalNullableValue;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Service\StackService;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Modules\Deck\Followup\CardCriteria;
use OCA\Mcp\Tests\Support\Deck\DeckDoubles;
use OCA\Mcp\Modules\Deck\Execution\DeckSessionException;
use OCA\Deck\Service\AssignmentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';

final class DeckServiceAccessTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\NativeDeckAdapterFixtures;
    public function testUserIsBoundOnEveryCall(): void {
        $this->recordSetUserId();
        $this->services[CardService::class]->method('find')->willReturn($this->card());
        $this->nativeDeck($this->container, $this->time, session: $this->deckSession('bob'))->cards->findCard('bob', 7);
        $this->nativeDeck($this->container, $this->time, session: $this->deckSession('carol'))->cards->findCard('carol', 7);
        self::assertSame(['setUserId:bob', 'setUserId:carol'], $this->record('setUserId'));
    }

    /**
     * Regression of 0.9.1: under OAuth the session had no `user_id`, Deck wrote the card and then failed with a
     * TypeError, the model retried and the card was written twice. The capability refuses before any Deck write.
     */
    public function testWriteWithoutTheCallerInTheSessionIsRefusedBeforeDeckWrites(): void {
        $written = [];
        $capabilities = $this->sessionCapabilities(null, $written);
        foreach ([
            'create' => fn() => $capabilities->writer->createCard('alice', 10, 'Fechar', '', null),
            'delete' => fn() => $capabilities->writer->deleteCard('alice', 7),
            'assign' => fn() => $capabilities->assignments->assignCardUser('alice', 7, 'pedro'),
        ] as $operation => $call) {
            try {
                $call();
                self::fail($operation . ' reached the Deck without the caller in the session');
            } catch (DeckSessionException) {
            }
        }
        self::assertSame([], $written);
        self::assertSame([], $this->record('setUserId:'), 'nothing of the Deck is bound before the check');
    }
}
