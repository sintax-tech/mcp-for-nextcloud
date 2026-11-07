<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables;

use OCA\Mcp\Tests\Integration\Support\{Credential, Http, LoginFactory};
use OCA\Mcp\Tests\Integration\Tables\Write\WriteCellScene;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use RuntimeException;

/** Independent native API observations/cleanup and read-only Tables database digests for the disposable cell. */
final class TablesSchemaScene {
	/** Owner's app password, never printed. */
	private Credential $credential;

	/** Creates only a native API credential for the harness owner. */
	public function __construct() {
		$this->credential = LoginFactory::basic(WriteCellScene::OWNER);
	}

	/** Calls the official Tables REST API independently of MCP.
	 * @param string $method GET or DELETE.
	 * @param string $path Native path after api/1.
	 * @return array<string|int,mixed> Native JSON without an optional OCS envelope.
	 */
	public function api(string $method, string $path): array {
		$reply = Http::request($method, Http::BASE . '/index.php/apps/tables/api/1/' . $path,
			['Authorization: ' . $this->credential->authorization(), 'Accept: application/json', 'OCS-APIRequest: true']);
		if ($reply['status'] !== 200) {
			throw new RuntimeException('Tables native observation/cleanup failed: status=' . $reply['status']);
		}
		$data = json_decode($reply['body'], true, 512, JSON_THROW_ON_ERROR);
		return $data['ocs']['data'] ?? $data;
	}

	/** Hashes all native Tables rows without exposing their contents or writing to the database.
	 * @return array<string,array{rows:int,sha256:string}> Stable per-table evidence.
	 */
	public static function snapshot(): array {
		$db = Server::get(IDBConnection::class);
		$prefix = Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
		$out = [];
		foreach ($db->createSchema()->getTables() as $table) {
			$name = $table->getName();
			if (!str_starts_with($name, $prefix . 'tables_')) {
				continue;
			}
			$result = $db->getQueryBuilder()->select('*')->from(substr($name, strlen($prefix)))->executeQuery();
			try {
				$rows = $result->fetchAll();
			} finally {
				$result->closeCursor();
			}
			$rows = array_map(static function (array $row): string {
				ksort($row);
				return json_encode($row, JSON_THROW_ON_ERROR);
			}, $rows);
			sort($rows);
			$out[$name] = ['rows' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
		}
		if ($out === []) {
			throw new RuntimeException('No native Tables tables were captured.');
		}
		ksort($out);
		return $out;
	}
}
