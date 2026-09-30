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
        $users->expects($this->never())->method('search');
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
        $users->expects($this->once())
            ->method('search')
            ->with('ali', 20)
            ->willReturn([]);
        $data = $this->controller($users)->index('ali')->getData();
        $this->assertSame([
            ['uid' => 'alice', 'displayName' => 'Alice'],
            ['uid' => 'aline', 'displayName' => 'Aline'],
        ], $data);
        foreach ($data as $row) {
            $this->assertSame(['uid', 'displayName'], array_keys($row));
        }
    }

    public function testUserFoundByBothSourcesIsListedOnce(): void {
        $users = $this->createMock(IUserManager::class);
        $users->method('searchDisplayName')->willReturn([$this->user('alice', 'Alice')]);
        $users->method('search')->willReturn([
            $this->user('alice', 'Alice'),
            $this->user('albert', 'Albert'),
        ]);
        $this->assertSame([
            ['uid' => 'alice', 'displayName' => 'Alice'],
            ['uid' => 'albert', 'displayName' => 'Albert'],
        ], $this->controller($users)->index('al')->getData());
    }

    public function testResultsAreCappedAtTwenty(): void {
        $byDisplayName = [];
        for ($i = 0; $i < 15; $i++) {
            $byDisplayName[] = $this->user('u' . $i, 'User ' . $i);
        }
        $byUid = [];
        for ($i = 0; $i < 30; $i++) {
            $byUid[] = $this->user('v' . $i, 'Visitor ' . $i);
        }
        $users = $this->createMock(IUserManager::class);
        $users->method('searchDisplayName')->willReturn($byDisplayName);
        $users->method('search')->willReturn($byUid);
        $data = $this->controller($users)->index('u')->getData();
        $this->assertCount(20, $data);
        $this->assertSame('u14', $data[14]['uid']);
        $this->assertSame('v4', $data[19]['uid']);
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
