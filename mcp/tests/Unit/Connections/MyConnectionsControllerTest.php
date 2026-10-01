<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Connections;

use OCA\Mcp\Controller\MyConnectionsController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/** Personal connection list: a user sees and revokes only their own connections. */
final class MyConnectionsControllerTest extends TestCase {
    private ConnectionsFixture $fx;

    protected function setUp(): void {
        $this->fx = new ConnectionsFixture($this);
    }

    private function controller(?string $uid, bool $enabled = true): MyConnectionsController {
        $session = $this->createMock(IUserSession::class);
        $user = null;
        if ($uid !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $user->method('isEnabled')->willReturn($enabled);
        }
        $session->method('getUser')->willReturn($user);
        return new MyConnectionsController('mcp', $this->createMock(IRequest::class), $this->fx->list(), $session);
    }

    /** Open to every signed-in user, with CSRF protection kept (no NoCSRFRequired, no PublicPage). */
    public function testEndpointsAreForUsersAndCsrfProtected(): void {
        foreach (['index', 'destroy'] as $method) {
            $attributes = array_map(static fn ($a) => $a->getName(), (new \ReflectionMethod(MyConnectionsController::class, $method))->getAttributes());
            $this->assertSame([NoAdminRequired::class], $attributes, $method);
        }
    }

    public function testIndexListsOnlyTheOwnConnections(): void {
        $mine = $this->fx->grant('alice', 'https://claude.ai/c', 10);
        $this->fx->grant('bob', 'https://chatgpt.com/c', 5);
        $data = $this->controller('alice')->index()->getData();
        $this->assertSame([$mine], array_column($data['connections'], 'id'));
        $this->assertSame(['alice'], array_column($data['connections'], 'uid'));
        $this->assertStringNotContainsString('secret', json_encode($data));
        $this->assertSame([], $this->controller('carol')->index()->getData()['connections']);
    }

    public function testAUserCannotRevokeSomeoneElsesConnection(): void {
        $mine = $this->fx->grant('alice', 'https://claude.ai/c', 10);
        $bobs = $this->fx->grant('bob', 'https://claude.ai/c', 20);
        $response = $this->controller('alice')->destroy($bobs);
        $this->assertSame([404, ['error' => 'Not found']], [$response->getStatus(), $response->getData()]);
        $this->assertArrayHasKey($bobs, $this->fx->store->tokens);
        $this->assertSame(['revoked' => true], $this->controller('alice')->destroy($mine)->getData());
        $this->assertSame([$bobs], array_keys($this->fx->store->tokens));
    }

    public function testWithoutAnEnabledUserNothingIsListedOrRevoked(): void {
        $id = $this->fx->grant('alice', 'https://claude.ai/c', 10);
        $this->assertSame(400, $this->controller(null)->index()->getStatus());
        $this->assertSame(400, $this->controller('alice', false)->index()->getStatus());
        $this->assertSame(404, $this->controller(null)->destroy($id)->getStatus());
        $this->assertSame(404, $this->controller('alice', false)->destroy($id)->getStatus());
        $this->assertSame(400, $this->controller('alice')->index(0)->getStatus());
        $this->assertArrayHasKey($id, $this->fx->store->tokens);
    }
}
