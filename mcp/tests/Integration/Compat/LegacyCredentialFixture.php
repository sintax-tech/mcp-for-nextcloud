<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

use OCP\{IConfig, IDBConnection, Server};
use RuntimeException;
use Throwable;

/** Uses only the installed baseline's own store/hasher; no remote metadata fetch or code from the candidate release. */
final class LegacyCredentialFixture {
	/** @param string $output Private baseline checkpoint, never printed. @return void Trusted and untrusted legacy credentials. */
	public static function write(string $output): void {
		$uid = 'mcp-it-legacy';
		$client = 'https://claude.ai/mcp-it-client.json';
		$redirect = 'https://mcp-it.example/callback';
		$resource = 'https://mcp-it.example/index.php/apps/mcp/mcp';
		$hasher = Server::get(LegacyOAuthNames::hasher());
		$store = Server::get(LegacyOAuthNames::store());
		$db = Server::get(IDBConnection::class);
		$now = time();
		$ttl = 6 * 3600;
		$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
		$code = $hasher->generate('code');
		$tokens = [];
		$db->beginTransaction();
		try {
			$store->insertCode(['code_hash' => $hasher->hash($code), 'user_id' => $uid, 'client_id' => $client, 'redirect_uri' => $redirect,
				'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
				'resource' => $resource, 'scope' => 'mcp', 'expires_at' => $now + $ttl], $now);
			foreach (['rotated', 'plain', 'untrusted'] as $name) {
				$tokens[$name] = ['access' => $hasher->generate('at'), 'refresh' => $hasher->generate('rt')];
				$store->insertToken(['user_id' => $uid, 'client_id' => $name === 'untrusted' ? 'https://mcp-it.example/client.json' : $client,
					'resource' => $resource, 'scope' => 'mcp', 'access_hash' => $hasher->hash($tokens[$name]['access']), 'access_expires' => $now + $ttl,
					'refresh_hash' => $hasher->hash($tokens[$name]['refresh']), 'refresh_expires' => $now + $ttl, 'created_at' => $now], $now);
			}
			$spent = $tokens['rotated']['refresh'];
			$untrustedSpent = $tokens['untrusted']['refresh'];
			foreach (['rotated', 'untrusted'] as $name) {
				$old = $tokens[$name]['refresh'];
				$id = (int)$store->findByRefresh($hasher->hash($old))['id'];
				$tokens[$name] = ['access' => $hasher->generate('at'), 'refresh' => $hasher->generate('rt')];
				if (!$store->rotate($id, $hasher->hash($old), $hasher->hash($tokens[$name]['access']), $now + $ttl,
					$hasher->hash($tokens[$name]['refresh']), $now + $ttl)) {
					throw new RuntimeException('The baseline store refused the fixture rotation');
				}
			}
			$db->commit();
		} catch (Throwable $e) {
			$db->rollBack();
			throw $e;
		}
		Server::get(IConfig::class)->setAppValue('mcp', 'oauth_native_client_enabled', '1');
		$rows = [];
		foreach (['mcp_oauth_codes', 'mcp_oauth_tokens', 'mcp_oauth_spent'] as $table) {
			$qb = $db->getQueryBuilder();
			$result = $qb->select('*')->from($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))->executeQuery();
			try {
				$rows[$table] = array_map(static fn (array $row): array => array_map('strval', $row), $result->fetchAll());
			} finally {
				$result->closeCursor();
			}
		}
		file_put_contents($output, json_encode(['uid' => $uid, 'client' => $client, 'redirect' => $redirect, 'resource' => $resource,
			'code' => $code, 'verifier' => $verifier, 'tokens' => $tokens, 'untrusted_client' => 'https://mcp-it.example/client.json',
			'spent' => $spent, 'untrusted_spent' => $untrustedSpent, 'rows' => $rows], JSON_THROW_ON_ERROR));
		chmod($output, 0600);
		echo 'Issued legacy credentials: ', implode(', ', array_map(static fn ($t, $r): string => "$t=" . count($r), array_keys($rows), $rows)), PHP_EOL;
	}
}
