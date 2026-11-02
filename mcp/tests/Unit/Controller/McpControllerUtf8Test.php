<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Controller;

use OCA\Mcp\Controller\McpController;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\L10n\UserL10n;
use OCA\Mcp\OAuth\Tokens\AccessTokenAuthenticator;
use OCA\Mcp\OAuth\Tokens\ResourceUrl;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Mcp\Protocol\McpProtocol;
use OCA\Mcp\Access\Policy\CauseProcessor;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * Issue #25, last line of defence: a JSON-RPC answer holding a string that is not valid UTF-8, from whatever tool,
 * is sent as 200 with U+FFFD in place of the invalid bytes, never as a 500 from json_encode.
 */
final class McpControllerUtf8Test extends TestCase {
	/**
	 * Restores the default translator after each request.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Translator::reset();
	}

	/**
	 * Invalid bytes in a tool result are replaced, and the rest of the answer arrives intact.
	 *
	 * @return void
	 */
	public function testAnInvalidStringIsSubstitutedInsteadOfFailing(): void {
		$response = $this->controller(['content' => [['type' => 'text', 'text' => "Relat\xF3rio \xED\xA0\xBD ok"]]])->post();
		$this->assertSame(200, $response->getStatus());
		$body = json_decode($response->render(), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame("Relat\u{FFFD}rio \u{FFFD} ok", $body['result']['content'][0]['text']);
	}

	/**
	 * The MCP endpoint for alice, connected and allowed, whose protocol answers tools/call with the given result.
	 *
	 * @param array<string, mixed> $result the JSON-RPC result the protocol returns
	 * @return McpController the controller, reading a tools/call request body
	 */
	private function controller(array $result): McpController {
		$config = (new InMemoryConfig())->mock($this);
		$policy = InMemoryConfig::policy($config, new InMemoryOAuthStore());
		$policy->setGlobalEnabled(true);
		$policy->setEligible('alice', true);
		$policy->setConnected('alice', true);
		$headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => McpProtocol::VERSION];
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => $headers[$name] ?? '');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('isEnabled')->willReturn(true);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example.org/nc/index.php/apps/mcp/');
		$resourceRequest = $this->createMock(IRequest::class);
		$resourceRequest->method('getRequestUri')->willReturn('/nc/index.php/apps/mcp/');
		$resourceRequest->method('getServerProtocol')->willReturn('https');
		$resourceRequest->method('getServerHost')->willReturn('cloud.example.org');
		$protocol = $this->createMock(McpProtocol::class);
		$protocol->method('handle')->willReturn(['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]]);
		$guests = $this->createMock(GuestRestrictions::class);
		$guests->method('apply')->willReturn(true);
		return new class('mcp', $request, $session, $urls, $policy, $protocol, new ResourceUrl($resourceRequest),
			$this->createMock(AccessTokenAuthenticator::class), new UserL10n(JsonL10n::wire($this->createMock(IFactory::class), ['alice' => 'en'])),
			$this->createMock(CauseProcessor::class), $guests) extends McpController {
			/**
			 * The request body, as php://input would give it.
			 *
			 * @return string|false a tools/call request
			 */
			protected function readBody(): string|false {
				return '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"files_read","arguments":{}}}';
			}
		};
	}
}
