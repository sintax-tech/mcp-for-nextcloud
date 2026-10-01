<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use OCA\Mcp\Controller\GrantsController;
use OCA\Mcp\OAuth\ClientMetadataFetcher;
use OCA\Mcp\OAuth\NativeClient;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class GrantsControllerTest extends TestCase {
    private MatrixFixture $fx;
    /** @var array<string, mixed> JSON body parameters seen through IRequest::getParam */
    private array $body = [];

    protected function setUp(): void {
        $this->fx = new MatrixFixture($this);
        $this->fx->addUser('ana', 'Ana Souza', 'ana@corp.example');
        $this->fx->addUser('bob', 'Bob', 'bob@corp.example');
        $this->fx->addUser('carl', 'Carl', 'carl@corp.example', false);
    }

    private function controller(array $body = []): GrantsController {
        $this->body = $body;
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => array_key_exists($key, $this->body) ? $this->body[$key] : $default);
        return new GrantsController('mcp', $request, $this->fx->matrix(), $this->fx->policy, $this->fx->userManager(), $this->fx->config->mock($this));
    }

    private static function assertBad(JSONResponse $response): void {
        self::assertSame([400, ['error' => 'Invalid request']], [$response->getStatus(), $response->getData()]);
    }

    public function testEveryEndpointIsAdminOnlyAndCsrfProtected(): void {
        foreach (['index', 'update', 'bulk', 'service', 'oauthClients', 'updateOauthClients'] as $method) {
            $this->assertSame([], (new \ReflectionMethod(GrantsController::class, $method))->getAttributes(), $method);
        }
    }

    public function testIndexReturnsTheMatrixPage(): void {
        $response = $this->controller()->index('', '', 1);
        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['ana', 'bob', 'carl'], array_column($response->getData()['users'], 'uid'));
        $this->assertStringNotContainsString('corp.example', json_encode($response->getData()));
        self::assertBad($this->controller()->index('', 'nope', 1));
        self::assertBad($this->controller()->index('', '', 0));
    }

    public function testToggleEligibilityAndGrantIsReflectedInThePolicy(): void {
        $row = $this->controller(['eligible' => true])->update('ana')->getData();
        $this->assertTrue($row['eligible']);
        $this->assertTrue($this->fx->policy->eligible('ana'));
        $row = $this->controller(['module' => 'files', 'operation' => 'edit', 'granted' => true])->update('ana')->getData();
        $this->assertTrue($row['grants']['files']['edit']);
        $this->assertTrue($this->fx->policy->granted('ana', 'files', 'edit'));
        $this->controller(['module' => 'files', 'operation' => 'read', 'granted' => false])->update('ana');
        $this->assertFalse($this->fx->policy->granted('ana', 'files', 'read'));
        $this->assertFalse($this->fx->policy->granted('bob', 'files', 'edit'));
    }

    public function testUpdateValidatesInput(): void {
        foreach ([
            ['ghost', ['eligible' => true]],
            ['ana', []],
            ['ana', ['eligible' => 'yes']],
            ['ana', ['eligible' => true, 'module' => 'files', 'operation' => 'read', 'granted' => true]],
            ['ana', ['module' => 'files', 'operation' => 'delete', 'granted' => true]],
            ['ana', ['module' => 'mail', 'operation' => 'read', 'granted' => true]],
            ['ana', ['module' => 'files', 'operation' => 'read', 'granted' => 1]],
            ['ana', ['module' => 'files', 'operation' => 'read']],
        ] as [$uid, $body]) {
            self::assertBad($this->controller($body)->update($uid));
        }
        $this->assertSame([], $this->fx->config->user['ana']['mcp'] ?? []);
    }

    public function testBulkUpdatesThePageAtomically(): void {
        $response = $this->controller(['uids' => ['ana', 'bob', 'ana'], 'module' => 'notes', 'operation' => 'create', 'granted' => true])->bulk();
        $this->assertSame(['updated' => 2], $response->getData());
        $this->assertTrue($this->fx->policy->granted('ana', 'notes', 'create'));
        $this->assertTrue($this->fx->policy->granted('bob', 'notes', 'create'));
        $this->controller(['uids' => ['ana', 'bob'], 'module' => null, 'operation' => 'eligible', 'granted' => true])->bulk();
        $this->assertTrue($this->fx->policy->eligible('bob'));

        self::assertBad($this->controller(['uids' => ['ana', 'ghost'], 'module' => 'notes', 'operation' => 'edit', 'granted' => true])->bulk());
        $this->assertFalse($this->fx->policy->granted('ana', 'notes', 'edit'));
        foreach ([
            ['uids' => [], 'module' => 'notes', 'operation' => 'edit', 'granted' => true],
            ['uids' => array_fill(0, 51, 'ana'), 'module' => 'notes', 'operation' => 'edit', 'granted' => true],
            ['uids' => 'ana', 'module' => 'notes', 'operation' => 'edit', 'granted' => true],
            ['uids' => ['ana'], 'module' => 'notes', 'operation' => 'eligible', 'granted' => true],
            ['uids' => ['ana'], 'module' => 'files', 'operation' => 'delete', 'granted' => true],
            ['uids' => ['ana'], 'module' => 'notes', 'operation' => 'edit', 'granted' => 'true'],
        ] as $body) {
            self::assertBad($this->controller($body)->bulk());
        }
    }

    public function testBulkEligibilityRevokesSelectedGroupMembersOnly(): void {
        $this->fx->groups['sales'] = ['ana', 'bob'];
        foreach (['ana', 'bob', 'carl'] as $uid) {
            $this->fx->oauth->insertToken(['user_id' => $uid], 0);
            $this->fx->oauth->insertCode(['user_id' => $uid], 0);
        }
        $this->controller(['uids' => $this->fx->groups['sales'], 'module' => null,
            'operation' => 'eligible', 'granted' => false])->bulk();
        $this->assertSame(['carl'], array_values(array_column($this->fx->oauth->tokens, 'user_id')));
        $this->assertSame(['carl'], array_values(array_column($this->fx->oauth->codes, 'user_id')));
        $this->controller(['uids' => $this->fx->groups['sales'], 'module' => null,
            'operation' => 'eligible', 'granted' => true])->bulk();
        $this->assertCount(1, $this->fx->oauth->tokens);
    }

    public function testServiceUsesItsOwnKey(): void {
        $this->assertSame(['enabled' => true], $this->controller(['enabled' => true])->service()->getData());
        $this->assertSame('1', $this->fx->config->app['mcp']['service_enabled']);
        $this->assertArrayNotHasKey('enabled', $this->fx->config->app['mcp']);
        self::assertBad($this->controller(['enabled' => '1'])->service());
        $this->assertTrue($this->fx->policy->globalEnabled());
    }

    public function testOauthClientsShowsTheDefaultHostsWhenUnset(): void {
        $data = $this->controller()->oauthClients()->getData();
        $this->assertSame([
            'hosts' => explode(',', ClientMetadataFetcher::DEFAULT_HOSTS),
            'hostsDefault' => true,
            'nativeClientEnabled' => false,
            'nativeClientId' => NativeClient::CLIENT_ID,
            'nativeRedirectUris' => NativeClient::REDIRECT_URIS,
        ], $data);
    }

    public function testOauthClientsReadsExplicitConfig(): void {
        $this->fx->config->app['mcp']['oauth_client_hosts'] = 'claude.ai,gemini.example.com';
        $this->fx->config->app['mcp']['oauth_native_client_enabled'] = '1';
        $data = $this->controller()->oauthClients()->getData();
        $this->assertSame(['claude.ai', 'gemini.example.com'], $data['hosts']);
        $this->assertFalse($data['hostsDefault']);
        $this->assertTrue($data['nativeClientEnabled']);
    }

    public function testUpdateOauthClientsStoresHostsAndToggle(): void {
        $data = $this->controller(['hosts' => ['claude.ai', 'my-host.example.org', 'claude.ai']])->updateOauthClients()->getData();
        $this->assertSame('claude.ai,my-host.example.org', $this->fx->config->app['mcp']['oauth_client_hosts']);
        $this->assertSame(['claude.ai', 'my-host.example.org'], $data['hosts']);
        $this->assertFalse($data['hostsDefault']);
        $this->assertArrayNotHasKey('oauth_native_client_enabled', $this->fx->config->app['mcp']);

        $data = $this->controller(['nativeClientEnabled' => true])->updateOauthClients()->getData();
        $this->assertSame('1', $this->fx->config->app['mcp']['oauth_native_client_enabled']);
        $this->assertTrue($data['nativeClientEnabled']);
        $this->controller(['nativeClientEnabled' => false])->updateOauthClients();
        $this->assertSame('0', $this->fx->config->app['mcp']['oauth_native_client_enabled']);
    }

    public function testUpdateOauthClientsRejectsInvalidInputWithoutWriting(): void {
        foreach ([
            [],
            ['hosts' => []],
            ['hosts' => 'claude.ai'],
            ['hosts' => ['Claude.ai']],
            ['hosts' => ['https://claude.ai']],
            ['hosts' => ['claude.ai:443']],
            ['hosts' => ['claude.ai/path']],
            ['hosts' => ['*.claude.ai']],
            ['hosts' => ['claude .ai']],
            ['hosts' => ['']],
            ['hosts' => ['claude..ai']],
            ['hosts' => [42]],
            ['hosts' => ['claude.ai'], 'nativeClientEnabled' => 'yes'],
            ['nativeClientEnabled' => 1],
        ] as $body) {
            self::assertBad($this->controller($body)->updateOauthClients());
        }
        $this->assertSame([], array_intersect_key($this->fx->config->app['mcp'] ?? [], ['oauth_client_hosts' => 1, 'oauth_native_client_enabled' => 1]));
    }
}
