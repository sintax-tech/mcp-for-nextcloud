<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCA\Mcp\Controller\McpController;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\L10n\UserL10n;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\OAuth\AccessTokenAuthenticator;
use OCA\Mcp\OAuth\ResourceUrl;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\McpProtocol;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class TestableMcpController extends McpController {
    public string $body = '';
    protected function readBody(): string|false { return $this->body; }
}

final class McpControllerTest extends TestCase {
    private InMemoryConfig $store;
    private GrantPolicy $policy;
    private ?IUser $user = null;
    private array $headers = [];
    /** @var AccessTokenAuthenticator&\PHPUnit\Framework\MockObject\MockObject */
    private AccessTokenAuthenticator $authenticator;
    private ?IUser $volatileUser = null;
    /** @var string|null `user_id` of the simulated ISession, the value the DI container hands out as `userId` */
    private ?string $sessionUid = null;
    /** @var list<string|null> every `user_id` written through IUserSession::setUser(), in order */
    private array $sessionWrites = [];
    /** @var array<string, string> language of each account, by uid */
    private array $languages = ['alice' => 'pt_BR', 'bob' => 'es'];

    protected function tearDown(): void {
        Translator::reset();
    }

    protected function setUp(): void {
        $this->store = new InMemoryConfig();
        $this->policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy($this->store->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        $this->policy->setGlobalEnabled(true);
        foreach (['alice', 'bob'] as $uid) {
            $this->policy->setEligible($uid, true);
            $this->policy->setConnected($uid, true);
        }
        $this->login('alice');
        $this->authenticator = $this->createMock(AccessTokenAuthenticator::class);
        $this->headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => McpProtocol::VERSION,
        ];
    }

    /** A core login (Basic, browser) leaves the uid in the session, as `Session::setUser()` does. */
    private function login(?string $uid, bool $enabled = true): void {
        $this->sessionUid = $uid;
        if ($uid === null) { $this->user = null; return; }
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('isEnabled')->willReturn($enabled);
        $this->user = $user;
    }

    private function controller(string $body = '', ?McpProtocol $protocol = null): TestableMcpController {
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturnCallback(fn (string $name) => $this->headers[$name] ?? '');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturnCallback(fn () => $this->volatileUser ?? $this->user);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->with('mcp.mcp.post')->willReturn('https://cloud.example.org/nc/index.php/apps/mcp/');
        $session->method('setVolatileActiveUser')->willReturnCallback(function (?IUser $user): void { $this->volatileUser = $user; });
        // Mirrors OC\User\Session::setUser(): the uid goes to the session (`user_id`) and becomes the active user.
        $session->method('setUser')->willReturnCallback(function (?IUser $user): void {
            $this->sessionUid = $user?->getUID();
            $this->sessionWrites[] = $this->sessionUid;
            $this->volatileUser = $user;
        });
        $resourceRequest = $this->createMock(IRequest::class);
        $resourceRequest->method('getRequestUri')->willReturn('/nc/index.php/apps/mcp/');
        $resourceRequest->method('getServerProtocol')->willReturn('https');
        $resourceRequest->method('getServerHost')->willReturn('cloud.example.org');
        $controller = new TestableMcpController('mcp', $request, $session, $urls, $this->policy, $protocol ?? McpProtocolTest::protocol($this),
            new ResourceUrl($resourceRequest), $this->authenticator,
            new UserL10n(JsonL10n::wire($this->createMock(\OCP\L10N\IFactory::class), $this->languages)));
        $controller->body = $body;
        return $controller;
    }

    private function listTools(): array {
        $response = $this->controller('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')->post();
        return [$response->getStatus(), $response->render()];
    }

    public function testConnectedUserCanListAndCall(): void {
        [$status, $body] = $this->listTools();
        $this->assertSame(200, $status);
        $this->assertStringContainsString('"mcp_status"', $body);
        $call = $this->controller('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"mcp_status","arguments":{}}}')->post();
        $this->assertSame(200, $call->getStatus());
        $this->assertStringContainsString('"content"', $call->render());
    }

    public function testAnonymousAndDisabledUsersGet401(): void {
        $this->login(null);
        $response = $this->controller('{}')->post();
        $this->assertSame(401, $response->getStatus());
        $this->assertSame('', $response->render());
        $this->assertSame('Bearer resource_metadata="https://cloud.example.org/nc/index.php/apps/mcp/.well-known/oauth-protected-resource", scope="mcp"',
            self::headers($response)['WWW-Authenticate']);
        $this->login('alice', false);
        $this->assertSame(401, $this->listTools()[0]);
    }

    public function testRevocationAppliesOnNextRequestForTheSameCredential(): void {
        $this->assertSame(200, $this->listTools()[0]);
        $this->policy->setConnected('alice', false);
        $this->assertSame([403, ''], $this->listTools());
        $this->login('bob');
        $this->assertSame(200, $this->listTools()[0]);
    }

    public function testEligibilityAndGlobalSwitchApplyOnNextRequest(): void {
        $this->policy->setEligible('alice', false);
        $this->assertSame(403, $this->listTools()[0]);
        $this->policy->setEligible('alice', true);
        $this->policy->setGlobalEnabled(false);
        $this->assertSame(403, $this->listTools()[0]);
        $this->login('bob');
        $this->assertSame(403, $this->listTools()[0]);
    }

