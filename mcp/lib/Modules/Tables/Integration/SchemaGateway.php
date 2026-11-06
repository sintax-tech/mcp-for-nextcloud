<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

/** Thin native boundary delegating preparation, a single attempt and fresh observation. */
class SchemaGateway {
	/**
	 * @param SchemaPreparation $preparation Read-only preparation.
	 * @param SchemaNativeWriter $writer One mutation.
	 * @param SchemaObservation $observation Fresh observation.
	 */
	public function __construct(private SchemaPreparation $preparation, private SchemaNativeWriter $writer, private SchemaObservation $observation) {}
	/**
	 * @param string $action Action.
	 * @param array<string,mixed> $a Arguments.
	 * @param string $uid Caller.
	 * @param string $profile Proven API family.
	 * @return array<string,mixed> Prepared native state.
	 */
	public function prepare(string $action, array $a, string $uid, string $profile): array { return $this->preparation->prepare($action, $a, $uid, $profile); }
	/**
	 * @param string $action Action.
	 * @param array<string,mixed> $prepared Revalidated state.
	 * @param string $uid Caller.
	 * @return int|null Native returned ID.
	 */
	public function apply(string $action, array $prepared, string $uid): ?int { return $this->writer->apply($action, $prepared, $uid); }
	/**
	 * @param string $action Action.
	 * @param array<string,mixed> $p Prepared state.
	 * @param int|null $returned Returned native ID.
	 * @param string $uid Caller.
	 * @return array<string,mixed> Observed authorized state.
	 */
	public function observe(string $action, array $p, ?int $returned, string $uid): array { return $this->observation->observe($action, $p, $returned, $uid); }
}
