<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

use OCA\Mcp\Modules\Tables\Presentation\SchemaMessages;

/** Tool-specific CreateTable intent, native sink and observation. */
final class CreateTableAction implements SchemaAction {
	/** @param SchemaPreparation $preparation Authorization. @param SchemaNativeWriter $writer Native sink. @param SchemaObservation $observation Fresh readback. */
	public function __construct(private SchemaPreparation $preparation, private SchemaNativeWriter $writer, private SchemaObservation $observation) {}
	/** {@inheritDoc} */
	public function name(): string { return 'create_table'; }
	/** {@inheritDoc} */
	public function operation(): string { return 'schema'; }
	/** {@inheritDoc} */
	public function notes(): array { return [SchemaMessages::singleEntity()]; }
	/** {@inheritDoc} */
	public function prepare(array $args, string $uid, string $profile): array {
		$base = $this->preparation->base('table', null, true, false, $args, $uid, $profile);
		return $base;
	}
	/** {@inheritDoc} */
	public function apply(array $prepared, string $uid): ?int { return $this->writer->createTable($prepared, $uid); }
	/** {@inheritDoc} */
	public function observe(array $prepared, ?int $returned, string $uid): array { return $this->observation->node($prepared, $returned, $uid); }
}