    public function testNewUserIsNotAllowedByDefault(): void {
        $this->login('carol');
        $this->assertSame(403, $this->listTools()[0]);
    }

    public function testOriginMustMatchTheInstance(): void {
        $this->headers['Origin'] = 'https://evil.example.com';
        $this->assertSame(403, $this->listTools()[0]);
        $this->headers['Origin'] = 'https://CLOUD.example.org';
        $this->assertSame(200, $this->listTools()[0]);
        $this->headers['Origin'] = 'http://cloud.example.org';
        $this->assertSame(403, $this->listTools()[0]);
        $this->headers['Origin'] = 'https://cloud.example.org:8443';
        $this->assertSame(403, $this->listTools()[0]);
    }

    public function testContentNegotiation(): void {
        $this->headers['Accept'] = 'application/json';
        $this->assertSame(406, $this->listTools()[0]);
        $this->headers['Accept'] = 'application/json, text/event-stream';
        $this->headers['Content-Type'] = 'text/plain';
        $this->assertSame(406, $this->listTools()[0]);
        $this->headers['Content-Type'] = 'application/json; charset=utf-8';
        $this->assertSame(200, $this->listTools()[0]);
    }

    public function testOversizedBodyIs413(): void {
        $this->assertSame(413, $this->controller(str_repeat(' ', 1048577))->post()->getStatus());
    }

    public function testGetAndDeleteAre405WithoutSse(): void {
        $this->headers['Accept'] = 'text/event-stream';
        $this->assertSame(405, $this->controller()->get()->getStatus());
        $this->assertSame(405, $this->controller()->delete()->getStatus());
        $this->login(null);
        $this->assertSame(401, $this->controller()->get()->getStatus());
    }

    public function testNotificationIs202AndResponsesAreJson(): void {
        $response = $this->controller('{"jsonrpc":"2.0","method":"notifications/initialized"}')->post();
        $this->assertSame([202, ''], [$response->getStatus(), $response->render()]);
        $list = $this->controller('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')->post();
        $this->assertStringStartsWith('application/json', self::headers($list)['Content-Type']);
        $this->assertSame('no-store', self::headers($list)['Cache-Control']);
    }

