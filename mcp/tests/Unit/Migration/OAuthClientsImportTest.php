<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Migration;

use OCA\Mcp\Db\OAuthClientsMapper;
use OCA\Mcp\Migration\Version001100Date20261016000000;
use OCA\Mcp\OAuth\ClientResolver;
use OCA\Mcp\OAuth\CustomClient;
use OCA\Mcp\OAuth\NativeClient;
use OCA\Mcp\Tests\Unit\OAuth\ClientsTable;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The upgrade from 1.0.8 imports the native client's switch (and a pre-release list of self-hosted clients) from the
 * app configuration into the authoritative table, once, without overwriting a row that exists: no terminal step.
 */
final class OAuthClientsImportTest extends TestCase {
	private function import(ClientsTable $table, string $native, string $custom): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default = '') => $key === NativeClient::CONFIG_KEY ? $native : $default);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn ($app, $key, $default = '') => $key === CustomClient::CLIENTS_KEY ? $custom : $default);
		(new Version001100Date20261016000000($table->db->connection(), $config, $appConfig))
			->postSchemaChange($this->createMock(IOutput::class), fn () => null, []);
	}

	public function testTheNativeSwitchOf108IsImportedOn(): void {
		$table = new ClientsTable($this);
		$this->import($table, '1', '[]');
		$this->assertSame('1', ClientResolver::nativeGeneration($table->mapper));
		$this->assertSame([], $table->stored());
	}

	public function testAnInstanceWithoutTheSwitchStaysOff(): void {
		foreach (['0', ''] as $value) {
			$table = new ClientsTable($this);
			$this->import($table, $value, '[]');
			$this->assertNull($table->mapper->find(NativeClient::CLIENT_ID));
		}
	}

	public function testAPreReleaseListIsImportedAboveItsOldGenerationAndInvalidEntriesAreSkipped(): void {
		$table = new ClientsTable($this);
		$this->import($table, '0', json_encode([
			['id' => 'aaaaaaaaaaaa', 'name' => 'QwenPaw', 'enabled' => true, 'redirectUris' => ['https://h.example.com/cb'], 'gen' => 1],
			['id' => 'short', 'name' => 'x', 'enabled' => true, 'redirectUris' => ['https://h.example.com/cb']],
			['id' => 'bbbbbbbbbbbb', 'name' => 'Off', 'enabled' => false, 'redirectUris' => ['http://evil.example/cb']],
		]));
		$this->assertSame([['id' => 'aaaaaaaaaaaa', 'name' => 'QwenPaw', 'enabled' => true, 'redirectUris' => ['https://h.example.com/cb'], 'gen' => '2']],
			$table->stored());
	}

	public function testARowThatExistsIsNeverOverwritten(): void {
		$table = new ClientsTable($this);
		$table->native(false, '7');
		$this->import($table, '1', '[]');
		$this->assertSame([false, '7'], [$table->mapper->find(NativeClient::CLIENT_ID)['enabled'], $table->mapper->find(NativeClient::CLIENT_ID)['gen']]);
		$this->assertSame(OAuthClientsMapper::KIND_NATIVE, $table->mapper->find(NativeClient::CLIENT_ID)['kind']);
	}
}
