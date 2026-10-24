<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

use OCP\IAppConfig;
use OCP\IDBConnection;

/** Reads only the backup document, without invalidating or trusting AppConfig's shared cache. */
class BackupSettingsDocument {
	/** @param IDBConnection $db server connection, shared with the policy writer's transaction */
	public function __construct(private IDBConnection $db) {
	}

	/**
	 * @return string|null persisted JSON, or null only when the key is absent
	 * @throws \Throwable on a database error or an incompatible stored value
	 */
	public function read(): ?string {
		$query = $this->db->getQueryBuilder();
		$query->select('configvalue', 'type')->from('appconfig')
			->where($query->expr()->eq('appid', $query->createNamedParameter('mcp')))
			->andWhere($query->expr()->eq('configkey', $query->createNamedParameter(BackupLocation::KEY)));
		$result = $query->executeQuery();
		try {
			$row = $result->fetch();
		} finally {
			$result->closeCursor();
		}
		if ($row === false) {
			return null;
		}
		// This document is written as a non-sensitive string. Legacy untyped strings remain readable;
		// sensitive, incompatible or unknown types cannot silently release historical reservations.
		if (!in_array((string)$row['type'], ['0', (string)IAppConfig::VALUE_MIXED, (string)IAppConfig::VALUE_STRING], true)
			|| !is_string($row['configvalue'])) {
			throw new \UnexpectedValueException('Invalid backup settings storage type');
		}
		return $row['configvalue'];
	}
}
