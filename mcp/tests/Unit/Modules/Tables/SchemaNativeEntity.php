<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

/** Authorized native metadata entity used by preparation tests, never a gateway double. */
final class SchemaNativeEntity {
	/** @param array<string,mixed> $data Native values. */
	public function __construct(public array $data) {}
	/** @return int Native identifier. */
	public function getId(): int { return $this->data['id']; }
	/** @return int Parent. */
	public function getTableId(): int { return $this->data['tableId'] ?? $this->data['id']; }
	/** @return string Owner. */
	public function getOwnership(): string { return $this->data['ownership'] ?? 'alice'; }
	/** @return bool Local node. */
	public function isFederated(): bool { return false; }
	/** @return array<string,mixed> Native snapshot. */
	public function jsonSerialize(): array { return $this->data; }
	/** @return list<int> Visible columns. */
	public function getColumnIds(): array { return array_column($this->data['columnSettings'] ?? [], 'columnId'); }
}
