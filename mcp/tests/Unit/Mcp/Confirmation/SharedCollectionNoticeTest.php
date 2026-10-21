<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Confirmation;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice;
use OCA\Mcp\Platform\Dav\Collections\Collection;
use PHPUnit\Framework\TestCase;
final class SharedCollectionNoticeTest extends TestCase {
    public function testNoticeUsesEachRequestsIdentityAndFallsBackToOwnerIdWithoutWriting(): void {
        $users = $this->createMock(\OCP\IUserManager::class);
        $users->expects($this->exactly(2))->method('get')->with('bob')->willReturn(null);
        $notice = new SharedCollectionNotice($users);
        $collection = new Collection(1, 'team', 'Team', 'bob', 'principals/users/bob', true, '/team/');
        self::assertNull($notice->forCollection($collection, 'bob'));
        foreach (['alice', 'carla'] as $user) {
            $result = $notice->forCollection($collection, $user);
            self::assertSame('bob', $result['ownerDisplayName']);
            self::assertSame('bob', $result['owner']);
            self::assertSame('Team', $result['resource']);
            self::assertTrue($result['requiresConfirmation']);
        }
        self::assertNull($notice->forCollection($collection, 'bob'));
    }
}
