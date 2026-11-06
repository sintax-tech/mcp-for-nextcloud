<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

use OCA\Mcp\Modules\Tables\Presentation\SchemaMessages;

/** Tool-specific ShareNode intent, native sink and observation. */
final class ShareNodeAction implements SchemaAction {
	/** @param SchemaPreparation $preparation Authorization. @param SchemaNativeWriter $writer Native sink. @param SchemaObservation $observation Fresh readback. */
	public function __construct(private SchemaPreparation $preparation, private SchemaNativeWriter $writer, private SchemaObservation $observation) {}
	/** {@inheritDoc} */
	public function name(): string { return 'share_node'; }
	/** {@inheritDoc} */
	public function operation(): string { return 'share'; }
	/** {@inheritDoc} */
	public function notes(): array { return [SchemaMessages::sharing()]; }
	/** {@inheritDoc} */
	public function prepare(array $args, string $uid, string $profile): array {
		$base = $this->preparation->base($args['node_type'], $args['node_id'], false, true, $args, $uid, $profile);
		return $this->preparation->sharing($base, $uid);
	}
	/** {@inheritDoc} */
	public function apply(array $prepared, string $uid): ?int { return $this->writer->shareNode($prepared, $uid); }
	/** {@inheritDoc} */
	public function observe(array $prepared, ?int $returned, string $uid): array { return $this->observation->sharing($prepared, $uid); }
}
