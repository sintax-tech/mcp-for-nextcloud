<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Controller\OAuthController;
use OCA\Mcp\OAuth\Authorization\AuthorizationValidator;
use OCA\Mcp\OAuth\Authorization\ServerMetadata;
use OCA\Mcp\OAuth\Clients\ClientMetadata;
use OCA\Mcp\OAuth\Clients\ClientResolver;
use OCA\Mcp\OAuth\Tokens\ResourceUrl;
use OCA\Mcp\OAuth\Tokens\TokenService;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises GET authorize and POST consent through different front-controller prefixes. */
final class ConsentIssuerTest extends TestCase {
	/** @var array<string,mixed> pending requests shared by the two controller instances */
	private array $stored = [];

	/** @return iterable<string,array{string,string,string}> prefix changes in either direction and every callback outcome */
	public static function flows(): iterable {
		foreach (['' => 'root', '/nextcloud' => 'subdirectory'] as $root => $label) {
			foreach ([[$root . '/index.php', $root], [$root, $root . '/index.php']] as [$client, $router]) {
				foreach (['allow', 'deny', 'unavailable'] as $outcome) {
					yield "$label $client to $router $outcome" => [$client, $router, $outcome];
				}
			}
		}
	}

	/** @param string $prefix installation/front-controller prefix @return IRequest request at that prefix */
	private function request(string $prefix): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getRequestUri')->willReturn($prefix . '/apps/mcp/oauth/authorize');
		$request->method('getServerProtocol')->willReturn('https');
		$request->method('getServerHost')->willReturn('cloud.example.org');
		$request->method('getParams')->willReturn([
			'client_id' => 'https://claude.ai/test-client', 'redirect_uri' => 'https://claude.ai/callback',
			'response_type' => 'code', 'scope' => 'mcp', 'state' => 'issuer-prefix-proof',
			'code_challenge_method' => 'S256', 'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
		]);
		return $request;
	}

	/**
	 * @param string $clientPrefix prefix used for discovery and GET authorize
	 * @param string $routerPrefix prefix generated for the POST action
	 * @param string $outcome successful consent, denial or temporary storage failure
	 * @return void all callbacks retain the issuer discovered before the prefix changed
	 */
	#[DataProvider('flows')]
	public function testConsentRetainsTheDiscoveredIssuerAcrossRouterPrefixChanges(string $clientPrefix, string $routerPrefix, string $outcome): void {
		$this->flow($clientPrefix, $routerPrefix, $outcome);
	}

	/** @return void a page created before issuer capture cannot emit an issuer guessed from the POST */
	public function testAnOlderPendingConsentWithoutIssuerMustRestart(): void {
		$this->flow('/index.php', '', 'deny', true);
	}

	/**
	 * @param string $clientPrefix client prefix
	 * @param string $routerPrefix router prefix
	 * @param string $outcome expected callback result
	 * @param bool $legacy remove the issuer to simulate a pending page from the previous app version
	 * @return void assertions on the actual controller callback or expired page
	 */
	private function flow(string $clientPrefix, string $routerPrefix, string $outcome, bool $legacy = false): void {
		$get = $this->request($clientPrefix);
		$post = $this->request($routerPrefix);
		$discovered = (new ServerMetadata(new ResourceUrl($get)))->document()['issuer'];
		$this->assertNotSame($discovered, (new ResourceUrl($post))->issuer());
		$session = $this->createMock(ISession::class);
		$session->method('set')->willReturnCallback(function (string $key, array $value): void { $this->stored = $value; });
		$session->method('get')->willReturnCallback(fn () => $this->stored);
		$session->method('remove')->willReturnCallback(function (): void { $this->stored = []; });
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice');
		$users = $this->createMock(IUserSession::class);
		$users->method('getUser')->willReturn($user);
		$generator = $this->createMock(IURLGenerator::class);
		$action = $routerPrefix . '/apps/mcp/oauth/authorize';
		$generator->method('linkToRoute')->willReturn($action);
		$resolver = $this->createMock(ClientResolver::class);
		$resolver->method('resolve')->willReturn(new ClientMetadata('https://claude.ai/test-client', 'Test client', ['https://claude.ai/callback']));
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('globalEnabled')->willReturn(true);
		$policy->method('eligible')->willReturn(true);
		$tokens = $this->createMock(TokenService::class);
		if ($outcome === 'deny' || $legacy) {
			$tokens->expects($this->never())->method('consent');
		} elseif ($outcome === 'unavailable') {
			$tokens->expects($this->once())->method('consent')->willThrowException(new PolicyUnavailable());
		} else {
			$tokens->expects($this->once())->method('consent')->willReturn('issued-code');
		}
		$controller = static fn (IRequest $request): OAuthController => new OAuthController('mcp', $request,
			$users, $session, $generator, new AuthorizationValidator($resolver), $tokens, $policy, new ResourceUrl($request));
		$page = $controller($get)->authorize()->getParams();
		$this->assertSame($action, $page['action']);
		$pending = $page['pending'];
		$this->assertSame($discovered, $this->stored[$pending]['issuer']);
		if ($legacy) {
			unset($this->stored[$pending]['issuer']);
		}
		$response = $controller($post)->consent($pending, $outcome === 'deny' ? 'deny' : 'allow');
		$this->assertSame([], $this->stored, 'the pending request is consumed');
		if ($legacy) {
			$this->assertSame('consent', $response->getTemplateName());
			$this->assertStringContainsString('expired', $response->getParams()['error']);
			return;
		}
		$this->assertSame('oauth-redirect', $response->getTemplateName());
		parse_str((string)parse_url($response->getParams()['target'], PHP_URL_QUERY), $query);
		$this->assertSame($discovered, $query['iss']);
		$this->assertSame('issuer-prefix-proof', $query['state']);
		$this->assertSame($outcome === 'allow' ? 'issued-code' : ($outcome === 'deny' ? 'access_denied' : 'temporarily_unavailable'),
			$query[$outcome === 'allow' ? 'code' : 'error']);
	}
}
