<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Protocol;

use OCA\Mcp\Mcp\Protocol\McpProtocol;
use OCA\Mcp\Mcp\Protocol\ServerIdentity;
use OCA\Mcp\Mcp\Prompts\PromptCatalog;
use OCA\Mcp\OAuth\Tokens\ResourceUrl;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\InstalledApp;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCA\Mcp\Tests\Unit\StubRequest;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The server identity announced to MCP clients is whatever IAppManager reports, not a version baked into the
 * code, plus this app's own icons, served from the host of the current request.
 */
final class ServerInfoTest extends TestCase {
	/** A version that is not the one in appinfo/info.xml, so a hardcoded manifest string cannot pass. */
	private const SENTINEL = '9.9.9-sentinel';

	/**
	 * initialize, server/discover and a modern tools/call report the version the app manager returns.
	 *
	 * The manager is required to be consulted, with the cache left on. A separate check ties
	 * InstalledApp::version() to the version element of info.xml.
	 */
	public function testAnnouncedVersionIsTheInstalledAppVersion(): void {
		$declared = (string) simplexml_load_file(dirname(__DIR__, 4) . '/appinfo/info.xml')->version;
		$this->assertSame($declared, InstalledApp::version());
		$this->assertNotSame(self::SENTINEL, $declared);

		$apps = $this->createMock(IAppManager::class);
		InstalledApp::mock($apps, self::SENTINEL, $this->atLeastOnce());
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		$protocol = new McpProtocol(new ToolRegistry([], $policy, $apps,
			$this->createMock(IUserManager::class), $this->createMock(LoggerInterface::class)),
			new PromptCatalog(), $policy, $this->createMock(LoggerInterface::class), new ServerIdentity($apps, StubRequest::urls($this)));
		$expected = ['name' => 'mcp-for-cloud', 'version' => self::SENTINEL,
			'icons' => [StubRequest::lightIcon(), StubRequest::darkIcon()]];

		$this->assertSame($expected, $protocol->serverInfo());

		$init = $protocol->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
			'protocolVersion' => McpProtocol::VERSION, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'c', 'version' => '1'],
		]]), '', 'alice');
		$this->assertSame($expected, $init['body']['result']['serverInfo']);

		$discover = $protocol->handle(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'server/discover', 'params' => [
			'_meta' => [McpProtocol::META_VERSION => McpProtocol::MODERN_VERSION],
		]]), McpProtocol::MODERN_VERSION, 'alice', ['method' => 'server/discover']);
		$this->assertSame($expected, $discover['body']['result']['_meta'][McpProtocol::META_SERVER_INFO]);

		$call = $protocol->handle(json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
			'name' => McpProtocol::TOOL, 'arguments' => new \stdClass(),
			'_meta' => [McpProtocol::META_VERSION => McpProtocol::MODERN_VERSION],
		]]), McpProtocol::MODERN_VERSION, 'alice', ['method' => 'tools/call', 'name' => McpProtocol::TOOL]);
		$this->assertSame($expected, $call['body']['result']['_meta'][McpProtocol::META_SERVER_INFO]);
	}

	/**
	 * The icon URLs follow the host of the current request, so a subdomain installation advertises its own
	 * host and a client that renders serverInfo.icons does not fall back to a favicon of the registrable domain.
	 */
	public function testIconUrlsFollowTheRequestHost(): void {
		$apps = $this->createMock(IAppManager::class);
		InstalledApp::mock($apps);
		$request = $this->createMock(IRequest::class);
		$request->method('getRequestUri')->willReturn('/index.php/apps/mcp/');
		$request->method('getServerProtocol')->willReturn('https');
		$request->method('getServerHost')->willReturn('nextcloud.example.com');
		$identity = new ServerIdentity($apps, new ResourceUrl($request));
		$this->assertSame([
			['src' => 'https://nextcloud.example.com/apps/mcp/img/app.svg', 'mimeType' => 'image/svg+xml'],
			['src' => 'https://nextcloud.example.com/apps/mcp/img/app-dark.svg', 'mimeType' => 'image/svg+xml', 'theme' => 'dark'],
		], $identity->info()['icons']);
	}
}
