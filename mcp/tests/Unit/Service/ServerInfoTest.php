<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Service;

use OCA\Mcp\Service\McpProtocol;
use OCA\Mcp\Service\PromptCatalog;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\InstalledApp;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The server identity announced to MCP clients is whatever IAppManager reports, not a version baked into the code.
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
		$declared = (string) simplexml_load_file(dirname(__DIR__, 3) . '/appinfo/info.xml')->version;
		$this->assertSame($declared, InstalledApp::version());
		$this->assertNotSame(self::SENTINEL, $declared);

		$apps = $this->createMock(IAppManager::class);
		InstalledApp::mock($apps, self::SENTINEL, $this->atLeastOnce());
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		$protocol = new McpProtocol(new ToolRegistry([], $policy, $apps,
			$this->createMock(IUserManager::class), $this->createMock(LoggerInterface::class)),
			new PromptCatalog(), $policy, $this->createMock(LoggerInterface::class), $apps);
		$expected = ['name' => 'mcp-for-cloud', 'version' => self::SENTINEL];

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
}
