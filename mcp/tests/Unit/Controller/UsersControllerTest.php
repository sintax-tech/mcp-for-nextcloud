<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Controller;

use OCA\Mcp\Controller\UsersController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class UsersControllerTest extends TestCase {
    public function testSearchEndpointIsAdminOnly(): void {
        $attributes = array_map(fn ($a) => $a->getName(), (new \ReflectionMethod(UsersController::class, 'index'))->getAttributes());
        $this->assertNotContains(NoAdminRequired::class, $attributes);
    }

    public function testEmptyTermReturnsEmptyListWithoutQuerying(): void {
        $users = $this->createMock(IUserManager::class);
        $users->expects($this->never())->method('searchDisplayName');
        $response = $this->controller($users)->index('   ');
        $this->assertSame(200, $response->getStatus());
        $this->assertSame([], $response->getData());
    }

    public function testSearchReturnsUidAndDisplayNameOnly(): void {
        $users = $this->createMock(IUserManager::class);
        $users->expects($this->once())
            ->method('searchDisplayName')
            ->with('ali', 20)
            ->willReturn([$this->user('alice', 'Alice'), $this->user('aline', 'Aline')]);
        $data = $this->controller($users)->index('ali')->getData();
        $this->assertSame([
            ['uid' => 'alice', 'displayName' => 'Alice'],
            ['uid' => 'aline', 'displayName' => 'Aline'],
        ], $data);
        foreach ($data as $row) {
            $this->assertSame(['uid', 'displayName'], array_keys($row));
        }
    }

    private function controller(IUserManager $users): UsersController {
        return new UsersController('mcp', $this->createMock(IRequest::class), $users);
    }

    private function user(string $uid, string $displayName): IUser {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn($displayName);
        $user->method('getEMailAddress')->willReturn($uid . '@example.org');
        return $user;
    }
}
