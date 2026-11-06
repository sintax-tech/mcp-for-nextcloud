<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Compat;

use OCA\Mcp\Db\OAuthClientsMapper;
use OCA\Mcp\OAuth\Clients\ClientResolver;
use OCA\Mcp\OAuth\Clients\NativeClient;
use OCP\IConfig;
use OCP\Server;
use OCP\IDBConnection;
use PHPUnit\Framework\Assert;

/** Checks the existing upgrade contract without adding a test identity. */
final class NativeClientUpgradeProof {
	/** @param IDBConnection $database Independent real-cell connection. @return void Asserts preservation of the real baseline checkpoint. */
	public static function assertPreserved(IDBConnection $database): void {
		$generation = '1';
		$version = UpgradeBaseline::version();
		if (UpgradeBaseline::isModern($version)) {
			$path = (string)getenv('MCP_IT_LEGACY') . '.release.json';
			Assert::assertFileExists($path);
			$sidecar = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
			Assert::assertSame($version, $sidecar['baseline']);
			$generation = $sidecar['native_generation'];
			Assert::assertMatchesRegularExpression('/^[1-9][0-9]*$/D', $generation);
		}
		$row = (new OAuthClientsMapper($database))->find(NativeClient::CLIENT_ID);
		Assert::assertNotNull($row, 'the baseline native switch must survive the upgrade');
		Assert::assertSame([OAuthClientsMapper::KIND_NATIVE, true, $generation], [$row['kind'], $row['enabled'], $row['gen']]);
		Assert::assertSame($generation, ClientResolver::nativeGeneration(Server::get(OAuthClientsMapper::class)));
		Assert::assertSame(NativeClient::CLIENT_ID, Server::get(ClientResolver::class)->resolve(NativeClient::CLIENT_ID)->clientId);
		Assert::assertSame('1', Server::get(IConfig::class)->getAppValue('mcp', NativeClient::CONFIG_KEY), 'the legacy value is left as it was');
	}
}
