<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Assignments;

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
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';

final class DeckVerificationAssignmentsTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\DeckVerificationAdapterFixtures;
    public function testCardAssignmentsAreReadAgainAfterTheReadCheck(): void {
        $assignment = new Assignment(['cardId' => 9, 'participant' => 'pedro', 'type' => 0]);
        $this->services[AssignmentMapper::class]->expects(self::once())->method('findIn')->with([9])->willReturn([$assignment]);
        self::assertSame([$assignment], $this->capabilities->assignments->cardAssignments('alice', 9));
        self::assertSame(['check:' . CardMapper::class . ':9:' . Acl::PERMISSION_READ], $this->checks());
    }
}
