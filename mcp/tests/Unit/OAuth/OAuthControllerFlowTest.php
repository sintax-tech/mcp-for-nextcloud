<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\Controller\OAuthController;
use OCA\Mcp\OAuth\AuthorizationValidator;
use OCA\Mcp\OAuth\ClientMetadata;
use OCA\Mcp\OAuth\ClientResolver;
use OCA\Mcp\OAuth\OAuthException;
use OCA\Mcp\OAuth\ResourceUrl;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** The consent POST and the token endpoint of {@see OAuthController}: what decides whether a code is ever issued. */
final class OAuthControllerFlowTest extends TestCase {
    private const ISSUER = 'https://cloud.example.org';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    /** @var array<string,mixed> */
    private array $stored = [];
    private bool $serviceOn = true;
    private bool $clientsAvailable = true;
    private ?bool $eligible = true;
    private MockObject&GrantPolicy $policy;
    private MockObject&TokenService $tokens;
    /** @var array<string,mixed> */
    private array $params = [];

    private function controller(): OAuthController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParams')->willReturnCallback(fn () => $this->params);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $user->method('getDisplayName')->willReturn('Alice');
        $users = $this->createMock(IUserSession::class);
        $users->method('getUser')->willReturn($user);
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(fn () => $this->stored ?: null);
        $session->method('set')->willReturnCallback(function ($key, $value): void {
            $this->stored = $value;
        });
        $session->method('remove')->willReturnCallback(function (): void {
            $this->stored = [];
        });
        $resolver = $this->createMock(ClientResolver::class);
        $resolver->method('resolve')->willReturnCallback(fn () => $this->clientsAvailable ? new ClientMetadata('https://claude.ai/c', 'Claude', ['https://claude.ai/cb']) : throw new \OCA\Mcp\Access\Policy\PolicyUnavailable());
        $this->policy = $this->createMock(GrantPolicy::class);
        $this->policy->method('globalEnabled')->willReturnCallback(fn () => $this->serviceOn);
        $this->policy->method('eligible')->willReturnCallback(fn () => $this->eligible ?? throw new \OCA\Mcp\Access\Policy\PolicyUnavailable());
        $this->tokens = $this->createMock(TokenService::class);
        $urls = $this->createMock(ResourceUrl::class);
        $urls->method('base')->willReturn(self::ISSUER . '/apps/mcp/');
        $urls->method('issuer')->willReturn(self::ISSUER);
        $generator = $this->createMock(IURLGenerator::class);
        $generator->method('linkToRoute')->willReturn('/consent');
        return new OAuthController('mcp', $request, $users, $session, $generator, new AuthorizationValidator($resolver), $this->tokens, $this->policy, $urls);
    }

    public function testUnavailableClientAuthorityNeverOffersConsent(): void {
        $controller = $this->controller();
        $this->clientsAvailable = false;
        $this->params = ['client_id' => 'nextcloud-mcp'];
        $response = $controller->authorize();
        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame(503, $response->getStatus());
        $this->assertTrue($response->getParams()['unavailable']);
        $this->assertNull($response->getParams()['pending']);
        $this->assertSame([], $this->stored);
    }

    /** @return array<string,string> the headers, read without the server container OCP needs for getHeaders() */
    private function headers(JSONResponse $response): array {
        $property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
        return $property->getValue($response);
    }

    private function authorize(OAuthController $controller): string {
        $this->params = ['client_id' => 'https://claude.ai/c', 'redirect_uri' => 'https://claude.ai/cb', 'response_type' => 'code',
            'code_challenge_method' => 'S256', 'code_challenge' => self::CHALLENGE, 'state' => 'S', 'scope' => 'mcp'];
        $page = $controller->authorize();
        $this->assertInstanceOf(TemplateResponse::class, $page);
        return (string)$page->getParams()['pending'];
    }

    public function testAllowingIssuesTheCodeAndRecordsThePersonalConnection(): void {
        $controller = $this->controller();
        $pending = $this->authorize($controller);
        // The connection and the code are one command of the token service; the controller never sets them apart.
        $this->tokens->expects($this->once())->method('consent')->with($this->anything(), 'alice')->willReturn('CODE');
        $this->policy->expects($this->never())->method('setConnected');

        $response = $controller->consent($pending, 'allow');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        parse_str((string)parse_url($response->getRedirectURL(), PHP_URL_QUERY), $query);
        $this->assertSame('CODE', $query['code']);
    }

    /** A storage failure of the policy writer is temporarily_unavailable for the client, never a 500 or a code. */
    public function testAPolicyStorageFailureIssuesNoCodeAndTellsTheClientToRetry(): void {
        $controller = $this->controller();
        $pending = $this->authorize($controller);
        $this->tokens->method('consent')->willThrowException(new \OCA\Mcp\Access\Policy\PolicyUnavailable());

        $response = $controller->consent($pending, 'allow');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        parse_str((string)parse_url($response->getRedirectURL(), PHP_URL_QUERY), $query);
        $this->assertSame('temporarily_unavailable', $query['error']);
        $this->assertArrayNotHasKey('code', $query);
    }

    /** Eligibility or the service changed between the check and the mutex: the consent page, never a code. */
    public function testAConsentRefusedUnderTheMutexShowsTheBlockedPage(): void {
        $controller = $this->controller();
        $pending = $this->authorize($controller);
        $this->tokens->expects($this->once())->method('consent')->willReturn(null);

        $response = $controller->consent($pending, 'allow');

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertTrue($response->getParams()['blocked']);
    }

    /** The token endpoint answers a storage failure with temporarily_unavailable and Retry-After, never throttled. */
    public function testATokenRequestDuringAStorageFailureIsRetriable(): void {
        $controller = $this->controller();
        $this->params = ['grant_type' => 'refresh_token'];
        $this->tokens->method('refresh')->willThrowException(new \OCA\Mcp\Access\Policy\PolicyUnavailable());
        $this->tokens->expects($this->once())->method('housekeeping');

        $response = $controller->token();

        $this->assertSame(503, $response->getStatus());
        $this->assertSame('temporarily_unavailable', $response->getData()['error']);
        $this->assertSame('1', $this->headers($response)['Retry-After']);
        $this->assertSame('no-store', $this->headers($response)['Cache-Control']);
        $this->assertSame([], $response->getThrottleMetadata());
    }

    public function testAnythingButAllowIssuesNoCodeAndConnectsNothing(): void {
        foreach (['deny', '', 'ALLOW', 'allow ', 'yes'] as $decision) {
            $controller = $this->controller();
            $pending = $this->authorize($controller);
            $this->tokens->expects($this->never())->method('consent');
            $this->policy->expects($this->never())->method('setConnected');

            $response = $controller->consent($pending, $decision);

            $this->assertInstanceOf(RedirectResponse::class, $response, $decision);
            parse_str((string)parse_url($response->getRedirectURL(), PHP_URL_QUERY), $query);
            $this->assertSame('access_denied', $query['error'], $decision);
            $this->assertArrayNotHasKey('code', $query);
        }
    }

    public function testAnUnknownPendingRequestIssuesNothing(): void {
        $controller = $this->controller();
        $this->authorize($controller);
        $this->tokens->expects($this->never())->method('consent');

        $response = $controller->consent('not-the-pending-id', 'allow');

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertNotNull($response->getParams()['error']);
    }

    public function testAPendingRequestIsSingleUse(): void {
        $controller = $this->controller();
        $pending = $this->authorize($controller);
        $this->tokens->expects($this->once())->method('consent')->willReturn('CODE');

        $this->assertInstanceOf(RedirectResponse::class, $controller->consent($pending, 'allow'));
        $second = $controller->consent($pending, 'allow');

        $this->assertInstanceOf(TemplateResponse::class, $second);
        $this->assertNotNull($second->getParams()['error']);
    }

    public function testAWrongPendingIdBurnsTheRealOne(): void {
        $controller = $this->controller();
        $pending = $this->authorize($controller);
        $this->tokens->expects($this->never())->method('consent');

        $controller->consent('guess', 'allow');
        $response = $controller->consent($pending, 'allow');

        $this->assertInstanceOf(TemplateResponse::class, $response, 'the session entry is removed whatever is submitted');
    }

    /** Between the consent page and the click the administrator can switch the service off or drop the user's eligibility. */
    public function testRevokedServiceOrEligibilityBetweenPageAndClickBlocksTheCode(): void {
        foreach (['service' => 'serviceOn', 'eligibility' => 'eligible'] as $what => $flag) {
            $this->serviceOn = $this->eligible = true;
            $controller = $this->controller();
            $pending = $this->authorize($controller);
            $this->$flag = false;
            $this->tokens->expects($this->never())->method('consent');
            $this->policy->expects($this->never())->method('setConnected');

            $response = $controller->consent($pending, 'allow');

            $this->assertInstanceOf(TemplateResponse::class, $response, $what);
            $this->assertTrue($response->getParams()['blocked'], $what);
        }
    }

    /**
     * An eligibility that is unknown (only a group of indeterminate policy grants it) is neither the blocked page nor
     * a consent: the page says it cannot be checked now, keeps nothing, and the click answers temporarily_unavailable.
     */
    public function testAnUnknownEligibilityIsNeitherBlockedNorAConsent(): void {
        $controller = $this->controller();
        $pending = $this->authorize($controller);
        $this->eligible = null;
        $this->tokens->expects($this->never())->method('consent');
        $response = $controller->consent($pending, 'allow');
        $this->assertInstanceOf(RedirectResponse::class, $response);
        parse_str((string)parse_url($response->getRedirectURL(), PHP_URL_QUERY), $query);
        $this->assertSame('temporarily_unavailable', $query['error']);

        $this->params = ['client_id' => 'https://claude.ai/c', 'redirect_uri' => 'https://claude.ai/cb', 'response_type' => 'code',
            'code_challenge_method' => 'S256', 'code_challenge' => self::CHALLENGE, 'scope' => 'mcp'];
        $page = $controller->authorize();
        $this->assertTrue($page->getParams()['unavailable']);
        $this->assertFalse($page->getParams()['blocked']);
        $this->assertNull($page->getParams()['pending']);
        $this->assertSame([], $this->stored, 'nothing was kept in the session');
    }

    public function testTheConsentPageIsBlockedWhenTheServiceIsOffOrTheUserIsNotEligible(): void {
        foreach (['serviceOn', 'eligible'] as $flag) {
            $this->serviceOn = $this->eligible = true;
            $this->$flag = false;
            $controller = $this->controller();
            $this->params = ['client_id' => 'https://claude.ai/c', 'redirect_uri' => 'https://claude.ai/cb', 'response_type' => 'code',
                'code_challenge_method' => 'S256', 'code_challenge' => self::CHALLENGE, 'scope' => 'mcp'];

            $page = $controller->authorize();

            $this->assertTrue($page->getParams()['blocked'], $flag);
            $this->assertNull($page->getParams()['pending'], $flag);
            $this->assertSame([], $this->stored, 'nothing was kept in the session');
        }
    }

    public function testTheTokenEndpointNeverCachesAndRoutesEachGrantType(): void {
        $controller = $this->controller();
        $this->tokens->expects($this->once())->method('exchangeCode')->willReturn(['access_token' => 'a']);
        $this->tokens->expects($this->once())->method('refresh')->willReturn(['access_token' => 'b']);
        foreach (['authorization_code', 'refresh_token'] as $grant) {
            $this->params = ['grant_type' => $grant];
            $response = $controller->token();
            $this->assertSame(200, $response->getStatus(), $grant);
            $this->assertSame('no-store', $this->headers($response)['Cache-Control'], $grant);
            $this->assertSame('no-cache', $this->headers($response)['Pragma'], $grant);
        }
    }

    public function testAnUnknownGrantTypeIsARefusalWithoutThrottling(): void {
        foreach ([[], ['grant_type' => 'password'], ['grant_type' => ['x']]] as $params) {
            $this->params = $params;
            $response = $this->controller()->token();

            $this->assertInstanceOf(JSONResponse::class, $response);
            $this->assertSame(400, $response->getStatus());
            $this->assertSame('unsupported_grant_type', $response->getData()['error']);
            $this->assertSame('no-store', $this->headers($response)['Cache-Control']);
            $this->assertSame([], $response->getThrottleMetadata(), 'only a bad grant is throttled');
        }
    }

    public function testOnlyAnInvalidGrantIsThrottled(): void {
        $controller = $this->controller();
        $this->tokens->method('exchangeCode')->willThrowException(new OAuthException('invalid_grant', 'Invalid authorization code'));
        $this->tokens->method('refresh')->willThrowException(new OAuthException('invalid_request', 'refresh_token and client_id are required'));

        $this->params = ['grant_type' => 'authorization_code'];
        $grant = $controller->token();
        $this->params = ['grant_type' => 'refresh_token'];
        $request = $controller->token();

        $this->assertSame(['action' => 'mcp_oauth_token'], $grant->getThrottleMetadata());
        $this->assertSame([], $request->getThrottleMetadata());
        $this->assertSame('invalid_grant', $grant->getData()['error']);
        $this->assertSame('no-store', $this->headers($grant)['Cache-Control']);
    }
}
