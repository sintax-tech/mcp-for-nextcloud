<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\People\Search;

use OCA\Mcp\Modules\People\Search\PeopleSearch;
use OCP\Collaboration\Collaborators\ISearch;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PeopleSearchTest extends TestCase {
    public function testCapabilityReturnsFreshRowsUsingNativeSharingArguments(): void {
        self::assertTrue(class_exists(PeopleSearch::class), 'Final search capability is missing');
        $search = $this->createMock(ISearch::class);
        $search->expects(self::exactly(2))->method('search')->with('éé', [0, 1], false, 2, 0)->willReturnOnConsecutiveCalls(
            [['exact' => ['groups' => [['value' => ['shareWith' => 'team']]]], 'users' => [['value' => ['shareWith' => 'alice']]]], false],
            [['users' => [['value' => ['shareWith' => 'bob']]]], false],
        );
        $capability = new PeopleSearch($search, $this->createMock(LoggerInterface::class));
        $args = ['query' => ' éé ', 'include_groups' => true, 'limit' => 2];
        self::assertSame([
            ['type' => 'group', 'id' => 'team', 'displayName' => 'team'],
            ['type' => 'user', 'uid' => 'alice', 'displayName' => 'alice'],
        ], $capability->search($args));
        self::assertSame([['type' => 'user', 'uid' => 'bob', 'displayName' => 'bob']], $capability->search($args));
    }

    public function testCapabilityRejectsInvalidInputBeforeNativeSearch(): void {
        self::assertTrue(class_exists(PeopleSearch::class), 'Final search capability is missing');
        $search = $this->createMock(ISearch::class);
        $search->expects(self::never())->method('search');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid search term or limit');
        (new PeopleSearch($search, $this->createMock(LoggerInterface::class)))->search(['query' => 'x']);
    }
}
