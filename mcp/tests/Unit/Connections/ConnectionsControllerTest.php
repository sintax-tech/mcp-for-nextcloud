<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Connections;

use OCA\Mcp\Controller\ConnectionsController;
use OCA\Mcp\Service\ConnectionList;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/** Admin connection list: every user's live connections, without secrets, and revocation by id or by user. */
final class ConnectionsControllerTest extends TestCase {
    private ConnectionsFixture $fx;

    protected function setUp(): void {
        $this->fx = new ConnectionsFixture($this);
    }

    private function controller(): ConnectionsController {
        return new ConnectionsController('mcp', $this->createMock(IRequest::class), $this->fx->list());
    }

    public function testEveryEndpointIsAdminOnlyAndCsrfProtected(): void {
        foreach (['index', 'destroy', 'revokeUser'] as $method) {
            $this->assertSame([], (new \ReflectionMethod(ConnectionsController::class, $method))->getAttributes(), $method);
        }
    }

    public function testIndexListsLiveConnectionsNewestFirstWithoutSecrets(): void {
        $claude = $this->fx->grant('alice', 'https://claude.ai/oauth/mcp-oauth-client-metadata', 300);
        $native = $this->fx->grant('bob', 'nextcloud-mcp-native', 100);
        $this->fx->grant('alice', 'https://chatgpt.com/x', 50, true);
        $this->fx->grant('ghost', 'https://ChatGPT.com/oauth/client.json', 200);
        $data = $this->controller()->index()->getData();
        $this->assertSame([$native, $claude], [$data['connections'][0]['id'], $data['connections'][2]['id']]);
        $this->assertCount(3, $data['connections']);
        $this->assertSame(['id' => $native, 'uid' => 'bob', 'displayName' => 'Bob',
            'client' => ['kind' => 'native', 'host' => '', 'id' => 'nextcloud-mcp-native'],
            'createdAt' => $this->fx->now - 100, 'expiresAt' => $this->fx->now + 30 * 86400], $data['connections'][0]);
        $this->assertSame(['ghost', 'ghost', 'chatgpt.com'], [$data['connections'][1]['uid'], $data['connections'][1]['displayName'], $data['connections'][1]['client']['host']]);
        $this->assertSame('claude.ai', $data['connections'][2]['client']['host']);
        $this->assertFalse($data['hasMore']);
        $this->assertStringNotContainsString('secret', json_encode($data));
        $this->assertSame(['ghost'], array_column($this->controller()->index('gho')->getData()['connections'], 'uid'));
        $this->assertSame(['alice'], array_column($this->controller()->index('claude')->getData()['connections'], 'uid'));
    }

    public function testIndexPagesAndRejectsBadInput(): void {
        for ($i = 1; $i <= ConnectionList::PAGE_SIZE + 2; $i++) {
            $this->fx->grant('alice', 'https://claude.ai/c', $i);
        }
        $first = $this->controller()->index('', 1)->getData();
        $this->assertCount(ConnectionList::PAGE_SIZE, $first['connections']);
        $this->assertTrue($first['hasMore']);
        $this->assertCount(2, $this->controller()->index('', 2)->getData()['connections']);
        foreach ([['', 0], ['', ConnectionList::MAX_PAGE + 1], [str_repeat('a', 101), 1]] as [$search, $page]) {
            $response = $this->controller()->index($search, $page);
            $this->assertSame([400, ['error' => 'Invalid request']], [$response->getStatus(), $response->getData()]);
        }
    }

    public function testDestroyRevokesOneConnectionOfAnyUser(): void {
        $keep = $this->fx->grant('alice', 'https://claude.ai/c', 10);
        $gone = $this->fx->grant('bob', 'https://claude.ai/c', 20);
        $this->fx->store->spent['refresh-secret-bob20'] = $this->fx->store->tokens[$gone];
        $response = $this->controller()->destroy($gone);
        $this->assertSame([200, ['revoked' => true]], [$response->getStatus(), $response->getData()]);
        $this->assertSame([$keep], array_keys($this->fx->store->tokens));
        $this->assertSame([], $this->fx->store->spent, 'the rotation history of the grant goes with it');
        $this->assertNull($this->fx->store->findByAccess('access-secret-bob20'), 'the access token stops working at once');
        $this->assertSame(404, $this->controller()->destroy($gone)->getStatus());
        $this->assertSame(404, $this->controller()->destroy(0)->getStatus());
    }

    public function testRevokeUserRemovesEveryConnectionOfThatUserOnly(): void {
        $this->fx->grant('alice', 'https://claude.ai/c', 10);
        $this->fx->grant('alice', 'nextcloud-mcp-native', 20);
        $bob = $this->fx->grant('bob', 'https://claude.ai/c', 30);
        $this->assertSame(['revoked' => true], $this->controller()->revokeUser('alice')->getData());
        $this->assertSame([$bob], array_keys($this->fx->store->tokens));
        $this->assertSame(400, $this->controller()->revokeUser('')->getStatus());
        $this->assertSame(400, $this->controller()->revokeUser(str_repeat('u', 65))->getStatus());
    }

    public function testCountOnlyIncludesLiveConnections(): void {
        $this->fx->grant('alice', 'https://claude.ai/c', 10);
        $this->fx->grant('bob', 'https://claude.ai/c', 20, true);
        $this->assertSame(1, $this->fx->list()->count());
    }
}
