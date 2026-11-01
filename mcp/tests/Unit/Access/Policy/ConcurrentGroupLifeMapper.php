<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\Policy;

use OCA\Mcp\Db\GroupLifeMapper;
use OCP\IDBConnection;

/** Applies one concurrent lifecycle mark immediately before the real transition. */
final class ConcurrentGroupLifeMapper extends GroupLifeMapper {
	/**
	 * @param IDBConnection $db fixture database
	 * @param \Closure():void|null $concurrent one-shot concurrent mark
	 */
	public function __construct(IDBConnection $db, private ?\Closure $concurrent) {
		parent::__construct($db);
	}

	/** {@inheritDoc} */
	public function transition(string $gid, string $expectedGen, string $state, string $gen): bool {
		if ($this->concurrent !== null) {
			[$concurrent, $this->concurrent] = [$this->concurrent, null];
			$concurrent();
		}
		return parent::transition($gid, $expectedGen, $state, $gen);
	}
}
