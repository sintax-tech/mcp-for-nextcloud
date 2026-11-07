<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use UnexpectedValueException;

/** Canonical parsing of an Allow row. Unknown names are opaque, never aliases. */
final readonly class StoredGroupGrant {
	/** @var bool Whether this version recognizes the grant. */
	public bool $recognized;
	/** @var string Opaque selection identifier for the composite row identity. */
	public string $selectionId;

	/**
	 * @param string $groupId Exact group identity, already validated by the reader.
	 * @param string $module Canonical module name.
	 * @param string $operation Canonical operation name.
	 * @throws UnexpectedValueException A malformed name cannot be isolated safely.
	 */
	public function __construct(public string $groupId, public string $module, public string $operation) {
		if (!self::wellFormed($module, $operation)) {
			throw new UnexpectedValueException('Malformed group grant');
		}
		$this->recognized = self::known($module, $operation);
		$this->selectionId = self::id($groupId, $module, $operation);
	}

	/** @return array{id:string,module:string,operation:string,value:string} Admin-only stored Allow diagnosis. */
	public function diagnostic(): array {
		return ['id' => $this->selectionId, 'module' => $this->module, 'operation' => $this->operation, 'value' => 'allow'];
	}

	/**
	 * @param string $module Stored module.
	 * @param string $operation Stored operation.
	 * @return bool Whether this pair can be interpreted unambiguously as a grant name.
	 */
	public static function wellFormed(string $module, string $operation): bool {
		if ($module === '_system' || $operation === 'eligible') {
			return $module === '_system' && $operation === 'eligible';
		}
		return strlen($module) <= 32 && strlen($operation) <= 32
			&& preg_match('/^[a-z][a-z0-9_]*$/D', $module) === 1
			&& preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/D', $operation) === 1;
	}

	/**
	 * @param string $module Stored module.
	 * @param string $operation Stored operation.
	 * @return bool Whether this version can grant the pair.
	 */
	public static function known(string $module, string $operation): bool {
		return \OCA\Mcp\Access\Grants\GrantPolicy::inCatalog($module, $operation)
			|| ($module === '_system' && $operation === 'eligible');
	}

	/**
	 * Stable opaque selection ID for the existing composite primary key; never accepts free grant keys for cleanup.
	 * @param string $gid Exact group identity.
	 * @param string $module Stored module.
	 * @param string $operation Stored operation.
	 * @return string SHA-256 of the unambiguous row tuple.
	 */
	public static function id(string $gid, string $module, string $operation): string {
		return hash('sha256', json_encode([$gid, $module, $operation], JSON_THROW_ON_ERROR));
	}
}
