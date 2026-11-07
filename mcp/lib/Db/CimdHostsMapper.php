<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use OCA\Mcp\Platform\Numbers\DecimalCounter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use UnexpectedValueException;

/** Authoritative CIMD host admission and withdrawal generations; never reads an AppConfig snapshot. */
class CimdHostsMapper {
	public const TABLE = 'mcp_cimd_hosts';

	/** @param IDBConnection $db Official connection shared with the policy transaction. */
	public function __construct(private IDBConnection $db) {}

	/** @return list<array{host:string, enabled:bool, gen:string}> Validated rows, including withdrawal tombstones. */
	public function all(): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('*')->from(self::TABLE)->orderBy('host_key')->executeQuery();
		try {
			return array_map(self::row(...), $result->fetchAll());
		} finally {
			$result->closeCursor();
		}
	}

	/** @param string $host Exact lowercase host. @return array{host:string, enabled:bool, gen:string}|null Stored state. */
	public function find(string $host): ?array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('host_key', $qb->createNamedParameter(hash('sha256', $host))))->executeQuery();
		try {
			$row = $result->fetch();
			$state = is_array($row) ? self::row($row) : null;
			if ($state !== null && $state['host'] !== $host) {
				throw new UnexpectedValueException('CIMD host digest collision');
			}
			return $state;
		} finally {
			$result->closeCursor();
		}
	}

	/** @param string $host Exact lowercase host. @param bool $enabled Admission. @param string $gen Positive counter. */
	public function put(string $host, bool $enabled, string $gen): void {
		$values = ['host_key' => hash('sha256', $host), 'host' => $host, 'enabled' => $enabled ? 1 : 0, 'gen' => $gen];
		self::row($values);
		$qb = $this->db->getQueryBuilder();
		if ($this->find($host) === null) {
			$qb->insert(self::TABLE);
			foreach ($values as $key => $value) {
				$qb->setValue($key, $qb->createNamedParameter($value, in_array($key, ['enabled', 'gen'], true) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
			}
		} else {
			$qb->update(self::TABLE)->set('enabled', $qb->createNamedParameter($values['enabled'], IQueryBuilder::PARAM_INT))
				->set('gen', $qb->createNamedParameter($gen, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('host_key', $qb->createNamedParameter($values['host_key'])));
		}
		$qb->executeStatement();
	}

	/** @param string $host Effective trusted host. @return void A concurrent insert never overwrites its generation. */
	public function seed(string $host): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE);
		foreach (['host_key' => hash('sha256', $host), 'host' => $host, 'enabled' => 1, 'gen' => 1] as $key => $value) {
			$qb->setValue($key, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
		}
		try {
			$qb->executeStatement();
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) { throw $e; }
		}
	}

	/** @param array<string,mixed> $row Database row. @return array{host:string, enabled:bool, gen:string} Validated state. */
	private static function row(array $row): array {
		$host = $row['host'] ?? null;
		$enabled = (string)($row['enabled'] ?? '');
		$gen = DecimalCounter::validate((string)($row['gen'] ?? ''), DecimalCounter::MAX);
		if (!is_string($host) || $host === '' || $host !== strtolower(trim($host)) || $gen === '0'
			|| !in_array($enabled, ['0', '1'], true) || ($row['host_key'] ?? null) !== hash('sha256', $host)) {
			throw new UnexpectedValueException('Invalid stored CIMD host');
		}
		return ['host' => $host, 'enabled' => $enabled === '1', 'gen' => $gen];
	}
}