    /** Response::getHeaders() needs the server container; read the explicitly set headers instead. */
    private static function headers(\OCP\AppFramework\Http\Response $response): array {
        return (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
    }

    public function testBasicClientsKeepTheBasicChallenge(): void {
        $this->login(null);
        $this->headers['Authorization'] = 'Basic Zm9vOmJhcg==';
        $this->assertStringStartsWith('Basic', self::headers($this->controller('{}')->post())['WWW-Authenticate']);
    }

    public function testValidBearerRunsAsTheTokenOwner(): void {
        $this->login('bob');
        $bob = $this->user;
        $this->login(null);
        $this->headers['Authorization'] = 'Bearer ncmcp_at_valid';
        $this->authenticator->expects($this->once())->method('authenticate')
            ->with('Bearer ncmcp_at_valid', 'https://cloud.example.org/nc/index.php/apps/mcp/')->willReturn($bob);
        $response = $this->controller('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')->post();
        $this->assertSame(200, $response->getStatus());
        $this->assertSame($bob, $this->volatileUser);
    }

    public function testOwnBearerWithAnotherSessionIsRejected(): void {
        $this->headers['Authorization'] = 'Bearer ncmcp_at_valid';
        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        $bob->method('isEnabled')->willReturn(true);
        $this->authenticator->expects($this->once())->method('authenticate')->willReturn($bob);
        $this->assertSame([401, ''], $this->listTools());
        $this->assertNull($this->volatileUser);
    }

    public function testInvalidOwnBearerCannotFallBackToAnActiveSession(): void {
        $this->headers['Authorization'] = 'Bearer ncmcp_at_invalid';
        $this->authenticator->expects($this->once())->method('authenticate')->willReturn(null);
        $this->assertSame([401, ''], $this->listTools());
    }

    public function testOwnBearerSchemeIsCaseInsensitiveAndCannotFallBackToSession(): void {
        $this->headers['Authorization'] = 'bearer ncmcp_at_invalid';
        $this->authenticator->expects($this->once())->method('authenticate')->willReturn(null);
        $this->assertSame([401, ''], $this->listTools());
    }

    public function testMatchingSessionAndBearerUsesValidatedBearer(): void {
        $this->headers['Authorization'] = 'Bearer ncmcp_at_valid';
        $this->authenticator->expects($this->once())->method('authenticate')->willReturn($this->user);
        $this->assertSame(200, $this->listTools()[0]);
        $this->assertSame($this->user, $this->volatileUser);
    }

    public function testInvalidBearerGetsInvalidTokenChallenge(): void {
        $this->login(null);
        $this->headers['Authorization'] = 'Bearer ncmcp_at_expired';
        $this->authenticator->method('authenticate')->willReturn(null);
        $response = $this->controller('{}')->post();
        $this->assertSame(401, $response->getStatus());
        $this->assertStringStartsWith('Bearer error="invalid_token", resource_metadata=', self::headers($response)['WWW-Authenticate']);
    }

    public function testBasicRequestTranslatesInTheLanguageOfTheAccount(): void {
        [$status, $body] = $this->listTools();
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Verificar o status do MCP', $body);
        $this->assertSame('Disconnect', Translator::t('Disconnect'), 'the translator is released when the request ends');
    }

    public function testBearerRequestTranslatesInTheLanguageOfTheTokenOwner(): void {
        $this->languages = ['alice' => 'en', 'bob' => 'pt_BR'];
        $this->login('bob');
        $bob = $this->user;
        $this->login(null);
        $this->headers['Authorization'] = 'Bearer ncmcp_at_valid';
        $this->authenticator->method('authenticate')->willReturn($bob);
        $response = $this->controller('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')->post();
        $this->assertSame(200, $response->getStatus());
        $this->assertStringContainsString('Verificar o status do MCP', $response->render());
        $this->assertSame('Disconnect', Translator::t('Disconnect'));
    }

    public function testRejectedRequestDoesNotKeepTheTranslatorOfAPreviousOne(): void {
        $this->assertSame(200, $this->listTools()[0]);
        $this->login(null);
        $this->assertSame(401, $this->listTools()[0]);
        $this->assertSame('Disconnect', Translator::t('Disconnect'));
    }
    /** @return IUser an enabled, connected account authenticated by an OAuth access token */
    private function bearerFor(string $uid): IUser {
        $this->login($uid);
        $user = $this->user;
        $this->login(null);
        $this->headers['Authorization'] = 'Bearer ncmcp_at_valid';
        $this->authenticator->method('authenticate')->willReturn($user);
        return $user;
    }

    /**
     * @param-out string|null $seen `user_id` of the session while the protocol handled the request
     * @return McpProtocol a protocol double that records the session `user_id` it runs under
     */
    private function probe(?string &$seen, ?\Throwable $failure = null): McpProtocol {
        $protocol = $this->createMock(McpProtocol::class);
        $protocol->method('handle')->willReturnCallback(function () use (&$seen, $failure): array {
            $seen = $this->sessionUid;
            if ($failure !== null) {
                throw $failure;
            }
            return ['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => []]];
        });
        return $protocol;
    }

    /**
     * Regression of 0.9.1: services of other apps (Deck's CardService, ActivityManager...) receive the DI `userId`,
     * which is `ISession::get('user_id')`. A Bearer request must have the token owner there while it is handled.
     */
    public function testBearerOwnerIsTheSessionUserWhileTheRequestIsHandled(): void {
        $this->bearerFor('bob');
        $seen = 'unset';
        $response = $this->controller('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', $this->probe($seen))->post();
        $this->assertSame(200, $response->getStatus());
        $this->assertSame('bob', $seen);
    }

    /** The Bearer owner is written for the request only: the session keeps no login once the response is built. */
    public function testBearerOwnerIsRemovedFromTheSessionWhenTheRequestEnds(): void {
        $bob = $this->bearerFor('bob');
        $seen = null;
        $this->controller('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', $this->probe($seen))->post();
        $this->assertSame(['bob', null], $this->sessionWrites);
        $this->assertNull($this->sessionUid);
        $this->assertSame($bob, $this->volatileUser, 'the rest of the request still runs as the token owner');
    }

    public function testBearerOwnerIsRemovedFromTheSessionWhenHandlingFails(): void {
        $this->bearerFor('bob');
        $seen = null;
        try {
            $this->controller('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', $this->probe($seen, new \RuntimeException('boom')))->post();
            $this->fail('the failure was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame('bob', $seen);
        $this->assertNull($this->sessionUid);
    }

    public function testBearerOnGetAndDeleteLeavesNoSessionUser(): void {
        $this->bearerFor('bob');
        $this->headers['Accept'] = 'text/event-stream';
        $this->assertSame(405, $this->controller()->get()->getStatus());
        $this->assertSame(405, $this->controller()->delete()->getStatus());
        $this->assertNull($this->sessionUid);
    }

    /** A token whose owner may not connect never reaches the session. */
    public function testDeniedBearerNeverWritesTheSession(): void {
        $this->bearerFor('carol');
        $this->assertSame(403, $this->listTools()[0]);
        $this->assertSame([], $this->sessionWrites);
        $this->assertNull($this->sessionUid);
    }

    /** A core login already holds the uid in the session; the request neither rewrites nor removes it. */
    public function testBasicAndMatchingSessionKeepTheirSessionUntouched(): void {
        $seen = null;
        $this->controller('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', $this->probe($seen))->post();
        $this->assertSame('alice', $seen);
        $this->headers['Authorization'] = 'Bearer ncmcp_at_valid';
        $this->authenticator->method('authenticate')->willReturn($this->user);
        $this->controller('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', $this->probe($seen))->post();
        $this->assertSame('alice', $seen);
        $this->assertSame([], $this->sessionWrites);
        $this->assertSame('alice', $this->sessionUid);
    }
}
