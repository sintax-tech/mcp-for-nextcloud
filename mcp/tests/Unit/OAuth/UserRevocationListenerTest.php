<?php
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\UserRevocationListener;
use OCP\IUser;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;

final class UserRevocationListenerTest extends TestCase {
    public function testDisableAndDeletionRevokeButOtherChangesDoNot(): void {
        $store = new InMemoryOAuthStore();
        $listener = new UserRevocationListener($store);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        foreach ([new UserChangedEvent($user, 'enabled', false, true), new UserDeletedEvent($user)] as $event) {
            $store->insertToken(['user_id' => 'alice'], 0);
            $store->insertCode(['user_id' => 'alice'], 0);
            $listener->handle(new UserChangedEvent($user, 'displayName', 'Alice'));
            $listener->handle(new UserChangedEvent($user, 'enabled', true, false));
            $this->assertCount(1, $store->tokens);
            $listener->handle($event);
            $this->assertSame([], $store->tokens);
            $this->assertSame([], $store->codes);
        }
    }
}
