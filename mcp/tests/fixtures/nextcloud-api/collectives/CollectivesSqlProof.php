<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once __DIR__ . '/CollectivesSqlTypes.php';
require_once __DIR__ . '/CollectivesSqlWiring.php';

/** Rebuilds the fetch return proof from the exact files bundled in each officially checksummed server archive. */
final class CollectivesSqlProof {
	/**
	 * @param array{files:array<string,string>,drivers:array<string,string>} $pin paths and file SHA256s within the server tarball
	 * @param array<string,string> $sources complete bytes from the tarball, not an OCP signature
	 * @return array proved returns, followed bodies, driver wiring and language leaves
	 */
	public static function build(array $pin, array $sources): array {
		if (array_keys($sources) !== array_keys($pin['files'])) {
			throw new RuntimeException('SQL proof source set differs from the pin');
		}
		$tree = sys_get_temp_dir() . '/collectives-sql-proof-' . bin2hex(random_bytes(5));
		mkdir($tree);
		try {
			foreach ($sources as $file => $source) {
				if (hash('sha256', $source) !== $pin['files'][$file]) {
					throw new RuntimeException('SQL source SHA mismatch: ' . $file);
				}
				file_put_contents($tree . '/' . hash('sha256', $file) . '.php', $source);
			}
			$index = new CallGraphIndex(['sql/' => $tree], null);
			$wiring = CollectivesSqlWiring::check($index, $pin['drivers']);
			$returns = [];
			$methods = [];
			foreach (['Doctrine\\DBAL\\Driver\\PDO\\Result', 'Doctrine\\DBAL\\Driver\\OCI8\\Result'] as $driver) {
				$proof = new CollectivesSqlTypes($index, $driver);
				foreach (['fetch', 'fetchAll'] as $method) {
					foreach (['PDO::FETCH_ASSOC', 'PDO::FETCH_NUM', ...($method === 'fetchAll' ? ['PDO::FETCH_COLUMN'] : [])] as $mode) {
						$type = $proof->call('OC\\DB\\ResultAdapter', $method, [$mode]);
						$expected = $method === 'fetch' || $mode === 'PDO::FETCH_COLUMN' ? '@scalar[]' : '@scalar[][]';
						if ($type !== $expected) {
							throw new RuntimeException("Unproved SQL return: $driver $method $mode: " . var_export($type, true));
						}
						$returns[$method][$mode] = substr($type, 1);
					}
				}
				$methods += $proof->methods;
			}
			ksort($methods);
			return ['returns' => $returns, 'methods' => $methods, 'wiring' => $wiring,
				'leaves' => ['php-core PDOStatement::fetch', 'php-core PDOStatement::fetchAll', 'php-core PDOStatement::__toString[absent]',
					'function oci_fetch_array[OCI_RETURN_LOBS]', 'function oci_fetch_all[OCI_RETURN_LOBS]']];
		} finally {
			foreach (glob($tree . '/*') as $file) {
				unlink($file);
			}
			rmdir($tree);
		}
	}
}
